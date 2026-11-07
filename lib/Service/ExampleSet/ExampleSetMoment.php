<?php

/**
 * OpenRegister Example Set Moment
 *
 * The day, or the moment on a day, that a `{{date:…}}` or `{{datetime:…}}`
 * placeholder names, counted from the install day.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Days and moments counted from the install day.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */
class ExampleSetMoment {
	/**
	 * The first given weekday on or after a day.
	 *
	 * @param DateTimeImmutable $day     The day.
	 * @param string            $weekday `mon` to `sun`.
	 *
	 * @return DateTimeImmutable
	 */
	private function onOrAfter(DateTimeImmutable $day, string $weekday): DateTimeImmutable {
		$wanted = (int) array_search($weekday, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true) + 1;
		$days   = ((($wanted - (int) $day->format('N')) + 7) % 7);

		return $day->add(new DateInterval('P'.$days.'D'));
	}//end onOrAfter()

	/**
	 * A day, or a moment on a day, counted from the install day.
	 *
	 * @param array{kind: string, rest: string} $parts `date` or `datetime`, and the number of days, optionally a
	 *                                                 weekday, and for a moment the time as HH:MM.
	 * @param mixed                             $today The install day.
	 *
	 * @return string|null `2026-10-05`, or for a moment its UTC instant, `2026-10-05T07:12:00+00:00` for 09:12 in
	 *                     Amsterdam; null when malformed. A database datetime column keeps the wall time and drops
	 *                     the offset, and Nextcloud reads it back as UTC, so a moment in any other offset would move.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function dayOrMoment(array $parts, mixed $today): ?string {
		if (($today instanceof DateTimeImmutable) === false || preg_match($this->momentPattern(kind: $parts['kind']), $parts['rest'], $match) !== 1) {
			return null;
		}

		$day = $this->dayOf(today: $today, match: $match);
		if ($parts['kind'] === 'date') {
			return $day->format('Y-m-d');
		}

		return $day->setTime((int) $match[3], (int) $match[4])->setTimezone(new DateTimeZone('UTC'))->format('c');
	}//end dayOrMoment()

	/**
	 * The pattern a day (`date`) or a moment (`datetime`) placeholder's reference must match.
	 *
	 * @param string $kind `date` or `datetime`.
	 *
	 * @return string
	 */
	private function momentPattern(string $kind): string {
		if ($kind === 'datetime') {
			return '/^([+-]?\d{1,4})(?:@(mon|tue|wed|thu|fri|sat|sun))? ([01]\d|2[0-3]):([0-5]\d)$/';
		}

		return '/^([+-]?\d{1,4})(?:@(mon|tue|wed|thu|fri|sat|sun))?$/';
	}//end momentPattern()

	/**
	 * The day a matched reference names: the install day moved by the number of days, then to the weekday when named.
	 *
	 * @param DateTimeImmutable  $today The install day.
	 * @param array<int, string> $match The reference matched against momentPattern().
	 *
	 * @return DateTimeImmutable
	 */
	private function dayOf(DateTimeImmutable $today, array $match): DateTimeImmutable {
		$day = $today->modify(sprintf('%+d days', (int) $match[1]));
		if (($match[2] ?? '') !== '') {
			$day = $this->onOrAfter(day: $day, weekday: $match[2]);
		}

		return $day;
	}//end dayOf()
}//end class
