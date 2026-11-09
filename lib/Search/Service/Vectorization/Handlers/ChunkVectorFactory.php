<?php

/**
 * OpenRegister Chunk Vector Factory
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

/**
 * Builds the vector row of one embedded text chunk.
 *
 * The text kept with the vector is the chunk's first 500 characters; a source counts at least one chunk.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class ChunkVectorFactory implements ChunkVectorFactoryInterface {
	/**
	 * The vector row of a chunk: its source, its embedding and its place among its source's chunks.
	 *
	 * @param ChunkVectorSourceInterface $source        The chunk's source, place and text.
	 * @param array<string, mixed> $embeddingData Its embedding, model and dimensions.
	 * @param int                  $totalChunks   How many chunks its source has.
	 *
	 * @return StoredVector
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function forChunk(ChunkVectorSourceInterface $source, array $embeddingData, int $totalChunks): StoredVector {
		return new StoredVector(
			subject: new VectorSubject(entityType: (string)$source->sourceType(), entityId: (string)$source->sourceId()),
			payload: $this->payload(embeddingData: $embeddingData),
			position: $this->position(source: $source, totalChunks: $totalChunks),
			metadata: $this->provenance(source: $source)
		);
	}//end forChunk()

	/**
	 * The embedding, model and dimensions of a chunk.
	 *
	 * @param array<string, mixed> $embeddingData Its embedding, model and dimensions.
	 *
	 * @return VectorPayload
	 */
	private function payload(array $embeddingData): VectorPayload {
		return new VectorPayload(
			embedding: $embeddingData['embedding'],
			model: (string)$embeddingData['model'],
			dimensions: (int)$embeddingData['dimensions']
		);
	}//end payload()

	/**
	 * A chunk's place among its source's chunks, with the first 500 characters of its text.
	 *
	 * @param ChunkVectorSourceInterface $source      The chunk's source, place and text.
	 * @param int                        $totalChunks How many chunks its source has; at least one is counted.
	 *
	 * @return VectorChunkPosition
	 */
	private function position(ChunkVectorSourceInterface $source, int $totalChunks): VectorChunkPosition {
		return new VectorChunkPosition(
			chunkIndex: $source->chunkIndex(),
			totalChunks: max(1, $totalChunks),
			chunkText: substr($source->text(), 0, 500)
		);
	}//end position()

	/**
	 * Where in its source a chunk came from.
	 *
	 * @param ChunkVectorSourceInterface $source The chunk's source, place and text.
	 *
	 * @return array<string, mixed>
	 */
	private function provenance(ChunkVectorSourceInterface $source): array {
		return [
			'source_id' => $source->sourceId(),
			'start_offset' => $source->startOffset(),
			'end_offset' => $source->endOffset(),
		];
	}//end provenance()
}//end class
