<?php

/**
 * FlowStreamAdvance: the branch a completion advances and its budget.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-completions-advance-budget-must-apply-to-the-completing-branch
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The completing branch and how many firings the advance may commit.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-completions-advance-budget-must-apply-to-the-completing-branch
 */
final class FlowStreamAdvance {

	/**
	 * Constructor.
	 *
	 * @param string     $streamId The completing branch.
	 * @param int|string $budget   `0`, `N`, or `"all"`.
	 */
	public function __construct(
		public readonly string $streamId,
		public readonly int|string $budget,
	) {
	}//end __construct()

	/**
	 * The firings the walk may commit: null for `"all"`, otherwise at least zero.
	 *
	 * @return int|null The firings.
	 */
	public function firings(): ?int {
		if ($this->budget === 'all') {
			return null;
		}

		return max(0, (int)$this->budget);
	}//end firings()
}//end class
