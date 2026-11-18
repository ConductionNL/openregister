<?php

/**
 * Unit tests for `CrossRegisterReportController::aggregate()`, the
 * aggregation across registers at `/api/reports/aggregate`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-cross-register-reporting
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Controller\CrossRegisterReportController;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Aggregation\CrossRegisterReport;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Controller\CrossRegisterReportController
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportEnvelope
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQueryRules
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQueryShape
 */
class CrossRegisterReportControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private CrossRegisterReport&MockObject $report;

	private AggregationRunnerInterface&MockObject $runner;

	private TimeseriesRequestValidator&MockObject $validator;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->report = $this->createMock(CrossRegisterReport::class);
		$this->runner = $this->createMock(AggregationRunnerInterface::class);
		$this->validator = $this->createMock(TimeseriesRequestValidator::class);
	}//end setUp()

	private function controller(): CrossRegisterReportController {
		return new CrossRegisterReportController($this->request, $this->report, $this->runner, $this->validator);
	}//end controller()

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => ($params[$name] ?? $default)
		);
	}//end stubParams()

	public function testWithoutARegisterItCountsAcrossAllRegisters(): void {
		$this->stubParams([]);
		$this->report->expects($this->once())->method('totals')->with('count', null, [])->willReturn(
			['total' => 15000, 'byRegister' => [['register' => 'zaken', 'count' => 8000], ['register' => 'klanten', 'count' => 7000]]]
		);

		$response = $this->controller()->aggregate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'total' => 15000,
				'byRegister' => [['register' => 'zaken', 'count' => 8000], ['register' => 'klanten', 'count' => 7000]],
				'metric' => 'count',
				'field' => null,
			],
			$response->getData()
		);
	}//end testWithoutARegisterItCountsAcrossAllRegisters()

	public function testRegistersAndFieldArePassedOn(): void {
		$this->stubParams(['metric' => 'SUM', 'field' => 'bedrag', 'registers' => 'zaken, klanten,']);
		$this->report->expects($this->once())->method('totals')
			->with('sum', 'bedrag', ['zaken', 'klanten'])
			->willReturn(['total' => 3, 'byRegister' => []]);

		$response = $this->controller()->aggregate();

		$this->assertSame('bedrag', $response->getData()['field']);
		$this->assertSame('sum', $response->getData()['metric']);
	}//end testRegistersAndFieldArePassedOn()

	public function testAnInvalidMetricIsABadRequest(): void {
		$this->stubParams(['metric' => 'avg', 'field' => 'bedrag']);
		$this->report->method('totals')->willThrowException(new \InvalidArgumentException('avg cannot be combined'));

		$response = $this->controller()->aggregate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'avg cannot be combined'], $response->getData());
	}//end testAnInvalidMetricIsABadRequest()

	public function testAMonthlySeriesValidatesEachPairOnItsOwnSchema(): void {
		$this->stubParams(
			['registers' => 'zaken,klanten', 'groupBy' => '@self.created', 'interval' => 'month', 'from' => '2026-01-01', 'to' => '2026-04-01']
		);
		$schema = $this->createMock(Schema::class);
		$query = AggregationQuery::create(metric: 'count');
		$this->runner->expects($this->once())->method('findSchema')->with('20', '2')->willReturn($schema);
		$this->validator->expects($this->once())->method('validate')->with(
			[
				'field' => '_created',
				'interval' => 'month',
				'from' => '2026-01-01',
				'to' => '2026-04-01',
				'metric' => 'count',
				'metricField' => null,
				'filter' => [],
				'cumulative' => false,
			],
			$schema
		)->willReturn($query);
		$this->report->expects($this->once())->method('series')->willReturnCallback(
			function (array $registerRefs, string $gap, array $range, callable $queryFor) use ($query): array {
				$this->assertSame(['zaken', 'klanten'], $registerRefs);
				$this->assertSame('month', $gap);
				$this->assertSame(['from' => '2026-01-01', 'to' => '2026-04-01'], $range);
				$this->assertSame($query, $queryFor('2', '20'));
				return ['labels' => ['2026-01'], 'series' => [['name' => 'zaken', 'data' => [100]]]];
			}
		);

		$response = $this->controller()->aggregate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['labels' => ['2026-01'], 'series' => [['name' => 'zaken', 'data' => [100]]], 'metric' => 'count', 'interval' => 'month'],
			$response->getData()
		);
	}//end testAMonthlySeriesValidatesEachPairOnItsOwnSchema()

	public function testASeriesWithoutARangeCoversTheLastTwelveBuckets(): void {
		$this->stubParams(['interval' => 'month']);
		$this->report->expects($this->once())->method('series')->willReturnCallback(
			function (array $registerRefs, string $gap, array $range): array {
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-01T00:00:00Z$/', $range['from']);
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-01T00:00:00Z$/', $range['to']);
				return ['labels' => [], 'series' => []];
			}
		);

		$this->assertSame(Http::STATUS_OK, $this->controller()->aggregate()->getStatus());
	}//end testASeriesWithoutARangeCoversTheLastTwelveBuckets()

	public function testASeriesOfAnotherMetricOrAnUnknownIntervalIsRefused(): void {
		$this->report->expects($this->never())->method('series');

		$this->stubParams(['interval' => 'month', 'metric' => 'sum', 'field' => 'bedrag']);
		$sum = $this->controller()->aggregate();

		$this->request = $this->createMock(IRequest::class);
		$this->stubParams(['interval' => 'fortnight']);
		$fortnight = $this->controller()->aggregate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $sum->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $fortnight->getStatus());
		$this->assertStringContainsString('interval', $fortnight->getData()['error']);
	}//end testASeriesOfAnotherMetricOrAnUnknownIntervalIsRefused()
}//end class
