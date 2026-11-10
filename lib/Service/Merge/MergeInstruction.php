<?php

/**
 * OpenRegister MergeInstruction
 *
 * Which object merges into which, why and on whose authority.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

/**
 * One steward's instruction to merge an object into a survivor.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
 */
final readonly class MergeInstruction {

	/**
	 * Bundle the instruction.
	 *
	 * @param string $from     Uuid of the object being merged away.
	 * @param string $into     Uuid of the surviving object.
	 * @param string $reason   Steward-supplied merge reason.
	 * @param string $mergedBy Acting user uid.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
	 */
	public function __construct(
		public string $from,
		public string $into,
		public string $reason,
		public string $mergedBy,
	) {
	}//end __construct()
}//end class
