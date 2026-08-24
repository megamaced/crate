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
     * ComicVine (`api_key`) and PriceCharting (`token`) can only authenticate
     * in the query string, and an HTTP client's exception message quotes the
     * full effective request URI — so a mistyped key turns every 401 into a
     * plaintext copy of that key in nextcloud.log. Discogs and TMDB use the
     * Authorization header and never populate these.
     *
     * @var list<string>
     */
    private const CREDENTIAL_QUERY_PARAMS = ['key', 'api_key', 'token', 'access_token'];

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
        $options = [
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
            'timeout' => static::DEFAULT_TIMEOUT,
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logWarning($url, $e);
            return [];
        }
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
