<?php

/**
 * A hardening floor and the direction in which it holds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * A floor and its comparator: `atLeast` (a higher value is stronger) or
 * `atMost` (a lower value is stronger) (ADR-011 Rule 2).
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */
final class FloorRule {
	/**
	 * Hold the rule.
	 *
	 * @param string $comparator `atLeast` or `atMost`.
	 * @param int    $floor      The floor.
	 */
	public function __construct(
		public readonly string $comparator,
		public readonly int $floor,
	) {
	}//end __construct()

	/**
	 * Whether a value is at or above the floor's strength.
	 *
	 * The one place the direction of "stronger" is decided. Every caller that
	 * decides it again is a caller that can decide it the other way round.
	 *
	 * @param int $value The value to judge.
	 *
	 * @return bool True when the value is at or above the floor's strength.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function admits(int $value): bool {
		if ($this->comparator === 'atMost') {
			return $value <= $this->floor;
		}

		return $value >= $this->floor;
	}//end admits()
}//end class
