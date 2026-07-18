<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ExportController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ExportService $exportService,
        private readonly IUserSession $userSession,
        private readonly CrateShareMapper $shareMapper,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Stream a CSV or XLSX export of the user's collection.
     *
     * GET /apps/crate/export
     *   ?format=csv|xlsx
     *   &scope=owned|wanted|all
     *   &category=music|film|book|game|comic|all
     *   &includeEnriched=0|1
     *   &includeMarket=0|1
     *   &includePrice=0|1   — original purchase price + currency
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function export(
        string $format = 'csv',
        string $scope = 'owned',
        string $category = 'all',
        int $includeEnriched = 0,
        int $includeMarket = 0,
        int $includePrice = 0,
        ?string $owner = null,
    ): DataDownloadResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataDownloadResponse('', 'error.txt', 'text/plain');
        }
        $userId = $user->getUID();

        $cat = CrateCategories::isCategory($category) ? $category : null;

        // Exporting a collection shared with the caller: export the owner's
        // items instead, but only if the caller actually holds a read share of
        // that category (or the owner's whole library).
        $exportUserId = $userId;
        if ($owner !== null && $owner !== '' && $owner !== $userId) {
            if ($cat === null || !$this->shareMapper->hasReadableCollectionShare($userId, $owner, $cat)) {
                return new DataDownloadResponse('', 'error.txt', 'text/plain');
            }
            $exportUserId = $owner;
        }

        [$content, $mimeType, $filename] = $this->exportService->generate(
            $exportUserId,
            in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv',
            in_array($scope, ['owned', 'wanted', 'all'], true) ? $scope : 'owned',
            $includeEnriched === 1,
            $includeMarket === 1,
            $cat,
            $includePrice === 1,
        );

        return new DataDownloadResponse($content, $filename, $mimeType);
    }
}
