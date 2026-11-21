<?php

/**
 * One invitee's answer to record on an appointment object.
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
 * The answer as submitted: the attendee and status are checked when it is recorded.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class AttendeeResponse {

	/**
	 * Constructor.
	 *
	 * @param string      $objectUuid  The object the appointment was created from.
	 * @param string      $attendee    The invitee identifier, as the object stores it.
	 * @param string      $status      One of AppointmentAttendeeService::STATUSES.
	 * @param string|null $respondedBy The principal that submitted the answer.
	 */
	public function __construct(
		public string $objectUuid,
		public string $attendee,
		public string $status,
		public ?string $respondedBy = null,
	) {
	}//end __construct()
}//end class
