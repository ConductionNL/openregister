<?php

/**
 * Unit tests for AggregateReportPeriods: zero-filled, labelled time buckets,
 * the default range, created-date ranges, period labels, changes and trends.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 */
class AggregateReportPeriodsTest extends TestCase {
	public function testMissingMonthsAreFilledWithZeroInTimeOrder(): void {
		$filled = AggregateReportPeriods::fillGaps(
			groups: [
				['key' => '2026-03-01T00:00:00Z', 'value' => 4],
				['key' => '2026-01-01T00:00:00Z', 'value' => 2],
			],
			gap: 'month',
			range: ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-04-01T00:00:00Z'],
			metric: 'count'
		);

		$this->assertSame(
			[
				['key' => '2026-01-01T00:00:00Z', 'value' => 2],
				['key' => '2026-02-01T00:00:00Z', 'value' => 0],
				['key' => '2026-03-01T00:00:00Z', 'value' => 4],
			],
			$filled
		);
	}//end testMissingMonthsAreFilledWithZeroInTimeOrder()

	public function testAnEmptyAverageBucketIsNull(): void {
		$filled = AggregateReportPeriods::fillGaps(
			groups: [],
			gap: 'day',
			range: ['from' => '2026-01-01T12:00:00Z', 'to' => '2026-01-03T00:00:00Z'],
			metric: 'avg'
		);

		$this->assertSame(
			[['key' => '2026-01-01T00:00:00Z', 'value' => null], ['key' => '2026-01-02T00:00:00Z', 'value' => null]],
			$filled
		);
	}//end testAnEmptyAverageBucketIsNull()

	public function testABucketOutsideTheComputedStartsIsKept(): void {
		$filled = AggregateReportPeriods::fillGaps(
			groups: [['key' => 'odd', 'value' => 1]],
			gap: 'year',
			range: ['from' => '2025-01-01T00:00:00Z', 'to' => '2026-01-01T00:00:00Z'],
			metric: 'count'
		);

		$this->assertSame([['key' => '2025-01-01T00:00:00Z', 'value' => 0], ['key' => 'odd', 'value' => 1]], $filled);
	}//end testABucketOutsideTheComputedStartsIsKept()

	public function testATooWideRangeIsNotFilled(): void {
		$groups = [['key' => '2026-01-01T00:00:00Z', 'value' => 1]];

		$this->assertSame(
			$groups,
			AggregateReportPeriods::fillGaps(
				groups: $groups,
				gap: 'minute',
				range: ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-01-02T00:00:00Z'],
				metric: 'count'
			)
		);
	}//end testATooWideRangeIsNotFilled()

	public function testWeeksStartOnMonday(): void {
		$filled = AggregateReportPeriods::fillGaps(
			groups: [],
			gap: 'week',
			range: ['from' => '2026-01-07T00:00:00Z', 'to' => '2026-01-13T00:00:00Z'],
			metric: 'count'
		);

		$this->assertSame(['2026-01-05T00:00:00Z', '2026-01-12T00:00:00Z'], array_column($filled, 'key'));
	}//end testWeeksStartOnMonday()

	public function testLabelsAreIso8601PerInterval(): void {
		$this->assertSame('2026-01', AggregateReportPeriods::label(key: '2026-01-01T00:00:00Z', gap: 'month'));
		$this->assertSame('2026-01-05', AggregateReportPeriods::label(key: '2026-01-05T00:00:00Z', gap: 'day'));
		$this->assertSame('2026-W02', AggregateReportPeriods::label(key: '2026-01-05T00:00:00Z', gap: 'week'));
		$this->assertSame('2026-Q3', AggregateReportPeriods::label(key: '2026-07-01T00:00:00Z', gap: 'quarter'));
		$this->assertSame('2026', AggregateReportPeriods::label(key: '2026-01-01T00:00:00Z', gap: 'year'));
		$this->assertSame('2026-01-05T09:00', AggregateReportPeriods::label(key: '2026-01-05T09:00:00Z', gap: 'hour'));
		$this->assertSame('not-a-date', AggregateReportPeriods::label(key: 'not-a-date', gap: 'month'));
	}//end testLabelsAreIso8601PerInterval()

