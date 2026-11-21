<?php

/**
 * OpenRegister ChunkSearch.
 *
 * One search for an operator-supplied value across a file's text chunks:
 * the chunks, the request carrying the value and its match options, and
 * the configured chunk overlap.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Files\Db\Chunk;

/**
 * One search for a value across a file's chunks.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class ChunkSearch {
	/**
	 * Constructor.
	 *
	 * @param Chunk[]             $chunks       Chunks for the target file, ordered by chunkIndex.
	 * @param ManualEntityRequest $request      The value and its whole-word and case-sensitive options.
	 * @param int                 $chunkOverlap Configured chunk overlap (chars).
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly array $chunks,
		public readonly ManualEntityRequest $request,
		public readonly int $chunkOverlap,
	) {
	}//end __construct()
}//end class
