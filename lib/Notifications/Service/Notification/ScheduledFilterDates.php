<?php

/**
 * Date, instant and duration parsing for the scheduled-filter evaluator.
 *
 * Every parse fails closed: an unparsable value is logged at debug and
 * reads as null, so the clause it belongs to does not match.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Resolves the instants, durations and object dates a filter clause compares.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ScheduledFilterDates {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a reference instant for `before` / `after`.
	 *
	 * Accepts `now`, an absolute ISO-8601 date/date-time, or a signed ISO-8601
	 * duration measured from now (`P7D` future, `-P7D` past). The sign is
	 * mandatory for the past direction: `before "P7D"` reads equally naturally
	 * as "a week from now" and "a week ago", and an ambiguous date operator in a
	 * reminder engine is how a thousand wrong emails get sent.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause; its operand is the instant as written.
	 *
	 * @return DateTimeImmutable|null The instant, or null when unresolvable.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function resolveInstant(ScheduledFilterClause $clause): ?DateTimeImmutable {
		$value = (string)$clause->operand;
		if ($value === 'now') {
			return $clause->now;
		}

		return ($this->relativeInstant(value: $value, now: $clause->now) ?? $this->parseDate(value: $value, field: $clause->field));
	}//end resolveInstant()

	/**
	 * A signed duration measured from now, or null when the value is not a
	 * duration (it is then parsed as an absolute date).
	 *
	 * @param string            $value The instant as written.
	 * @param DateTimeImmutable $now   Logical "now".
	 *
	 * @return DateTimeImmutable|null
	 */
	private function relativeInstant(string $value, DateTimeImmutable $now): ?DateTimeImmutable {
		$negative = str_starts_with($value, '-');
		try {
			$interval = new DateInterval(substr($value, (int)$negative));
		} catch (Exception $e) {
			return null;
		}

		if ($negative === true) {
			return $now->sub($interval);
		}

		return $now->add($interval);
	}//end relativeInstant()

	/**
	 * Parse the clause's ISO-8601 DateInterval operand. Logs + returns null on failure.
	 *
	 * @param ScheduledFilterClause $clause The leaf clause; field and operator are for diagnostics only.
	 *
	 * @return DateInterval|null
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function parseDuration(ScheduledFilterClause $clause): ?DateInterval {
		$duration = (string)$clause->operand;
		$where    = ['field' => $clause->field, 'operator' => $clause->operator];
		if ($duration === '') {
			$this->logger->debug('ScheduledFilterEvaluator: empty duration (fail-closed)', $where);
			return null;
		}

		try {
			return new DateInterval($duration);
		} catch (Exception $e) {
			$this->logger->debug('ScheduledFilterEvaluator: unparsable duration (fail-closed)', $where + ['value' => $duration, 'error' => $e->getMessage()]);
			return null;
		}
	}//end parseDuration()

	/**
	 * Parse an object-data date value. Accepts strings only; non-string
	 * (null, bool, array, etc.) → null + debug log. Empty string → null.
	 *
	 * @param mixed  $value Raw value from object data.
	 * @param string $field Field name for diagnostics only.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function parseDate($value, string $field): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			$this->logger->debug('ScheduledFilterEvaluator: missing or non-string date (fail-closed)', ['field' => $field]);
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception $e) {
			$this->logger->debug('ScheduledFilterEvaluator: unparsable object date (fail-closed)', ['field' => $field, 'error' => $e->getMessage()]);
			return null;
		}
	}//end parseDate()
}//end class
