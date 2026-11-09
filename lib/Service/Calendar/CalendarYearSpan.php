<?php

/**
 * The years a calendar body covers, first to last.
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

/**
 * The first and last year a calendar's events touch, which is the range its
 * VTIMEZONE must cover.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class CalendarYearSpan {

	/**
	 * Constructor.
	 *
	 * @param int $fromYear First year covered.
	 * @param int $toYear Last year covered.
	 *
	 * @return void
	 */
	public function __construct(
		public int $fromYear,
		public int $toYear,
	) {

	}//end __construct()
}//end class