	public function testTheDefaultRangeIsTheLastTwelveMonths(): void {
		$range = AggregateReportPeriods::defaultRange(
			gap: 'month',
			now: new DateTimeImmutable('2026-11-14T10:30:00', new DateTimeZone('UTC'))
		);

		$this->assertSame(['from' => '2025-12-01T00:00:00Z', 'to' => '2026-12-01T00:00:00Z'], $range);
	}//end testTheDefaultRangeIsTheLastTwelveMonths()
	public function testADateOnlyRangeCoversTheWholeLastDay(): void {
		$this->assertSame(
			['gte' => '2026-01-01', 'lt' => '2026-04-01'],
			AggregateReportPeriods::createdRange(from: '2026-01-01', to: '2026-03-31')
		);
	}//end testADateOnlyRangeCoversTheWholeLastDay()

	public function testAMomentRangeIsInclusive(): void {
		$this->assertSame(
			['lte' => '2026-03-31T12:00:00Z'],
			AggregateReportPeriods::createdRange(from: '', to: '2026-03-31T12:00:00Z')
		);
		$this->assertSame([], AggregateReportPeriods::createdRange(from: '', to: ''));
	}//end testAMomentRangeIsInclusive()

	public function testAnUnparseableRangeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`from` MUST be an ISO-8601 date or datetime');

		AggregateReportPeriods::createdRange(from: 'last tuesday-ish', to: '');
	}//end testAnUnparseableRangeIsRefused()

	public function testPeriodLabelsNameWholeQuartersMonthsAndYears(): void {
		$this->assertSame('Q1 2026', AggregateReportPeriods::periodLabel(from: '2026-01-01', to: '2026-03-31'));
		$this->assertSame('Q4 2025', AggregateReportPeriods::periodLabel(from: '2025-10-01', to: '2025-12-31'));
		$this->assertSame('2026-02', AggregateReportPeriods::periodLabel(from: '2026-02-01', to: '2026-02-28'));
		$this->assertSame('2025', AggregateReportPeriods::periodLabel(from: '2025-01-01', to: '2025-12-31'));
		$this->assertSame('2026-01-05/2026-01-20', AggregateReportPeriods::periodLabel(from: '2026-01-05', to: '2026-01-20'));
	}//end testPeriodLabelsNameWholeQuartersMonthsAndYears()

	public function testChangeIsTheDifferenceAndItsPercentage(): void {
		$this->assertSame(['absolute' => 70, 'percentage' => 18.42], AggregateReportPeriods::change(current: 450, previous: 380));
		$this->assertSame(['absolute' => 5, 'percentage' => null], AggregateReportPeriods::change(current: 5, previous: 0));
		$this->assertSame(['absolute' => null, 'percentage' => null], AggregateReportPeriods::change(current: 5, previous: null));
	}//end testChangeIsTheDifferenceAndItsPercentage()

	public function testTrendCarriesThePreviousBucketAndTheChange(): void {
		$rows = AggregateReportPeriods::withTrend(
			rows: [['m' => '2026-01', 'count' => 10], ['m' => '2026-02', 'count' => 15], ['m' => '2026-03', 'count' => 12]],
			column: 'count'
		);

		$this->assertSame(
			[
				['m' => '2026-01', 'count' => 10, 'previousCount' => null, 'change' => null],
				['m' => '2026-02', 'count' => 15, 'previousCount' => 10, 'change' => 50.0],
				['m' => '2026-03', 'count' => 12, 'previousCount' => 15, 'change' => -20.0],
			],
			$rows
		);
	}//end testTrendCarriesThePreviousBucketAndTheChange()
}//end class
