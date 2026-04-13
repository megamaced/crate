<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\DiscogsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class DiscogsController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DiscogsService $discogsService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    private function userId(): string
    {
        return $this->userSession->getUser()->getUID();
    }

    #[NoAdminRequired]
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
     * Fetch full release details from Discogs /releases/{id}.
     *
     * Used by the frontend when the user wants to enrich an item before saving,
     * or called server-side via media#enrich. Exposed here so the frontend can
     * also preview the data without yet persisting it.
     */
    #[NoAdminRequired]
    public function getRelease(string $id): DataResponse
    {
        $data = $this->discogsService->getRelease($this->userId(), $id);
        if (empty($data)) {
            return new DataResponse(
                ['error' => 'Release not found or no Discogs token configured'],
                Http::STATUS_NOT_FOUND,
            );
        }
        return new DataResponse($data);
    }

    /**
     * Fetch artist profile from Discogs /artists/{id}.
     */
    #[NoAdminRequired]
    public function getArtist(string $id): DataResponse
    {
        $data = $this->discogsService->getArtist($this->userId(), $id);
        if (empty($data)) {
            return new DataResponse(
                ['error' => 'Artist not found or no Discogs token configured'],
                Http::STATUS_NOT_FOUND,
            );
        }
        return new DataResponse($data);
    }
}
