<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

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
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Stream a CSV or XLSX export of the user's collection.
     *
     * GET /apps/crate/export
     *   ?format=csv|xlsx
     *   &scope=owned|wanted|all
     *   &includeEnriched=0|1
     *   &includeMarket=0|1
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function export(
        string $format          = 'csv',
        string $scope           = 'owned',
        int    $includeEnriched = 0,
        int    $includeMarket   = 0,
    ): DataDownloadResponse {
        $userId = $this->userSession->getUser()->getUID();

        [$content, $mimeType, $filename] = $this->exportService->generate(
            $userId,
            in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv',
            in_array($scope,  ['owned', 'wanted', 'all'], true) ? $scope : 'owned',
            $includeEnriched === 1,
            $includeMarket   === 1,
        );

        return new DataDownloadResponse($content, $filename, $mimeType);
    }
}
