<?php

/**
 * DateTime Normalizer
 *
 * Canonical conversion point for user-supplied datetime input. Guarantees that
 * `null`, the empty string, and whitespace-only strings become `null` rather
 * than being silently interpreted as "now" by PHP's `new DateTime('')`.
 *
 * All OpenRegister code paths that convert user-supplied datetime values to
 * `DateTime`/`DateTimeImmutable` (or to a database datetime string) MUST
 * delegate to this class. Direct use of `new DateTime($value)` on user data
 * is forbidden — see OpenSpec change `fix-empty-string-date-conversion`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Introduced by fix-empty-string-date-conversion
 *
 * @spec openspec/specs/datetime-input-handling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Normalises user-supplied datetime input to `DateTimeImmutable` or formatted
 * strings, treating empty/whitespace/null input as absence (`null`).
 *
 * Rules enforced by `normalize()`:
 *   1. `null`                       → `null`
 *   2. `string` trimmed and empty   → `null`
 *   3. `DateTimeInterface` instance → `DateTimeImmutable` of the same instant
 *   4. parse failure / unsupported type → `null` (with debug log)
 */
class DateTimeNormalizer {

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
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for debug-level notices on parse failures.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Normalise user-supplied input to `DateTimeImmutable` or `null`.
	 *
	 * @param mixed             $value          Value to normalise (string, null, DateTimeInterface, or anything else).
	 * @param DateTimeZone|null $assumeTimezone Timezone to attribute to a string that carries no
	 *                                          offset or timezone name of its own. A string that
	 *                                          does carry one keeps it, and a `DateTimeInterface`
	 *                                          is never re-zoned. Defaults to PHP's
	 *                                          `date_default_timezone_get()`, which is what
	 *                                          `new DateTimeImmutable()` would use anyway.
	 *
	 * @return DateTimeImmutable|null A `DateTimeImmutable` when parseable, otherwise `null`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function normalize(mixed $value, ?DateTimeZone $assumeTimezone = null): ?DateTimeImmutable {
		if ($value === null) {
			return null;
		}

		if ($value instanceof DateTimeImmutable === true) {
			return $value;
		}

		if ($value instanceof DateTimeInterface === true) {
			return DateTimeImmutable::createFromInterface($value);
		}

		if (is_string($value) === false) {
			$this->logger->debug(
				message: '[DateTimeNormalizer] Non-string, non-DateTime input rejected',
				context: ['file' => __FILE__, 'line' => __LINE__, 'type' => get_debug_type($value)]
			);
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		try {
			// `DateTimeImmutable` applies the second argument ONLY when the string
			// itself carries no offset or timezone name, which is exactly the
			// "naive database column value" case the callers need it for.
			return new DateTimeImmutable($trimmed, $assumeTimezone);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[DateTimeNormalizer] Unparseable datetime string',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'value' => $trimmed,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end normalize()

	/**
	 * Format user-supplied input as a database datetime string.
	 *
	 * The value is converted to `self::DATABASE_TIMEZONE` BEFORE it is rendered.
	 * `format()` renders a `DateTimeImmutable` in whatever timezone that instance
	 * carries, so rendering `2026-10-20T00:00:00+02:00` directly produced
	 * `2026-10-20 00:00:00` — the offset silently discarded rather than applied.
	 * The read path then interpreted that offset-less column value as UTC, so the
	 * instant came back two hours later than it was sent (WOO-567: a WMEBV
	 * objection deadline in the Portaliq inbox shifted from 00:00 to 02:00).
	 * Converting first stores `2026-10-19 22:00:00`, the same instant.
	 *
	 * @param mixed $value Value to normalise and format.
	 *
	 * @return string|null `Y-m-d H:i:s`-formatted string in `self::DATABASE_TIMEZONE`,
	 *                     or `null` for empty/invalid input.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function formatForDatabase(mixed $value): ?string {
		$datetime = $this->normalize(value: $value, assumeTimezone: $this->databaseTimezone());
		if ($datetime === null) {
			return null;
		}

		return $datetime->setTimezone($this->databaseTimezone())->format(self::DATABASE_FORMAT);
	}//end formatForDatabase()

	/**
	 * Format user-supplied input as an ISO 8601 string with timezone offset.
	 *
	 * @param mixed $value Value to normalise and format.
	 *
	 * @return string|null ISO 8601 string with offset, or `null` for empty/invalid input.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function formatForIso8601(mixed $value): ?string {
		$datetime = $this->normalize(value: $value);
		if ($datetime === null) {
			return null;
		}

		return $datetime->format(DateTimeInterface::ATOM);
	}//end formatForIso8601()

	/**
	 * Format a `format: date` value for its database column.
	 *
	 * The counterpart of `formatForDatabase()` for values that name a calendar
	 * DAY rather than an instant. Deliberately does NOT convert to
	 * `self::DATABASE_TIMEZONE`: a date has no instant, so converting it is a
	 * category error that can move it to the previous or next day. A client
	 * that sends `2026-10-20T00:00:00+02:00` for a due date means the 20th, and
	 * UTC conversion would store the 19th; `2026-10-20T23:30:00-05:00` would
	 * store the 21st.
	 *
	 * The wall-clock reading is kept as given, which is also what this method
	 * did before WOO-567 split the two formats apart — so `date` behaviour is
	 * unchanged by that fix, which is the point.
	 *
	 * @param mixed $value Value to normalise and format.
	 *
	 * @return string|null `Y-m-d H:i:s`-formatted string preserving the given
	 *                     calendar day, or `null` for empty/invalid input.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function formatDateForDatabase(mixed $value): ?string {
		$datetime = $this->normalize(value: $value, assumeTimezone: $this->databaseTimezone());
		if ($datetime === null) {
			return null;
		}

		return $datetime->format(self::DATABASE_FORMAT);
	}//end formatDateForDatabase()

	/**
	 * Format a value read back from a database datetime column as ISO 8601.
	 *
	 * The counterpart of `formatForDatabase()`. A DATETIME column carries no
	 * offset, so the string the driver hands back (`2026-10-19 22:00:00`) is
	 * naive and `formatForIso8601()` would attribute the server's
	 * `date.timezone` to it. That happens to be right on a UTC server and wrong
	 * everywhere else, which would re-open WOO-567 on any deployment whose PHP
	 * timezone is not UTC. Read paths that pull a `date-time` property straight
	 * out of its column MUST use this method so the stored instant survives
	 * regardless of how the server is configured.
	 *
	 * @param mixed $value Column value to interpret and format.
	 *
	 * @return string|null ISO 8601 string with offset, or `null` for empty/invalid input.
	 *
	 * @spec openspec/specs/datetime-input-handling/spec.md
	 */
	public function formatDatabaseValueForIso8601(mixed $value): ?string {
		$datetime = $this->normalize(value: $value, assumeTimezone: $this->databaseTimezone());
		if ($datetime === null) {
			return null;
		}

		return $datetime->format(DateTimeInterface::ATOM);
	}//end formatDatabaseValueForIso8601()

	/**
	 * The timezone offset-less database datetime columns are expressed in.
	 *
	 * @return DateTimeZone The `self::DATABASE_TIMEZONE` zone.
	 */
	private function databaseTimezone(): DateTimeZone {
		return new DateTimeZone(self::DATABASE_TIMEZONE);
	}//end databaseTimezone()
}//end class
