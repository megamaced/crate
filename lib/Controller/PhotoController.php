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
    public function get(int $itemId, int $slot, string $size = 'full'): Response
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new Response(Http::STATUS_FORBIDDEN);
        }
        if (!in_array($slot, self::SLOTS, true)) {
            return new Response(Http::STATUS_BAD_REQUEST);
        }

        try {
            $item = $this->mapper->findByUser($itemId, $user->getUID());
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
                return $response;
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
            $item = $this->mapper->findByUser($itemId, $userId);
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

        $appData = $this->appDataFactory->get('crate');
        try {
            $folder = $appData->getFolder('photos');
        } catch (NotFoundException) {
            $folder = $appData->newFolder('photos');
        }

        // Hold the media_item row across the file ops so concurrent uploads
        // can't leave the DB pointing at a missing file. Matches the
        // ArtworkController upload pattern.
        $this->db->beginTransaction();
        try {
            $item = $this->mapper->findByUser($itemId, $userId);

            // Clear any prior file in this slot (any extension).
            foreach (self::PHOTO_EXTENSIONS as $oldExt) {
                try {
                    $folder->getFile($this->fileName($itemId, $slot, $oldExt))->delete();
                } catch (NotFoundException) {
                }
            }

            $bytes = (string) file_get_contents($uploadedFile['tmp_name']);
            // Strip EXIF/IPTC/XMP before persisting. Photos are the "receipts
            // and personal photos" slot — phone-gallery uploads commonly
            // carry GPS, timestamps, camera serials. See GdImageTrait.
            $bytes = $this->stripImageMetadata($bytes, (string) $mime);
            $file  = $folder->newFile($this->fileName($itemId, $slot, $ext));
            $file->putContent($bytes);

            if ($slot === 1) {
                $item->setPhoto1Path('local');
            } else {
                $item->setPhoto2Path('local');
            }
            $item->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($item);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

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
            $item = $this->mapper->findByUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $this->db->beginTransaction();
        try {
            $item = $this->mapper->findByUser($itemId, $userId);
            try {
                $folder = $this->appDataFactory->get('crate')->getFolder('photos');
                foreach (self::PHOTO_EXTENSIONS as $ext) {
                    try {
                        $folder->getFile($this->fileName($itemId, $slot, $ext))->delete();
                    } catch (NotFoundException) {
                    }
                }
            } catch (NotFoundException) {
            }

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

        return new DataResponse(['status' => 'ok']);
    }

    private function fileName(int $itemId, int $slot, string $ext): string
    {
        return 'photo_' . $itemId . '_' . $slot . $ext;
    }
}
