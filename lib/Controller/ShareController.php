<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class ShareController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ShareService $shareService,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly MediaItemMapper $mediaItemMapper,
        private readonly PlaylistMapper $playlistMapper,
    ) {
        parent::__construct($appName, $request);
    }

    // ── User search ────────────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function searchUsers(string $q = ''): DataResponse
    {
        if (strlen(trim($q)) < 2) {
            return new DataResponse([]);
        }
        $me = $this->userId();
        $users = $this->userManager->search(trim($q));
        $result = [];
        foreach ($users as $user) {
            if ($user->getUID() === $me) {
                continue; // don't show yourself
            }
            $result[] = [
                'uid'         => $user->getUID(),
                'displayName' => $user->getDisplayName(),
            ];
            if (count($result) >= 25) {
                break;
            }
        }
        return new DataResponse($result);
    }

    // ── Share album ────────────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function shareAlbum(int $id, string $userId): DataResponse
    {
        try {
            return new DataResponse($this->shareService->shareAlbum($this->userId(), $id, $userId));
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Album not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }

    #[NoAdminRequired]
    public function sharesForAlbum(int $id): DataResponse
    {
        try {
            $this->mediaItemMapper->findByUser($id, $this->userId());
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Album not found'], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse($this->shareService->getSharesForAlbum($this->userId(), $id));
    }

    // ── Share playlist ─────────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function sharePlaylist(int $id, string $userId): DataResponse
    {
        try {
            return new DataResponse($this->shareService->sharePlaylist($this->userId(), $id, $userId));
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Playlist not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }

    #[NoAdminRequired]
    public function sharesForPlaylist(int $id): DataResponse
    {
        try {
            $this->playlistMapper->findByUser($id, $this->userId());
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Playlist not found'], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse($this->shareService->getSharesForPlaylist($this->userId(), $id));
    }

    // ── Share whole library ────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function shareLibrary(string $userId): DataResponse
    {
        try {
            return new DataResponse($this->shareService->shareLibrary($this->userId(), $userId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }

    #[NoAdminRequired]
    public function sharesForLibrary(): DataResponse
    {
        return new DataResponse($this->shareService->getSharesForLibrary($this->userId()));
    }

    // ── Share single category ──────────────────────────────────────────────────

    #[NoAdminRequired]
    public function shareCategory(string $category, string $userId): DataResponse
    {
        try {
            return new DataResponse($this->shareService->shareCategory($this->userId(), $category, $userId));
        } catch (\InvalidArgumentException $e) {
            $status = $e->getMessage() === 'Unknown category.'
                ? Http::STATUS_BAD_REQUEST
                : Http::STATUS_CONFLICT;
            return new DataResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function sharesForCategory(string $category): DataResponse
    {
        try {
            return new DataResponse($this->shareService->getSharesForCategory($this->userId(), $category));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    // ── Shared with me ─────────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function sharedWithMe(): DataResponse
    {
        return new DataResponse($this->shareService->getSharedWithMe($this->userId()));
    }

    // ── Remove share ───────────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function unshare(int $id): DataResponse
    {
        try {
            $this->shareService->unshare($id, $this->userId());
            return new DataResponse([]);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Share not found'], Http::STATUS_NOT_FOUND);
        }
    }
}
