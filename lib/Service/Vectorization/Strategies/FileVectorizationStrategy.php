<?php
/**
 * File Vectorization Strategy
 *
 * Strategy for vectorizing file chunks.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Vectorization\Strategies;

use OCA\OpenRegister\Db\ChunkMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * FileVectorizationStrategy
 *
 * Handles file-specific vectorization logic.
 *
 * OPTIONS:
 * - max_files: int - Maximum number of files to process (0 = all)
 * - file_types: array - MIME types to filter (empty = all types)
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 */
class FileVectorizationStrategy implements VectorizationStrategyInterface
{

    /**
     * Chunk mapper
     *
     * @var ChunkMapper
     */
    private ChunkMapper $chunkMapper;

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param ChunkMapper     $chunkMapper Chunk mapper
     * @param IDBConnection   $db          Database connection
     * @param LoggerInterface $logger      Logger
     */
    public function __construct(
        ChunkMapper $chunkMapper,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->chunkMapper = $chunkMapper;
        $this->db          = $db;
        $this->logger      = $logger;

    }//end __construct()


    /**
     * Fetch file chunks for vectorization
     *
     * @param array $options Options: max_files, file_types
     *
     * @return \OCA\OpenRegister\Db\Chunk[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Chunk>
     */
    public function fetchEntities(array $options): array
    {
        $maxFiles  = (int) ($options['max_files'] ?? 0);
        $fileTypes = $options['file_types'] ?? [];

        $this->logger->debug(
                '[FileVectorizationStrategy] Fetching file chunks',
                [
                    'maxFiles'  => $maxFiles,
                    'fileTypes' => $fileTypes,
                ]
                );

        // Get all chunks for files (source_type = 'file').
        // We'll need to query by source_type only.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_chunks')
            ->where($qb->expr()->eq('source_type', $qb->createNamedParameter('file', IQueryBuilder::PARAM_STR)))
            ->orderBy('source_id', 'ASC')
            ->addOrderBy('chunk_index', 'ASC');

        if ($maxFiles > 0) {
            $qb->setMaxResults($maxFiles * 100);
        }

        /*
         * @psalm-suppress InaccessibleMethod - findEntities is accessible via inheritance
         */
        $allChunks = $this->chunkMapper->findEntities($qb);

        // Group by source_id and apply max_files limit.
        $uniqueFiles = [];
        $fileChunks  = [];

        foreach ($allChunks as $chunk) {
            $sourceId = $chunk->getSourceId();
            if (isset($uniqueFiles[$sourceId]) === false) {
                $uniqueFiles[$sourceId] = true;
                if ($maxFiles > 0 && count($uniqueFiles) > $maxFiles) {
                    break;
                }
            }

            $fileChunks[] = $chunk;
        }

        return $fileChunks;

    }//end fetchEntities()


    /**
     * Extract chunks from file
     *
     * @param mixed $entity Chunk entity
     *
     * @return ((int|string)|mixed|null)[][] Array of items with 'text' and chunk data
     *
     * @psalm-return list<array{end_offset: mixed|null, index: array-key, start_offset: mixed|null, text: mixed}>
     */
    public function extractVectorizationItems($entity): array
    {
        // Entity is already a chunk, return it as a single item.
        return [
            [
                'text'         => $entity->getTextContent(),
                'index'        => $entity->getChunkIndex(),
                'start_offset' => $entity->getStartOffset(),
                'end_offset'   => $entity->getEndOffset(),
            ],
        ];

    }//end extractVectorizationItems()


    /**
     * Prepare metadata for file chunk vector
     *
     * @param mixed $entity Chunk entity
     * @param array $item   Chunk item
     *
     * @return (array|int|mixed|string)[] Metadata for storage
     *
     * @psalm-return array{
     *     entity_type: 'file',
     *     entity_id: string,
     *     chunk_index: mixed,
     *     total_chunks: int<0, max>,
     *     chunk_text: string,
     *     additional_metadata: array{
     *         source_id: mixed,
     *         start_offset: mixed,
     *         end_offset: mixed
     *     }
     * }
     */
    public function prepareVectorMetadata($entity, array $item): array
    {
        // Get total chunks for this source.
        $sourceChunks = $this->chunkMapper->findBySource('file', $entity->getSourceId());
        $totalChunks  = count($sourceChunks);

        return [
            'entity_type'         => 'file',
            'entity_id'           => (string) $entity->getSourceId(),
            'chunk_index'         => $item['index'],
            'total_chunks'        => $totalChunks,
            'chunk_text'          => substr($item['text'], 0, 500),
        // Preview.
            'additional_metadata' => [
                'source_id'    => $entity->getSourceId(),
                'start_offset' => $item['start_offset'],
                'end_offset'   => $item['end_offset'],
            ],
        ];

    }//end prepareVectorMetadata()


    /**
     * Get file ID as identifier
     *
     * @param mixed $entity Chunk entity
     *
     * @return string|int Source ID (file ID)
     */
    public function getEntityIdentifier($entity)
    {
        return $entity->getSourceId();

    }//end getEntityIdentifier()


}//end class
