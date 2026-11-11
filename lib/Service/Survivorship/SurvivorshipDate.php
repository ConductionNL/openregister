<?php

/**
 * SurvivorshipDate
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

use DateTimeImmutable;
use Throwable;

/**
 * Reads the date-ish values survivorship compares, never throwing.
 *
 * Shared by the survivorship resolver (record freshness) and the trust tier
 * resolver (when a trust row takes effect).
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#3.2
 */
final class SurvivorshipDate {

	/**
	 * Parse a date-ish value into a DateTimeImmutable, never throwing.
	 *
	 * @param mixed $value Candidate date value.
	 *
	 * @return DateTimeImmutable|null Parsed date, or null when absent/unparseable.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#3.2
	 */
	public static function parse(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end parse()
}//end class
