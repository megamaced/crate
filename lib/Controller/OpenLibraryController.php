<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\OpenLibraryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class OpenLibraryController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly OpenLibraryService $openLibraryService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /** GET /api/v1/openlibrary/search?q={query} */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function search(string $q = ''): DataResponse
    {
        if ($q === '') {
            return new DataResponse([]);
        }
        return new DataResponse($this->openLibraryService->search($q));
    }

    /** GET /api/v1/openlibrary/isbn/{isbn} */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function byIsbn(string $isbn): DataResponse
    {
        $clean = preg_replace('/[^0-9Xx]/', '', $isbn);
        if (strlen($clean) < 10) {
            return new DataResponse(['error' => 'Invalid ISBN'], Http::STATUS_BAD_REQUEST);
        }
        $data = $this->openLibraryService->getByIsbn($clean);
        if (empty($data)) {
            return new DataResponse(['error' => 'Book not found for ISBN'], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse($data);
    }

    /** GET /api/v1/openlibrary/work/{id} */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function getWork(string $id): DataResponse
    {
        $data = $this->openLibraryService->getWork($id);
        if (empty($data)) {
            return new DataResponse(
                ['error' => 'Work not found'],
                Http::STATUS_NOT_FOUND,
            );
        }
        return new DataResponse($data);
    }
}
