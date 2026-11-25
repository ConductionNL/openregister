<?php

/**
 * Week histogram buckets follow a calendar's declared first week of the year.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Object\FacetCalculation;
use OCA\OpenRegister\Service\Object\FacetWeekLabels;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\FacetWeekLabels
 * @covers \OCA\OpenRegister\Service\Object\FacetCalculation
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarBlackouts
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarWeekNumbering
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses   \OCA\OpenRegister\Service\Object\FacetEntry
 * @uses   \OCA\OpenRegister\Service\Object\FacetListing
 */
class FacetWeekLabelsTest extends TestCase {

	/**
	 * The labels of a calendar declaring this first week.
	 *
	 * @param string $firstWeek The declaration.
	 *
	 * @return FacetWeekLabels
	 */
	private function labels(string $firstWeek): FacetWeekLabels {
		return new FacetWeekLabels(
			calendar: WorkingCalendar::fromArray(
				[
					'slug' => 'gemeente-x',
					'workingWeekdays' => [1, 2, 3, 4, 5],
					'hoursPerWorkingDay' => 8,
					'timezone' => 'Europe/Amsterdam',
					'firstWeekOfYear' => $firstWeek,
				]
			)
		);
	}//end labels()

	/**
	 * A week histogram as the database keyed it, by ISO week.
	 *
	 * @param array<int, string> $keys The ISO year-week keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function weekHistogram(array $keys): array {
		$buckets = [];
		foreach ($keys as $index => $key) {
			$buckets[] = ['key' => $key, 'results' => ($index + 1), 'from' => 'from-' . $key, 'to' => 'to-' . $key];
		}

		return ['type' => 'date_histogram', 'interval' => 'week', 'buckets' => $buckets];
	}//end weekHistogram()

	/**
	 * When week 1 holds 1 January, the ISO week holding 1 January 2027 (ISO 2026-53) is week 1 of 2027.
	 *
	 * Counts and from/to stay: every declaration starts its weeks on a Monday.
	 *
	 * @return void
	 */
	public function testWeekOneHoldingJanuaryFirstRelabelsTheTurnOfTheYear(): void {
		$relabelled = $this->labels(firstWeek: 'containsJanuary1')->relabel(facets: self::weekHistogram(keys: ['2026-52', '2026-53', '2027-01']));

		$this->assertSame(
			[
				['key' => '2026-52', 'results' => 1, 'from' => 'from-2026-52', 'to' => 'to-2026-52'],
				['key' => '2027-01', 'results' => 2, 'from' => 'from-2026-53', 'to' => 'to-2026-53'],
				['key' => '2027-02', 'results' => 3, 'from' => 'from-2027-01', 'to' => 'to-2027-01'],
			],
			$relabelled['buckets']
		);
	}//end testWeekOneHoldingJanuaryFirstRelabelsTheTurnOfTheYear()

	/**
	 * When week 1 must be whole, ISO week 1 of 2026 (from Monday 29 December 2025) is the last week of 2025.
	 *
	 * @return void
	 */
	public function testAFirstFullWeekPushesAPartialWeekIntoTheYearBefore(): void {
		$relabelled = $this->labels(firstWeek: 'firstFullWeek')->relabel(facets: self::weekHistogram(keys: ['2026-01', '2026-02', '2026-53']));

		$this->assertSame(['2025-52', '2026-01', '2026-52'], array_column($relabelled['buckets'], 'key'));
		$this->assertSame('firstFullWeek', $this->labels(firstWeek: 'firstFullWeek')->declaration());
	}//end testAFirstFullWeekPushesAPartialWeekIntoTheYearBefore()

	/**
	 * Week histograms are found under @self, per field and per schema; other facets and odd keys stay as they are.
	 *
	 * @return void
	 */
	public function testOnlyWeekHistogramsChangeAtAnyDepth(): void {
		$month = ['type' => 'date_histogram', 'interval' => 'month', 'buckets' => [['key' => '2026-53', 'results' => 4]]];
		$terms = ['type' => 'terms', 'buckets' => [['key' => '2026-53', 'results' => 5]]];
		$odd = ['type' => 'date_histogram', 'interval' => 'week', 'buckets' => [['key' => 'unknown', 'results' => 1], ['key' => '2026-54', 'results' => 1], 'not a bucket']];
		$facets = [
			'@self' => ['created' => self::weekHistogram(keys: ['2026-53']), 'updated' => $month],
			'status' => $terms,
			'schema-7' => ['ingediend' => self::weekHistogram(keys: ['2026-53'])],
			'odd' => $odd,
		];

		$relabelled = $this->labels(firstWeek: 'containsJanuary1')->relabel(facets: $facets);

		$this->assertSame('2027-01', $relabelled['@self']['created']['buckets'][0]['key']);
		$this->assertSame('2027-01', $relabelled['schema-7']['ingediend']['buckets'][0]['key']);
		$this->assertSame($month, $relabelled['@self']['updated']);
		$this->assertSame($terms, $relabelled['status']);
		$this->assertSame($odd, $relabelled['odd']);
	}//end testOnlyWeekHistogramsChangeAtAnyDepth()

	/**
	 * The facet calculation hands the mapper's facets through the labels before formatting them.
	 *
	 * @return void
	 */
	public function testTheFacetCalculationReportsTheDeclaredWeeks(): void {
		$mapper = $this->createMock(MagicObjectSearchInterface::class);
		$mapper->method('getSimpleFacets')->willReturn(['@self' => ['created' => self::weekHistogram(keys: ['2026-53'])]]);

		$declared = new FacetCalculation(mapper: $mapper, logger: new NullLogger(), weeks: $this->labels(firstWeek: 'containsJanuary1'));
		$iso      = new FacetCalculation(mapper: $mapper, logger: new NullLogger());

		$this->assertSame('2027-01', $declared->calculate(facetQuery: [], facetableConfig: [])['facets']['_created']['data']['buckets'][0]['value']);
		$this->assertSame('2026-53', $iso->calculate(facetQuery: [], facetableConfig: [])['facets']['_created']['data']['buckets'][0]['value']);
	}//end testTheFacetCalculationReportsTheDeclaredWeeks()
}//end class
