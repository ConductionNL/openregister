<?php

/**
 * OpenRegister Chunk Vector Factory Interface
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

use OCA\OpenRegister\Files\Db\Chunk;

/**
 * Builds the vector row of one embedded text chunk, for VectorStorageHandlerInterface::storeVector().
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
interface ChunkVectorFactoryInterface {
	/**
	 * The vector row of a chunk: its source, its embedding and its place among its source's chunks.
	 *
	 * @param Chunk                $chunk         The chunk.
	 * @param array<string, mixed> $embeddingData Its embedding, model and dimensions.
	 * @param int                  $totalChunks   How many chunks its source has.
	 *
	 * @return StoredVector
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function forChunk(Chunk $chunk, array $embeddingData, int $totalChunks): StoredVector;
}//end interface
