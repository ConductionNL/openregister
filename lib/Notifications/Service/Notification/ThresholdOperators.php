<?php

/**
 * ThresholdOperators: the comparison operators of a notification trigger.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Evaluates plain comparison operators against a value.
 *
 * Operators mirror the JSON-schema style used by the notification spec: `lt`,
 * `lte`, `gt`, `gte`, `eq`, `ne`. Ordering is numeric, so a non-numeric side
 * fails every ordering operator; an unknown operator never holds.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ThresholdOperators {

	/**
	 * Whether a value satisfies every declared operator.
	 *
	 * @param mixed                $value     The field value to test.
	 * @param array<string, mixed> $operators Map of operator to threshold (e.g. `['lt' => 0.85]`).
	 *
	 * @return bool
	 */
	public function allHold(mixed $value, array $operators): bool {
		foreach ($operators as $op => $threshold) {
			if ($this->holds(op: (string) $op, sides: [$value, $threshold]) === false) {
				return false;
			}
		}

		return true;
	}//end allHold()

	/**
	 * Whether one operator holds between a value and its threshold.
	 *
	 * @param string              $op    The operator.
	 * @param array{mixed, mixed} $sides The value and the threshold.
	 *
	 * @return bool
	 */
	private function holds(string $op, array $sides): bool {
		$equals = $this->sidesEqual(sides: $sides);

		return match ($op) {
			'eq' => $equals,
			'ne' => ($equals === false),
			default => $this->orderingHolds(op: $op, sides: $sides),
		};
	}//end holds()

	/**
	 * Whether both sides are equal: as floats when both are numeric, so that
	 * `1.0` eq `1`, and as strings otherwise.
	 *
	 * @param array{mixed, mixed} $sides The value and the threshold.
	 *
	 * @return bool
	 */
	private function sidesEqual(array $sides): bool {
		if ($this->bothNumeric(sides: $sides) === true) {
			return (float) $sides[0] === (float) $sides[1];
		}

		return (string) $sides[0] === (string) $sides[1];
	}//end sidesEqual()

	/**
	 * Whether an ordering operator holds.
	 *
	 * @param string              $op    The operator.
	 * @param array{mixed, mixed} $sides The value and the threshold.
	 *
	 * @return bool
	 */
	private function orderingHolds(string $op, array $sides): bool {
		if ($this->bothNumeric(sides: $sides) === false) {
			return false;
		}

		[$value, $threshold] = array_map('floatval', $sides);

		return match ($op) {
			'lt' => $value < $threshold,
			'lte' => $value <= $threshold,
			'gt' => $value > $threshold,
			'gte' => $value >= $threshold,
			default => false,
		};
	}//end orderingHolds()

	/**
	 * Whether value and threshold are both numeric.
	 *
	 * @param array{mixed, mixed} $sides The value and the threshold.
	 *
	 * @return bool
	 */
	private function bothNumeric(array $sides): bool {
		return is_numeric($sides[0]) === true && is_numeric($sides[1]) === true;
	}//end bothNumeric()
}//end class
