<?php

/**
 * Unit tests for `AggregationController::report()`, the reporting
 * aggregation at `/api/objects/{register}/{schema}/aggregate`.
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
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Controller\AggregationController;
use OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Controller\AggregationController
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportEnvelope
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices
 * @uses   \OCA\OpenRegister\Search\Controller\Support\AggregationRun
 * @uses   \OCA\OpenRegister\Support\FilterParams
 * @uses   \OCA\OpenRegister\Support\AggregationFilterRequest
 */
class AggregationControllerReportTest extends TestCase {

	private AggregationRunner&MockObject $runner;

	private IRequest&MockObject $request;

	private TimeseriesRequestValidator&MockObject $validator;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->runner = $this->createMock(AggregationRunner::class);
		$this->validator = $this->createMock(TimeseriesRequestValidator::class);
	}//end setUp()

	private function makeController(): AggregationController {
		return new AggregationController($this->request, new AggregationControllerServices($this->runner, $this->validator));
	}//end makeController()

	/**
	 * @param array<string, mixed> $params
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, $default = null) use ($params) {
				return array_key_exists($name, $params) ? $params[$name] : $default;
			}
		);
		$this->request->method('getParams')->willReturn($params);
	}//end stubParams()

	public function testCountByStatusReturnsResultsAndTotal(): void {
		$this->stubParams(['groupBy' => 'status', 'metric' => 'count']);
		$captured = null;
		$this->runner->expects($this->once())->method('runAdhocByRef')->willReturnCallback(
			function (string $reg, string $sch, AggregationQuery $query) use (&$captured) {
				$captured = $query;
				return [
					'groups' => [['key' => 'nieuw', 'value' => 30], ['key' => 'in_behandeling', 'value' => 45], ['key' => 'afgehandeld', 'value' => 125]],
					'backend' => 'postgres',
					'cached' => false,
				];
			}
		);

		$response = $this->makeController()->report('zaken', 'meldingen');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					['status' => 'nieuw', 'count' => 30],
					['status' => 'in_behandeling', 'count' => 45],
					['status' => 'afgehandeld', 'count' => 125],
				],
				'total' => 200,
				'metric' => 'count',
				'field' => null,
				'backend' => 'postgres',
				'cached' => false,
			],
			$response->getData()
		);
		$this->assertSame('miss', $response->getHeaders()['X-OR-Cache'] ?? null);
		$this->assertSame('MISS', $response->getHeaders()['X-Cache'] ?? null);
		$this->assertSame(['field' => 'status'], $captured->groupBy);
		$this->assertSame('count', $captured->metric);
	}//end testCountByStatusReturnsResultsAndTotal()

	public function testSumNamesTheMetricAndTheField(): void {
		$this->stubParams(['groupBy' => 'categorie', 'metric' => 'sum', 'field' => 'bedrag']);
		$this->runner->method('runAdhocByRef')->willReturn(['groups' => [['key' => 'wonen', 'value' => 1500.5]], 'backend' => 'mysql', 'cached' => true]);

		$data = $this->makeController()->report('r', 's')->getData();

		$this->assertSame([['categorie' => 'wonen', 'sum' => 1500.5]], $data['results']);
		$this->assertSame('sum', $data['metric']);
		$this->assertSame('bedrag', $data['field']);
		$this->assertSame(1, $data['total']);
	}//end testSumNamesTheMetricAndTheField()

	public function testSeveralMetricsShareOneRow(): void {
		$this->stubParams(['groupBy' => 'status', 'metric' => 'count,sum,avg', 'field' => 'bedrag']);
		$captured = null;
		$this->runner->method('runAdhocByRef')->willReturnCallback(
			function (string $reg, string $sch, AggregationQuery $query) use (&$captured) {
				$captured = $query;
				return ['groups' => [['key' => 'betaald', 'values' => ['count' => 50, 'sum_bedrag' => 125000.0, 'avg_bedrag' => 2500.0]]]];
			}
		);

		$data = $this->makeController()->report('r', 'facturen')->getData();

		$this->assertSame([['status' => 'betaald', 'count' => 50, 'sum' => 125000.0, 'avg' => 2500.0]], $data['results']);
		$this->assertSame('count,sum,avg', $data['metric']);
		$this->assertCount(3, $captured->metrics);
	}//end testSeveralMetricsShareOneRow()

	public function testHavingFiltersTheRowsButNotTheTotal(): void {
		$this->stubParams(['groupBy' => 'status', 'having' => ['count' => ['gte' => '40']]]);
		$this->runner->method('runAdhocByRef')->willReturn(['groups' => [['key' => 'a', 'value' => 30], ['key' => 'b', 'value' => 45]]]);

		$data = $this->makeController()->report('r', 's')->getData();

		$this->assertSame([['status' => 'b', 'count' => 45]], $data['results']);
		$this->assertSame(75, $data['total']);
	}//end testHavingFiltersTheRowsButNotTheTotal()

	public function testHavingOnAMetricNotAskedForIsA400AndNeverRuns(): void {
		$this->stubParams(['groupBy' => 'status', 'having' => ['sum' => ['gt' => '1']]]);
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$response = $this->makeController()->report('r', 's');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('not one of the metrics', $response->getData()['error']);
	}//end testHavingOnAMetricNotAskedForIsA400AndNeverRuns()

	public function testGroupByIsRequired(): void {
		$this->stubParams(['metric' => 'count']);
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$response = $this->makeController()->report('r', 's');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'groupBy is required'], $response->getData());
	}//end testGroupByIsRequired()

	public function testARefusedCallerGets403(): void {
		$this->stubParams(['groupBy' => 'status']);
		$this->runner->method('runAdhocByRef')->willThrowException(new NotAuthorizedException('no access'));

		$response = $this->makeController()->report('r', 's');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'no access'], $response->getData());
	}//end testARefusedCallerGets403()

	public function testAMonthlySeriesIsZeroFilledAndLabelled(): void {
		$this->stubParams(
			[
				'groupBy' => '@self.created',
				'interval' => 'month',
				'from' => '2026-01-01T00:00:00Z',
				'to' => '2026-04-01T00:00:00Z',
			]
		);
		$schema = $this->createMock(Schema::class);
		$this->runner->method('findSchema')->willReturn($schema);
		$input = null;
		$this->validator->expects($this->once())->method('validate')->willReturnCallback(
			function (array $given) use (&$input) {
				$input = $given;
				return AggregationQuery::create(metric: 'count');
			}
		);
		$this->runner->method('runAdhocByRef')->willReturn(
			['groups' => [['key' => '2026-01-01T00:00:00Z', 'value' => 3], ['key' => '2026-03-01T00:00:00Z', 'value' => 1]]]
		);

		$data = $this->makeController()->report('r', 's')->getData();

		$this->assertSame('_created', $input['field']);
		$this->assertSame('month', $input['interval']);
		$this->assertSame('count', $input['metric']);
		$this->assertNull($input['metricField']);
		$this->assertSame(
			[
				['@self.created' => '2026-01', 'count' => 3],
				['@self.created' => '2026-02', 'count' => 0],
				['@self.created' => '2026-03', 'count' => 1],
			],
			$data['results']
		);
		$this->assertSame(4, $data['total']);
	}//end testAMonthlySeriesIsZeroFilledAndLabelled()

	public function testASeriesWithoutARangeCoversTwelveBuckets(): void {
		$this->stubParams(['groupBy' => '@self.created', 'interval' => 'month']);
		$this->runner->method('findSchema')->willReturn($this->createMock(Schema::class));
		$input = null;
		$this->validator->method('validate')->willReturnCallback(
			function (array $given) use (&$input) {
				$input = $given;
				return AggregationQuery::create(metric: 'count');
			}
		);
		$this->runner->method('runAdhocByRef')->willReturn(['groups' => []]);

		$data = $this->makeController()->report('r', 's')->getData();

		$this->assertCount(12, $data['results']);
		$this->assertSame(substr($input['from'], 0, 7), $data['results'][0]['@self.created']);
		$this->assertSame(0, $data['total']);
	}//end testASeriesWithoutARangeCoversTwelveBuckets()

	public function testASeriesTakesOneMetric(): void {
		$this->stubParams(['groupBy' => '@self.created', 'interval' => 'month', 'metric' => 'count,sum', 'field' => 'bedrag']);
		$this->validator->expects($this->never())->method('validate');

		$response = $this->makeController()->report('r', 's');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testASeriesTakesOneMetric()

	public function testAnInvalidSeriesIsA400(): void {
		$this->stubParams(['groupBy' => 'status', 'interval' => 'fortnight', 'from' => '2026-01-01', 'to' => '2026-02-01']);
		$this->runner->method('findSchema')->willReturn($this->createMock(Schema::class));
		$this->validator->method('validate')->willThrowException(new \InvalidArgumentException('`interval` MUST be one of ...'));
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$response = $this->makeController()->report('r', 's');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => '`interval` MUST be one of ...'], $response->getData());
	}//end testAnInvalidSeriesIsA400()
	public function testFromAndToNarrowACategoricalReportToTheCreatedRange(): void {
		$this->stubParams(['groupBy' => 'status', 'from' => '2026-01-01', 'to' => '2026-03-31']);
		$captured = null;
		$this->runner->method('runAdhocByRef')->willReturnCallback(
			function (string $reg, string $sch, AggregationQuery $query) use (&$captured) {
				$captured = $query;
				return ['groups' => []];
			}
		);

		$this->makeController()->report('r', 's');

		$this->assertSame(['gte' => '2026-01-01', 'lt' => '2026-04-01'], $captured->filter['_created']);
	}//end testFromAndToNarrowACategoricalReportToTheCreatedRange()

	public function testAnUnparseableRangeIsA400(): void {
		$this->stubParams(['groupBy' => 'status', 'from' => 'soon']);
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->makeController()->report('r', 's')->getStatus());
	}//end testAnUnparseableRangeIsA400()

	public function testTrendAddsThePreviousMonthToASeries(): void {
		$this->stubParams(
			['groupBy' => '@self.created', 'interval' => 'month', 'from' => '2026-01-01', 'to' => '2026-03-01', 'trend' => 'true']
		);
		$this->runner->method('findSchema')->willReturn($this->createMock(Schema::class));
		$this->validator->method('validate')->willReturn(AggregationQuery::create(metric: 'count'));
		$this->runner->method('runAdhocByRef')->willReturn(
			['groups' => [['key' => '2026-01-01T00:00:00Z', 'value' => 4], ['key' => '2026-02-01T00:00:00Z', 'value' => 6]]]
		);

		$data = $this->makeController()->report('r', 's')->getData();

		$this->assertSame(
			[
				['@self.created' => '2026-01', 'count' => 4, 'previousCount' => null, 'change' => null],
				['@self.created' => '2026-02', 'count' => 6, 'previousCount' => 4, 'change' => 50.0],
			],
			$data['results']
		);
	}//end testTrendAddsThePreviousMonthToASeries()

	public function testCompareRunsEachPeriodAndReportsTheChange(): void {
		$this->stubParams(
			[
				'register' => 'zaken',
				'schema' => 'meldingen',
				'metric' => 'count',
				'period1_from' => '2026-01-01',
				'period1_to' => '2026-03-31',
				'period2' => ['from' => '2025-01-01', 'to' => '2025-03-31'],
			]
		);
		$filters = [];
		$this->runner->expects($this->exactly(2))->method('runAdhocByRef')->willReturnCallback(
			function (string $reg, string $sch, AggregationQuery $query) use (&$filters) {
				$filters[] = $query->filter['_created'];
				return ['value' => count($filters) === 1 ? 450 : 380];
			}
		);

		$data = $this->makeController()->compare()->getData();

		$this->assertSame(
			[['gte' => '2026-01-01', 'lt' => '2026-04-01'], ['gte' => '2025-01-01', 'lt' => '2025-04-01']],
			$filters
		);
		$this->assertSame(
			[
				'period1' => ['label' => 'Q1 2026', 'from' => '2026-01-01', 'to' => '2026-03-31', 'count' => 450],
				'period2' => ['label' => 'Q1 2025', 'from' => '2025-01-01', 'to' => '2025-03-31', 'count' => 380],
				'change' => ['absolute' => 70, 'percentage' => 18.42],
				'metric' => 'count',
				'field' => null,
			],
			$data
		);
	}//end testCompareRunsEachPeriodAndReportsTheChange()

	public function testCompareNeedsBothPeriods(): void {
		$this->stubParams(['register' => 'zaken', 'schema' => 'meldingen', 'period1_from' => '2026-01-01', 'period1_to' => '2026-03-31']);
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->makeController()->compare()->getStatus());
	}//end testCompareNeedsBothPeriods()

	public function testCompareRefusedForOnePeriodIsA403(): void {
		$this->stubParams(
			[
				'register' => 'r', 'schema' => 's',
				'period1_from' => '2026-01-01', 'period1_to' => '2026-01-31',
				'period2_from' => '2025-01-01', 'period2_to' => '2025-01-31',
			]
		);
		$this->runner->method('runAdhocByRef')->willThrowException(new NotAuthorizedException('no access'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->makeController()->compare()->getStatus());
	}//end testCompareRefusedForOnePeriodIsA403()
}//end class
