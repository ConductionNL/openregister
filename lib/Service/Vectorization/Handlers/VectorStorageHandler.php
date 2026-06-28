<?php

/**
 * Vector Storage Handler
 *
 * Handles storing vector embeddings in database and Solr.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

/**
 * VectorStorageHandler
 *
 * Responsible for storing vector embeddings in the PostgreSQL database.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class VectorStorageHandler
{
    /**
     * Constructor
     *
     * @param IDBConnection   $db     Database connection
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Store vector embedding in the database
     *
     * PostgreSQL is the sole vector storage backend.
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-vector-embeddings/tasks.md#task-2
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
        array $metadata=[]
    ): int {
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-vector-embeddings/tasks.md#task-2
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
                'file'        => __FILE__,
                'line'        => __LINE__,
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
            $metadataJson = null;
            if (empty($metadata) === false) {
                $metadataJson = json_encode($metadata);
            }

            // Sanitize chunk_text to prevent encoding errors.
            $sanitizedChunkText = null;
            if ($chunkText !== null) {
                $sanitizedChunkText = $this->sanitizeText(text: $chunkText);
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
                message: '[VectorStorageHandler] Vector stored successfully in database',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'vector_id'   => $vectorId,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ]
            );

            return $vectorId;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorStorageHandler] Failed to store vector in database',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'error'       => $e->getMessage(),
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ]
            );
            throw new Exception('Vector storage failed: '.$e->getMessage());
        }//end try
    }//end storeVectorInDatabase()

    /**
     * Sanitize text to prevent UTF-8 encoding errors
     *
     * Removes invalid UTF-8 sequences and problematic control characters.
     *
     * @param string $text Text to sanitize
     *
     * @return string Sanitized text safe for UTF-8 storage
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-vector-embeddings/tasks.md#task-2
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
