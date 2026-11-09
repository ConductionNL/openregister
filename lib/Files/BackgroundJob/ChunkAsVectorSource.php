<?php

/**
 * A stored text chunk read as the source of its vector row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Files\BackgroundJob
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

namespace OCA\OpenRegister\Files\BackgroundJob;

use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\ChunkVectorSourceInterface;

/**
 * Hands a chunk's source, place and text to ChunkVectorFactoryInterface::forChunk().
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class ChunkAsVectorSource implements ChunkVectorSourceInterface {
	/**
	 * Constructor.
	 *
	 * @param Chunk $chunk The stored chunk.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		private readonly Chunk $chunk,
	) {
	}//end __construct()

	/**
	 * The type of the chunk's source.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function sourceType(): string {
		return $this->chunk->getSourceType();
	}//end sourceType()

	/**
	 * The id of the chunk's source.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function sourceId(): int {
		return $this->chunk->getSourceId();
	}//end sourceId()

	/**
	 * The chunk's place among its source's chunks.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function chunkIndex(): int {
		return $this->chunk->getChunkIndex();
	}//end chunkIndex()

	/**
	 * The chunk's text; empty when it has none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function text(): string {
		return (string)$this->chunk->getTextContent();
	}//end text()

	/**
	 * Where the chunk starts in its source.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function startOffset(): int {
		return $this->chunk->getStartOffset();
	}//end startOffset()

	/**
	 * Where the chunk ends in its source.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function endOffset(): int {
		return $this->chunk->getEndOffset();
	}//end endOffset()
}//end class
