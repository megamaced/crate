<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\MarketValueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;

class SettingsController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IConfig $config,
        private readonly IUserSession $userSession,
        private readonly ICredentialsManager $credentialsManager,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function getDiscogsToken(): DataResponse
    {
        $token = (string) ($this->credentialsManager->retrieve($this->userId(), 'crate/discogs_token') ?? '');
        return new DataResponse(['hasToken' => $token !== '']);
    }

    #[NoAdminRequired]
    public function setDiscogsToken(string $token = ''): DataResponse
    {
        $uid = $this->userId();
        $trimmed = trim($token);
        if ($trimmed === '') {
            $this->credentialsManager->delete($uid, 'crate/discogs_token');
        } else {
            $this->credentialsManager->store($uid, 'crate/discogs_token', $trimmed);
        }
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function getTmdbToken(): DataResponse
    {
        $token = (string)($this->credentialsManager->retrieve($this->userId(), 'crate/tmdb_token') ?? '');
        return new DataResponse(['hasToken' => $token !== '']);
    }

    #[NoAdminRequired]
    public function setTmdbToken(string $token = ''): DataResponse
    {
        $uid     = $this->userId();
        $trimmed = trim($token);
        if ($trimmed === '') {
            $this->credentialsManager->delete($uid, 'crate/tmdb_token');
        } else {
            $this->credentialsManager->store($uid, 'crate/tmdb_token', $trimmed);
        }
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function getRawgKey(): DataResponse
    {
        $key = (string)($this->credentialsManager->retrieve($this->userId(), 'crate/rawg_key') ?? '');
        return new DataResponse(['hasKey' => $key !== '']);
    }

    #[NoAdminRequired]
    public function setRawgKey(string $key = ''): DataResponse
    {
        $uid     = $this->userId();
        $trimmed = trim($key);
        if ($trimmed === '') {
            $this->credentialsManager->delete($uid, 'crate/rawg_key');
        } else {
            $this->credentialsManager->store($uid, 'crate/rawg_key', $trimmed);
        }
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function getComicVineKey(): DataResponse
    {
        $key = (string)($this->credentialsManager->retrieve($this->userId(), 'crate/comicvine_key') ?? '');
        return new DataResponse(['hasKey' => $key !== '']);
    }

    #[NoAdminRequired]
    public function setComicVineKey(string $key = ''): DataResponse
    {
        $uid     = $this->userId();
        $trimmed = trim($key);
        if ($trimmed === '') {
            $this->credentialsManager->delete($uid, 'crate/comicvine_key');
        } else {
            $this->credentialsManager->store($uid, 'crate/comicvine_key', $trimmed);
        }
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function getPriceChartingToken(): DataResponse
    {
        $token = (string)($this->credentialsManager->retrieve($this->userId(), 'crate/pricecharting_token') ?? '');
        return new DataResponse(['hasToken' => $token !== '']);
    }

    #[NoAdminRequired]
    public function setPriceChartingToken(string $token = ''): DataResponse
    {
        $uid     = $this->userId();
        $trimmed = trim($token);
        if ($trimmed === '') {
            $this->credentialsManager->delete($uid, 'crate/pricecharting_token');
        } else {
            $this->credentialsManager->store($uid, 'crate/pricecharting_token', $trimmed);
        }
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function getMarketSettings(): DataResponse
    {
        $uid = $this->userId();
        $autoFetch = $this->config->getUserValue($uid, 'crate', 'auto_fetch_market_rates', '0') === '1';
        $autoEnrichClick = $this->config->getUserValue($uid, 'crate', 'auto_enrich_click', '1') === '1';
        $autoEnrichImport = $this->config->getUserValue($uid, 'crate', 'auto_enrich_import', '1') === '1';
        return new DataResponse([
            'autoFetchMarketRates' => $autoFetch,
            'autoEnrichOnClick'    => $autoEnrichClick,
            'autoEnrichOnImport'   => $autoEnrichImport,
            'marketCurrency'       => $this->config->getUserValue($uid, 'crate', 'market_currency', 'GBP'),
        ]);
    }

    #[NoAdminRequired]
    public function setMarketSettings(
        bool $autoFetchMarketRates = false,
        string $marketCurrency = 'GBP',
        bool $autoEnrichOnClick = true,
        bool $autoEnrichOnImport = true,
    ): DataResponse {
        $uid = $this->userId();
        $currency = strtoupper($marketCurrency);
        if (!in_array($currency, MarketValueService::SUPPORTED_CURRENCIES, true)) {
            return new DataResponse(['error' => 'Invalid currency'], Http::STATUS_BAD_REQUEST);
        }
        $this->config->setUserValue($uid, 'crate', 'auto_fetch_market_rates', $autoFetchMarketRates ? '1' : '0');
        $this->config->setUserValue($uid, 'crate', 'market_currency', $currency);
        $this->config->setUserValue($uid, 'crate', 'auto_enrich_click', $autoEnrichOnClick ? '1' : '0');
        $this->config->setUserValue($uid, 'crate', 'auto_enrich_import', $autoEnrichOnImport ? '1' : '0');
        return new DataResponse([]);
    }

    /**
     * PUT /api/v1/settings/currency
     * Update just the market currency preference — convenience endpoint for Android app.
     */
    #[NoAdminRequired]
    public function setCurrency(string $currency = 'GBP'): DataResponse
    {
        $c = strtoupper($currency);
        if (!in_array($c, MarketValueService::SUPPORTED_CURRENCIES, true)) {
            return new DataResponse(['error' => 'Invalid currency'], Http::STATUS_BAD_REQUEST);
        }
        $this->config->setUserValue($this->userId(), 'crate', 'market_currency', $c);
        return new DataResponse(['marketCurrency' => $c]);
    }

    /**
     * GET /api/v1/settings/currencies
     * Single canonical list of currencies the backend will actually fetch market
     * values for. Consumed by the settings UI so the dropdown can't drift.
     */
    #[NoAdminRequired]
    public function getSupportedCurrencies(): DataResponse
    {
        return new DataResponse(MarketValueService::SUPPORTED_CURRENCIES);
    }

    /**
     * GET /api/v1/me
     * Returns the current user's profile and app settings — used by the Android app.
     */
    #[NoAdminRequired]
    public function me(): DataResponse
    {
        // userId() already handles the null check
        $uid      = $this->userId();
        $user     = $this->userSession->getUser();
        $currency = $this->config->getUserValue($uid, 'crate', 'market_currency', 'GBP');
        $hasToken = (string) ($this->credentialsManager->retrieve($uid, 'crate/discogs_token') ?? '') !== '';
        $autoFetch = $this->config->getUserValue($uid, 'crate', 'auto_fetch_market_rates', '0') === '1';

        $autoEnrichClick = $this->config->getUserValue($uid, 'crate', 'auto_enrich_click', '1') === '1';
        $autoEnrichImport = $this->config->getUserValue($uid, 'crate', 'auto_enrich_import', '1') === '1';

        return new DataResponse([
            'userId'              => $uid,
            'displayName'         => $user->getDisplayName(),
            'avatarUrl'           => '/index.php/avatar/' . urlencode($uid) . '/64',
            'hasDiscogsToken'     => $hasToken,
            'marketCurrency'      => $currency,
            'autoFetchMarketRates' => $autoFetch,
            'autoEnrichOnClick'    => $autoEnrichClick,
            'autoEnrichOnImport'   => $autoEnrichImport,
            'hiddenCategories'     => $this->getHiddenCategories($uid),
            'crateVersion'         => $this->config->getAppValue('crate', 'installed_version', '0.0.0'),
        ]);
    }

    /**
     * PUT /api/v1/settings/hidden-categories
     * Update the per-user list of categories to hide from navigation, the
     * home feed, and search. Must be a subset of the five known categories,
     * and must leave at least one visible.
     *
     * @param string[] $categories
     */
    #[NoAdminRequired]
    public function setHiddenCategories(array $categories = []): DataResponse
    {
        // Reject unknown / non-string entries explicitly rather than silently
        // dropping them — otherwise a client with a stale category list gets
        // a 200 OK while its intent was discarded.
        $rejected = [];
        $clean    = [];
        foreach ($categories as $entry) {
            if (!is_string($entry) || !in_array($entry, \OCA\Crate\CrateCategories::ALL, true)) {
                $rejected[] = $entry;
                continue;
            }
            if (!in_array($entry, $clean, true)) {
                $clean[] = $entry;
            }
        }

        if (!empty($rejected)) {
            return new DataResponse(
                ['error' => 'Unknown categories in request.', 'rejected' => array_values($rejected)],
                Http::STATUS_BAD_REQUEST,
            );
        }

        // Always keep at least one visible — count(ALL) - count(hidden) >= 1.
        if (count($clean) >= count(\OCA\Crate\CrateCategories::ALL)) {
            return new DataResponse(
                ['error' => 'At least one category must remain visible.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $this->config->setUserValue(
            $this->userId(),
            'crate',
            'hidden_categories',
            json_encode($clean, JSON_THROW_ON_ERROR),
        );

        return new DataResponse(['hiddenCategories' => $clean]);
    }

    /**
     * Resolve the user's hidden_categories setting back to a clean list of
     * known category keys. Tolerates stale entries from older clients.
     *
     * @return string[]
     */
    private function getHiddenCategories(string $uid): array
    {
        $raw = $this->config->getUserValue($uid, 'crate', 'hidden_categories', '[]');
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            $decoded,
            static fn($c) => is_string($c) && in_array($c, \OCA\Crate\CrateCategories::ALL, true),
        )));
    }
}
