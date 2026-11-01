<?php

/**
 * One batch of unvectorized chunks embedded and stored by ChunkVectorizationJob.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\Vectorization\Handlers\VectorStorageHandler;
use OCA\OpenRegister\Kernel\Contracts\EmbeddingGeneratorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Embeds one batch of unvectorized chunks, stores their vectors and marks them vectorized
 *
 * A single chunk's embedding or storage failure is logged and skipped; the
 * rest of the batch continues, and the failed chunk stays `vectorized = false`
 * for retry on the next scheduled run.
 */
final class ChunkVectorizationBatch {
	/**
	 * Maximum chunks to embed per run (bounded provider calls)
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Chunk mapper
	 *
	 * @var ChunkMapper
	 */
	private readonly ChunkMapper $chunkMapper;

	/**
	 * Embedding coordinator
	 *
	 * @var EmbeddingGeneratorInterface
	 */
	private readonly EmbeddingGeneratorInterface $embeddings;

	/**
	 * Vector storage handler
	 *
	 * @var VectorStorageHandler
	 */
	private readonly VectorStorageHandler $storageHandler;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Chunk count per source (`<type>_<id>`), looked up once per batch
	 *
	 * @var array<string, int>
	 */
	private array $totalChunks = [];

	/**
	 * Constructor
	 *
	 * @param ContainerInterface $container App container the collaborators are resolved from
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	public function __construct(ContainerInterface $container) {
		$this->chunkMapper    = $container->get(ChunkMapper::class);
		$this->embeddings     = $container->get(EmbeddingGeneratorInterface::class);
		$this->storageHandler = $container->get(VectorStorageHandler::class);
		$this->logger         = $container->get(LoggerInterface::class);
	}//end __construct()

	/**
	 * Vectorize a batch of unvectorized chunks.
	 *
	 * @return array{0: int, 1: int} [vectorized count, failed count]
	 *
	 * @throws Exception When the batch-embedding call itself fails (e.g. no provider configured)
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	public function vectorize(): array {
		$chunks = $this->chunkMapper->findUnvectorized(limit: self::BATCH_SIZE);

		if ($chunks === []) {
			return [0, 0];
		}

		$texts = array_map(
			static fn (Chunk $chunk): string => (string)$chunk->getTextContent(),
			$chunks
		);

		// The batch-embedding call tolerates per-text failures (null embedding
		// entries) but throws when the provider itself is unavailable.
		$embeddingResults = $this->embeddings->generateBatchEmbeddings($texts);

		$vectorized = 0;
		foreach ($chunks as $index => $chunk) {
			if ($this->vectorizeChunk(chunk: $chunk, embeddingData: $embeddingResults[$index] ?? null) === true) {
				$vectorized++;
			}
		}

		return [$vectorized, count($chunks) - $vectorized];
	}//end vectorize()

	/**
	 * Store one chunk's embedding and mark the chunk vectorized.
	 *
	 * @param Chunk                     $chunk         The chunk
	 * @param array<string, mixed>|null $embeddingData Its entry from the batch-embedding result
	 *
	 * @return bool Whether the chunk was vectorized
	 */
	private function vectorizeChunk(Chunk $chunk, ?array $embeddingData): bool {
		if ($embeddingData === null || ($embeddingData['embedding'] ?? null) === null) {
			$this->logger->warning(
				message: '[ChunkVectorizationJob] Embedding failed for chunk, will retry next run',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'chunk_id' => $chunk->getId(),
					'error' => $embeddingData['error'] ?? 'No embedding returned',
				]
			);
			return false;
		}

		try {
			$this->storeVector(chunk: $chunk, embeddingData: $embeddingData);

			$chunk->setVectorized(true);
			$chunk->setUpdatedAt(new DateTime());
			$this->chunkMapper->update($chunk);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ChunkVectorizationJob] Failed to store vector for chunk, will retry next run',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'chunk_id' => $chunk->getId(),
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try
	}//end vectorizeChunk()

	/**
	 * Store the vector of one chunk.
	 *
	 * @param Chunk                $chunk         The chunk
	 * @param array<string, mixed> $embeddingData Its embedding, model and dimensions
	 *
	 * @return void
	 *
	 * @throws Exception When the vector cannot be stored
	 */
	private function storeVector(Chunk $chunk, array $embeddingData): void {
		$this->storageHandler->storeVector(
			entityType: (string)$chunk->getSourceType(),
			entityId: (string)$chunk->getSourceId(),
			embedding: $embeddingData['embedding'],
			model: (string)$embeddingData['model'],
			dimensions: (int)$embeddingData['dimensions'],
			chunkIndex: (int)$chunk->getChunkIndex(),
			totalChunks: max(1, $this->totalChunksOf(chunk: $chunk)),
			chunkText: substr((string)$chunk->getTextContent(), 0, 500),
			metadata: [
				'source_id' => $chunk->getSourceId(),
				'start_offset' => $chunk->getStartOffset(),
				'end_offset' => $chunk->getEndOffset(),
			]
		);
	}//end storeVector()

	/**
	 * Count the chunks of the chunk's source, once per source per batch.
	 *
	 * @param Chunk $chunk The chunk
	 *
	 * @return int
	 */
	private function totalChunksOf(Chunk $chunk): int {
		$sourceKey = $chunk->getSourceType() . '_' . $chunk->getSourceId();
		if (isset($this->totalChunks[$sourceKey]) === false) {
			$this->totalChunks[$sourceKey] = count(
				$this->chunkMapper->findBySource(
					sourceType: (string)$chunk->getSourceType(),
					sourceId: (int)$chunk->getSourceId()
				)
			);
		}

		return $this->totalChunks[$sourceKey];
	}//end totalChunksOf()
}//end class
