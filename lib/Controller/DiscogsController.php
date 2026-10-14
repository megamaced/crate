<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\DiscogsService;
use OCP\AppFramework\Http;
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

    /**
     * Import the authenticated user's Discogs collection into Crate.
     * POST /api/v1/discogs/import
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 5, period: 60)]
    public function import(): DataResponse
    {
        $importWantlist = (bool) $this->request->getParam('importWantlist', false);
        try {
            $result = $this->discogsService->importCollection($this->userId(), $importWantlist);
            return new DataResponse($result);
        } catch (\OCA\Crate\Exception\DiscogsRateLimitException $e) {
            return new DataResponse(
                ['error' => 'Discogs rate limit exceeded. Please wait a moment and try again.'],
                Http::STATUS_TOO_MANY_REQUESTS,
            );
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Failed to import Discogs collection: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
