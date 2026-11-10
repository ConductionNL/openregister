<?php

/**
 * OpenRegister RateOperands
 *
 * The two resolved operands every rate-schedule operator reads: a quantity (or
 * a unit) and the schedule of bands.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * The quantity and the schedule of `graduated` and `bandRate`, as the
 * evaluator resolved them and before RateSchedule checks their types.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class RateOperands {

	/**
	 * Constructor.
	 *
	 * @param mixed $quantity The quantity (graduated) or the unit (bandRate).
	 * @param mixed $bands    The schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly mixed $quantity,
		public readonly mixed $bands,
	) {
	}//end __construct()

	/**
	 * Whether either operand resolved to null, which makes the operator yield null.
	 *
	 * @return bool True when the quantity or the schedule is null.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function hasNull(): bool {
		return $this->quantity === null || $this->bands === null;
	}//end hasNull()
}//end class
