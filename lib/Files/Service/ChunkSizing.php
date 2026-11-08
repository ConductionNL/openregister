<?php

/**
 * ChunkSizing: the target size and overlap text is chunked with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service
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

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * Target chunk size and the overlap between consecutive chunks.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class ChunkSizing {
	/**
	 * Bundle the sizing.
	 *
	 * @param int $size    Target chunk size.
	 * @param int $overlap Overlap size.
	 */
	public function __construct(
		public int $size,
		public int $overlap,
	) {
	}//end __construct()
}//end class
