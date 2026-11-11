<?php

/**
 * OpenRegister ObjectDateLayout
 *
 * How one declared object date is drawn as an event.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

/**
 * How one declared object date is drawn as an event: its summary, its end
 * and length, and who is invited. Groups the drawing half of an
 * {@see ObjectDateDeclaration} so its constructor stays small.
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
final class ObjectDateLayout {
	/**
	 * Constructor.
	 *
	 * @param string|null $summaryTemplate Template for the event summary.
	 * @param string|null $endProperty The property carrying the end of a period or appointment.
	 * @param int|null $durationMinutes The length of an appointment without an end property.
	 * @param string|null $attendeesProperty The property holding the appointment's attendees.
	 */
	public function __construct(
		public readonly ?string $summaryTemplate,
		public readonly ?string $endProperty,
		public readonly ?int $durationMinutes,
		public readonly ?string $attendeesProperty,
	) {
	}//end __construct()
}//end class
