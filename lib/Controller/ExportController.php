<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
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
     *
     * Rate-limited like /import/commit: one call reads the whole collection and
     * builds the file in memory, so it is the same class of work.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function export(
        string $format = 'csv',
        string $scope = 'owned',
        string $category = 'all',
        int $includeEnriched = 0,
        int $includeMarket = 0,
        int $includePrice = 0,
        ?string $owner = null,
    ): Response {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }
        $userId = $user->getUID();

        $cat = CrateCategories::isCategory($category) ? $category : null;

        // Exporting a collection shared with the caller: export the owner's
        // items instead, but only if the caller actually holds a read share of
        // that category (or the owner's whole library).
        $exportUserId = $userId;
        if ($owner !== null && $owner !== '' && $owner !== $userId) {
            if (!$this->canReadForExport($userId, $owner, $cat)) {
                return new DataResponse(
                    ['error' => 'No read access to that collection'],
                    Http::STATUS_FORBIDDEN,
                );
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

    /**
     * Read authorisation for an export of $owner's items. A null category
     * exports the owner's whole library, which requires read access to every
     * category — a whole-library share satisfies all five, a single-category
     * share does not.
     */
    private function canReadForExport(string $userId, string $owner, ?string $category): bool
    {
        if ($category !== null) {
            return $this->shareMapper->hasReadableCollectionShare($userId, $owner, $category);
        }
        foreach (CrateCategories::ALL as $candidate) {
            if (!$this->shareMapper->hasReadableCollectionShare($userId, $owner, $candidate)) {
                return false;
            }
        }
        return true;
    }
}
