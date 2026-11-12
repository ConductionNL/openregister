<?php

/**
 * The start and end of one event, and whether it is all-day.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeImmutable;

/**
 * When one VEVENT starts and ends, and whether it publishes as a whole day.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class EventSpan {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $start The start.
	 * @param DateTimeImmutable $end The end.
	 * @param bool $allDay Whether the event is all-day.
	 *
	 * @return void
	 */
	public function __construct(
		public DateTimeImmutable $start,
		public DateTimeImmutable $end,
		public bool $allDay,
	) {

	}//end __construct()
}//end class
