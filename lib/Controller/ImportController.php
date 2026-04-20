<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ImportController extends OCSController
{
    use UsesAuthenticatedUser;

    /**
     * Canonical field names a column may be mapped to. Matches the values
     * in ImportService::ALIASES.
     */
    private const VALID_MAPPING_FIELDS = [
        'artist', 'title', 'format', 'year', 'notes',
        'status', 'discogsId', 'barcode', 'label', 'category',
    ];

    private const VALID_CATEGORIES = ['music', 'film', 'book', 'game', 'comic'];

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ImportService $importService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Parse an uploaded file and return headers + first 5 rows for preview,
     * plus the auto-detected column mapping.
     *
     * POST /api/v1/import/preview
     * Expects multipart/form-data with field "file".
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
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
    #[UserRateLimit(limit: 10, period: 60)]
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

        // Normalise and validate: keys are column indices (int), values are
        // canonical field names from VALID_MAPPING_FIELDS or null. Reject any
        // unknown field name rather than silently dropping it.
        $mapping = [];
        foreach ($rawMapping as $colIdx => $field) {
            if ($field === '' || $field === null) {
                $mapping[(int)$colIdx] = null;
                continue;
            }
            $fieldStr = (string)$field;
            if (!in_array($fieldStr, self::VALID_MAPPING_FIELDS, true)) {
                return new DataResponse(
                    ['error' => "Unknown mapping field: {$fieldStr}"],
                    Http::STATUS_BAD_REQUEST,
                );
            }
            $mapping[(int)$colIdx] = $fieldStr;
        }

        try {
            $parsed = $this->importService->parseFile($file['tmp_name'], $file['name']);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $mappedRows = $this->importService->applyMapping($parsed['rows'], $mapping);
        $rawCategory = $this->request->getParam('category', 'music');
        $category    = in_array($rawCategory, self::VALID_CATEGORIES, true)
            ? $rawCategory
            : 'music';
        $result = $this->importService->import($mappedRows, $this->userId(), $category);

        return new DataResponse($result);
    }
}
