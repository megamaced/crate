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
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ArtworkController extends Controller
{
    use GdImageTrait;

    /** Hosts permitted for remote-artwork fetch. Matches the enrichment sources. */
    private const REMOTE_IMAGE_HOSTS = [
        // Discogs
        'i.discogs.com', 'img.discogs.com', 'st.discogs.com',
        // TMDB
        'image.tmdb.org',
        // RAWG
        'media.rawg.io',
        // ComicVine
        'comicvine.gamespot.com', 'static.comicvine.com',
        // Open Library
        'covers.openlibrary.org',
    ];

    /** Content-Type values accepted for remote artwork and uploads. */
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    /** File extensions considered when locating / clearing cached artwork. */
    private const ARTWORK_EXTENSIONS = ['.jpg', '.png', '.webp', '.gif'];

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaItemMapper $mapper,
        private readonly IUserSession $userSession,
        private readonly IAppDataFactory $appDataFactory,
        private readonly IClientService $clientService,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(int $itemId, string $size = 'full'): Response
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new Response(Http::STATUS_FORBIDDEN);
        }
        $userId = $user->getUID();

        // Read-path: owner OR sharee (via per-album / library / category share)
        try {
            $item = $this->mapper->findVisibleForUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        $artworkPath = $item->getArtworkPath();
        if (!$artworkPath) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        $appData = $this->appDataFactory->get('crate');

        // ── Local / user-uploaded artwork ─────────────────────────────────────
        if ($artworkPath === 'local') {
            try {
                $folder = $appData->getFolder('artwork');
            } catch (NotFoundException) {
                return new Response(Http::STATUS_NOT_FOUND);
            }
            foreach (self::ARTWORK_EXTENSIONS as $ext) {
                try {
                    $file = $folder->getFile('artwork_' . $itemId . $ext);
                    $mime = match ($ext) {
                        '.png'  => 'image/png',
                        '.webp' => 'image/webp',
                        '.gif'  => 'image/gif',
                        default => 'image/jpeg',
                    };
                    if ($size === 'thumb') {
                        return $this->thumbResponse((string) $file->getContent(), $mime, 86400);
                    }
                    $response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
                    $response->cacheFor(3600);
                    return $response;
                } catch (NotFoundException) {
                }
            }
            return new Response(Http::STATUS_NOT_FOUND);
        }

        // ── Discogs / remote URL ──────────────────────────────────────────────
        if (!str_starts_with($artworkPath, 'http')) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        // SSRF mitigation: only allow image hosts we actually enrich from.
        $host = parse_url($artworkPath, PHP_URL_HOST) ?? '';
        if (!in_array($host, self::REMOTE_IMAGE_HOSTS, true)) {
            return new Response(Http::STATUS_FORBIDDEN);
        }

        $cacheFile = 'artwork_' . $itemId . $this->extension($artworkPath);

        try {
            $folder = $appData->getFolder('artwork');
        } catch (NotFoundException) {
            $folder = $appData->newFolder('artwork');
        }

        try {
            $file = $folder->getFile($cacheFile);
        } catch (NotFoundException) {
            try {
                $client = $this->clientService->newClient();
                // Follow redirects manually so every hop's host is re-checked
                // against the allowlist — not just the initial URL. A 302 from
                // an allowlisted CDN to an off-allowlist (or non-https) target
                // is rejected. NC's client additionally blocks private IPs.
                $url = $artworkPath;
                $download = null;
                for ($hop = 0; $hop <= 3; $hop++) {
                    $download = $client->get($url, [
                        'headers' => ['User-Agent' => 'CrateNextcloudApp/0.4'],
                        'timeout' => 10,
                        'allow_redirects' => false,
                    ]);
                    $status = $download->getStatusCode();
                    if ($status < 300 || $status >= 400) {
                        break;
                    }
                    $location = trim($download->getHeader('Location'));
                    if ($location === '') {
                        break;
                    }
                    $next      = $this->resolveRedirect($url, $location);
                    $nextHost  = parse_url($next, PHP_URL_HOST) ?? '';
                    $nextSchme = parse_url($next, PHP_URL_SCHEME);
                    if ($nextSchme !== 'https' || !in_array($nextHost, self::REMOTE_IMAGE_HOSTS, true)) {
                        return new Response(Http::STATUS_FORBIDDEN);
                    }
                    $url = $next;
                    if ($hop === 3) {
                        // Too many redirects.
                        return new Response(Http::STATUS_BAD_GATEWAY);
                    }
                }
                if ($download === null) {
                    return new Response(Http::STATUS_BAD_GATEWAY);
                }
                // Reject non-image responses to prevent cache-poisoning via
                // compromised upstream or MITM returning HTML / scripts.
                $contentType = strtolower(trim(
                    (string) ($download->getHeader('Content-Type') ?: '')
                ));
                $contentType = explode(';', $contentType, 2)[0];
                if (!in_array($contentType, self::ALLOWED_IMAGE_MIMES, true)) {
                    return new Response(Http::STATUS_BAD_GATEWAY);
                }
                $imageData = $download->getBody();
                // Cap remote artwork size to 10 MB.
                if (is_string($imageData) && strlen($imageData) > 10 * 1024 * 1024) {
                    return new Response(Http::STATUS_BAD_GATEWAY);
                }
            } catch (\Exception) {
                return new Response(Http::STATUS_BAD_GATEWAY);
            }
            // Remote source could be a user upload (e.g. Discogs community
            // pressing images) — strip EXIF on write for defence-in-depth.
            $imageData = $this->stripImageMetadata((string) $imageData, $contentType);
            $file = $folder->newFile($cacheFile);
            $file->putContent($imageData);
        }

        $mime = 'image/jpeg';
        foreach (self::EXT_TO_MIME as $ext => $m) {
            if (str_ends_with($cacheFile, $ext)) {
                $mime = $m;
                break;
            }
        }
        if ($size === 'thumb') {
            return $this->thumbResponse((string) $file->getContent(), $mime, 86400);
        }
        $response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
        $response->cacheFor(86400);
        return $response;
    }

    /**
     * Resize image bytes to a 200×200-bounded thumbnail using GD.
     * Falls back to the original data if GD is unavailable or the image
     * cannot be decoded. Errors are logged rather than swallowed by `@`.
     */

    /**
     * Upload a user-provided image as artwork for a media item.
     * POST /artwork/{itemId}
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function upload(int $itemId): Response
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
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

        // Cap artwork upload at 10 MB. Defence-in-depth alongside the
        // per-user rate limit and PHP's upload_max_filesize.
        if (($uploadedFile['size'] ?? 0) > 10 * 1024 * 1024) {
            return new DataResponse(['error' => 'File too large (max 10 MB)'], 413);
        }

        // Detect and validate MIME type from file content
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($uploadedFile['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowedMimes, true)) {
            return new DataResponse(['error' => 'Unsupported file type'], Http::STATUS_UNSUPPORTED_MEDIA_TYPE);
        }

        $ext = match ($mime) {
            'image/png'  => '.png',
            'image/webp' => '.webp',
            'image/gif'  => '.gif',
            default      => '.jpg',
        };

        $appData = $this->appDataFactory->get('crate');
        try {
            $folder = $appData->getFolder('artwork');
        } catch (NotFoundException) {
            $folder = $appData->newFolder('artwork');
        }

        // Serialise concurrent uploads/deletes for the same item via a DB
        // transaction holding the media_item row. Combined with the file ops
        // below, this prevents races where two uploads leave the row pointing
        // at a non-existent file.
        $this->db->beginTransaction();
        try {
            // Re-read inside the transaction
            $item = $this->mapper->findWritableForUser($itemId, $userId);

            foreach (self::ARTWORK_EXTENSIONS as $oldExt) {
                try {
                    $folder->getFile('artwork_' . $itemId . $oldExt)->delete();
                } catch (NotFoundException) {
                }
            }

            $bytes = (string) file_get_contents($uploadedFile['tmp_name']);
            // Strip EXIF/IPTC/XMP before persisting — phone-gallery uploads
            // commonly carry GPS, timestamps, camera serials. See GdImageTrait.
            $bytes = $this->stripImageMetadata($bytes, $mime);
            $file  = $folder->newFile('artwork_' . $itemId . $ext);
            $file->putContent($bytes);

            $item->setArtworkPath('local');
            $item->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($item);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return new DataResponse(['status' => 'ok', 'artworkPath' => 'local']);
    }

    /**
     * Remove artwork from a media item.
     * DELETE /artwork/{itemId}
     */
    #[NoAdminRequired]
    public function delete(int $itemId): Response
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
        }
        $userId = $user->getUID();

        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $this->db->beginTransaction();
        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);
            try {
                $folder = $this->appDataFactory->get('crate')->getFolder('artwork');
                foreach (self::ARTWORK_EXTENSIONS as $ext) {
                    try {
                        $folder->getFile('artwork_' . $itemId . $ext)->delete();
                    } catch (NotFoundException) {
                    }
                }
            } catch (NotFoundException) {
            }

            $item->setArtworkPath(null);
            $item->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($item);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * Resolve a redirect Location (which may be absolute, protocol-relative,
     * or host-relative) against the URL that issued it, so the caller can
     * validate the resulting host. Host-relative targets stay on the current
     * (already-allowlisted) host.
     */
    private function resolveRedirect(string $base, string $location): string
    {
        // Absolute URL with its own scheme.
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $scheme = parse_url($base, PHP_URL_SCHEME) ?? 'https';
        $host   = parse_url($base, PHP_URL_HOST) ?? '';
        // Protocol-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        // Host-relative: /path
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $location;
        }
        // Path-relative: resolve against the base directory.
        $basePath = parse_url($base, PHP_URL_PATH) ?? '/';
        $dir      = substr($basePath, 0, strrpos($basePath, '/') + 1) ?: '/';
        return $scheme . '://' . $host . $dir . $location;
    }

    private function extension(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        foreach (['.png', '.webp', '.gif', '.jpg', '.jpeg'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return $ext === '.jpeg' ? '.jpg' : $ext;
            }
        }
        return '.jpg';
    }

    private const EXT_TO_MIME = [
        '.png'  => 'image/png',
        '.webp' => 'image/webp',
        '.gif'  => 'image/gif',
        '.jpg'  => 'image/jpeg',
    ];
}
