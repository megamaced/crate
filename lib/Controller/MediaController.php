<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\MediaService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class MediaController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaService $mediaService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    private function userId(): string
    {
        return $this->userSession->getUser()->getUID();
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        return new DataResponse($this->mediaService->findAll($this->userId()));
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        return new DataResponse($this->mediaService->find($id, $this->userId()));
    }

    #[NoAdminRequired]
    public function create(
        string $title,
        string $artist,
        string $format,
        ?int $year = null,
        ?string $barcode = null,
        ?string $notes = null,
        string $status = 'owned',
        ?string $discogsId = null,
        ?string $artworkPath = null,
    ): DataResponse {
        return new DataResponse(
            $this->mediaService->create(
                $this->userId(),
                $title,
                $artist,
                $format,
                $year,
                $barcode,
                $notes,
                $status,
                $discogsId,
                $artworkPath,
            )
        );
    }

    #[NoAdminRequired]
    public function update(
        int $id,
        string $title,
        string $artist,
        string $format,
        ?int $year = null,
        ?string $barcode = null,
        ?string $notes = null,
        string $status = 'owned',
        ?string $discogsId = null,
        ?string $artworkPath = null,
    ): DataResponse {
        return new DataResponse(
            $this->mediaService->update(
                $id,
                $this->userId(),
                $title,
                $artist,
                $format,
                $year,
                $barcode,
                $notes,
                $status,
                $discogsId,
                $artworkPath,
            )
        );
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse
    {
        $this->mediaService->delete($id, $this->userId());
        return new DataResponse([]);
    }
}
