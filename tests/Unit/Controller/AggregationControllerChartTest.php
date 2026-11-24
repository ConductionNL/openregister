<?php

/**
 * Unit tests for `AggregationController::chart()`, the chart data at
 * `/api/objects/{register}/{schema}/chart`.
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
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-chart-data-api-endpoints-for-frontend-visualization
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

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
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateChartData
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportEnvelope
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery
 * @uses   \OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices
 * @uses   \OCA\OpenRegister\Search\Controller\Support\AggregationRun
 * @uses   \OCA\OpenRegister\Support\FilterParams
 * @uses   \OCA\OpenRegister\Support\AggregationFilterRequest
 * @uses   \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQueryRules
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationQueryShape
 * @uses   \OCA\OpenRegister\Support\UnknownFilterKeys
 */
class AggregationControllerChartTest extends TestCase {

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
	 * Answer the request's parameters from a fixed set.
	 *
	 * @param array<string, mixed> $params The parameters.
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => ($params[$name] ?? $default)
		);
		$this->request->method('getParams')->willReturn($params);
	}//end stubParams()

	public function testABarChartOfTheStatusDistribution(): void {
		$this->stubParams(['type' => 'bar', 'groupBy' => 'status', 'metric' => 'count']);
		$this->runner->expects($this->once())->method('runAdhocByRef')->willReturn(
			[
				'groups' => [
					['key' => 'nieuw', 'value' => 30],
					['key' => 'in_behandeling', 'value' => 45],
					['key' => 'wacht_op_info', 'value' => 12],
					['key' => 'afgehandeld', 'value' => 125],
					['key' => 'gesloten', 'value' => 8],
				],
				'cached' => true,
			]
		);

		$response = $this->makeController()->chart('zaken', 'meldingen');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'type' => 'bar',
				'labels' => ['nieuw', 'in_behandeling', 'wacht_op_info', 'afgehandeld', 'gesloten'],
				'series' => [['name' => 'count', 'data' => [30, 45, 12, 125, 8]]],
			],
			$response->getData()
		);
		$this->assertSame('hit', $response->getHeaders()['X-OR-Cache'] ?? null);
		$this->assertSame('HIT', $response->getHeaders()['X-Cache'] ?? null);
	}//end testABarChartOfTheStatusDistribution()

	public function testALineChartKeepsTheGapFilledMonths(): void {
		$this->stubParams(
			[
				'type' => 'line',
				'groupBy' => '@self.created',
				'interval' => 'month',
				'metric' => 'count',
				'from' => '2026-01-01T00:00:00Z',
				'to' => '2026-04-01T00:00:00Z',
			]
		);
		$this->runner->method('findSchema')->willReturn($this->createMock(Schema::class));
		$this->validator->method('validate')->willReturn(AggregationQuery::create(metric: 'count'));
		$this->runner->method('runAdhocByRef')->willReturn(
			['groups' => [['key' => '2026-01-01T00:00:00Z', 'value' => 3], ['key' => '2026-03-01T00:00:00Z', 'value' => 1]]]
		);

		$data = $this->makeController()->chart('r', 's')->getData();

		$this->assertSame('line', $data['type']);
		$this->assertSame(['2026-01', '2026-02', '2026-03'], $data['labels']);
		$this->assertSame([['name' => 'count', 'data' => [3, 0, 1]]], $data['series']);
	}//end testALineChartKeepsTheGapFilledMonths()

	public function testAPieChartCarriesPercentages(): void {
		$this->stubParams(['type' => 'pie', 'groupBy' => 'categorie']);
		$this->runner->method('runAdhocByRef')->willReturn(
			[
				'groups' => [
					['key' => 'Openbare ruimte', 'value' => 80],
					['key' => 'Verkeer', 'value' => 60],
					['key' => 'Afval', 'value' => 40],
					['key' => 'Overig', 'value' => 20],
				],
			]
		);

		$response = $this->makeController()->chart('r', 's');

		$this->assertSame(
			[
				'type' => 'pie',
				'labels' => ['Openbare ruimte', 'Verkeer', 'Afval', 'Overig'],
				'series' => [80, 60, 40, 20],
				'percentages' => [40.0, 30.0, 20.0, 10.0],
			],
			$response->getData()
		);
		$this->assertSame('miss', $response->getHeaders()['X-OR-Cache'] ?? null);
	}//end testAPieChartCarriesPercentages()

	public function testSeveralMetricsAreSeveralSeries(): void {
		$this->stubParams(['groupBy' => 'status', 'metric' => 'count,sum', 'field' => 'bedrag']);
		$this->runner->method('runAdhocByRef')->willReturn(
			['groups' => [['key' => 'betaald', 'values' => ['count' => 50, 'sum_bedrag' => 125000.0]]]]
		);

		$data = $this->makeController()->chart('r', 's')->getData();

		$this->assertSame('bar', $data['type']);
		$this->assertSame([['name' => 'count', 'data' => [50]], ['name' => 'sum', 'data' => [125000.0]]], $data['series']);
	}//end testSeveralMetricsAreSeveralSeries()

	public function testAnUnknownTypeIsRefusedBeforeAnythingIsRead(): void {
		$this->stubParams(['type' => 'radar', 'groupBy' => 'status']);
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$response = $this->makeController()->chart('r', 's');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'type must be one of: bar, line, area, pie, doughnut'], $response->getData());
	}//end testAnUnknownTypeIsRefusedBeforeAnythingIsRead()

	public function testARefusedReportIsAnsweredAsItIs(): void {
		$this->stubParams(['type' => 'bar']);
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$response = $this->makeController()->chart('r', 's');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'groupBy is required'], $response->getData());
	}//end testARefusedReportIsAnsweredAsItIs()

	public function testAReportTheCallerMayNotReadStaysRefused(): void {
		$this->stubParams(['type' => 'pie', 'groupBy' => 'status']);
		$this->runner->method('runAdhocByRef')->willThrowException(new NotAuthorizedException('no read'));

		$response = $this->makeController()->chart('r', 's');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'no read'], $response->getData());
	}//end testAReportTheCallerMayNotReadStaysRefused()
}//end class
