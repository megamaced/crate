<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\DiscogsService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class DiscogsController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DiscogsService $discogsService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function search(string $q = '', string $barcode = ''): DataResponse
    {
        if ($barcode !== '') {
            $results = $this->discogsService->searchByBarcode($this->userId(), $barcode);
        } elseif ($q !== '') {
            $results = $this->discogsService->search($this->userId(), $q);
        } else {
            return new DataResponse([]);
        }

        return new DataResponse($results);
    }

    /**
     * Dedicated barcode lookup — wraps search but always uses barcode mode.
     * GET /api/v1/discogs/barcode/{barcode}
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function barcodeSearch(string $barcode): DataResponse
    {
        $results = $this->discogsService->searchByBarcode($this->userId(), $barcode);
        return new DataResponse($results);
    }
}
