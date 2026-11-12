<?php

/**
 * File Vectorization Strategy
 *
 * Strategy for vectorizing file chunks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */

namespace OCA\OpenRegister\Service\Vectorization\Strategies;

use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
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
class FileVectorizationStrategy implements FileVectorizationStrategyInterface {

	/**
	 * Constructor
	 *
	 * @param ChunkMapperInterface $chunkMapper Chunk mapper
	 * @param IDBConnection $db Database connection
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ChunkMapperInterface $chunkMapper,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Fetch file chunks for vectorization
	 *
	 * @param array $options Options: max_files, file_types
	 *
	 * @return \OCA\OpenRegister\Files\Db\Chunk[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Files\Db\Chunk>
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function fetchEntities(array $options): array {
		$maxFiles = (int)($options['max_files'] ?? 0);
		$fileTypes = $options['file_types'] ?? [];

		$this->logFetching(maxFiles: $maxFiles, fileTypes: $fileTypes);

		/*
		 * @psalm-suppress InaccessibleMethod - findEntities is accessible via inheritance
		 */

		$allChunks = $this->chunkMapper->findEntitiesPublic($this->fileChunkQuery(maxFiles: $maxFiles));

		return self::chunksOfFirstFiles(chunks: $allChunks, maxFiles: $maxFiles);
	}//end fetchEntities()

	/**
	 * Log the start of a fetch.
	 *
	 * @param int   $maxFiles  The maximum number of files, 0 for all
	 * @param mixed $fileTypes The file types asked for
	 *
	 * @return void
	 */
	private function logFetching(int $maxFiles, mixed $fileTypes): void {
		$this->logger->debug(
			message: '[FileVectorizationStrategy] Fetching file chunks',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'maxFiles' => $maxFiles,
				'fileTypes' => $fileTypes,
			]
		);
	}//end logFetching()

	/**
	 * The query for every file chunk, in file and chunk order.
	 *
	 * @param int $maxFiles The maximum number of files, 0 for all
	 *
	 * @return IQueryBuilder The query.
	 */
	private function fileChunkQuery(int $maxFiles): IQueryBuilder {
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

		return $qb;
	}//end fileChunkQuery()

	/**
	 * Keep the chunks of the first files, stopping at the first chunk of one file too many.
	 *
	 * @param array $chunks   The chunks, ordered by file
	 * @param int   $maxFiles The maximum number of files, 0 for all
	 *
	 * @return array The chunks kept.
	 */
	private static function chunksOfFirstFiles(array $chunks, int $maxFiles): array {
		// Group by source_id and apply max_files limit.
		$uniqueFiles = [];
		$fileChunks = [];

		foreach ($chunks as $chunk) {
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
	}//end chunksOfFirstFiles()

	/**
	 * Extract chunks from file
	 *
	 * @param mixed $entity Chunk entity
	 *
	 * @return ((int|string)|mixed|null)[][] Array of items with 'text' and chunk data
	 *
	 * @psalm-return list<array{end_offset: mixed|null, index: array-key, start_offset: mixed|null, text: mixed}>
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function extractVectorizationItems($entity): array {
		// Entity is already a chunk, return it as a single item.
		return [
			[
				'text' => $entity->getTextContent(),
				'index' => $entity->getChunkIndex(),
				'start_offset' => $entity->getStartOffset(),
				'end_offset' => $entity->getEndOffset(),
			],
		];
	}//end extractVectorizationItems()

	/**
	 * Prepare metadata for file chunk vector
	 *
	 * @param mixed $entity Chunk entity
	 * @param array $item Chunk item
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
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function prepareVectorMetadata($entity, array $item): array {
		// Get total chunks for this source.
		$sourceChunks = $this->chunkMapper->findBySource('file', $entity->getSourceId());
		$totalChunks = count($sourceChunks);

		return [
			'entity_type' => 'file',
			'entity_id' => (string)$entity->getSourceId(),
			'chunk_index' => $item['index'],
			'total_chunks' => $totalChunks,
			'chunk_text' => substr($item['text'], 0, 500),
			// Preview.
			'additional_metadata' => self::chunkPlacement(entity: $entity, item: $item),
		];
	}//end prepareVectorMetadata()

	/**
	 * Where the chunk sits: its source file and its offsets in that file.
	 *
	 * @param mixed $entity Chunk entity
	 * @param array $item   Chunk item
	 *
	 * @return array The placement metadata.
	 */
	private static function chunkPlacement($entity, array $item): array {
		return [
			'source_id' => $entity->getSourceId(),
			'start_offset' => $item['start_offset'],
			'end_offset' => $item['end_offset'],
		];
	}//end chunkPlacement()

	/**
	 * Get file ID as identifier
	 *
	 * @param mixed $entity Chunk entity
	 *
	 * @return string|int Source ID (file ID)
	 */
	public function getEntityIdentifier($entity) {
		return $entity->getSourceId();
	}//end getEntityIdentifier()
}//end class
