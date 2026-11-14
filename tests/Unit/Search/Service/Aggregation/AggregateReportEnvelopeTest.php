<?php

/**
 * Unit tests for AggregateReportEnvelope: the report rows, the total, the
 * zero-filled time buckets with their labels, and the `having` filter.
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
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportEnvelope
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 */
class AggregateReportEnvelopeTest extends TestCase {

	public function testSingleMetricGroupsBecomeRowsNamedByFieldAndMetric(): void {
		$rows = AggregateReportEnvelope::rows(
			groups: [['key' => 'nieuw', 'value' => 30], ['key' => 'afgehandeld', 'value' => 125]],
			groupFields: ['status'],
			metrics: [['metric' => 'count', 'field' => null]]
		);

		$this->assertSame([['status' => 'nieuw', 'count' => 30], ['status' => 'afgehandeld', 'count' => 125]], $rows);
	}//end testSingleMetricGroupsBecomeRowsNamedByFieldAndMetric()

	public function testMultiMetricValuesAreNamedByTheirMetric(): void {
		$rows = AggregateReportEnvelope::rows(
			groups: [['key' => 'betaald', 'values' => ['count' => 50, 'sum_bedrag' => 125000.0, 'avg_bedrag' => 2500.0]]],
			groupFields: ['status'],
			metrics: [
				['metric' => 'count', 'field' => null],
				['metric' => 'sum', 'field' => 'bedrag'],
				['metric' => 'avg', 'field' => 'bedrag'],
			]
		);

		$this->assertSame([['status' => 'betaald', 'count' => 50, 'sum' => 125000.0, 'avg' => 2500.0]], $rows);
	}//end testMultiMetricValuesAreNamedByTheirMetric()

	public function testAMetricUsedTwiceKeepsTheRunnerKey(): void {
		$names = AggregateReportEnvelope::metricNames(
			metrics: [['metric' => 'sum', 'field' => 'bedrag'], ['metric' => 'sum', 'field' => 'btw']]
		);

		$this->assertSame(['sum_bedrag' => 'sum_bedrag', 'sum_btw' => 'sum_btw'], $names);
	}//end testAMetricUsedTwiceKeepsTheRunnerKey()

	public function testCrossTabKeysAreReadThroughTheSelfColumn(): void {
		$rows = AggregateReportEnvelope::rows(
			groups: [['keys' => ['status' => 'nieuw', '_created' => '2026-01-01'], 'value' => 3]],
			groupFields: ['status', '@self.created'],
			metrics: [['metric' => 'count', 'field' => null]]
		);

		$this->assertSame([['status' => 'nieuw', '@self.created' => '2026-01-01', 'count' => 3]], $rows);
	}//end testCrossTabKeysAreReadThroughTheSelfColumn()

	public function testTotalSumsTheCountColumn(): void {
		$this->assertSame(
			200,
			AggregateReportEnvelope::total(rows: [['count' => 30], ['count' => 45], ['count' => 125]])
		);
	}//end testTotalSumsTheCountColumn()

	public function testTotalWithoutCountIsTheRowCount(): void {
		$this->assertSame(2, AggregateReportEnvelope::total(rows: [['sum' => 10.5], ['sum' => 4.0]]));
	}//end testTotalWithoutCountIsTheRowCount()

	public function testSelfFieldsMapToTheirColumns(): void {
		$this->assertSame('_created', AggregateReportEnvelope::column(field: '@self.created'));
		$this->assertSame('_updated', AggregateReportEnvelope::column(field: '@self.updated'));
		$this->assertSame('status', AggregateReportEnvelope::column(field: 'status'));
	}//end testSelfFieldsMapToTheirColumns()

