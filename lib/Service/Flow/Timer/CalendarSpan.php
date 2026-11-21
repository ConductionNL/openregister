<?php

/**
 * An interval between two instants, measured against one working calendar.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The parameters of a measurement walk in {@see SlaCalculator}.
 */
final class CalendarSpan {

	/**
	 * The start.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $from;

	/**
	 * The end, not before the start.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $to;

	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface $from     The start.
	 * @param DateTimeInterface $to       The end, not before the start.
	 * @param WorkingCalendar   $calendar The resolved calendar.
	 */
	public function __construct(
		DateTimeInterface $from,
		DateTimeInterface $to,
		public readonly WorkingCalendar $calendar,
	) {
		$this->from = DateTimeImmutable::createFromInterface($from);
		$this->to = DateTimeImmutable::createFromInterface($to);
	}//end __construct()

	/**
	 * What is being measured, for the refusal when the walk runs too long.
	 *
	 * @param string $what The quantity, such as `business days`.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function describe(string $what): string {
		return sprintf('Measuring %s between %s and %s', $what, $this->from->format('c'), $this->to->format('c'));
	}//end describe()
}//end class
