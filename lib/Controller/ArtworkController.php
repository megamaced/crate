<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Db\MediaItemMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IUserSession;

class ArtworkController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaItemMapper $mapper,
        private readonly IUserSession $userSession,
        private readonly IAppDataFactory $appDataFactory,
        private readonly IClientService $clientService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function get(int $itemId): Response
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $item = $this->mapper->findByUser($itemId, $userId);
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
            foreach (['.jpg', '.png', '.webp', '.gif'] as $ext) {
                try {
                    $file = $folder->getFile('artwork_' . $itemId . $ext);
                    $mime = match ($ext) {
                        '.png'  => 'image/png',
                        '.webp' => 'image/webp',
                        '.gif'  => 'image/gif',
                        default => 'image/jpeg',
                    };
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
                $download = $client->get($artworkPath, [
                    'headers' => ['User-Agent' => 'CrateNextcloudApp/0.1'],
                    'timeout' => 10,
                ]);
                $imageData = $download->getBody();
            } catch (\Exception) {
                return new Response(Http::STATUS_BAD_GATEWAY);
            }
            $file = $folder->newFile($cacheFile);
            $file->putContent($imageData);
        }

        $mime = str_ends_with($cacheFile, '.png') ? 'image/png' : 'image/jpeg';
        $response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mime]);
        $response->cacheFor(86400);
        return $response;
    }

    /**
     * Upload a user-provided image as artwork for a media item.
     * POST /artwork/{itemId}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function upload(int $itemId): Response
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $item = $this->mapper->findByUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $uploadedFile = $this->request->getUploadedFile('file');
        if (!$uploadedFile || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
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

        // Remove any previous cached/uploaded file for this item
        foreach (['.jpg', '.png', '.webp', '.gif'] as $oldExt) {
            try {
                $folder->getFile('artwork_' . $itemId . $oldExt)->delete();
            } catch (NotFoundException) {
            }
        }

        $file = $folder->newFile('artwork_' . $itemId . $ext);
        $file->putContent((string) file_get_contents($uploadedFile['tmp_name']));

        $item->setArtworkPath('local');
        $this->mapper->update($item);

        return new DataResponse(['status' => 'ok', 'artworkPath' => 'local']);
    }

    /**
     * Remove artwork from a media item.
     * DELETE /artwork/{itemId}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function delete(int $itemId): Response
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $item = $this->mapper->findByUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $folder = $this->appDataFactory->get('crate')->getFolder('artwork');
            foreach (['.jpg', '.png', '.webp', '.gif'] as $ext) {
                try {
                    $folder->getFile('artwork_' . $itemId . $ext)->delete();
                } catch (NotFoundException) {
                }
            }
        } catch (NotFoundException) {
        }

        $item->setArtworkPath(null);
        $this->mapper->update($item);

        return new DataResponse(['status' => 'ok']);
    }

    private function extension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        return str_ends_with(strtolower($path), '.png') ? '.png' : '.jpg';
    }
}
