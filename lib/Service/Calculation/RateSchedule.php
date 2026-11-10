<?php

/**
 * OpenRegister RateSchedule
 *
 * The arithmetic behind the `graduated` and `bandRate` calculation operators:
 * a quantity spread over ordered bands, each band charging its own rate per
 * unit, optionally capped.
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
 * A graduated rate schedule, evaluated without I/O.
 *
 * A schedule is a list of bands, `[{upTo: 14, rate: 23}, {upTo: 28, rate: 35},
 * {rate: 45}]`. Band one covers units 1 to 14, band two units 15 to 28, and a
 * last band without `upTo` covers every unit after that. This is the shape of a
 * daily penalty that steps up over time, of tiered pricing and of a tax
 * bracket table, so it is configuration for any of them and code for none.
 *
 * Units past the last closed band are not charged when the schedule has no open
 * band. A quantity of zero or less charges nothing.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class RateSchedule {

	/**
	 * Evaluate already-resolved operands of graduated or bandRate.
	 *
	 * Null-safe like the other arithmetic operators: a quantity or a schedule
	 * that resolves to null yields null, so a record that has not started
	 * accruing has no amount rather than an invented zero.
	 *
	 * @param mixed $quantity The quantity (graduated) or the unit (bandRate).
	 * @param mixed $bands The schedule.
	 * @param mixed $cap The cap, or null. Ignored by bandRate.
	 * @param bool $cumulative True for graduated, false for bandRate.
	 *
	 * @return int|float|null The amount or the rate, or null when an operand is null.
	 *
	 * @throws EvaluationException When an operand is not numeric or the schedule is malformed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function evaluate(mixed $quantity, mixed $bands, mixed $cap, bool $cumulative): int|float|null {
		if ($quantity === null || $bands === null) {
			return null;
		}

		$this->assertOperands(quantity: $quantity, bands: $bands);
		if ($cumulative === false) {
			return $this->rateAt(unit: ($quantity + 0), bands: $bands);
		}

		return $this->accrued(quantity: ($quantity + 0), bands: $bands, cap: $this->checkedCap(cap: $cap));
	}//end evaluate()

	/**
	 * Refuse a quantity that is not numeric or a schedule that is not a list.
	 *
	 * @param mixed $quantity The quantity or the unit.
	 * @param mixed $bands The schedule.
	 *
	 * @return void
	 *
	 * @throws EvaluationException When an operand has the wrong type.
	 */
	private function assertOperands(mixed $quantity, mixed $bands): void {
		if (is_numeric($quantity) === false || is_array($bands) === false) {
			throw new EvaluationException('graduated/bandRate needs a numeric quantity and a list of bands.');
		}
	}//end assertOperands()

	/**
	 * The cap of graduated as a number, or null for no cap.
	 *
	 * @param mixed $cap The cap operand.
	 *
	 * @return int|float|null The cap.
	 *
	 * @throws EvaluationException When the cap is not numeric.
	 */
	private function checkedCap(mixed $cap): int|float|null {
		if ($cap === null) {
			return null;
		}

		if (is_numeric($cap) === false) {
			throw new EvaluationException('The cap of graduated must be numeric.');
		}

		return ($cap + 0);
	}//end checkedCap()

	/**
	 * The amount a quantity accrues over the schedule, capped when a cap is given.
	 *
	 * @param int|float $quantity How many units accrued (for example elapsed days).
	 * @param array<int, mixed> $bands The schedule, see the class comment.
	 * @param int|float|null $cap The highest amount, or null for no cap.
	 *
	 * @return int|float The accrued amount.
	 *
	 * @throws EvaluationException When the schedule is malformed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function accrued(int|float $quantity, array $bands, int|float|null $cap): int|float {
		$total = $this->uncappedTotal(quantity: $quantity, bands: $bands);
		if ($cap !== null && $total > $cap) {
			return $cap;
		}

		return $total;
	}//end accrued()

	/**
	 * The amount a quantity accrues over the schedule, before any cap.
	 *
	 * @param int|float $quantity How many units accrued.
	 * @param array<int, mixed> $bands The schedule, see the class comment.
	 *
	 * @return int|float The accrued amount.
	 *
	 * @throws EvaluationException When the schedule is malformed.
	 */
	private function uncappedTotal(int|float $quantity, array $bands): int|float {
		$total = 0;
		$floor = 0;
		foreach ($this->normalise(bands: $bands) as $band) {
			if ($quantity <= $floor) {
				break;
			}

			$ceiling = $this->ceilingOf(band: $band, quantity: $quantity);
			$total += (($ceiling - $floor) * $band['rate']);
			$floor  = $ceiling;
		}

		return $total;
	}//end uncappedTotal()

	/**
	 * Where a band stops counting for a quantity: its bound, or the quantity when it is lower.
	 *
	 * @param array{upTo: int|float|null, rate: int|float} $band The checked band.
	 * @param int|float $quantity How many units accrued.
	 *
	 * @return int|float The ceiling.
	 */
	private function ceilingOf(array $band, int|float $quantity): int|float {
		if ($band['upTo'] !== null && $band['upTo'] < $quantity) {
			return $band['upTo'];
		}

		return $quantity;
	}//end ceilingOf()

	/**
	 * The rate of the band a unit falls in.
	 *
	 * @param int|float $unit The unit, counted from 1 (for example day 15).
	 * @param array<int, mixed> $bands The schedule, see the class comment.
	 *
	 * @return int|float|null The band's rate, or null when no band covers the unit.
	 *
	 * @throws EvaluationException When the schedule is malformed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function rateAt(int|float $unit, array $bands): int|float|null {
		// The schedule is checked whatever the unit is: a malformed one must raise an evaluation error and never be read as
		// an amount, and `graduated` already refuses it at any quantity.
		$checked = $this->normalise(bands: $bands);
		if ($unit <= 0) {
			return null;
		}

		foreach ($checked as $band) {
			if ($band['upTo'] === null || $unit <= $band['upTo']) {
				return $band['rate'];
			}
		}

		return null;
	}//end rateAt()

	/**
	 * Check a schedule and reduce each band to its two numbers.
	 *
	 * @param array<int, mixed> $bands The schedule as authored.
	 *
	 * @return array<int, array{upTo: int|float|null, rate: int|float}> The checked bands.
	 *
	 * @throws EvaluationException When a band is not an object with a numeric rate, the bounds
	 *                             do not rise, or an open band is not the last one.
	 */
	private function normalise(array $bands): array {
		if ($bands === [] || array_is_list($bands) === false) {
			throw new EvaluationException('A rate schedule must be a non-empty list of bands.');
		}

		$checked  = [];
		$previous = 0;
		$last     = (count($bands) - 1);
		foreach ($bands as $index => $band) {
			$checked[] = $this->checkBand(band: $band, number: ($index + 1), previous: $previous, isLast: ($index === $last));
			$previous  = ($checked[$index]['upTo'] ?? $previous);
		}

		return $checked;
	}//end normalise()

	/**
	 * Check one band against the band before it.
	 *
	 * @param mixed $band The band as authored.
	 * @param int $number The band's position, from 1, for the message.
	 * @param int|float $previous The upper bound of the band before it, 0 for the first.
	 * @param bool $isLast Whether this is the last band, the only one that may be open.
	 *
	 * @return array{upTo: int|float|null, rate: int|float} The checked band.
	 *
	 * @throws EvaluationException When the band is malformed.
	 */
	private function checkBand(mixed $band, int $number, int|float $previous, bool $isLast): array {
		if (is_array($band) === false || is_numeric($band['rate'] ?? null) === false) {
			throw new EvaluationException(sprintf('Band %d of a rate schedule needs a numeric rate.', $number));
		}

		$upTo = ($band['upTo'] ?? null);
		if ($upTo === null) {
			return ['upTo' => $this->openBound(isLast: $isLast), 'rate' => ($band['rate'] + 0)];
		}

		return ['upTo' => $this->closedBound(upTo: $upTo, previous: $previous), 'rate' => ($band['rate'] + 0)];
	}//end checkBand()

	/**
	 * The bound of a band that leaves out upTo: none, allowed only on the last band.
	 *
	 * @param bool $isLast Whether this is the last band.
	 *
	 * @return null No bound.
	 *
	 * @throws EvaluationException When the band is not the last one.
	 */
	private function openBound(bool $isLast): null {
		if ($isLast === false) {
			throw new EvaluationException('Only the last band of a rate schedule may leave out upTo.');
		}

		return null;
	}//end openBound()

	/**
	 * The bound of a band that states upTo, which must be numeric and rise.
	 *
	 * @param mixed $upTo The bound as authored.
	 * @param int|float $previous The upper bound of the band before it, 0 for the first.
	 *
	 * @return int|float The bound.
	 *
	 * @throws EvaluationException When the bound is not numeric or does not rise.
	 */
	private function closedBound(mixed $upTo, int|float $previous): int|float {
		if (is_numeric($upTo) === false || ($upTo + 0) <= $previous) {
			throw new EvaluationException('The upTo bounds of a rate schedule must rise from band to band.');
		}

		return ($upTo + 0);
	}//end closedBound()
}//end class
