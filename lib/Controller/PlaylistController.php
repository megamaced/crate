<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\PlaylistService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class PlaylistController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PlaylistService $playlistService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(?int $containsItemId = null): DataResponse
    {
        return new DataResponse(
            $this->playlistService->findAll($containsItemId),
        );
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        try {
            return new DataResponse($this->playlistService->find($id, $this->userId()));
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function create(string $name, ?string $description = null): DataResponse
    {
        if (trim($name) === '') {
            return new DataResponse(['error' => 'Name is required'], Http::STATUS_BAD_REQUEST);
        }
        return new DataResponse($this->playlistService->create($this->userId(), trim($name), $description));
    }

    #[NoAdminRequired]
    public function update(int $id, string $name, ?string $description = null): DataResponse
    {
        if (trim($name) === '') {
            return new DataResponse(['error' => 'Name is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse($this->playlistService->update($id, $this->userId(), trim($name), $description));
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse
    {
        try {
            $this->playlistService->delete($id, $this->userId());
            return new DataResponse([]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function addItem(int $id, int $mediaItemId): DataResponse
    {
        try {
            return new DataResponse($this->playlistService->addItem($id, $this->userId(), $mediaItemId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Playlist or item not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function removeItem(int $id, int $mediaItemId): DataResponse
    {
        try {
            return new DataResponse($this->playlistService->removeItem($id, $this->userId(), $mediaItemId));
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Playlist not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
