<?php

/**
 * The refusal. A change that would take a control below its declared floor
 * does not happen.
 *
 * WHY THIS IS A GUARD AND NOT A WARNING. A hardening report that lists a
 * control as failing, next to a settings screen that happily set it that way,
 * documents the weakening rather than stopping it. The gemeente that turns the
 * inbound lockout down to sixty seconds "for one migration" is the gemeente
 * that still has it at sixty seconds in March. The floor is the sentence the
 * security officer wrote down; this class is the only thing that makes it a
 * sentence and not a note.
 *
 * TWO REFUSALS, AND THE SECOND ONE IS THE ONE PEOPLE FORGET:
 *
 *  1. A control value that would cross its floor is refused.
 *  2. A *floor* that would be declared weaker than the shipped baseline is
 *     refused. Lowering the floor is the cheapest way to make a failing
 *     control pass, and an instance that can lower its own floor has no floor.
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
 * Refuses a change that would weaken a control.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class HardeningFloorGuard {

	/**
	 * Constructor.
	 *
	 * @param HardeningPolicy $policy Reads the floors in force.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly HardeningPolicy $policy,
	) {

	}//end __construct()

	/**
	 * Refuse a control value that would cross the declared floor.
	 *
	 * @param string $control The control identifier.
	 * @param int $proposed The value asked for.
	 *
	 * @return void
	 *
	 * @throws HardeningFloorException When the value would weaken the control.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The catalogue is a constant and its readers are
	 * pure functions over it. Injecting a stateless lookup would add a constructor argument
	 * to every caller and change nothing about what the lookup can answer.
	 */
	public function assertValue(string $control, int $proposed): void {
		$floor = $this->policy->floor(control: $control);
		$comparator = HardeningPolicy::comparator(control: $control);

		if (HardeningControl::satisfies(comparator: $comparator, value: $proposed, floor: $floor) === true) {
			return;
		}

		throw new HardeningFloorException(
			control: $control,
			floor: $floor,
			proposed: $proposed,
			comparator: $comparator,
			message: self::refusal(control: $control, floor: $floor, proposed: $proposed, comparator: $comparator),
		);

	}//end assertValue()

	/**
	 * Refuse a floor declared weaker than the shipped baseline.
	 *
	 * @param string $control The control identifier.
	 * @param int $proposed The floor asked for.
	 *
	 * @return void
	 *
	 * @throws HardeningFloorException When the floor would be weaker than the baseline.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The catalogue is a constant and its readers are
	 * pure functions over it. Injecting a stateless lookup would add a constructor argument
	 * to every caller and change nothing about what the lookup can answer.
	 */
	public function assertFloor(string $control, int $proposed): void {
		$baseline = HardeningPolicy::baseline(control: $control);
		$comparator = HardeningPolicy::comparator(control: $control);

		if (HardeningControl::satisfies(comparator: $comparator, value: $proposed, floor: $baseline) === true) {
			return;
		}

		throw new HardeningFloorException(
			control: $control,
			floor: $baseline,
			proposed: $proposed,
			comparator: $comparator,
			message: 'The floor for ' . $control . ' may not be weaker than the shipped baseline of ' . $baseline . '.',
		);

	}//end assertFloor()

	/**
	 * The sentence an administrator reads when a change is refused.
	 *
	 * @param string $control The control identifier.
	 * @param int $floor The floor in force.
	 * @param int $proposed The value asked for.
	 * @param string $comparator `atLeast` or `atMost`.
	 *
	 * @return string The refusal.
	 */
	private static function refusal(string $control, int $floor, int $proposed, string $comparator): string {
		$direction = 'at least';
		if ($comparator === 'atMost') {
			$direction = 'at most';
		}

		return 'This instance declared a floor of ' . $direction . ' ' . $floor . ' for ' . $control
			. ', and ' . $proposed . ' is below it. Raise the floor first, or leave the control alone.';

	}//end refusal()
}//end class
