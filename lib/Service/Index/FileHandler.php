<?php

/**
 * FileHandler
 *
 * Handles file chunk indexing to Solr/Elasticsearch.
 * Reads chunks from database (created by TextExtractionService) and indexes them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use Exception;
use OCA\OpenRegister\Db\ChunkMapper;
use Psr\Log\LoggerInterface;

/**
 * FileHandler
 *
 * Indexes file chunks to search backend (Solr/Elastic).
 *
 * ARCHITECTURE:
 * - TextExtractionService extracts text and creates chunks in database (separate flow).
 * - FileHandler reads chunks from database and indexes them to Solr/Elastic.
 * - Does NOT extract text - only indexes existing chunks.
 *
 * RESPONSIBILITIES:
 * - Read chunks from database (ChunkMapper).
 * - Index chunks to Solr fileCollection.
 * - Retrieve file statistics from Solr.
 * - Keep Solr index in sync with database chunks.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index
 */
class FileHandler
{
    /**
     * Constructor
     *
     * @param LoggerInterface        $logger        Logger
     * @param ChunkMapper            $chunkMapper   Chunk mapper for retrieving chunks from database
     * @param SearchBackendInterface $searchBackend Search backend (Solr/Elastic/etc)
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ChunkMapper $chunkMapper,
        private readonly SearchBackendInterface $searchBackend
    ) {
    }//end __construct()

    /**
     * Index file chunks to Solr fileCollection.
     *
     * Reads chunks from database (already extracted by TextExtractionService) and indexes them.
     * This method does NOT extract text - it only indexes existing chunks.
     *
     * @param int   $fileId   Nextcloud file ID
     * @param array $chunks   Array of chunk entities from ChunkMapper (from database)
     * @param array $metadata File metadata
     *
     * @return array Indexing result
     *
     * @throws Exception If fileCollection is not configured
     *
     * @psalm-return array{success: bool, indexed: int, collection: string}
     */
    public function indexFileChunks(int $fileId, array $chunks, array $metadata): array
    {
        $this->logger->info(
            '[FileHandler] Indexing file chunks',
            [
                'file_id'     => $fileId,
                'chunk_count' => count($chunks),
            ]
        );

        $documents = [];
        foreach ($chunks as $index => $chunk) {
            $documents[] = [
                'id'           => $chunk->getUuid() ?? $fileId.'_chunk_'.$index,
                'file_id'      => $fileId,
                'chunk_index'  => $chunk->getChunkIndex(),
                'total_chunks' => count($chunks),
                'chunk_text'   => $chunk->getTextContent(),
                'file_name'    => $metadata['file_name'] ?? '',
                'file_type'    => $metadata['file_type'] ?? '',
                'file_size'    => $metadata['file_size'] ?? 0,
                'owner'        => $chunk->getOwner(),
                'organisation' => $chunk->getOrganisation(),
                'language'     => $chunk->getLanguage(),
                'created_at'   => $chunk->getCreatedAt()?->format('c') ?? date('c'),
                'updated_at'   => $chunk->getUpdatedAt()?->format('c') ?? date('c'),
            ];
        }

        // Use search backend to index documents.
        $success = $this->searchBackend->index($documents);

        if ($success === true) {
            $indexedCount = count($documents);
        } else {
            $indexedCount = 0;
        }

        // Note: Collection name is set above based on backend type.
        // Returning it in the result for API consistency.
        return [
            'success'    => $success,
            'indexed'    => $indexedCount,
            'collection' => $collection,
        ];
    }//end indexFileChunks()

    /**
     * Get statistics for files in Solr.
     *
     * @return array Statistics including document count, collection info
     *
     * @psalm-return array{available: bool, collection?: string, document_count?: int, error?: string}
     */
    public function getFileStats(): array
    {
        try {
            // Get document count from search backend (backend handles collection selection).
            $searchResults = $this->searchBackend->search(
                [
                    'q'    => '*:*',
                    'rows' => 0,
                ]
            );

            $documentCount = $searchResults['response']['numFound'] ?? 0;

            // Note: Collection name is determined earlier based on backend type.
            return [
                'available'      => true,
                'collection'     => $collection,
                'document_count' => $documentCount,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                '[FileHandler] Failed to get file stats',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'available' => false,
                'error'     => $e->getMessage(),
            ];
        }//end try
    }//end getFileStats()