	public function testMissingMonthsAreFilledWithZeroInTimeOrder(): void {
		$filled = AggregateReportEnvelope::fillGaps(
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
		$filled = AggregateReportEnvelope::fillGaps(
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
		$filled = AggregateReportEnvelope::fillGaps(
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
			AggregateReportEnvelope::fillGaps(
				groups: $groups,
				gap: 'minute',
				range: ['from' => '2026-01-01T00:00:00Z', 'to' => '2026-01-02T00:00:00Z'],
				metric: 'count'
			)
		);
	}//end testATooWideRangeIsNotFilled()

	public function testWeeksStartOnMonday(): void {
		$filled = AggregateReportEnvelope::fillGaps(
			groups: [],
			gap: 'week',
			range: ['from' => '2026-01-07T00:00:00Z', 'to' => '2026-01-13T00:00:00Z'],
			metric: 'count'
		);

		$this->assertSame(['2026-01-05T00:00:00Z', '2026-01-12T00:00:00Z'], array_column($filled, 'key'));
	}//end testWeeksStartOnMonday()

	public function testLabelsAreIso8601PerInterval(): void {
		$this->assertSame('2026-01', AggregateReportEnvelope::label(key: '2026-01-01T00:00:00Z', gap: 'month'));
		$this->assertSame('2026-01-05', AggregateReportEnvelope::label(key: '2026-01-05T00:00:00Z', gap: 'day'));
		$this->assertSame('2026-W02', AggregateReportEnvelope::label(key: '2026-01-05T00:00:00Z', gap: 'week'));
		$this->assertSame('2026-Q3', AggregateReportEnvelope::label(key: '2026-07-01T00:00:00Z', gap: 'quarter'));
		$this->assertSame('2026', AggregateReportEnvelope::label(key: '2026-01-01T00:00:00Z', gap: 'year'));
		$this->assertSame('2026-01-05T09:00', AggregateReportEnvelope::label(key: '2026-01-05T09:00:00Z', gap: 'hour'));
		$this->assertSame('not-a-date', AggregateReportEnvelope::label(key: 'not-a-date', gap: 'month'));
	}//end testLabelsAreIso8601PerInterval()

	public function testTheDefaultRangeIsTheLastTwelveMonths(): void {
		$range = AggregateReportEnvelope::defaultRange(
			gap: 'month',
			now: new DateTimeImmutable('2026-11-14T10:30:00', new DateTimeZone('UTC'))
		);

		$this->assertSame(['from' => '2025-12-01T00:00:00Z', 'to' => '2026-12-01T00:00:00Z'], $range);
	}//end testTheDefaultRangeIsTheLastTwelveMonths()

	public function testHavingKeepsOnlyTheRowsEveryConditionHoldsFor(): void {
		$having = AggregateReportEnvelope::parseHaving(raw: ['count' => ['gt' => '10', 'lte' => '50']], columns: ['count']);
		$rows = [['status' => 'a', 'count' => 5], ['status' => 'b', 'count' => 30], ['status' => 'c', 'count' => 60], ['status' => 'd', 'count' => null]];

		$this->assertSame([['status' => 'b', 'count' => 30]], AggregateReportEnvelope::applyHaving(rows: $rows, having: $having));
	}//end testHavingKeepsOnlyTheRowsEveryConditionHoldsFor()

	public function testABareHavingValueMeansEquality(): void {
		$this->assertSame(['sum' => ['eq' => 7.0]], AggregateReportEnvelope::parseHaving(raw: ['sum' => '7'], columns: ['sum']));
	}//end testABareHavingValueMeansEquality()

	public function testNoHavingKeepsEveryRow(): void {
		$rows = [['count' => 1]];

		$this->assertSame([], AggregateReportEnvelope::parseHaving(raw: null, columns: ['count']));
		$this->assertSame($rows, AggregateReportEnvelope::applyHaving(rows: $rows, having: []));
	}//end testNoHavingKeepsEveryRow()

	public function testHavingOnAnUnrequestedMetricIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`having` names "sum", which is not one of the metrics (count)');

		AggregateReportEnvelope::parseHaving(raw: ['sum' => ['gt' => 1]], columns: ['count']);
	}//end testHavingOnAnUnrequestedMetricIsRefused()

	public function testAnUnknownHavingOperatorIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`having` operator "like" MUST be one of eq, ne, gt, gte, lt, lte');

		AggregateReportEnvelope::parseHaving(raw: ['count' => ['like' => 1]], columns: ['count']);
	}//end testAnUnknownHavingOperatorIsRefused()

	public function testANonNumericHavingOperandIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`having[count][gt]` MUST be a number');

		AggregateReportEnvelope::parseHaving(raw: ['count' => ['gt' => 'many']], columns: ['count']);
	}//end testANonNumericHavingOperandIsRefused()

	public function testAScalarHavingIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		AggregateReportEnvelope::parseHaving(raw: 'count>10', columns: ['count']);
	}//end testAScalarHavingIsRefused()
}//end class
