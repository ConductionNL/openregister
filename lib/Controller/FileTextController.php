<?php

/**
 * OpenRegister File Text Controller
 *
 * Controller for file text management operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Http;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\ManualEntityException;
use OCA\OpenRegister\Service\File\ManualEntityResult;
use OCA\OpenRegister\Service\File\ManualEntityService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * FileTextController
 *
 * Controller for file text management operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class FileTextController extends Controller
{
    /**
     * Constructor
     *
     * @param string                $appName              App name
     * @param IRequest              $request              Request object
     * @param TextExtractionService $textExtractor        Text extraction service
     * @param IndexService          $indexService         Index service for file operations
     * @param FileService           $fileService          File service for file operations
     * @param EntityRelationMapper  $entityRelationMapper Entity relation mapper
     * @param LoggerInterface       $logger               Logger
     * @param IAppConfig            $config               Application configuration
     * @param ManualEntityService   $manualEntityService  Orchestrator for the manual-entity write path
     * @param IUserSession          $userSession          Session user accessor (for the manual-entity endpoint)
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TextExtractionService $textExtractor,
        private readonly IndexService $indexService,
        private readonly FileService $fileService,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $config,
        private readonly ManualEntityService $manualEntityService,
        private readonly IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get extracted text for a file
     *
     * @param int $fileId Nextcloud file ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with file text or error
     */
    public function getFileText(int $fileId): JSONResponse
    {
        try {
            // TextExtractionService works with chunks, not FileText entities.
            // For now, return a message indicating this endpoint needs to be updated.
            // TODO: Implement chunk retrieval for file text display.
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'This endpoint is deprecated. Use chunk-based endpoints instead.',
                    'file_id' => $fileId,
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to get file text',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to retrieve file text: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getFileText()

    /**
     * Extract text from a file (force re-extraction)
     *
     * @param int $fileId Nextcloud file ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with extraction result
     */
    public function extractFileText(int $fileId): JSONResponse
    {
        $hasFileManagement    = $this->config->hasKey(app: 'openregister', key: 'fileManagement');
        $fileManagementConfig = json_decode(
            $this->config->getValueString(app: 'openregister', key: 'fileManagement'),
            true
        );
        $extractionScope      = $fileManagementConfig['extractionScope'] ?? null;
        if ($hasFileManagement === false || $extractionScope === 'none') {
            $logMsg = '[FileTextController] File extraction is disabled. Not extracting text from files.';
            $this->logger->info(message: $logMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
            return new JSONResponse(
                data: ['success' => false, 'message' => 'Text extraction disabled'],
                statusCode: Http::STATUS_NOT_IMPLEMENTED
            );
        }

        try {
            // Force re-extraction.
            $this->textExtractor->extractFile(fileId: $fileId, forceReExtract: true);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'Text extracted successfully',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to extract file text',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to extract file text: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end extractFileText()

    /**
     * Bulk extract text from multiple files
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with bulk extraction result
     */
    public function bulkExtract(): JSONResponse
    {
        try {
            $limit = (int) $this->request->getParam('limit', 100);
            $limit = min($limit, 500);
            // Max 500 files at once.
            $result = $this->textExtractor->extractPendingFiles($limit);

            return new JSONResponse(
                data: [
                    'success'   => true,
                    'processed' => $result['processed'],
                    'failed'    => $result['failed'],
                    'total'     => $result['total'],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed bulk extraction',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Bulk extraction failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end bulkExtract()

    /**
     * Get file text extraction statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with extraction stats
     */
    public function getStats(): JSONResponse
    {
        try {
            $stats = $this->textExtractor->getStats();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'stats'   => $stats,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to get stats',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to retrieve statistics: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getStats()

    /**
     * Delete file text by file ID
     *
     * @param int $fileId Nextcloud file ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with deletion result
     */
    public function deleteFileText(int $fileId): JSONResponse
    {
        try {
            // TextExtractionService works with chunks.
            // TODO: Implement chunk deletion for file.
            // For now, return a message indicating this needs implementation.
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Chunk deletion not yet implemented. Use chunk-based endpoints.',
                ],
                statusCode: 501
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to delete file text',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to delete file text: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end deleteFileText()

    /**
     * Process extracted files and index their chunks to SOLR
     *
     * @param int|null $limit        Maximum number of files to process
     * @param int|null $chunkSize    Chunk size in characters
     * @param int|null $chunkOverlap Overlap between chunks in characters
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with indexing stats
     */
    public function processAndIndexExtracted(?int $limit=null, ?int $chunkSize=null, ?int $chunkOverlap=null): JSONResponse
    {
        try {
            $options = [];
            if ($chunkSize !== null) {
                $options['chunk_size'] = $chunkSize;
            }

            if ($chunkOverlap !== null) {
                $options['chunk_overlap'] = $chunkOverlap;
            }

            $result = $this->indexService->processUnindexedChunks(limit: $limit);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to process extracted files',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to process extracted files: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end processAndIndexExtracted()

    /**
     * Process and index a single extracted file
     *
     * @param int      $fileId        File ID
     * @param int|null $chunkSize     Chunk size in characters
     * @param int|null $_chunkOverlap Overlap between chunks in characters (reserved for future use)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter) $_chunkOverlap reserved for future implementation
     *
     * @return JSONResponse JSON response with indexing result
     */
    public function processAndIndexFile(int $fileId, ?int $chunkSize=null, ?int $_chunkOverlap=null): JSONResponse
    {
        try {
            $options = [];
            if ($chunkSize !== null) {
                $options['chunk_size'] = $chunkSize;
            }

            // Process unindexed chunks for all files (fileId and options are not supported by current API).
            // TODO: Implement file-specific chunk processing with chunk size/overlap options.
            $result = $this->indexService->processUnindexedChunks();

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to process file',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to process file: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end processAndIndexFile()

    /**
     * Get chunking statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with chunking stats
     */
    public function getChunkingStats(): JSONResponse
    {
        try {
            $stats = $this->indexService->getChunkingStats();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'stats'   => $stats,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to get chunking stats',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to get chunking stats: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getChunkingStats()

    /**
     * Anonymize a file by replacing detected entities with placeholders
     *
     * Creates a new anonymized copy of the file with all detected PII entities
     * replaced by placeholders in the format [ENTITY_TYPE: key].
     * The original file remains unchanged.
     *
     * @param int $fileId Nextcloud file ID to anonymize
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with anonymization result
     */
    public function anonymizeFile(int $fileId): JSONResponse
    {
        try {
            $this->logger->info(
                message: '[FileTextController] Anonymizing file',
                context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
            );

            // Get the file node.
            $fileNode = $this->fileService->getFileById($fileId);
            if ($fileNode === null) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'File not found',
                    ],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // Check if the file is already anonymized.
            $fileName = $fileNode->getName();
            if (strpos($fileName, '_anonymized') !== false) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'File is already anonymized',
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Get detected entities for this file, excluding those the operator
            // has flagged with skip_anonymization=true. Per the
            // `entity-relation-grondslagen` change, the anonymise pass MUST NOT
            // redact rows that carry an operator skip decision.
            $entityData = $this->entityRelationMapper->findEntitiesForAnonymization($fileId);

            if (empty($entityData) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'No entities detected in this file. Run text extraction first.',
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Build entities array in the format expected by anonymizeDocument.
            // Format: [['text' => 'value', 'entityType' => 'TYPE', 'key' => 'unique_key'], ...].
            $entities        = [];
            $processedValues = [];
            // Track unique values to avoid duplicates.
            foreach ($entityData as $entity) {
                $value = $entity['entity_value'];

                // Skip if we've already processed this value.
                if (isset($processedValues[$value]) === true) {
                    continue;
                }

                $processedValues[$value] = true;
                $entities[] = [
                    'text'       => $value,
                    'entityType' => $entity['entity_type'],
                    'key'        => substr(md5($value.$entity['entity_type']), 0, 8),
                ];
            }

            $this->logger->debug(
                message: '[FileTextController] Found entities to anonymize',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'file_id'      => $fileId,
                    'entity_count' => count($entities),
                ]
            );

            // Perform anonymization.
            $anonymizedFile = $this->fileService->anonymizeDocument($fileNode, $entities);

            // Best-effort policy: the file is produced even when some entity
            // text could not be removed. Surface the residuals so the operator
            // can iterate (manual entities, skip unselected occurrences). Logs
            // stay PII-free (ADR-005); the residual TEXT is carried only in
            // this authenticated response for the review UI (deliberate,
            // product-owner-approved deviation from the no-PII-in-responses rule).
            $residualEntities = $this->fileService->getLastResidualEntities();
            $isComplete       = (count($residualEntities) === 0);

            // Mark entity relations as anonymized.
            $this->entityRelationMapper->markAsAnonymized(
                fileId: $fileId,
                anonymizedValue: 'anonymized_'.date('Y-m-d_H-i-s')
            );

            $this->logger->info(
                message: '[FileTextController] File anonymized'.($isComplete === true ? ' successfully' : ' with residual entities'),
                context: [
                    'file'               => __FILE__,
                    'line'               => __LINE__,
                    'original_file_id'   => $fileId,
                    'anonymized_file_id' => $anonymizedFile->getId(),
                    'anonymized_path'    => $anonymizedFile->getPath(),
                    'entities_replaced'  => count($entities),
                    'complete'           => $isComplete,
                    // PII-free: count only, never the residual text.
                    'residual_count'     => count($residualEntities),
                ]
            );

            return new JSONResponse(
                data: [
                    'success'            => true,
                    'complete'           => $isComplete,
                    'message'            => ($isComplete === true
                        ? 'File anonymized successfully'
                        : 'File anonymized, but some entities could not be fully removed — review the output and refine the entities (manual entities, skip unselected occurrences).'),
                    'original_file_id'   => $fileId,
                    'anonymized_file_id' => $anonymizedFile->getId(),
                    'anonymized_path'    => $anonymizedFile->getPath(),
                    'entities_replaced'  => count($entities),
                    'residual_count'     => count($residualEntities),
                    'residual_entities'  => $residualEntities,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to anonymize file',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to anonymize file: '.$e->getMessage(),
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end anonymizeFile()

    /**
     * Add an operator-supplied manual entity to a file.
     *
     * Implements `manual-entity-anonymisation`: takes an operator-typed
     * value + type, performs chunk-aware string matching against the
     * file's extracted text, creates (or reuses) the catalogue entry,
     * and inserts one `EntityRelation` row per occurrence found.
     *
     * Idempotent: re-calling for the same value on the same file does
     * NOT create duplicate relation rows. Zero-match responses are
     * non-errors (HTTP 200 with a `message` field).
     *
     * Request body:
     *
     *     {
     *         "value":         "Jan Jansen",     // required
     *         "type":          "PERSON",          // required
     *         "category":      "name",            // optional
     *         "wholeWord":     true,              // optional, default true
     *         "caseSensitive": true               // optional, default true
     *     }
     *
     * @param int $fileId Nextcloud file ID the manual entity applies to.
     *
     * @return JSONResponse 201 on matches found, 200 on zero matches, 4xx/5xx on failure.
     *
     * @NoAdminRequired
     */
    public function addManualEntity(int $fileId): JSONResponse
    {
        // Content-type guard. The endpoint accepts JSON only; reject
        // other media types with 415 so callers don't accidentally
        // trip the body-parser heuristics.
        $contentType = (string) $this->request->getHeader('Content-Type');
        $mediaType   = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType !== 'application/json' && $mediaType !== '') {
            return new JSONResponse(
                data: [
                    'error'  => 'unsupported_media_type',
                    'reason' => 'POST /api/files/{fileId}/manual-entities requires Content-Type: application/json',
                ],
                statusCode: Http::STATUS_UNSUPPORTED_MEDIA_TYPE
            );
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'unauthenticated'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        $body = $this->request->getParams();
        unset($body['fileId'], $body['_route']);

        $value         = isset($body['value']) === true ? (string) $body['value'] : '';
        $type          = isset($body['type']) === true ? (string) $body['type'] : '';
        $wholeWord     = isset($body['wholeWord']) === true ? (bool) $body['wholeWord'] : true;
        $caseSensitive = isset($body['caseSensitive']) === true ? (bool) $body['caseSensitive'] : true;

        if ($value === '') {
            return new JSONResponse(
                data: ['error' => 'invalid_request', 'field' => 'value'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        if ($type === '') {
            return new JSONResponse(
                data: ['error' => 'invalid_request', 'field' => 'type'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        // ADR-005 PII rule: log the request shape WITHOUT the value.
        $this->logger->info(
            message: '[FileTextController] addManualEntity request',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'fileId'        => $fileId,
                'type'          => $type,
                'wholeWord'     => $wholeWord,
                'caseSensitive' => $caseSensitive,
                'valueLength'   => strlen($value),
                'actor'         => $user->getUID(),
            ]
        );

        try {
            $result = $this->manualEntityService->addManualEntity(
                fileId: $fileId,
                value: $value,
                type: $type,
                wholeWord: $wholeWord,
                caseSensitive: $caseSensitive,
                actor: $user
            );
        } catch (ManualEntityException $e) {
            return $this->mapManualEntityException(exception: $e, fileId: $fileId);
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[FileTextController] addManualEntity unexpected failure',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'fileId' => $fileId,
                    'error'  => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                data: ['error' => 'internal_error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return $this->formatManualEntityResponse(result: $result);

    }//end addManualEntity()

    /**
     * Translate a `ManualEntityException` to the matching HTTP response.
     *
     * Per the spec:
     *   file_not_extracted      → 422 (operator must run extraction first)
     *   regex_compile_failure   → 400 (malformed needle)
     *   unsupported_entity_type → 400
     *   internal_error          → 500, OR 403 when the message carries the
     *                             `forbidden:` sentinel from the service-side
     *                             write-access check.
     *
     * @param ManualEntityException $exception Source exception.
     * @param int                   $fileId    Target file id (used for logging).
     *
     * @return JSONResponse Structured error body with no PII echo.
     */
    private function mapManualEntityException(ManualEntityException $exception, int $fileId): JSONResponse
    {
        $reason = $exception->getReason();

        if ($reason === ManualEntityException::REASON_INTERNAL_ERROR
            && str_starts_with($exception->getMessage(), 'forbidden:') === true
        ) {
            return new JSONResponse(
                data: [
                    'error'  => 'forbidden',
                    'reason' => 'write access to file required',
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $statusByReason = [
            ManualEntityException::REASON_FILE_NOT_EXTRACTED      => Http::STATUS_UNPROCESSABLE_ENTITY,
            ManualEntityException::REASON_REGEX_COMPILE_FAILURE   => Http::STATUS_BAD_REQUEST,
            ManualEntityException::REASON_UNSUPPORTED_ENTITY_TYPE => Http::STATUS_BAD_REQUEST,
            ManualEntityException::REASON_INTERNAL_ERROR          => Http::STATUS_INTERNAL_SERVER_ERROR,
        ];

        $status = ($statusByReason[$reason] ?? Http::STATUS_INTERNAL_SERVER_ERROR);

        $this->logger->info(
            message: '[FileTextController] addManualEntity translated exception',
            context: [
                'file'   => __FILE__,
                'line'   => __LINE__,
                'fileId' => $fileId,
                'reason' => $reason,
                'status' => $status,
            ]
        );

        return new JSONResponse(
            data: ['error' => $reason],
            statusCode: $status
        );

    }//end mapManualEntityException()

    /**
     * Format the success response body per the proposal.
     *
     * 201 when one or more matches were found; 200 with a `message`
     * field when zero matches were found (catalogue entry was still
     * created or reused).
     *
     * @param ManualEntityResult $result Service-layer result.
     *
     * @return JSONResponse
     */
    private function formatManualEntityResponse(ManualEntityResult $result): JSONResponse
    {
        $entityPayload = [
            'id'     => (int) $result->entity->getId(),
            'uuid'   => $result->entity->getUuid(),
            'value'  => $result->entity->getValue(),
            'type'   => $result->entity->getType(),
            'reused' => ($result->entityWasNew === false),
        ];

        $relationsPayload = [];
        foreach ($result->relations as $relation) {
            $relationsPayload[] = [
                'id'            => (int) $relation->getId(),
                'chunkId'       => $relation->getChunkId(),
                'positionStart' => $relation->getPositionStart(),
                'positionEnd'   => $relation->getPositionEnd(),
                'context'       => $relation->getContext(),
            ];
        }

        $body = [
            'entity'         => $entityPayload,
            'relations'      => $relationsPayload,
            'matchCount'     => $result->matchCount,
            'matchesSkipped' => $result->matchesSkipped,
        ];

        if ($result->matchCount === 0) {
            $body['message'] = 'Text not found in file. Catalogue entry created (or reused) and is available for use on other files.';
            return new JSONResponse(data: $body, statusCode: Http::STATUS_OK);
        }

        return new JSONResponse(data: $body, statusCode: Http::STATUS_CREATED);

    }//end formatManualEntityResponse()
}//end class
