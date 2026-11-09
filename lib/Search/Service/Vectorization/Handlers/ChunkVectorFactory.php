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
	 * @param string|null          $sourceType    The type of the chunk's source.
	 * @param int|null             $sourceId      The id of the chunk's source.
	 * @param int                  $chunkIndex    The chunk's place among its source's chunks.
	 * @param string|null          $text          The chunk's text.
	 * @param int                  $startOffset   Where the chunk starts in its source.
	 * @param int                  $endOffset     Where the chunk ends in its source.
	 * @param array<string, mixed> $embeddingData Its embedding, model and dimensions.
	 * @param int                  $totalChunks   How many chunks its source has.
	 *
	 * @return StoredVector
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function forChunk(
		?string $sourceType,
		?int $sourceId,
		int $chunkIndex,
		?string $text,
		int $startOffset,
		int $endOffset,
		array $embeddingData,
		int $totalChunks
	): StoredVector {
		return new StoredVector(
			subject: new VectorSubject(entityType: (string)$sourceType, entityId: (string)$sourceId),
			payload: new VectorPayload(
				embedding: $embeddingData['embedding'],
				model: (string)$embeddingData['model'],
				dimensions: (int)$embeddingData['dimensions']
			),
			position: new VectorChunkPosition(
				chunkIndex: $chunkIndex,
				totalChunks: max(1, $totalChunks),
				chunkText: substr((string)$text, 0, 500)
			),
			metadata: [
				'source_id' => $sourceId,
				'start_offset' => $startOffset,
				'end_offset' => $endOffset,
			]
		);
	}//end forChunk()
}//end class
