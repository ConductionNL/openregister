<?php

/**
 * The seam through which the app that owns a person's working hours answers for them.
 *
 * OpenRegister never stores a person's working hours or absences (design
 * D-C67-2): the app that administers them implements this and registers it on
 * {@see RegisterWorkingPatternProvidersEvent}. When a term is computed for a
 * named person the engine asks; when nobody answers, the scope calendar
 * decides.
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
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * Answers with one person's working pattern, read at the moment a term is computed.
 */
interface WorkingPatternProviderInterface {

	/**
	 * The app id of the provider, named in the diagnostic when its pattern applies.
	 *
	 * @return string The app id.
	 */
	public function appId(): string;

	/**
	 * The person's working pattern, or null when this app holds none for them.
	 *
	 * Every key is optional. `workingWeekdays` replaces the calendar's working
	 * weekdays (ISO, 1 = Monday), `hoursPerWorkingDay` replaces its hours, and
	 * each `absences` entry (`Y-m-d`, or `{date, name}`) is a non-working day
	 * for this person on top of the calendar's own closure days.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return array<string, mixed>|null `{workingWeekdays?: int[], hoursPerWorkingDay?: float|int, absences?: (string|{date, name?})[]}`, or null.
	 */
	public function patternFor(string $userId): ?array;
}//end interface
