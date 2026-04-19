<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\TmdbService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class TmdbController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TmdbService $tmdbService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/tmdb/search?q={query} */
    #[NoAdminRequired]
    public function search(string $q = ''): DataResponse
    {
        if ($q === '') {
            return new DataResponse([]);
        }
        return new DataResponse($this->tmdbService->search($this->userId(), $q));
    }

    /** GET /api/v1/tmdb/movie/{id} */
    #[NoAdminRequired]
    public function getMovie(string $id): DataResponse
    {
        $data = $this->tmdbService->getMovie($this->userId(), $id);
        if (empty($data)) {
            return new DataResponse(
                ['error' => 'Movie not found or no TMDB token configured'],
                Http::STATUS_NOT_FOUND,
            );
        }
        return new DataResponse($data);
    }
}
