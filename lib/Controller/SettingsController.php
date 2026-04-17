<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IConfig $config,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    private function userId(): string
    {
        return $this->userSession->getUser()->getUID();
    }

    #[NoAdminRequired]
    public function getDiscogsToken(): DataResponse
    {
        $token = $this->config->getUserValue($this->userId(), 'crate', 'discogs_token', '');
        return new DataResponse(['hasToken' => $token !== '']);
    }

    #[NoAdminRequired]
    public function setDiscogsToken(string $token = ''): DataResponse
    {
        $this->config->setUserValue($this->userId(), 'crate', 'discogs_token', trim($token));
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function getMarketSettings(): DataResponse
    {
        $uid = $this->userId();
        $autoFetch = $this->config->getUserValue($uid, 'crate', 'auto_fetch_market_rates', '0') === '1';
        return new DataResponse([
            'autoFetchMarketRates' => $autoFetch,
            'marketCurrency'       => $this->config->getUserValue($uid, 'crate', 'market_currency', 'GBP'),
        ]);
    }

    #[NoAdminRequired]
    public function setMarketSettings(bool $autoFetchMarketRates = false, string $marketCurrency = 'GBP'): DataResponse
    {
        $uid = $this->userId();
        $this->config->setUserValue($uid, 'crate', 'auto_fetch_market_rates', $autoFetchMarketRates ? '1' : '0');
        $this->config->setUserValue($uid, 'crate', 'market_currency', strtoupper($marketCurrency));
        return new DataResponse([]);
    }

    /**
     * PUT /api/v1/settings/currency
     * Update just the market currency preference — convenience endpoint for Android app.
     */
    #[NoAdminRequired]
    public function setCurrency(string $currency = 'GBP'): DataResponse
    {
        $this->config->setUserValue($this->userId(), 'crate', 'market_currency', strtoupper($currency));
        return new DataResponse(['marketCurrency' => strtoupper($currency)]);
    }

    /**
     * GET /api/v1/me
     * Returns the current user's profile and app settings — used by the Android app.
     */
    #[NoAdminRequired]
    public function me(): DataResponse
    {
        $user     = $this->userSession->getUser();
        $uid      = $user->getUID();
        $currency = $this->config->getUserValue($uid, 'crate', 'market_currency', 'GBP');
        $hasToken = $this->config->getUserValue($uid, 'crate', 'discogs_token', '') !== '';
        $autoFetch = $this->config->getUserValue($uid, 'crate', 'auto_fetch_market_rates', '0') === '1';

        return new DataResponse([
            'userId'              => $uid,
            'displayName'         => $user->getDisplayName(),
            'avatarUrl'           => '/index.php/avatar/' . urlencode($uid) . '/64',
            'hasDiscogsToken'     => $hasToken,
            'marketCurrency'      => $currency,
            'autoFetchMarketRates' => $autoFetch,
        ]);
    }
}
