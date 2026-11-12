<?php

/**
 * What a declared SLA must say before a term can be armed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Refuses an SLA declaration that cannot be armed, naming what is wrong.
 *
 * 🔴 NOTHING HERE DEFAULTS. An unknown unit and an unknown roll are REFUSED
 * rather than read as the nearest sensible thing: a mistyped
 * `nextWorkingDay` read as `none` would save, arm and behave like a setting
 * nobody made, on a deadline with legal effect. Keeping the three refusals
 * in one class is what keeps that rule one rule.
 *
 * Separate from {@see SlaCalculator} because they run at different moments:
 * these when a term is DECLARED, the calculator's arithmetic every time one
 * is armed or measured.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */
class SlaDeclaration {

	/**
	 * Validate an SLA of shape `{value, unit}`.
	 *
	 * @param mixed $sla The declared SLA.
	 *
	 * @return array{value: int, unit: string} The normalised SLA.
	 *
	 * @throws FlowTimerValidationException When the shape, the range or the unit is refused.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validateSla(mixed $sla): array {
		$this->refuseUnlessShaped(sla: $sla);

		$value = $this->validValue(value: $sla['value']);

		return [
			'value' => $value,
			'unit' => $this->validateUnit(unit: $sla['unit']),
			'rollToWorkingDay' => $this->validateRoll(roll: ($sla['rollToWorkingDay'] ?? SlaCalculatorInterface::ROLL_NONE)),
		];
	}//end validateSla()

	/**
	 * Refuse anything that is not an array carrying both `value` and `unit`.
	 *
	 * @param mixed $sla The declared SLA.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When the shape is wrong.
	 *
	 * @phpstan-assert array<mixed> $sla
	 */
	private function refuseUnlessShaped(mixed $sla): void {
		if (is_array($sla) === false || array_key_exists('value', $sla) === false || array_key_exists('unit', $sla) === false) {
			throw new FlowTimerValidationException(message: 'An SLA must have the shape {value, unit}.');
		}

	}//end refuseUnlessShaped()

	/**
	 * The declared value as an in-range integer; a string of digits counts.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return int The value.
	 *
	 * @throws FlowTimerValidationException When it is not an integer in range.
	 */
	private function validValue(mixed $value): int {
		$number = $value;
		if (is_string($number) === true && preg_match('/^\d+$/', $number) === 1) {
			$number = (int)$number;
		}

		if (is_int($number) === false || $number < SlaCalculatorInterface::MIN_VALUE || $number > SlaCalculatorInterface::MAX_VALUE) {
			throw self::valueRefusal(value: $value);
		}

		return $number;
	}//end validValue()

	/**
	 * The refusal of an SLA value outside the allowed integers.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return FlowTimerValidationException The refusal.
	 */
	private static function valueRefusal(mixed $value): FlowTimerValidationException {
		return new FlowTimerValidationException(
			message: sprintf(
				"SLA value '%s' is refused: it must be an integer from %d to %d.",
				var_export($value, true),
				SlaCalculatorInterface::MIN_VALUE,
				SlaCalculatorInterface::MAX_VALUE
			)
		);
	}//end valueRefusal()

	/**
	 * Validate a roll name.
	 *
	 * An absent roll is `none`, and an unknown one is REFUSED rather than
	 * defaulted. Read as `none`, a typed `nextWorkingDay` would save, arm and
	 * behave like a setting nobody made — on a deadline with legal effect,
	 * which is the worst place for a silent default.
	 *
	 * @param mixed $roll The declared roll.
	 *
	 * @return string The roll.
	 *
	 * @throws FlowTimerValidationException On an unknown roll.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function validateRoll(mixed $roll): string {
		if ($roll === null || $roll === '') {
			return SlaCalculatorInterface::ROLL_NONE;
		}

		if (is_string($roll) === false || in_array($roll, SlaCalculatorInterface::ROLLS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"rollToWorkingDay '%s' is refused: use one of %s.",
					var_export($roll, true),
					implode(', ', SlaCalculatorInterface::ROLLS)
				)
			);
		}

		return $roll;
	}//end validateRoll()

	/**
	 * Validate a unit name.
	 *
	 * @param mixed $unit The declared unit.
	 *
	 * @return string The unit.
	 *
	 * @throws FlowTimerValidationException On an unknown unit.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function validateUnit(mixed $unit): string {
		if (is_string($unit) === false || in_array($unit, SlaCalculatorInterface::UNITS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Unit '%s' is refused: use one of %s.", var_export($unit, true), implode(', ', SlaCalculatorInterface::UNITS))
			);
		}

		return $unit;
	}//end validateUnit()

}//end class
