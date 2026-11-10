<?php

/**
 * OpenRegister ChunkingPlan.
 *
 * How a document is chunked: the strategy and the target chunk sizing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
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

namespace OCA\OpenRegister\Files\Service;

/**
 * How a document is chunked: the strategy and the target chunk sizing.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ChunkingPlan {
	/**
	 * Constructor.
	 *
	 * @param mixed       $strategy The chunking strategy as configured.
	 * @param ChunkSizing $sizing   The target chunk size and overlap.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly mixed $strategy,
		public readonly ChunkSizing $sizing,
	) {
	}//end __construct()
}//end class
