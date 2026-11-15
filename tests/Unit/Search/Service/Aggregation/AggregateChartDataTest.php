<?php

/**
 * Unit tests for AggregateChartData, the chart shape of a report.
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
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-chart-data-api-endpoints-for-frontend-visualization
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateChartData;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\AggregateChartData
 */
class AggregateChartDataTest extends TestCase {

	public function testABarChartHasLabelsAndOneSeriesPerMetric(): void {
		$chart = AggregateChartData::fromRows(
			type: 'bar',
			rows: [
				['status' => 'nieuw', 'count' => 30],
				['status' => 'in_behandeling', 'count' => 45],
				['status' => 'wacht_op_info', 'count' => 12],
				['status' => 'afgehandeld', 'count' => 125],
				['status' => 'gesloten', 'count' => 8],
			],
			labelColumn: 'status',
			metricColumns: ['count']
		);

		$this->assertSame(
			[
				'labels' => ['nieuw', 'in_behandeling', 'wacht_op_info', 'afgehandeld', 'gesloten'],
				'series' => [['name' => 'count', 'data' => [30, 45, 12, 125, 8]]],
			],
			$chart
		);
	}//end testABarChartHasLabelsAndOneSeriesPerMetric()

	public function testSeveralMetricsBecomeSeveralSeriesInOrder(): void {
		$chart = AggregateChartData::fromRows(
			type: 'line',
			rows: [['@self.created' => '2026-01', 'count' => 3, 'sum' => 120.5], ['@self.created' => '2026-02', 'count' => 0, 'sum' => '7']],
			labelColumn: '@self.created',
			metricColumns: ['count', 'sum']
		);

		$this->assertSame(['2026-01', '2026-02'], $chart['labels']);
		$this->assertSame([['name' => 'count', 'data' => [3, 0]], ['name' => 'sum', 'data' => [120.5, 7]]], $chart['series']);
		$this->assertArrayNotHasKey('percentages', $chart);
	}//end testSeveralMetricsBecomeSeveralSeriesInOrder()

	public function testAPieChartHasAFlatSeriesAndPercentages(): void {
		$chart = AggregateChartData::fromRows(
			type: 'pie',
			rows: [
				['categorie' => 'Openbare ruimte', 'count' => 80],
				['categorie' => 'Verkeer', 'count' => 60],
				['categorie' => 'Afval', 'count' => 40],
				['categorie' => 'Overig', 'count' => 20],
			],
			labelColumn: 'categorie',
			metricColumns: ['count', 'sum']
		);

		$this->assertSame(
			[
				'labels' => ['Openbare ruimte', 'Verkeer', 'Afval', 'Overig'],
				'series' => [80, 60, 40, 20],
				'percentages' => [40.0, 30.0, 20.0, 10.0],
			],
			$chart
		);
	}//end testAPieChartHasAFlatSeriesAndPercentages()

	public function testPercentagesRoundToOneDecimalAndAnEmptyWholeIsZero(): void {
		$third = AggregateChartData::fromRows(
			type: 'doughnut',
			rows: [['k' => 'a', 'count' => 1], ['k' => 'b', 'count' => 2]],
			labelColumn: 'k',
			metricColumns: ['count']
		);
		$this->assertSame([33.3, 66.7], $third['percentages']);

		$empty = AggregateChartData::fromRows(
			type: 'pie',
			rows: [['k' => 'a', 'count' => 0], ['k' => 'b', 'count' => 0]],
			labelColumn: 'k',
			metricColumns: ['count']
		);
		$this->assertSame([0.0, 0.0], $empty['percentages']);
	}//end testPercentagesRoundToOneDecimalAndAnEmptyWholeIsZero()

	public function testOddValuesAndLabelsAreNormalised(): void {
		$chart = AggregateChartData::fromRows(
			type: 'bar',
			rows: [
				['k' => null, 'count' => null],
				['k' => true, 'count' => 'n/a'],
				['k' => false, 'count' => '2.5'],
				['k' => 7, 'count' => 4],
				['k' => ['x' => 1]],
			],
			labelColumn: 'k',
			metricColumns: ['count']
		);

		$this->assertSame(['', 'true', 'false', '7', '{"x":1}'], $chart['labels']);
		$this->assertSame([0, 0, 2.5, 4, 0], $chart['series'][0]['data']);
	}//end testOddValuesAndLabelsAreNormalised()

	public function testTheTypeDefaultsToBarAndUnknownTypesAreRefused(): void {
		$this->assertSame('bar', AggregateChartData::type(raw: null));
		$this->assertSame('bar', AggregateChartData::type(raw: ''));
		$this->assertSame('line', AggregateChartData::type(raw: ' LINE '));
		$this->assertSame('doughnut', AggregateChartData::type(raw: 'doughnut'));

		foreach (['radar', ['bar'], 3] as $raw) {
			try {
				AggregateChartData::type(raw: $raw);
				$this->fail('type accepted '.json_encode($raw));
			} catch (InvalidArgumentException $e) {
				$this->assertSame('type must be one of: bar, line, area, pie, doughnut', $e->getMessage());
			}
		}
	}//end testTheTypeDefaultsToBarAndUnknownTypesAreRefused()
}//end class
