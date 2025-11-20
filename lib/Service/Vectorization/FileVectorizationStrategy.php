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

namespace OCA\OpenRegister\Service\Vectorization;

use OCA\OpenRegister\Db\FileTextMapper;
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
     * File text mapper
     *
     * @var FileTextMapper
     */
    private FileTextMapper $fileTextMapper;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param FileTextMapper  $fileTextMapper File text mapper
     * @param LoggerInterface $logger         Logger
     */
    public function __construct(
        FileTextMapper $fileTextMapper,
        LoggerInterface $logger
    ) {
        $this->fileTextMapper = $fileTextMapper;
        $this->logger         = $logger;

    }//end __construct()


    /**
     * Fetch files with completed extractions and chunks
     *
     * @param array $options Options: max_files, file_types
     *
     * @return array Array of FileText entities
     */
    public function fetchEntities(array $options): array
    {
        $maxFiles  = (int) ($options['max_files'] ?? 0);
        $fileTypes = $options['file_types'] ?? [];

        $this->logger->debug(
                '[FileVectorizationStrategy] Fetching files',
                [
                    'maxFiles'  => $maxFiles,
                    'fileTypes' => $fileTypes,
                ]
                );

        // Get files with completed extraction
        $files = $this->fileTextMapper->findByStatus('completed', $maxFiles > 0 ? $maxFiles : 1000);

        // Filter by file types if specified
        if (!empty($fileTypes)) {
            $files = array_filter(
                    $files,
                    function ($file) use ($fileTypes) {
                        return in_array($file->getMimeType(), $fileTypes);
                    }
                    );
        }

        // Filter files that have chunks
        $files = array_filter(
                $files,
                function ($file) {
                    return $file->getChunked() && $file->getChunkCount() > 0;
                }
                );

        // Apply max files limit
        if ($maxFiles > 0 && count($files) > $maxFiles) {
            $files = array_slice($files, 0, $maxFiles);
        }

        return array_values($files);

    }//end fetchEntities()


    /**
     * Extract chunks from file
     *
     * @param mixed $entity FileText entity
     *
     * @return array Array of items with 'text' and chunk data
     */
    public function extractVectorizationItems($entity): array
    {
        $chunksJson = $entity->getChunksJson();

        if (empty($chunksJson)) {
            $this->logger->warning(
                    '[FileVectorizationStrategy] No chunks JSON found',
                    [
                        'fileId' => $entity->getFileId(),
                    ]
                    );
            return [];
        }

        $chunks = json_decode($chunksJson, true);

        if (!is_array($chunks)) {
            $this->logger->error(
                    '[FileVectorizationStrategy] Invalid chunks data',
                    [
                        'fileId' => $entity->getFileId(),
                    ]
                    );
            return [];
        }

        // Add index to each chunk for tracking
        $items = [];
        foreach ($chunks as $index => $chunk) {
            $items[] = [
                'text'         => $chunk['text'],
                'index'        => $index,
                'start_offset' => $chunk['start_offset'] ?? null,
                'end_offset'   => $chunk['end_offset'] ?? null,
            ];
        }

        return $items;

    }//end extractVectorizationItems()


    /**
     * Prepare metadata for file chunk vector
     *
     * @param mixed $entity FileText entity
     * @param array $item   Chunk item
     *
     * @return array Metadata for storage
     */
    public function prepareVectorMetadata($entity, array $item): array
    {
        $chunks      = json_decode($entity->getChunksJson(), true);
        $totalChunks = is_array($chunks) ? count($chunks) : 1;

        return [
            'entity_type'         => 'file',
            'entity_id'           => (string) $entity->getFileId(),
            'chunk_index'         => $item['index'],
            'total_chunks'        => $totalChunks,
            'chunk_text'          => substr($item['text'], 0, 500),
        // Preview
            'additional_metadata' => [
                'file_id'      => $entity->getFileId(),
                'file_name'    => $entity->getFileName(),
                'file_path'    => $entity->getFilePath(),
                'mime_type'    => $entity->getMimeType(),
                'start_offset' => $item['start_offset'],
                'end_offset'   => $item['end_offset'],
            ],
        ];

    }//end prepareVectorMetadata()


    /**
     * Get file ID as identifier
     *
     * @param mixed $entity FileText entity
     *
     * @return string|int File ID
     */
    public function getEntityIdentifier($entity)
    {
        return $entity->getFileId();

    }//end getEntityIdentifier()


}//end class
