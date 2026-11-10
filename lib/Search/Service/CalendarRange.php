<?php

/**
 * OpenRegister CalendarRange
 *
 * The visible date range of a calendar view.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
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

namespace OCA\OpenRegister\Search\Service;

/**
 * The inclusive start and end (ISO 8601 date or datetime) a calendar view shows.
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
 */
final class CalendarRange {
	/**
	 * Constructor.
	 *
	 * @param string $start Inclusive range start (ISO 8601 date/datetime).
	 * @param string $end   Inclusive range end (ISO 8601 date/datetime).
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
	 */
	public function __construct(
		public readonly string $start,
		public readonly string $end,
	) {
	}//end __construct()
}//end class
