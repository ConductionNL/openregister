<?php

/**
 * CalendarBlackoutRefusal: a booking refused because it falls in a blackout period.
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
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use RuntimeException;

/**
 * Names the period that refused the booking.
 */
class CalendarBlackoutRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                                                                     $slug   The calendar.
	 * @param string                                                                     $kind   The refused kind of booking.
	 * @param array{name: string, from: string, to: string, refuses: array<int, string>} $period The period.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $kind,
		public readonly array $period,
	) {
		parent::__construct(
			message: sprintf(
				"A %s cannot be booked in '%s' (%s to %s) on working calendar '%s'.",
				$kind,
				$period['name'],
				$period['from'],
				$period['to'],
				$slug
			)
		);
	}//end __construct()
}//end class
