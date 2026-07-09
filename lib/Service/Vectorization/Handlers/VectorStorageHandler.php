<?php

/**
 * Vector Storage Handler
 *
 * Handles storing vector embeddings in the database (serialized-BLOB storage of
 * record plus an opportunistic PostgreSQL pgvector fast-path column).
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
     * @param IDBConnection    $db       Database connection
     * @param PgVectorPlatform $pgVector pgvector fast-path capability helper
     * @param LoggerInterface  $logger   PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly PgVectorPlatform $pgVector,
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

            // Additive pgvector dual-write (hybrid-document-search, decision 1):
            // populate the PostgreSQL fast-path column when available and the
            // dimension matches; the BLOB write above stays the storage of record.
            $this->populateVectorColumn(vectorId: $vectorId, embedding: $embedding);

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
     * Upsert the pgvector ANN sidecar row for a stored vector.
     *
     * PostgreSQL + matching configured dimension only (hybrid-document-search,
     * decision 2): vectors whose embedding dimension does not match the
     * sidecar's declared dimension get no sidecar row and keep being served by
     * the PHP-cosine fallback. Failures are logged, never fatal — the BLOB
     * write is the durable storage of record.
     *
     * @param int   $vectorId  Vector row id
     * @param array $embedding Embedding (array of floats)
     *
     * @return bool True when the sidecar row was written
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#2.1
     */
    private function populateVectorColumn(int $vectorId, array $embedding): bool
    {
        $columnDimension = $this->pgVector->getVectorColumnDimension();

        if ($columnDimension === null || count($embedding) !== $columnDimension) {
            return false;
        }

        try {
            $this->db->executeStatement(
                'INSERT INTO '.PgVectorPlatform::SIDECAR_TABLE.' (vector_id, embedding) '
                .'VALUES (?, ?::vector) '
                .'ON CONFLICT (vector_id) DO UPDATE SET embedding = EXCLUDED.embedding',
                [(string) $vectorId, $this->pgVector->formatVector($embedding)]
            );

            return true;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[VectorStorageHandler] Failed to write pgvector ANN sidecar row',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'vector_id' => $vectorId,
                    'error'     => $e->getMessage(),
                ]
            );

            return false;
        }//end try
    }//end populateVectorColumn()

    /**
     * Warm-up backfill: convert existing BLOB rows to pgvector ANN sidecar rows.
     *
     * Job-only warm-up (DECIDED 2026-07-06): the migration creates the sidecar
     * table and index only; this method — driven by ChunkVectorizationJob —
     * converts existing rows in bounded batches, selecting vectors WITHOUT a
     * sidecar row (the sidecar equivalent of `embedding_vector IS NULL`) whose
     * stored dimension matches the sidecar's declared dimension (idempotent:
     * converted rows drop out of the selection). Rows with an unparseable BLOB
     * are logged and skipped; the `$afterId` cursor lets the caller make
     * progress past persistently-failing rows.
     *
     * @param int $batchSize Maximum rows to process in this call
     * @param int $afterId   Only process rows with id > this cursor
     *
     * @return array{converted: int, failed: int, last_id: int, remaining: int}
     *
     * @SuppressWarnings(PHPMD.ErrorControlOperator)  @unserialize: malformed BLOBs emit
     *   E_WARNING; the false return is handled explicitly (row skipped + logged).
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  One bounded batch loop with explicit
     *   per-row failure handling (resource normalisation, parse check, upsert outcome).
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Selection + per-row tolerance +
     *   remaining-count reporting belong to one atomic batch step.
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#2.2
     */
    public function backfillEmbeddingVectors(int $batchSize=100, int $afterId=0): array
    {
        $columnDimension = $this->pgVector->getVectorColumnDimension();

        if ($columnDimension === null) {
            return [
                'converted' => 0,
                'failed'    => 0,
                'last_id'   => $afterId,
                'remaining' => 0,
            ];
        }

        $converted = 0;
        $failed    = 0;
        $lastId    = $afterId;
        $sidecar   = PgVectorPlatform::SIDECAR_TABLE;

        try {
            $result = $this->db->executeQuery(
                'SELECT v.id, v.embedding FROM *PREFIX*openregister_vectors v '
                ."LEFT JOIN $sidecar a ON a.vector_id = v.id "
                .'WHERE a.vector_id IS NULL AND v.embedding_dimensions = ? AND v.id > ? '
                .'ORDER BY v.id ASC LIMIT '.((int) $batchSize),
                [(string) $columnDimension, (string) $afterId]
            );
            $rows   = $result->fetchAll();
            $result->closeCursor();

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];

                // PostgreSQL returns BLOB columns as stream resources
                // (live-verified on PG16); normalise to a string first.
                $blob = $row['embedding'];
                if (is_resource($blob) === true) {
                    $blob = stream_get_contents($blob);
                }

                // SEC-SVC-9: embeddings are plain float arrays; never allow
                // object instantiation during unserialize. The error-control
                // operator suppresses the E_WARNING malformed input emits —
                // the false return value is handled explicitly below.
                $embedding = false;
                if (is_string($blob) === true) {
                    $embedding = @unserialize($blob, ['allowed_classes' => false]);
                }

                if (is_array($embedding) === false || count($embedding) !== $columnDimension) {
                    $failed++;
                    $this->logger->warning(
                        message: '[VectorStorageHandler] Skipping backfill for unparseable embedding BLOB',
                        context: [
                            'file'      => __FILE__,
                            'line'      => __LINE__,
                            'vector_id' => $row['id'],
                        ]
                    );
                    continue;
                }

                $populated = $this->populateVectorColumn(vectorId: (int) $row['id'], embedding: $embedding);

                if ($populated === true) {
                    $converted++;
                }

                if ($populated === false) {
                    // The populate call logged the failure already.
                    $failed++;
                }
            }//end foreach

            $remainingResult = $this->db->executeQuery(
                'SELECT COUNT(*) FROM *PREFIX*openregister_vectors v '
                ."LEFT JOIN $sidecar a ON a.vector_id = v.id "
                .'WHERE a.vector_id IS NULL AND v.embedding_dimensions = ?',
                [(string) $columnDimension]
            );
            $remaining       = (int) $remainingResult->fetchOne();
            $remainingResult->closeCursor();
        } catch (Exception $e) {
            $this->logger->error(
                message: '[VectorStorageHandler] pgvector warm-up backfill batch failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'converted' => $converted,
                'failed'    => $failed,
                'last_id'   => $lastId,
                'remaining' => 0,
            ];
        }//end try

        return [
            'converted' => $converted,
            'failed'    => $failed,
            'last_id'   => $lastId,
            'remaining' => $remaining,
        ];
    }//end backfillEmbeddingVectors()

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
