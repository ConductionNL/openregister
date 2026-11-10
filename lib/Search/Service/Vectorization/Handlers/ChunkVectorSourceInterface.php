<?php

/**
 * Chunk Vector Source Interface
 *
 * The values of one text chunk that its vector row is built from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * A chunk's source, place and text, as ChunkVectorFactoryInterface::forChunk() reads them.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
interface ChunkVectorSourceInterface {
	/**
	 * The type of the chunk's source.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function sourceType(): string;

	/**
	 * The id of the chunk's source.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function sourceId(): int;

	/**
	 * The chunk's place among its source's chunks.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function chunkIndex(): int;

	/**
	 * The chunk's text; empty when it has none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function text(): string;

	/**
	 * Where the chunk starts in its source.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function startOffset(): int;

	/**
	 * Where the chunk ends in its source.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function endOffset(): int;
}//end interface
