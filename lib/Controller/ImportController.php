<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ImportController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ImportService $importService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    private function userId(): string
    {
        return $this->userSession->getUser()->getUID();
    }

    /**
     * Parse an uploaded file and return headers + first 5 rows for preview,
     * plus the auto-detected column mapping.
     *
     * POST /api/v1/import/preview
     * Expects multipart/form-data with field "file".
     */
    #[NoAdminRequired]
    public function preview(): DataResponse
    {
        $file = $this->request->getUploadedFile('file');

        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $parsed = $this->importService->parseFile($file['tmp_name'], $file['name']);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $mapping  = $this->importService->detectMapping($parsed['headers']);
        $preview  = array_slice($parsed['rows'], 0, 5);

        return new DataResponse([
            'headers' => $parsed['headers'],
            'preview' => $preview,
            'mapping' => $mapping,
            'totalRows' => count($parsed['rows']),
        ]);
    }

    /**
     * Commit the import: re-parse the file and apply the user-confirmed mapping.
     *
     * POST /api/v1/import/commit
     * Expects multipart/form-data with fields:
     *   - file: the same file
     *   - mapping: JSON string, array keyed by column index => field name or ""
     */
    #[NoAdminRequired]
    public function commit(): DataResponse
    {
        $file = $this->request->getUploadedFile('file');

        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
        }

        $mappingJson = $this->request->getParam('mapping', '{}');
        $rawMapping  = json_decode($mappingJson, true);

        if (!is_array($rawMapping)) {
            return new DataResponse(['error' => 'Invalid mapping'], Http::STATUS_BAD_REQUEST);
        }

        // Normalise: keys are column indices (int), values are field names or null
        $mapping = [];
        foreach ($rawMapping as $colIdx => $field) {
            $mapping[(int)$colIdx] = $field !== '' ? (string)$field : null;
        }

        try {
            $parsed = $this->importService->parseFile($file['tmp_name'], $file['name']);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $mappedRows = $this->importService->applyMapping($parsed['rows'], $mapping);
        $result     = $this->importService->import($mappedRows, $this->userId());

        return new DataResponse($result);
    }
}
