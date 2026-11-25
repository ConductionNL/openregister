<?php

/**
 * Time Zone Format Validator
 *
 * Validates an IANA time zone name, such as Europe/Amsterdam.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 *
 * @spec openspec/changes/calendar-time-zone/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use DateTimeZone;
use Opis\JsonSchema\Format;

/**
 * The `time-zone` string format: an IANA zone name PHP resolves.
 *
 * The same rule {@see \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition}
 * applies when a calendar is read, so a value the schema accepts at save is
 * one the calendar can count days in. An offset (`+02:00`) or an abbreviation
 * (`CET`) is refused: neither says where the clocks change, and a calendar
 * date only becomes a moment once somebody says where midnight is.
 *
 * @spec openspec/changes/calendar-time-zone/tasks.md
 */
class TimeZoneFormat implements Format {

	/**
	 * Whether the value is an IANA zone name.
	 *
	 * @param mixed $data The value.
	 *
	 * @inheritDoc
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/calendar-time-zone/tasks.md
	 */
	public function validate(mixed $data): bool {
		if (is_string($data) === false || $data === '') {
			return false;
		}

		return in_array($data, DateTimeZone::listIdentifiers(), true);
	}//end validate()
}//end class
