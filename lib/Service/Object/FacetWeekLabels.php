<?php

/**
 * OpenRegister FacetWeekLabels
 *
 * Week histogram buckets labelled by a calendar's declared first week of the year.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Kernel\Contracts\FacetWeekRelabelInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;

/**
 * Relabels the week buckets of date histograms, which the database groups by ISO week.
 *
 * Every declaration a calendar can make starts its weeks on a Monday, so an
 * ISO bucket spans exactly one declared week: the bucket, its count and its
 * from/to dates stay, and only the year-week key changes.
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */
final class FacetWeekLabels implements FacetWeekRelabelInterface {

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendar $calendar The calendar whose first week of the year is followed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function __construct(
		private readonly WorkingCalendar $calendar,
	) {
	}//end __construct()

	/**
	 * The declaration followed: `iso`, `containsJanuary1` or `firstFullWeek`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function declaration(): string {
		return $this->calendar->getFirstWeekOfYear();
	}//end declaration()

	/**
	 * The raw facets with every week bucket keyed by the declared week, at any depth.
	 *
	 * @param array<int|string, mixed> $facets The facets as the mapper computed them.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function relabel(array $facets): array {
		if (self::isWeekHistogram(facet: $facets) === true) {
			$facets['buckets'] = array_map(fn (mixed $bucket): mixed => $this->bucket(bucket: $bucket), $facets['buckets']);
			return $facets;
		}

		foreach ($facets as $name => $facet) {
			if (is_array($facet) === true) {
				$facets[$name] = $this->relabel(facets: $facet);
			}
		}

		return $facets;
	}//end relabel()

	/**
	 * Whether a node is a week histogram.
	 *
	 * @param array<int|string, mixed> $facet The node.
	 *
	 * @return bool
	 */
	private static function isWeekHistogram(array $facet): bool {
		return ($facet['type'] ?? null) === 'date_histogram'
			&& ($facet['interval'] ?? null) === 'week'
			&& is_array($facet['buckets'] ?? null) === true;
	}//end isWeekHistogram()

	/**
	 * One bucket keyed by the declared week of its Monday; a bucket whose key is no ISO week is kept as it is.
	 *
	 * @param mixed $bucket The bucket.
	 *
	 * @return mixed
	 */
	private function bucket(mixed $bucket): mixed {
		if (is_array($bucket) === false) {
			return $bucket;
		}

		$monday = self::mondayOf(key: (string) ($bucket['key'] ?? ''));
		if ($monday === null) {
			return $bucket;
		}

		$week = $this->calendar->weekOf(moment: $monday);
		$bucket['key'] = sprintf('%04d-%02d', $week['year'], $week['week']);

		return $bucket;
	}//end bucket()

	/**
	 * Noon on the Monday of an ISO week key `<iso-year>-<iso-week>`, or null for another shape.
	 *
	 * Noon, so the calendar's own zone reads the same day.
	 *
	 * @param string $key The bucket key, e.g. `2026-01`.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function mondayOf(string $key): ?DateTimeImmutable {
		if (preg_match('/^(\d{4})-(\d{1,2})$/', $key, $matches) !== 1) {
			return null;
		}

		$week = (int) $matches[2];
		if ($week < 1 || $week > 53) {
			return null;
		}

		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
			->setISODate((int) $matches[1], $week, 1)
			->setTime(12, 0);
	}//end mondayOf()
}//end class
