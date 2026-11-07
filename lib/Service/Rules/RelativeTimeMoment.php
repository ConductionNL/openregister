<?php

/**
 * The moment a relative-time condition is evaluated at.
 *
 * The present moment and the working calendar travel together through the
 * compilation of a relative-time condition: every walk needs both.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTimeInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;

/**
 * The present moment and the calendar a relative-time condition walks through.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class RelativeTimeMoment {

	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface    $now      The present moment.
	 * @param WorkingCalendar|null $calendar The calendar, when the unit needs one.
	 */
	public function __construct(
		public readonly DateTimeInterface $now,
		public readonly ?WorkingCalendar $calendar,
	) {
	}//end __construct()
}//end class
