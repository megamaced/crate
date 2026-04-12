<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Db\MediaItemMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
    public function get(int $itemId): Response
    {
        $userId = $this->userSession->getUser()->getUID();

        try {
            $item = $this->mapper->findByUser($itemId, $userId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        $artworkUrl = $item->getArtworkPath();
        if (!$artworkUrl || !str_starts_with($artworkUrl, 'http')) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        $appData = $this->appDataFactory->get('crate');
        $cacheFile = 'artwork_' . $itemId . $this->extension($artworkUrl);

        // Get or create the artwork folder
        try {
            $folder = $appData->getFolder('artwork');
        } catch (NotFoundException) {
            $folder = $appData->newFolder('artwork');
        }

        // Serve from cache if available
        try {
            $file = $folder->getFile($cacheFile);
        } catch (NotFoundException) {
            // Download from Discogs and cache
            try {
                $client = $this->clientService->newClient();
                $download = $client->get($artworkUrl, [
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
        $response->cacheFor(86400); // 1 day
        return $response;
    }

    private function extension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        return str_ends_with(strtolower($path), '.png') ? '.png' : '.jpg';
    }
}
