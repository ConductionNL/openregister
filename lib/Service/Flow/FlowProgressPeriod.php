<?php

/**
 * The time window a progress report counts over, read from the request.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateInterval;
use DateTime;
use Exception;
use InvalidArgumentException;

/**
 * `from` and `to` of a progress read: the last 90 days by default, at most
 * 366 days, and `from` never after `to`.
 *
 * A date without a time means the whole day: `from` starts at its first
 * second, `to` ends at its last. A refusal names the parameter it is about,
 * so the caller can answer 400 with that message.
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */
final class FlowProgressPeriod {

	/**
	 * The window when the caller names none, in days.
	 *
	 * @var int
	 */
	public const DEFAULT_DAYS = 90;

	/**
	 * The longest window a caller may ask for, in days.
	 *
	 * @var int
	 */
	public const MAX_DAYS = 366;

	/**
	 * The first moment counted.
	 *
	 * @var DateTime
	 */
	public readonly DateTime $from;

	/**
	 * The last moment counted.
	 *
	 * @var DateTime
	 */
	public readonly DateTime $to;

	/**
	 * Read and check the window.
	 *
	 * @param string|null $from The `from` parameter, or null for 90 days before `to`.
	 * @param string|null $to   The `to` parameter, or null for now.
	 * @param DateTime    $now  The clock instant.
	 *
	 * @throws InvalidArgumentException When a parameter does not parse, or the window is reversed or too long.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 */
	public function __construct(?string $from, ?string $to, DateTime $now) {
		$end = (clone $now);
		if ($this->given(value: $to) === true) {
			$end = $this->moment(name: 'to', value: (string)$to, endOfDay: true);
		}

		$start = (clone $end)->sub(new DateInterval('P' . self::DEFAULT_DAYS . 'D'));
		if ($this->given(value: $from) === true) {
			$start = $this->moment(name: 'from', value: (string)$from, endOfDay: false);
		}

		if ($start > $end) {
			throw new InvalidArgumentException('Parameter "from" must not be after "to".');
		}

		$longest = (clone $start)->add(new DateInterval('P' . self::MAX_DAYS . 'D'));
		if ($end > $longest) {
			throw new InvalidArgumentException(
				sprintf('Parameter "from" is too early: a window spans at most %d days.', self::MAX_DAYS)
			);
		}

		$this->from = $start;
		$this->to = $end;
	}//end __construct()

	/**
	 * Whether a parameter was given at all.
	 *
	 * @param string|null $value The parameter.
	 *
	 * @return bool
	 */
	private function given(?string $value): bool {
		return $value !== null && trim($value) !== '';
	}//end given()

	/**
	 * One parameter as a moment.
	 *
	 * @param string $name     The parameter's name, for the refusal.
	 * @param string $value    The parameter.
	 * @param bool   $endOfDay For a bare date, take its last second rather than its first.
	 *
	 * @return DateTime The moment.
	 *
	 * @throws InvalidArgumentException When it does not parse.
	 */
	private function moment(string $name, string $value, bool $endOfDay): DateTime {
		$value = trim($value);
		try {
			$moment = new DateTime($value);
		} catch (Exception $e) {
			throw new InvalidArgumentException(sprintf('Parameter "%s" is not a date: %s', $name, $value));
		}

		if ($endOfDay === true && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$moment->setTime(23, 59, 59);
		}

		return $moment;
	}//end moment()
}//end class
