<?php

/**
 * WorkingCalendars: builds a working calendar from its definition for callers outside lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
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

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * The named constructor of WorkingCalendar, for the calendar and timer endpoints.
 *
 * The endpoints called WorkingCalendar::fromArray() themselves; this makes the
 * same call, so they no longer name the class. The calendar it returns is
 * typed as the class because TermDiagnostic::explain() takes one; the
 * endpoints read it through WorkingCalendarInterface.
 */
final class WorkingCalendars implements WorkingCalendarDefinitionsInterface {

	/**
	 * Build and validate a calendar from its stored definition.
	 *
	 * @param array<string, mixed> $definition The `working-calendar` object data.
	 *
	 * @return WorkingCalendar The calendar.
	 *
	 * @throws FlowTimerValidationException On any refused definition, naming the fault.
	 */
	public static function fromDefinition(array $definition): WorkingCalendar {
		return WorkingCalendar::fromArray(definition: $definition);
	}//end fromDefinition()

	/**
	 * Build and validate a calendar from its stored definition.
	 *
	 * @param array<string, mixed> $definition The `working-calendar` object data.
	 *
	 * @return WorkingCalendar The calendar.
	 *
	 * @throws FlowTimerValidationException On any refused definition, naming the fault.
	 */
	public function parse(array $definition): WorkingCalendar {
		return self::fromDefinition(definition: $definition);
	}//end parse()
}//end class
