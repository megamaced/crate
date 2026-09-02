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

    /**
     * Column width of `crate_playlists.name`, in characters. A longer name is
     * a driver exception on PostgreSQL and strict MySQL and a silent
     * truncation elsewhere, so it is answered here instead.
     */
    private const MAX_NAME_LEN = 500;

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
            $this->playlistService->findAll($this->userId(), $containsItemId),
        );
    }

    /**
     * GET /api/v1/playlists/{id}
     *
     * Resolves ownership *or* an active share: this is the endpoint a client
     * lands on when a shared playlist is opened by URL — a refresh, a
     * bookmark, browser back — and answering 404 there sent the sharee home
     * from a playlist the shared-with-me list had just shown them.
     */
    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        try {
            return new DataResponse($this->playlistService->findForViewer($id, $this->userId()));
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[NoAdminRequired]
    public function create(string $name, ?string $description = null): DataResponse
    {
        $error = self::validateName($name);
        if ($error !== null) {
            return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }
        return new DataResponse($this->playlistService->create($this->userId(), trim($name), $description));
    }

    /**
     * PUT /api/v1/playlists/{id}
     *
     * `description` defaults to PlaylistService::DESCRIPTION_UNCHANGED rather
     * than null, so a body that omits it renames the playlist and leaves the
     * stored description alone. Sending '' still clears it.
     */
    #[NoAdminRequired]
    public function update(
        int $id,
        string $name,
        ?string $description = PlaylistService::DESCRIPTION_UNCHANGED,
    ): DataResponse {
        $error = self::validateName($name);
        if ($error !== null) {
            return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
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

    /**
     * Reject a playlist name the column cannot hold, or a blank one. Returns
     * the error message, or null when the name is usable.
     */
    private static function validateName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Name is required';
        }
        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME_LEN) {
            return 'Name exceeds ' . self::MAX_NAME_LEN . ' characters';
        }
        return null;
    }
}
