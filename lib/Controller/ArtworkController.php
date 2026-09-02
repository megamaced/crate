<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\CrateArtworkFiles;
use OCA\Crate\CrateImageHosts;
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
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ArtworkController extends Controller
{
    use GdImageTrait;

    /** Content-Type values accepted for remote artwork and uploads. */
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    /** File extensions considered when locating a user-uploaded cover. */
    private const ARTWORK_EXTENSIONS = CrateArtworkFiles::EXTENSIONS;

    /** Byte cap for a remote artwork fetch and for an upload. */
    private const MAX_REMOTE_IMAGE_BYTES = 10 * 1024 * 1024;

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

    /**
     * A generous ceiling: one uncached grid render asks for one image per tile,
     * so the limit sits well above any real page load while still bounding
     * repeated `?size=thumb` requests, each of which is a fresh GD decode.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 1200, period: 60)]
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
                    $file = $folder->getFile(CrateArtworkFiles::uploadName($itemId, $ext));
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
                    return $this->hardenImageResponse($response);
                } catch (NotFoundException) {
                }
            }
            return new Response(Http::STATUS_NOT_FOUND);
        }

        // ── Discogs / remote URL ──────────────────────────────────────────────
        // https only, for the first hop as well as the redirects below: every
        // enrichment CDN serves TLS, and a cleartext fetch is a cache-poisoning
        // opportunity for anything on the path.
        if (parse_url($artworkPath, PHP_URL_SCHEME) !== 'https') {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        // SSRF mitigation: only allow image hosts we actually enrich from.
        $host = parse_url($artworkPath, PHP_URL_HOST) ?? '';
        if (!CrateImageHosts::isAllowed($host)) {
            return new Response(Http::STATUS_FORBIDDEN);
        }

        // Keyed on the source URL as well as the item, so an entry cached for
        // one cover can never be served in place of another. See
        // CrateArtworkFiles for why that is the difference between a correct
        // cover and someone else's.
        //
        // The extension is not part of the key: it is decided by the fetch
        // below, from the Content-Type this endpoint has already validated,
        // and not by the URL's suffix. A PNG served from a path ending `.jpg`
        // used to be stored and handed back as `image/jpeg` — a wrong header
        // on the full-size response and a wrong extension on save-as. So the
        // reader asks for the digest at each extension in turn.
        $cachePrefix = CrateArtworkFiles::cachePrefix($itemId, $artworkPath);

        try {
            $folder = $appData->getFolder('artwork');
        } catch (NotFoundException) {
            $folder = $appData->newFolder('artwork');
        }

        $file = null;
        $mime = 'image/jpeg';
        foreach (self::EXT_TO_MIME as $ext => $extMime) {
            try {
                $file = $folder->getFile($cachePrefix . $ext);
                $mime = $extMime;
                break;
            } catch (NotFoundException) {
            }
        }

        if ($file === null) {
            try {
                $client = $this->clientService->newClient();
                // Follow redirects manually so every hop's host is re-checked
                // against the allowlist — not just the initial URL. A 302 from
                // an allowlisted CDN to an off-allowlist (or non-https) target
                // is rejected; the CDNs' own redirect targets are named in
                // CrateImageHosts::REDIRECT_DOMAINS, which is what lets an Open
                // Library cover held in the Internet Archive through. NC's
                // client additionally blocks private IPs.
                $url = $artworkPath;
                $download = null;
                for ($hop = 0; $hop <= 3; $hop++) {
                    $download = $client->get($url, [
                        'headers' => ['User-Agent' => 'CrateNextcloudApp/0.4'],
                        'timeout' => 10,
                        'allow_redirects' => false,
                        // Streamed so the byte cap below can stop reading. A
                        // buffered getBody() materialises the whole response
                        // first, which is the allocation the cap exists to
                        // refuse.
                        'stream' => true,
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
                    if ($nextSchme !== 'https' || !CrateImageHosts::isAllowedRedirectTarget($nextHost)) {
                        return new Response(Http::STATUS_FORBIDDEN);
                    }
                    $url = $next;
                    if ($hop === 3) {
                        // Too many redirects.
                        return new Response(Http::STATUS_BAD_GATEWAY);
                    }
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
                // Cap remote artwork size to 10 MB, reading no more than that
                // off the wire.
                $imageData = $this->readCappedBody($download);
                if ($imageData === null) {
                    return new Response(Http::STATUS_BAD_GATEWAY);
                }
            } catch (\Exception) {
                return new Response(Http::STATUS_BAD_GATEWAY);
            }
            // Remote source could be a user upload (e.g. Discogs community
            // pressing images) — strip EXIF on write for defence-in-depth.
            // These bytes came from an allowlisted enrichment CDN rather than
            // someone's phone, so a cover GD cannot re-encode is cached as it
            // arrived; stripImageMetadata has logged why.
            $sanitised = $this->stripImageMetadata($imageData, $contentType);
            $mime = $contentType;
            $file = $folder->newFile($cachePrefix . self::MIME_TO_EXT[$contentType]);
            $file->putContent($sanitised ?? $imageData);
        }

        if ($size === 'thumb') {
            return $this->thumbResponse((string) $file->getContent(), $mime, 86400);
        }
        $response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
        $response->cacheFor(86400);
        return $this->hardenImageResponse($response);
    }

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
        if (($uploadedFile['size'] ?? 0) > self::MAX_REMOTE_IMAGE_BYTES) {
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

        // Read and re-encode the bytes before the transaction opens. The pixel
        // budget has to be settled out here: a decode that exhausts
        // memory_limit is a fatal error, and inside the transaction below that
        // would leave neither a commit nor a rollback.
        $bytes = (string) file_get_contents($uploadedFile['tmp_name']);
        if (!$this->gdDimensionsWithinBudget($bytes)) {
            return new DataResponse(['error' => 'Image dimensions too large'], 413);
        }
        // Strip EXIF/IPTC/XMP before persisting — phone-gallery uploads
        // commonly carry GPS, timestamps, camera serials. See GdImageTrait.
        // A file that cannot be sanitised is refused rather than stored with
        // its metadata intact: the upload is the one place where the bytes are
        // the user's own and the metadata is theirs to leak.
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
            $folder = $appData->getFolder('artwork');
        } catch (NotFoundException) {
            $folder = $appData->newFolder('artwork');
        }

        $uploadName = CrateArtworkFiles::uploadName($itemId, $ext);

        // Serialise concurrent uploads/deletes of the same item's artwork. The
        // row UPDATE runs first, before any file is touched: that is what takes
        // the write lock, and it is held until commit, so a second upload of
        // the same item blocks here rather than interleaving its file ops with
        // ours. A non-locking SELECT would not have done it — under MVCC two
        // readers see the row simultaneously and neither waits.
        //
        // Nothing is deleted inside the transaction. The replacement is written
        // over the name it will keep, so a putContent() that throws (quota,
        // disk, permissions) rolls the row back with the previous file still in
        // place, rather than leaving a committed 'local' pointing at nothing.
        $this->db->beginTransaction();
        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);

            $item->setArtworkPath('local');
            $item->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($item);

            try {
                $file = $folder->getFile($uploadName);
            } catch (NotFoundException) {
                $file = $folder->newFile($uploadName);
            }
            $file->putContent($bytes);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Committed. Everything else the item had can go now: an upload this
        // replaces at a different extension, and every cover cached from a
        // remote URL the item used to point at. Best-effort — a file left
        // behind costs disk, not correctness, because the read path resolves
        // the name it wants rather than whatever is lying around.
        try {
            CrateArtworkFiles::deleteOthers($folder, $itemId, $uploadName);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not purge superseded artwork for item {id}: {msg}', [
                'id'  => $itemId,
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
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

        // Commit the null marker first, then unlink. A file removed before the
        // commit is gone even when the transaction rolls back, which leaves the
        // row claiming artwork that no longer exists; the other way round the
        // worst case is an orphaned file the read path never looks at.
        $this->db->beginTransaction();
        try {
            $item = $this->mapper->findWritableForUser($itemId, $userId);
            $item->setArtworkPath(null);
            $item->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mapper->update($item);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        try {
            $folder = $this->appDataFactory->get('crate')->getFolder('artwork');
            CrateArtworkFiles::deleteAll($folder, $itemId);
        } catch (NotFoundException) {
        } catch (\Throwable $e) {
            $this->logger->warning('Could not remove artwork files for item {id}: {msg}', [
                'id'  => $itemId,
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
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

    /**
     * Read a streamed response body, refusing anything over
     * MAX_REMOTE_IMAGE_BYTES rather than allocating it first. Returns the
     * bytes, or null when the response is too large or does not match what it
     * declared.
     */
    private function readCappedBody(IResponse $download): ?string
    {
        $declared = (int) ($download->getHeader('Content-Length') ?: 0);
        if ($declared > self::MAX_REMOTE_IMAGE_BYTES) {
            return null;
        }

        $body = $download->getBody();
        if (!is_resource($body)) {
            // Either an empty response, or a client implementation that
            // buffered regardless. Nothing left to save in the second case,
            // but the cap still applies.
            $body = (string) $body;
            if ($body === '' || strlen($body) > self::MAX_REMOTE_IMAGE_BYTES) {
                return null;
            }
            return $body;
        }

        $data = '';
        try {
            while (!feof($body)) {
                $chunk = fread($body, 65536);
                if ($chunk === false) {
                    return null;
                }
                $data .= $chunk;
                // An upstream that omits or understates Content-Length gets no
                // further than this: the read stops at the cap.
                if (strlen($data) > self::MAX_REMOTE_IMAGE_BYTES) {
                    return null;
                }
            }
        } finally {
            fclose($body);
        }

        if ($data === '') {
            return null;
        }
        // A declared length that contradicts what arrived means the response
        // is not what it described — a truncated fetch, or something on the
        // path rewriting it. Either way it is not a cover worth caching.
        if ($declared > 0 && $declared !== strlen($data)) {
            return null;
        }
        return $data;
    }

    private const EXT_TO_MIME = [
        '.png'  => 'image/png',
        '.webp' => 'image/webp',
        '.gif'  => 'image/gif',
        '.jpg'  => 'image/jpeg',
    ];

    /** Inverse of EXT_TO_MIME: the extension a validated Content-Type is stored under. */
    private const MIME_TO_EXT = [
        'image/png'  => '.png',
        'image/webp' => '.webp',
        'image/gif'  => '.gif',
        'image/jpeg' => '.jpg',
    ];
}
