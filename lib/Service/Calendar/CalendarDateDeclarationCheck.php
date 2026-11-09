<?php

/**
 * The save-time check of a calendar-provider block's declared date kinds.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use OCA\OpenRegister\Exception\CalendarDateKindException;

/**
 * Refuses a calendar-provider block whose declared dates cannot be used.
 *
 * The schema's configuration rules in lib/Db run this on save. It reads the
 * block exactly as the feed later will, through
 * {@see ObjectDateDeclaration::allFromConfig()}, so the save refuses what the
 * feed would refuse; it lives here so lib/Db names this small class instead
 * of the declaration value object.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectDateDeclaration::allFromConfig is a named constructor; there is no instance.
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final class CalendarDateDeclarationCheck {

	/**
	 * Refuse the block when any declared date cannot be used.
	 *
	 * @param array<string, mixed> $calendarConfig The calendarProvider config.
	 *
	 * @return void
	 *
	 * @throws CalendarDateKindException When any declaration cannot be used.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public static function assertDeclarable(array $calendarConfig): void {
		ObjectDateDeclaration::allFromConfig(calendarConfig: $calendarConfig);
	}//end assertDeclarable()
}//end class
