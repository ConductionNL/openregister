<?php

/**
 * Vector Storage Handler
 *
 * Handles storing vector embeddings in database and Solr.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vectorization\Handlers;

use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Index\Backends\SolrBackend;

/**
 * VectorStorageHandler
 *
 * Responsible for storing vector embeddings in database or Solr.
 * Routes storage based on configuration and handles both backends.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class VectorStorageHandler
{
    /**
     * Constructor
     *
     * @param IDBConnection   $db              Database connection
     * @param SettingsService $settingsService Settings service
     * @param IndexService    $indexService    Index service for Solr
     * @param LoggerInterface $logger          PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly SettingsService $settingsService,
        private readonly IndexService $indexService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Store vector embedding
     *
     * Routes to database or Solr based on configured backend.
     *
     * @param string      $entityType  Entity type ('object' or 'file')
     * @param string      $entityId    Entity UUID
     * @param array       $embedding   Vector embedding (array of floats)
     * @param string      $model       Model used to generate embedding
     * @param int         $dimensions  Number of dimensions
     * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks Total number of chunks
     * @param string|null $chunkText   The text that was embedded
     * @param array       $metadata    Additional metadata as associative array
     * @param string      $backend     Backend to use ('php', 'database', or 'solr')
     *
     * @return int The ID of the inserted vector (or pseudo-ID for Solr)
     *
     * @throws \Exception If storage fails
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible vector storage options
     */
    public function storeVector(
        string $entityType,
        string $entityId,
        array $embedding,
        string $model,
        int $dimensions,
        int $chunkIndex=0,
        int $totalChunks=1,
        ?string $chunkText=null,
        array $metadata=[],
        string $backend='php'
    ): int {
        $this->logger->debug(
            message: '[VectorStorageHandler] Routing vector storage',
            context: [
                'backend'     => $backend,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'chunk_index' => $chunkIndex,
                'dimensions'  => $dimensions,
            ]
        );

        try {
            // Route to selected backend.
            if ($backend === 'solr') {
                // Store in Solr and return a pseudo-ID.
                $documentId = $this->storeVectorInSolr(
                    entityType: $entityType,
                    entityId: $entityId,
                    embedding: $embedding,
                    model: $model,
                    dimensions: $dimensions,
                    chunkIndex: $chunkIndex,
                    totalChunks: $totalChunks,
                    chunkText: $chunkText,
                    metadata: $metadata
                );
                return crc32($documentId);
            }

            // Default: Store in database.
            return $this->storeVectorInDatabase(
                entityType: $entityType,
                entityId: $entityId,
                embedding: $embedding,
                model: $model,
                dimensions: $dimensions,
                chunkIndex: $chunkIndex,
                totalChunks: $totalChunks,
                chunkText: $chunkText,
                metadata: $metadata
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorStorageHandler] Failed to store vector',
                context: [
                    'backend'     => $backend,
                    'error'       => $e->getMessage(),
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ]
            );
            throw new Exception('Vector storage failed: '.$e->getMessage());
        }//end try
    }//end storeVector()

    /**
     * Store vector embedding in database
     *
     * @param string      $entityType  Entity type ('object' or 'file')
     * @param string      $entityId    Entity UUID
     * @param array       $embedding   Vector embedding (array of floats)
     * @param string      $model       Model used to generate embedding
     * @param int         $dimensions  Number of dimensions
     * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks Total number of chunks
     * @param string|null $chunkText   The text that was embedded
     * @param array       $metadata    Additional metadata as associative array
     *
     * @return int The ID of the inserted vector
     *
     * @throws \Exception If storage fails
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible vector storage options
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   Multiple storage conditions and error handling
     */
    private function storeVectorInDatabase(
        string $entityType,
        string $entityId,
        array $embedding,
        string $model,
        int $dimensions,
        int $chunkIndex=0,
        int $totalChunks=1,
        ?string $chunkText=null,
        array $metadata=[]
    ): int {
        $this->logger->debug(
            message: '[VectorStorageHandler] Storing vector in database',
            context: [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'chunk_index' => $chunkIndex,
                'dimensions'  => $dimensions,
            ]
        );

        try {
            // Serialize embedding to binary format.
            $embeddingBlob = serialize($embedding);

            // Serialize metadata to JSON.
            if (empty($metadata) === false) {
                $metadataJson = json_encode($metadata);
            }

            if (empty($metadata) === true) {
                $metadataJson = null;
            }

            // Sanitize chunk_text to prevent encoding errors.
            if ($chunkText !== null) {
                $sanitizedChunkText = $this->sanitizeText($chunkText);
            }

            if ($chunkText === null) {
                $sanitizedChunkText = null;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('openregister_vectors')
                ->values(
                    values: [
                        'entity_type'          => $qb->createNamedParameter($entityType),
                        'entity_id'            => $qb->createNamedParameter($entityId),
                        'chunk_index'          => $qb->createNamedParameter($chunkIndex, \PDO::PARAM_INT),
                        'total_chunks'         => $qb->createNamedParameter($totalChunks, \PDO::PARAM_INT),
                        'chunk_text'           => $qb->createNamedParameter($sanitizedChunkText),
                        'embedding'            => $qb->createNamedParameter($embeddingBlob, \PDO::PARAM_LOB),
                        'embedding_model'      => $qb->createNamedParameter($model),
                        'embedding_dimensions' => $qb->createNamedParameter($dimensions, \PDO::PARAM_INT),
                        'metadata'             => $qb->createNamedParameter($metadataJson),
                        'created_at'           => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                        'updated_at'           => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                    ]
                )
                ->executeStatement();

            $vectorId = $qb->getLastInsertId();

            $this->logger->info(
                message: 'Vector stored successfully in database',
                context: [
                    'vector_id'   => $vectorId,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ]
            );

            return $vectorId;
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Failed to store vector in database',
                context: [
                    'error'       => $e->getMessage(),
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ]
            );
            throw new Exception('Vector storage failed: '.$e->getMessage());
        }//end try
    }//end storeVectorInDatabase()

    /**
     * Store vector embedding in Solr
     *
     * Stores a vector embedding in the configured Solr collection using dense vector fields.
     *
     * @param string      $entityType  Entity type ('object' or 'file')
     * @param string      $entityId    Entity UUID
     * @param array       $embedding   Vector embedding (array of floats)
     * @param string      $model       Model used to generate embedding
     * @param int         $dimensions  Number of dimensions
     * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks Total number of chunks (reserved for future use)
     * @param string|null $chunkText   The text that was embedded (reserved for future use)
     * @param array       $metadata    Additional metadata (reserved for future use)
     *
     * @return string The Solr document ID
     *
     * @throws \Exception If storage fails or Solr is not configured
     *
     * @psalm-suppress UnusedParam Parameters reserved for future atomic update enhancements
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible vector storage options
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   Multiple Solr storage conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)        Multiple storage paths with error handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Comprehensive Solr vector storage with atomic updates
     */
    private function storeVectorInSolr(
        string $entityType,
        string $entityId,
        array $embedding,
        string $model,
        int $dimensions,
        int $chunkIndex=0,
        int $totalChunks=1,
        ?string $chunkText=null,
        array $metadata=[]
    ): string {
        $this->logger->debug(
            message: '[VectorStorageHandler] Storing vector in Solr',
            context: [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'chunk_index' => $chunkIndex,
                'dimensions'  => $dimensions,
            ]
        );

        try {
            // Get appropriate Solr collection based on entity type.
            $collection  = $this->getSolrCollectionForEntityType($entityType);
            $vectorField = $this->getSolrVectorField();

            if ($collection === null || $collection === '') {
                throw new Exception("Solr collection not configured for entity type: {$entityType}");
            }

            // Get Solr backend from IndexService.
            $solrBackend = $this->indexService->getBackend();
            if ($solrBackend->isAvailable() === false) {
                throw new Exception('Solr service is not available');
            }

            // Determine document ID based on entity type.
            $entityTypeLower = strtolower($entityType);
            if ($entityTypeLower === 'file' || $entityTypeLower === 'files') {
                $documentId = "{$entityId}_chunk_{$chunkIndex}";
            }

            if ($entityTypeLower !== 'file' && $entityTypeLower !== 'files') {
                $documentId = $entityId;
            }

            // Prepare atomic update document.
            $updateDocument = [
                'id'                => $documentId,
                $vectorField        => ['set' => $embedding],
                '_embedding_model_' => ['set' => $model],
                '_embedding_dim_'   => ['set' => $dimensions],
                'self_updated'      => ['set' => gmdate('Y-m-d\TH:i:s\Z')],
            ];

            $this->logger->debug(
                message: '[VectorStorageHandler] Preparing Solr atomic update',
                context: [
                    'document_id'    => $documentId,
                    'collection'     => $collection,
                    'vector_field'   => $vectorField,
                    'embedding_size' => count($embedding),
                ]
            );

            // Perform atomic update in Solr.
            // Cast to SolrBackend to access Solr-specific methods.
            if ($solrBackend instanceof SolrBackend === false) {
                throw new Exception('Vector storage requires SolrBackend');
            }

            $solrUrl = $solrBackend->getHttpClient()->buildSolrBaseUrl()."/{$collection}/update?commit=true";

            $response = $solrBackend->getHttpClient()->getHttpClient()->post(
                $solrUrl,
                [
                    'json'    => [$updateDocument],
                    'headers' => ['Content-Type' => 'application/json'],
                ]
            );

            $responseData = json_decode((string) $response->getBody(), true);

            $statusMissing = isset($responseData['responseHeader']['status']) === false;
            $statusNotZero = ($responseData['responseHeader']['status'] ?? null) !== 0;
            if ($statusMissing === true || $statusNotZero === true) {
                throw new Exception('Solr atomic update failed: '.json_encode($responseData));
            }

            $this->logger->info(
                message: '[VectorStorageHandler] Vector added to Solr document',
                context: [
                    'document_id' => $documentId,
                    'collection'  => $collection,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ]
            );

            return $documentId;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorStorageHandler] Failed to store vector in Solr',
                context: [
                    'error'       => $e->getMessage(),
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'chunk_index' => $chunkIndex,
                ]
            );
            throw new Exception('Solr vector storage failed: '.$e->getMessage());
        }//end try
    }//end storeVectorInSolr()

    /**
     * Get the appropriate Solr collection based on entity type
     *
     * @param string $entityType Entity type ('file' or 'object')
     *
     * @return string|null Solr collection name or null if not configured
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Collection resolution requires multiple conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple collection determination paths
     */
    private function getSolrCollectionForEntityType(string $entityType): ?string
    {
        try {
            $settings = $this->settingsService->getSettings();

            // Normalize entity type.
            $entityType = strtolower($entityType);

            // Determine which collection to use based on entity type.
            if ($entityType === 'file' || $entityType === 'files') {
                $collection = $settings['solr']['fileCollection'] ?? null;
            }

            if ($entityType !== 'file' && $entityType !== 'files') {
                // Default to object collection.
                $collection = $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
            }

            if ($collection === null || $collection === '') {
                $this->logger->warning(
                    message: '[VectorStorageHandler] No Solr collection configured for entity type',
                    context: ['entity_type' => $entityType]
                );
            }

            return $collection;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[VectorStorageHandler] Failed to get Solr collection for entity type',
                context: [
                    'entity_type' => $entityType,
                    'error'       => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end getSolrCollectionForEntityType()

    /**
     * Get the configured Solr vector field name
     *
     * @return string Solr vector field name (default: '_embedding_')
     */
    private function getSolrVectorField(): string
    {
        try {
            $settings = $this->settingsService->getSettings();

            // Get vector field from LLM configuration, default to '_embedding_' field name.
            return $settings['llm']['vectorConfig']['solrField'] ?? '_embedding_';
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[VectorStorageHandler] Failed to get Solr vector field, using default',
                context: ['error' => $e->getMessage()]
            );
            return '_embedding_';
        }
    }//end getSolrVectorField()

    /**
     * Sanitize text to prevent UTF-8 encoding errors
     *
     * Removes invalid UTF-8 sequences and problematic control characters.
     *
     * @param string $text Text to sanitize
     *
     * @return string Sanitized text safe for UTF-8 storage
     */
    private function sanitizeText(string $text): string
    {
        // Step 1: Remove invalid UTF-8 sequences.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Step 2: Remove NULL bytes and other problematic control characters.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Step 3: Replace any remaining invalid UTF-8 with replacement character.
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        // Step 4: Normalize whitespace.
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }//end sanitizeText()
}//end class