    /**
     * Process and index chunks for unindexed files.
     *
     * Reads chunks from database that have indexed=false and indexes them to Solr.
     * Chunks are created by TextExtractionService in a separate flow.
     * This method only reads and indexes existing chunks - does NOT extract text.
     *
     * @param int|null $limit Maximum number of files to process
     *
     * @return ((float|int|string[])[]|true)[]
     *
     * @psalm-return array{success: true,
     *     stats: array{processed: 0|1|2, indexed: 0|1|2, failed: int,
     *     total_chunks: int, errors: list<non-empty-string>,
     *     execution_time_ms: float}}
     */
    public function processUnindexedChunks(?int $limit=null): array
    {
        $this->logger->info(
            '[FileHandler] Starting chunk indexing',
            [
                'limit' => $limit,
            ]
        );

        $startTime = microtime(true);
        $stats     = [
            'processed'    => 0,
            'indexed'      => 0,
            'failed'       => 0,
            'total_chunks' => 0,
            'errors'       => [],
        ];

        // Get chunks that haven't been indexed yet.
        $unindexedChunks = $this->chunkMapper->findUnindexed(limit: $limit);

        // Group chunks by file_id.
        $chunksByFile = [];
        foreach ($unindexedChunks as $chunk) {
            $fileId = $chunk->getSourceId();
            if (isset($chunksByFile[$fileId]) === false) {
                $chunksByFile[$fileId] = [];
            }

            $chunksByFile[$fileId][] = $chunk;
        }

        // Process each file's chunks.
        foreach ($chunksByFile as $fileId => $chunks) {
            try {
                $stats['processed']++;

                // Prepare metadata.
                $metadata = [
                    'file_name' => "File {$fileId}",
                    'file_type' => 'unknown',
                    'file_size' => 0,
                ];

                // Index the chunks.
                $result = $this->indexFileChunks(fileId: $fileId, chunks: $chunks, metadata: $metadata);

                if ($result['success'] === true) {
                    $stats['indexed']++;
                    $stats['total_chunks'] += $result['indexed'];

                    // Mark chunks as indexed.
                    foreach ($chunks as $chunk) {
                        $chunk->setIndexed(true);
                        $this->chunkMapper->update($chunk);
                    }
                } else {
                    $stats['failed']++;
                    $stats['errors'][] = "Failed to index file {$fileId}";
                }
            } catch (Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = "File {$fileId}: ".$e->getMessage();
                $this->logger->error(
                    '[FileHandler] Failed to process file chunks',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        $stats['execution_time_ms'] = $executionTime;

        $this->logger->info(
            '[FileHandler] Chunk indexing complete',
            [
                'stats' => $stats,
            ]
        );

        return [
            'success' => true,
            'stats'   => $stats,
        ];
    }//end processUnindexedChunks()

    /**
     * Get chunking statistics.
     *
     * @return array Chunking statistics
     *
     * @psalm-return array{total_chunks: int, indexed_chunks: int,
     *     unindexed_chunks: int, vectorized_chunks: int}
     */
    public function getChunkingStats(): array
    {
        $totalChunks      = $this->chunkMapper->countAll();
        $indexedChunks    = $this->chunkMapper->countIndexed();
        $unindexedChunks  = $this->chunkMapper->countUnindexed();
        $vectorizedChunks = $this->chunkMapper->countVectorized();

        return [
            'total_chunks'      => $totalChunks,
            'indexed_chunks'    => $indexedChunks,
            'unindexed_chunks'  => $unindexedChunks,
            'vectorized_chunks' => $vectorizedChunks,
        ];
    }//end getChunkingStats()

    /**
     * Index files by their IDs.
     *
     * This method indexes file chunks into the search backend.
     *
     * @param array       $fileIds        Array of file IDs to index.
     * @param string|null $collectionName Optional collection name.
     *
     * @return array Indexing results.
     */
    public function indexFiles(array $fileIds, ?string $collectionName=null): array
    {
        $this->logger->info(
            '[FileHandler] Indexing files',
            [
                'count'      => count($fileIds),
                'collection' => $collectionName,
            ]
        );

        try {
            // Delegate to search backend.
            return $this->searchBackend->indexFiles($fileIds, $collectionName);
        } catch (Exception $e) {
            $this->logger->error(
                '[FileHandler] Failed to index files',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try
    }//end indexFiles()

    /**
     * Get file indexing statistics.
     *
     * Returns statistics about indexed files.
     *
     * @return array File indexing statistics.
     */
    public function getFileIndexStats(): array
    {
        try {
            /*
             * Delegate to search backend.
             * @psalm-suppress UndefinedInterfaceMethod - getFileIndexStats may exist on specific backend implementations
             */

            return $this->searchBackend->getFileIndexStats();
        } catch (Exception $e) {
            $this->logger->error(
                '[FileHandler] Failed to get file index stats',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try
    }//end getFileIndexStats()
}//end class
