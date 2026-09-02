<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Db\MediaItemMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * User-supplied photo slots per media item. Distinct from the existing
 * artwork (which holds the cover art and may be fetched remotely from an
 * enrichment source). Photos are **always** user-uploaded — they never
 * come from a remote URL — so there is no SSRF surface here.
 *
 * Each item gets exactly two slots (1 and 2). Files are stored under
 * appdata `photos/photo_{itemId}_{slot}.{ext}` and surfaced via the same
 * thumbnail pipeline used by ArtworkController.
 */
class PhotoController extends Controller
{
    use GdImageTrait;

    /** Slot values accepted on every endpoint. */
    private const SLOTS = [1, 2];

    /** File extensions considered when reading/clearing a slot. */
    private const PHOTO_EXTENSIONS = ['.jpg', '.png', '.webp', '.gif'];

    /** Mime types accepted on upload. */
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    private const EXT_TO_MIME = [
        '.png'  => 'image/png',
        '.webp' => 'image/webp',
        '.gif'  => 'image/gif',
        '.jpg'  => 'image/jpeg',
    ];

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaItemMapper $mapper,
        private readonly IUserSession $userSession,
        private readonly IAppDataFactory $appDataFactory,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/crate/photo/{itemId}/{slot}?size=thumb|full
     *
     * NoCSRFRequired so the browser can render `<img src="...">` and
     * `background-image: url(...)` without a CSRF token, matching the
     * artwork endpoint. See Build Log entry 12.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 1200, period: 60)]
    public function get(int $itemId, int $slot, string $size = 'full'): Response
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new Response(Http::STATUS_FORBIDDEN);
        }
        if (!in_array($slot, self::SLOTS, true)) {
            return new Response(Http::STATUS_BAD_REQUEST);
        }

        // Read-path: owner OR sharee (via per-album / library / category share)
        try {
            $item = $this->mapper->findVisibleForUser($itemId, $user->getUID());
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        $path = $slot === 1 ? $item->getPhoto1Path() : $item->getPhoto2Path();
        if ($path !== 'local') {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        try {
            $folder = $this->appDataFactory->get('crate')->getFolder('photos');
        } catch (NotFoundException) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        foreach (self::PHOTO_EXTENSIONS as $ext) {
            try {
                $file = $folder->getFile($this->fileName($itemId, $slot, $ext));
                $mime = self::EXT_TO_MIME[$ext];
                if ($size === 'thumb') {
                    return $this->thumbResponse((string) $file->getContent(), $mime, 3600);
                }
                $response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
                $response->cacheFor(3600);
                return $this->hardenImageResponse($response);
            } catch (NotFoundException) {
            }
        }

        return new Response(Http::STATUS_NOT_FOUND);
    }

    /**
     * POST /apps/crate/photo/{itemId}/{slot} (multipart `file`)
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function upload(int $itemId, int $slot): Response
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
        }
        if (!in_array($slot, self::SLOTS, true)) {
            return new DataResponse(['error' => 'Invalid slot'], Http::STATUS_BAD_REQUEST);
        }
        $userId = $user->getUID();

        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $uploadedFile = $this->request->getUploadedFile('file');
        if (!$uploadedFile || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
        }

        // 10 MB cap mirrors ArtworkController + nginx upload limits.
        if (($uploadedFile['size'] ?? 0) > 10 * 1024 * 1024) {
            return new DataResponse(['error' => 'File too large (max 10 MB)'], 413);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($uploadedFile['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return new DataResponse(
                ['error' => 'Unsupported file type'],
                Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $ext = match ($mime) {
            'image/png'  => '.png',
            'image/webp' => '.webp',
            'image/gif'  => '.gif',
            default      => '.jpg',
        };

        // Read and re-encode the bytes before the transaction opens. The pixel
        // budget has to be settled out here: a decode that exhausts
        // memory_limit is a fatal error, and inside the transaction below that
        // would leave neither a commit nor a rollback.
        $bytes = (string) file_get_contents($uploadedFile['tmp_name']);
        if (!$this->gdDimensionsWithinBudget($bytes)) {
            return new DataResponse(['error' => 'Image dimensions too large'], 413);
        }
        // Strip EXIF/IPTC/XMP before persisting. Photos are the "receipts
        // and personal photos" slot — phone-gallery uploads commonly
        // carry GPS, timestamps, camera serials. See GdImageTrait. A file that
        // cannot be sanitised is refused rather than stored intact: this slot
        // is served on to sharees, so its metadata travels with it.
        $sanitised = $this->stripImageMetadata($bytes, (string) $mime);
        if ($sanitised === null) {
            return new DataResponse(
                ['error' => 'This image could not be processed. Re-save it as JPEG or PNG and try again.'],
                Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
            );
        }
        $bytes = $sanitised;

        $appData = $this->appDataFactory->get('crate');
        try {
            $folder = $appData->getFolder('photos');
        } catch (NotFoundException) {
            $folder = $appData->newFolder('photos');
        }

        $fileName = $this->fileName($itemId, $slot, $ext);

        // Same ordering as ArtworkController::upload, and for the same two
        // reasons: the row UPDATE runs first because that is what actually
        // takes the write lock (a plain SELECT serialises nothing under MVCC),
        // and no file is removed until the transaction has committed, so a
        // failed write rolls back with the previous photo still in place.
        $this->db->beginTransaction();
        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);

            if ($slot === 1) {
                $item->setPhoto1Path('local');
            } else {
                $item->setPhoto2Path('local');
            }
            $item->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($item);

            try {
                $file = $folder->getFile($fileName);
            } catch (NotFoundException) {
                $file = $folder->newFile($fileName);
            }
            $file->putContent($bytes);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Committed: drop whatever this slot held at another extension.
        $this->deleteSlotFiles($itemId, $slot, $fileName);

        return new DataResponse(['status' => 'ok', 'slot' => $slot]);
    }

    /**
     * DELETE /apps/crate/photo/{itemId}/{slot}
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function delete(int $itemId, int $slot): Response
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
        }
        if (!in_array($slot, self::SLOTS, true)) {
            return new DataResponse(['error' => 'Invalid slot'], Http::STATUS_BAD_REQUEST);
        }
        $userId = $user->getUID();

        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        // Commit the cleared marker first; unlink afterwards. A file deleted
        // inside a transaction that then rolls back is gone regardless, leaving
        // the row pointing at a photo that no longer exists.
        $this->db->beginTransaction();
        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);
            if ($slot === 1) {
                $item->setPhoto1Path(null);
            } else {
                $item->setPhoto2Path(null);
            }
            $item->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($item);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->deleteSlotFiles($itemId, $slot, null);

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * Remove the files held in one photo slot, at every extension except
     * $keepName. Best-effort and post-commit: a file left behind costs disk,
     * not correctness, because the read path only looks at a slot the row
     * still marks 'local'.
     */
    private function deleteSlotFiles(int $itemId, int $slot, ?string $keepName): void
    {
        try {
            $folder = $this->appDataFactory->get('crate')->getFolder('photos');
        } catch (NotFoundException) {
            return;
        }
        foreach (self::PHOTO_EXTENSIONS as $ext) {
            $name = $this->fileName($itemId, $slot, $ext);
            if ($name === $keepName) {
                continue;
            }
            try {
                $folder->getFile($name)->delete();
            } catch (NotFoundException) {
            } catch (\Throwable $e) {
                $this->logger->warning('Could not remove photo {name}: {msg}', [
                    'name' => $name,
                    'msg'  => $e->getMessage(),
                    'app'  => 'crate',
                ]);
            }
        }
    }

    private function fileName(int $itemId, int $slot, string $ext): string
    {
        return 'photo_' . $itemId . '_' . $slot . $ext;
    }
}
