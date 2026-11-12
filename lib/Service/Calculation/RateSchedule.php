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
	 * The refusal for a bound that is not numeric or does not rise above the band before it.
	 *
	 * @var string
	 */
	private const RISING_BOUNDS = 'The upTo bounds of a rate schedule must rise from band to band.';

	/**
	 * Graduated: the amount a quantity accrues over the schedule, capped when a cap is given.
	 *
	 * Null-safe like the other arithmetic operators: a quantity or a schedule
	 * that resolves to null yields null, so a record that has not started
	 * accruing has no amount rather than an invented zero.
	 *
	 * @param RateOperands $operands The quantity and the schedule, as resolved.
	 * @param mixed        $cap      The cap, or null for no cap.
	 *
	 * @return int|float|null The amount, or null when an operand is null.
	 *
	 * @throws EvaluationException When an operand is not numeric or the schedule is malformed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function graduated(RateOperands $operands, mixed $cap): int|float|null {
		if ($operands->hasNull() === true) {
			return null;
		}

		$this->assertOperands(operands: $operands);
		$checkedCap = $this->checkedCap(cap: $cap);
		$total      = $this->uncappedTotal(quantity: ($operands->quantity + 0), bands: $operands->bands);

		return $this->capped(total: $total, cap: $checkedCap);
	}//end graduated()

	/**
	 * BandRate: the rate of the band a unit falls in; null-safe like graduated.
	 *
	 * @param RateOperands $operands The unit and the schedule, as resolved.
	 *
	 * @return int|float|null The rate, or null when an operand is null or no band covers the unit.
	 *
	 * @throws EvaluationException When an operand is not numeric or the schedule is malformed.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function bandRate(RateOperands $operands): int|float|null {
		if ($operands->hasNull() === true) {
			return null;
		}

		$this->assertOperands(operands: $operands);
		return $this->rateAt(unit: ($operands->quantity + 0), bands: $operands->bands);
	}//end bandRate()

	/**
	 * Refuse a quantity that is not numeric or a schedule that is not a list.
	 *
	 * @param RateOperands $operands The quantity or the unit, and the schedule.
	 *
	 * @return void
	 *
	 * @throws EvaluationException When an operand has the wrong type.
	 */
	private function assertOperands(RateOperands $operands): void {
		if (is_numeric($operands->quantity) === false || is_array($operands->bands) === false) {
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
	 * An accrued amount held to its cap.
	 *
	 * @param int|float      $total The accrued amount.
	 * @param int|float|null $cap   The highest amount, or null for no cap.
	 *
	 * @return int|float The amount, capped.
	 */
	private function capped(int|float $total, int|float|null $cap): int|float {
		if ($cap !== null && $total > $cap) {
			return $cap;
		}

		return $total;
	}//end capped()

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
			$checked[] = $this->checkBand(band: $band, position: new BandPosition(number: ($index + 1), isLast: ($index === $last)));
			$previous  = $this->risenBound(band: $checked[$index], previous: $previous);
		}

		return $checked;
	}//end normalise()

	/**
	 * Check one band on its own: a numeric rate, and a numeric bound or an open last band.
	 *
	 * @param mixed        $band     The band as authored.
	 * @param BandPosition $position Where the band sits in the schedule.
	 *
	 * @return array{upTo: int|float|null, rate: int|float} The checked band.
	 *
	 * @throws EvaluationException When the band is malformed.
	 */
	private function checkBand(mixed $band, BandPosition $position): array {
		if (is_array($band) === false || is_numeric($band['rate'] ?? null) === false) {
			throw new EvaluationException(sprintf('Band %d of a rate schedule needs a numeric rate.', $position->number));
		}

		$upTo = ($band['upTo'] ?? null);
		if ($upTo === null) {
			return ['upTo' => $this->openBound(isLast: $position->isLast), 'rate' => ($band['rate'] + 0)];
		}

		return ['upTo' => $this->closedBound(upTo: $upTo), 'rate' => ($band['rate'] + 0)];
	}//end checkBand()

	/**
	 * The upper bound reached after a checked band, which must rise above the band before it.
	 *
	 * @param array{upTo: int|float|null, rate: int|float} $band     The checked band.
	 * @param int|float                                    $previous The upper bound of the band before it, 0 for the first.
	 *
	 * @return int|float The band's bound, or the previous one for an open band.
	 *
	 * @throws EvaluationException When the bound does not rise.
	 */
	private function risenBound(array $band, int|float $previous): int|float {
		if ($band['upTo'] === null) {
			return $previous;
		}

		if ($band['upTo'] <= $previous) {
			throw new EvaluationException(self::RISING_BOUNDS);
		}

		return $band['upTo'];
	}//end risenBound()

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
	 * The bound of a band that states upTo, which must be numeric; risenBound() checks that it rises.
	 *
	 * @param mixed $upTo The bound as authored.
	 *
	 * @return int|float The bound.
	 *
	 * @throws EvaluationException When the bound is not numeric.
	 */
	private function closedBound(mixed $upTo): int|float {
		if (is_numeric($upTo) === false) {
			throw new EvaluationException(self::RISING_BOUNDS);
		}

		return ($upTo + 0);
	}//end closedBound()
}//end class
