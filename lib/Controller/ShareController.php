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
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\OCSController;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IShare;

class ShareController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ShareService $shareService,
        private readonly IUserSession $userSession,
        private readonly ISearch $collaboratorSearch,
        private readonly MediaItemMapper $mediaItemMapper,
        private readonly PlaylistMapper $playlistMapper,
    ) {
        parent::__construct($appName, $request);
    }

    // ── User search ────────────────────────────────────────────────────────────

    /**
     * Autocomplete for the share dialog.
     *
     * Delegates to the collaborator search rather than querying the user
     * manager directly, because that is what applies the instance's sharing
     * privacy settings: whether autocomplete is offered at all, whether it is
     * limited to the caller's own groups, and whether only an exact match may
     * be returned. Querying accounts directly answers regardless of all three,
     * turning the dialog into a directory dump. Rate-limited for the same
     * reason: two-character queries otherwise enumerate the whole instance.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function searchUsers(string $q = ''): DataResponse
    {
        $term = trim($q);
        if (strlen($term) < 2) {
            return new DataResponse([]);
        }
        $me = $this->userId();

        $found = $this->collaboratorSearch->search($term, [IShare::TYPE_USER], false, 25, 0);
        $matches = is_array($found[0] ?? null) ? $found[0] : [];

        $result = [];
        $seen   = [];
        $candidates = array_merge(
            (array)($matches['exact']['users'] ?? []),
            (array)($matches['users'] ?? []),
        );
        foreach ($candidates as $candidate) {
            $uid = (string)($candidate['value']['shareWith'] ?? '');
            if ($uid === '' || $uid === $me || isset($seen[$uid])) {
                continue; // don't show yourself, or the same account twice
            }
            $seen[$uid] = true;
            $result[] = [
                'uid'         => $uid,
                'displayName' => (string)($candidate['label'] ?? $uid),
            ];
            if (count($result) >= 25) {
                break;
            }
        }
        return new DataResponse($result);
    }

    // ── Share album ────────────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function shareAlbum(int $id, string $userId, string $permission = 'read'): DataResponse
    {
        try {
            return new DataResponse($this->shareService->shareAlbum($this->userId(), $id, $userId, $permission));
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Album not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            $status = $e->getMessage() === 'Unknown permission.'
                ? Http::STATUS_BAD_REQUEST
                : Http::STATUS_CONFLICT;
            return new DataResponse(['error' => $e->getMessage()], $status);
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
    public function sharePlaylist(int $id, string $userId, string $permission = 'read'): DataResponse
    {
        try {
            return new DataResponse($this->shareService->sharePlaylist($this->userId(), $id, $userId, $permission));
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'Playlist not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            $status = $e->getMessage() === 'Unknown permission.'
                ? Http::STATUS_BAD_REQUEST
                : Http::STATUS_CONFLICT;
            return new DataResponse(['error' => $e->getMessage()], $status);
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
    public function shareLibrary(string $userId, string $permission = 'read'): DataResponse
    {
        try {
            return new DataResponse($this->shareService->shareLibrary($this->userId(), $userId, $permission));
        } catch (\InvalidArgumentException $e) {
            $status = $e->getMessage() === 'Unknown permission.'
                ? Http::STATUS_BAD_REQUEST
                : Http::STATUS_CONFLICT;
            return new DataResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[NoAdminRequired]
    public function sharesForLibrary(): DataResponse
    {
        return new DataResponse($this->shareService->getSharesForLibrary($this->userId()));
    }

    // ── Share single category ──────────────────────────────────────────────────

    #[NoAdminRequired]
    public function shareCategory(string $category, string $userId, string $permission = 'read'): DataResponse
    {
        try {
            return new DataResponse(
                $this->shareService->shareCategory($this->userId(), $category, $userId, $permission),
            );
        } catch (\InvalidArgumentException $e) {
            $status = in_array($e->getMessage(), ['Unknown category.', 'Unknown permission.'], true)
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

    // ── Shared by me ───────────────────────────────────────────────────────────

    #[NoAdminRequired]
    public function sharedByMe(): DataResponse
    {
        return new DataResponse($this->shareService->getSharedByMe($this->userId()));
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
