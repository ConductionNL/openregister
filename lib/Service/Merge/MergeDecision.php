<?php

/**
 * OpenRegister MergeDecision
 *
 * A reviewer's per-property decision on one merge.
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
 * The decision map a reviewer approved (`property => 'from' | 'into'`), who
 * decided it and why: the author and rationale every resolver override
 * derived from the map carries.
 *
 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
 */
final readonly class MergeDecision {

	/**
	 * Bundle the decision.
	 *
	 * @param array<string, mixed> $decisions The reviewer's map: property => 'from' | 'into'.
	 * @param string               $decidedBy Acting uid, recorded as each override's author.
	 * @param string               $reason    The merge reason, recorded as each override's rationale.
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
	 */
	public function __construct(
		public array $decisions,
		public string $decidedBy,
		public string $reason,
	) {
	}//end __construct()
}//end class
