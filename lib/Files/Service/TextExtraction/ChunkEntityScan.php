<?php

/**
 * ChunkEntityScan: the chunk a set of detected entities is stored against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Db\Chunk;

/**
 * The chunk, its text and how its entities were found, for storing relations.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class ChunkEntityScan {
	/**
	 * Bundle the scan facts.
	 *
	 * @param Chunk  $chunk         The chunk the entities were extracted from.
	 * @param string $text          The full text content of the chunk.
	 * @param string $method        The detection method used.
	 * @param int    $contextWindow Characters of context to extract around each entity.
	 */
	public function __construct(
		public Chunk $chunk,
		public string $text,
		public string $method,
		public int $contextWindow,
	) {
	}//end __construct()
}//end class
