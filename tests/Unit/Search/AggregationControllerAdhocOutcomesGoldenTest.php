<?php

/**
 * Golden test pinning the allowed and refused outcomes of the ad-hoc aggregation endpoints.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Controller\AggregationController;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins status, body and header of value() and grouped() for every outcome,
 * the query each one hands the runner, and that a refused input never runs.
 *
 * @covers \OCA\OpenRegister\Search\Controller\AggregationController
 */
final class AggregationControllerAdhocOutcomesGoldenTest extends TestCase {

	/**
	 * Runner mock.
	 *
	 * @var AggregationRunner&MockObject
	 */
	private AggregationRunner&MockObject $runner;

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->runner  = $this->createMock(AggregationRunner::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller over the request params.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return AggregationController
	 */
	private function controller(array $params): AggregationController {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $name, $default = null) => array_key_exists($name, $params) ? $params[$name] : $default
		);
		$this->request->method('getParams')->willReturn($params);
		return new AggregationController('openregister', $this->request, $this->runner, $this->createMock(TimeseriesRequestValidator::class));
	}//end controller()

	/**
	 * Status, body and cache header of a response.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{int, mixed, string|null}
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData(), ($response->getHeaders()['X-OR-Cache'] ?? null)];
	}//end outcome()

	/**
	 * The two endpoints with params that make each one valid.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function endpoints(): array {
		return [
			'value' => ['value', ['metric' => 'sum', 'field' => 'p']],
			'grouped' => ['grouped', ['metric' => 'sum', 'field' => 'p', 'groupBy' => 'status,type', 'sort' => 'desc', 'limit' => '3']],
		];
	}//end endpoints()

	/**
	 * Allowed: the runner's envelope comes back with the cache header.
	 *
	 * @param string               $action The endpoint.
	 * @param array<string, mixed> $params Valid params.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAllowed(string $action, array $params): void {
		$seen = null;
		$this->runner->expects($this->once())->method('runAdhocByRef')->willReturnCallback(
			static function (string $registerRef, string $schemaRef, AggregationQuery $query) use (&$seen): array {
				$seen = [$registerRef, $schemaRef, $query->toArray()];
				return ['value' => 1, 'cached' => true];
			}
		);

		$this->assertSame([200, ['value' => 1, 'cached' => true], 'hit'], $this->outcome($this->controller($params)->$action('r', 's')));
		$groupBy = null;
		if ($action === 'grouped') {
			$groupBy = ['fields' => ['status', 'type'], 'sort' => 'desc', 'limit' => 3];
		}

		$this->assertSame(
			['r', 's', ['metric' => 'sum', 'field' => 'p', 'filter' => [], 'groupBy' => $groupBy, 'dateBucket' => null, 'metrics' => null, 'cumulative' => false]],
			$seen
		);
	}//end testAllowed()

	/**
	 * A metrics list sets the primary metric and field; an empty field is null.
	 *
	 * @param string               $action The endpoint.
	 * @param array<string, mixed> $params Valid params.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testMetricsListSetsThePrimaryMetric(string $action, array $params): void {
		$params['field']   = '';
		$params['metrics'] = [['metric' => 'avg', 'field' => 'q'], 'junk', ['metric' => 'count', 'field' => '']];
		$seen = null;
		$this->runner->method('runAdhocByRef')->willReturnCallback(
			static function (string $registerRef, string $schemaRef, AggregationQuery $query) use (&$seen): array {
				$seen = $query;
				return ['cached' => false];
			}
		);

		$this->assertSame([200, ['cached' => false], 'miss'], $this->outcome($this->controller($params)->$action('r', 's')));
		$this->assertSame('avg', $seen->metric);
		$this->assertSame('q', $seen->field);
		$this->assertSame([['metric' => 'avg', 'field' => 'q'], ['metric' => 'count', 'field' => null]], $seen->metrics);
	}//end testMetricsListSetsThePrimaryMetric()

	/**
	 * Refused by the runner: 403 with its message.
	 *
	 * @param string               $action The endpoint.
	 * @param array<string, mixed> $params Valid params.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testNotAuthorizedIs403(string $action, array $params): void {
		$this->runner->method('runAdhocByRef')->willThrowException(new NotAuthorizedException('no read'));
		$this->assertSame([403, ['error' => 'no read'], null], $this->outcome($this->controller($params)->$action('r', 's')));
	}//end testNotAuthorizedIs403()

	/**
	 * Unknown register or schema at run time: 404 with its message.
	 *
	 * @param string               $action The endpoint.
	 * @param array<string, mixed> $params Valid params.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testRunnerNotFoundIs404(string $action, array $params): void {
		$this->runner->method('runAdhocByRef')->willThrowException(new RuntimeException('gone'));
		$this->assertSame([404, ['error' => 'gone'], null], $this->outcome($this->controller($params)->$action('r', 's')));
	}//end testRunnerNotFoundIs404()

	/**
	 * Invalid input: 400 and the runner never runs.
	 *
	 * @param string               $action The endpoint.
	 * @param array<string, mixed> $params Valid params.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testInvalidInputIs400BeforeRunning(string $action, array $params): void {
		$params['metric'] = 'median';
		$this->runner->expects($this->never())->method('runAdhocByRef');
		$this->assertSame(
			[400, ['error' => 'aggregation metric MUST be one of: count, sum, avg, min, max (got: median)'], null],
			$this->outcome($this->controller($params)->$action('r', 's'))
		);
	}//end testInvalidInputIs400BeforeRunning()

	/**
	 * A schema that cannot be resolved for a bare filter: 404 and the runner never runs.
	 *
	 * @param string               $action The endpoint.
	 * @param array<string, mixed> $params Valid params.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testUnresolvableFilterSchemaIs404BeforeRunning(string $action, array $params): void {
		$params['filter'] = ['status' => 'open'];
		$this->runner->method('findSchema')->willThrowException(new RuntimeException('no schema'));
		$this->runner->expects($this->never())->method('runAdhocByRef');
		$this->assertSame([404, ['error' => 'no schema'], null], $this->outcome($this->controller($params)->$action('r', 's')));
	}//end testUnresolvableFilterSchemaIs404BeforeRunning()

	/**
	 * grouped() without a groupBy is a 400 before anything else is looked at.
	 *
	 * @return void
	 */
	public function testGroupedWithoutGroupByIs400(): void {
		$this->runner->expects($this->never())->method('findSchema');
		$this->runner->expects($this->never())->method('runAdhocByRef');
		$this->assertSame(
			[400, ['error' => 'groupBy is required'], null],
			$this->outcome($this->controller(['groupBy' => ' , ', 'filter' => ['a' => 1], 'metric' => 'median'])->grouped('r', 's'))
		);
	}//end testGroupedWithoutGroupByIs400()
}//end class
