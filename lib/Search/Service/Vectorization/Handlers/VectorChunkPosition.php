<?php

/**
 * Vector Chunk Position
 *
 * Where in its entity one vector row's text sits.
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
 * The chunk index, chunk count and embedded text of a stored vector.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class VectorChunkPosition {
	/**
	 * Constructor.
	 *
	 * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks).
	 * @param int         $totalChunks Total number of chunks.
	 * @param string|null $chunkText   The text that was embedded.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		public readonly int $chunkIndex,
		public readonly int $totalChunks,
		public readonly ?string $chunkText,
	) {
	}//end __construct()
}//end class
