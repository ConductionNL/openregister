<?php

/**
 * AppointmentAttendeeServiceInterface: what callers outside the component use of AppointmentAttendeeService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use InvalidArgumentException;
use OCA\OpenRegister\Service\WriteCause;

/**
 * The attendee responses the calendar feed endpoints read and record.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AppointmentAttendeeServiceInterface {

	/**
	 * Whether the calling principal may read this object at all.
	 *
	 * The answer comes from an RBAC-checked read, so it is the object rules
	 * deciding and not a second copy of them. Callers use it to refuse before
	 * they read or write, and they refuse with a 404: a 403 on an object the
	 * caller may not see would confirm that the uuid exists.
	 *
	 * Shaped after `CaseAnchorReader::mayRead()`, which is how the rest of the
	 * app asks this question.
	 *
	 * @param string $objectUuid The object the appointment was created from.
	 *
	 * @return boolean True only when the RBAC-checked read succeeds.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function mayRead(string $objectUuid): bool;

	/**
	 * Record one invitee's answer on the object.
	 *
	 * The write goes through the ordinary object save path with RBAC on, so a
	 * caller who may not write the object cannot record an answer on it.
	 * A second answer from the same invitee replaces the first: an attendance
	 * list is a current state, not a log of everyone's changes of mind.
	 *
	 * @param string $objectUuid The object the appointment was created from.
	 * @param string $attendee The invitee identifier, as the object stores it.
	 * @param string $status One of STATUSES.
	 * @param string|null $respondedBy The principal that submitted the answer.
	 *
	 * @return array<int, array<string, mixed>> The full response list after the write.
	 *
	 * @throws InvalidArgumentException When the status is not an answer, or the object is unknown.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function recordResponse(
		string $objectUuid,
		string $attendee,
		string $status,
		?string $respondedBy = null,
	): array;

	/**
	 * Record one invitee's answer on the object, as recordResponse() does.
	 *
	 * @param AttendeeResponse $response The answer as submitted.
	 *
	 * @return array<int, array<string, mixed>> The full response list after the write.
	 *
	 * @throws InvalidArgumentException When the status is not an answer, or the object is unknown.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function recordAttendeeResponse(AttendeeResponse $response): array;

	/**
	 * The answers recorded on an object, as stored.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return array<int, array<string, mixed>> The responses, possibly empty.
	 *
	 * @throws InvalidArgumentException When the object is unknown.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function responsesFor(string $objectUuid): array;
}//end interface
