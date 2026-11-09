<?php

/**
 * Chunk Vector Source
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
final class ChunkVectorSource {
	/**
	 * Constructor.
	 *
	 * @param string|null $sourceType  The type of the chunk's source.
	 * @param int|null    $sourceId    The id of the chunk's source.
	 * @param int         $chunkIndex  The chunk's place among its source's chunks.
	 * @param string|null $text        The chunk's text.
	 * @param int         $startOffset Where the chunk starts in its source.
	 * @param int         $endOffset   Where the chunk ends in its source.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		public readonly ?string $sourceType,
		public readonly ?int $sourceId,
		public readonly int $chunkIndex,
		public readonly ?string $text,
		public readonly int $startOffset,
		public readonly int $endOffset,
	) {
	}//end __construct()
}//end class
