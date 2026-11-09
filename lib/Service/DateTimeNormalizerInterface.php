<?php

/**
 * DateTimeNormalizerInterface: what callers outside the Service layer use of DateTimeNormalizer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns user-supplied datetime input into a moment or a database string, with
 * null, empty and whitespace-only input read as absence rather than "now".
 */
interface DateTimeNormalizerInterface {

	/**
	 * MySQL/MariaDB datetime format.
	 *
	 * @var string
	 */
	public const DATABASE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Timezone the offset-less database datetime columns are expressed in.
	 *
	 * A `date`/`date-time` schema property is backed by a DATETIME column
	 * (`timestamp without time zone` on PostgreSQL), which stores a clock time
	 * and no offset. Both directions of the round-trip therefore have to agree
	 * on one timezone for that clock time, and that timezone is UTC — never the
	 * server's `date.timezone`, which differs per deployment.
	 *
	 * @var string
	 */
	public const DATABASE_TIMEZONE = 'UTC';

	/**
	 * Normalise user-supplied input to `DateTimeImmutable` or `null`.
	 *
	 * @param mixed             $value          Value to normalise.
	 * @param DateTimeZone|null $assumeTimezone Timezone for a string that carries none of its own.
	 *
	 * @return DateTimeImmutable|null The moment, or null when the input is absent or unparseable.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function normalize(mixed $value, ?DateTimeZone $assumeTimezone = null): ?DateTimeImmutable;

	/**
	 * Format user-supplied input as a UTC database datetime string.
	 *
	 * @param mixed $value Value to format.
	 *
	 * @return string|null The `Y-m-d H:i:s` string, or null when the input is absent.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function formatForDatabase(mixed $value): ?string;

	/**
	 * Format user-supplied input as a database date (no timezone shift).
	 *
	 * @param mixed $value Value to format.
	 *
	 * @return string|null The stored form, or null when the input is absent.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function formatDateForDatabase(mixed $value): ?string;

	/**
	 * Read a stored database datetime back as an ISO 8601 string.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The ISO 8601 string, or null when the value is absent.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function formatDatabaseValueForIso8601(mixed $value): ?string;
}//end interface
