<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\RawgService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class RawgController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly RawgService $rawgService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/rawg/search?q={query} */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function search(string $q = ''): DataResponse
    {
        if ($q === '') {
            return new DataResponse([]);
        }
        return new DataResponse($this->rawgService->search($this->userId(), $q));
    }

    /** GET /api/v1/rawg/game/{id} */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function getGame(string $id): DataResponse
    {
        $data = $this->rawgService->getGame($this->userId(), $id);
        if (empty($data)) {
            return new DataResponse(
                ['error' => 'Game not found or no RAWG API key configured'],
                Http::STATUS_NOT_FOUND,
            );
        }
        return new DataResponse($data);
    }
}
