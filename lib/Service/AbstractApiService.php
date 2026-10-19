<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

/**
 * Shared HTTP client wrapper for the external-API enrichment services
 * (TMDB, RAWG, ComicVine, Open Library, PriceCharting).
 *
 * Subclasses supply a service name for log messages and, when applicable,
 * the credential key used to retrieve the per-user API token or key.
 *
 * Error handling is deliberately permissive: network / parse errors are
 * logged (with credentials stripped, so tokens don't leak) and surfaced as
 * an empty array, so callers can treat "no data" the same whether the
 * upstream is down or the result set is empty.
 */
abstract class AbstractApiService
{
    /** Default request timeout in seconds. */
    protected const DEFAULT_TIMEOUT = 10;

    /**
     * Query parameters that carry a per-user credential. RAWG (`key`),
     * ComicVine (`api_key`) and PriceCharting (`t`) can only authenticate
     * in the query string, and an HTTP client's exception message quotes the
     * full effective request URI — so a mistyped key turns every 401 into a
     * plaintext copy of that key in nextcloud.log. Discogs and TMDB use the
     * Authorization header and never populate these.
     *
     * @var list<string>
     */
    private const CREDENTIAL_QUERY_PARAMS = ['key', 'api_key', 't', 'token', 'access_token'];

    public function __construct(
        protected readonly IClientService $clientService,
        protected readonly ICredentialsManager $credentialsManager,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /** Short human-readable service name used in log messages. */
    abstract protected function serviceName(): string;

    /**
     * The `crate/...` credential key used to retrieve this service's
     * per-user token or API key. Services that don't use credentials
     * (e.g. Open Library) may return `null`.
     */
    protected function credentialKey(): ?string
    {
        return null;
    }

    /** Retrieve the user's stored credential for this service, or '' if missing. */
    protected function getCredential(string $userId): string
    {
        $key = $this->credentialKey();
        if ($key === null) {
            return '';
        }
        return (string)($this->credentialsManager->retrieve($userId, $key) ?? '');
    }

    /**
     * Perform a GET and return the JSON-decoded body.
     * Returns an empty array on any HTTP / parse error, after logging a
     * warning with the query-string-stripped URL.
     *
     * @param array<string, string|int> $query
     * @param array<string, string>     $headers
     * @return array<string, mixed>
     */
    protected function getJson(string $url, array $query = [], array $headers = []): array
    {
        try {
            return $this->fetchJson($url, $query, $headers);
        } catch (\Exception $e) {
            $this->logWarning($url, $e);
            return [];
        }
    }

    /**
     * Perform a GET and return the JSON-decoded body, letting a failed request
     * throw — for a caller that has to tell one failure from another. A body
     * that is not a JSON object is a failure too: truncated or garbled, it is
     * not an answer.
     *
     * @param array<string, string|int> $query
     * @param array<string, string>     $headers
     * @return array<string, mixed>
     * @throws \UnexpectedValueException when the body is not a JSON object
     */
    protected function fetchJson(string $url, array $query = [], array $headers = []): array
    {
        $options = [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
            'timeout' => static::DEFAULT_TIMEOUT,
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        $response = $this->clientService->newClient()->get($url, $options);
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new \UnexpectedValueException($this->serviceName() . ' returned a body that is not a JSON object');
        }
        return $body;
    }

    /**
     * GET $url and return the list of objects under $key in its JSON body —
     * for callers that must not mistake a broken answer for an empty one.
     * See listIn().
     *
     * @param array<string, string|int> $query
     * @param array<string, string>     $headers
     * @return list<array<string, mixed>>
     * @throws \UnexpectedValueException when the body holds no such list
     */
    protected function fetchList(string $url, string $key, array $query = [], array $headers = []): array
    {
        $options = [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
            'timeout' => static::DEFAULT_TIMEOUT,
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        $response = $this->clientService->newClient()->get($url, $options);
        return $this->listIn((string) $response->getBody(), $key);
    }

    /**
     * The list of objects under $key in the JSON $body. Anything else — a
     * body that does not decode, a missing key, a JSON object where the list
     * should be, or an entry that is not an object — is an error or an
     * unexpected shape, not an empty result, so it throws.
     *
     * Decoded with objects kept as objects first: once decoded to PHP arrays,
     * `{}` and `[]` are both `[]`, and an error object under the key would
     * pass for an empty list.
     *
     * @return list<array<string, mixed>>
     * @throws \UnexpectedValueException
     */
    protected function listIn(string $body, string $key): array
    {
        $decoded = json_decode($body, false);
        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException($this->serviceName() . ' returned a body that is not a JSON object');
        }
        $list = $decoded->{$key} ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new \UnexpectedValueException($this->serviceName() . ' response has no "' . $key . '" list');
        }
        foreach ($list as $entry) {
            if (!$entry instanceof \stdClass) {
                throw new \UnexpectedValueException($this->serviceName() . ' "' . $key . '" list holds a non-object');
            }
        }
        /** @var list<array<string, mixed>> */
        return json_decode((string) json_encode($list), true);
    }

    /**
     * Emit a standardised warning log entry for an API error. The exception is
     * described by class and code and its message is redacted; the exception
     * object itself is not attached, because a logged stack trace would carry
     * the request URI (and with it the credential) all over again.
     */
    protected function logWarning(string $url, \Throwable $e): void
    {
        $this->logger->warning($this->serviceName() . ' API error for {url}: {class} ({code}) {msg}', [
            'url'   => strtok($url, '?') ?: $url,
            'class' => get_class($e),
            'code'  => $e->getCode(),
            'msg'   => self::redactCredentials($e->getMessage()),
            'app'   => 'crate',
        ]);
    }

    /**
     * Replace the value of every credential-bearing query parameter in $text
     * with a placeholder. Applied to anything derived from an upstream error
     * before it reaches the log, which is readable in the admin UI, rotated
     * to disk and swept up by backups.
     */
    public static function redactCredentials(string $text): string
    {
        foreach (self::CREDENTIAL_QUERY_PARAMS as $param) {
            $text = (string) preg_replace(
                '/([?&]' . preg_quote($param, '/') . '=)[^&\s\'"`<>]+/i',
                '${1}REDACTED',
                $text,
            );
        }
        return $text;
    }
}
