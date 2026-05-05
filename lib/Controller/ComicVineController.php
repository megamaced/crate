<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\ComicVineService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ComicVineController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ComicVineService $comicVineService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/comicvine/search?q={query} */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function search(string $q = ''): DataResponse
    {
        if ($q === '') {
            return new DataResponse([]);
        }
        return new DataResponse($this->comicVineService->search($this->userId(), $q));
    }

    /** GET /api/v1/comicvine/volume/{id} */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function getVolume(string $id): DataResponse
    {
        $data = $this->comicVineService->getVolume($this->userId(), $id);
        if (empty($data)) {
            return new DataResponse(
                ['error' => 'Volume not found or no ComicVine API key configured'],
                Http::STATUS_NOT_FOUND,
            );
        }
        return new DataResponse($data);
    }
}
