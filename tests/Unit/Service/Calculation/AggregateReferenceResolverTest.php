<?php

/**
 * OpenRegister AggregateReferenceResolverTest
 *
 * Unit coverage for the aggregate-reference pre-resolver feeding the
 * calculation engine's `@aggregate.<name>` payload tokens.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-5
 */
class AggregateReferenceResolverTest extends TestCase {
	/** @var AggregationRunner&MockObject */
	private $runner;

	/** @var LoggerInterface&MockObject */
	private $logger;

	private AggregateReferenceResolver $resolver;

	protected function setUp(): void {
		$this->runner = $this->createMock(AggregationRunner::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new AggregateReferenceResolver($this->runner, $this->logger);
	}//end setUp()

	/**
	 * A scalar aggregation injects the scalar directly under @aggregate.<name>,
	 * and the filter is parameterised by the saving object's @self field.
	 *
	 * @return void
	 */
	public function testScalarAggregateResolvesAndInjectsValue(): void {
		$capturedQuery = null;
		$capturedSchema = null;
		$this->runner->expects($this->once())
			->method('runAdhocByRef')
			->willReturnCallback(function (string $registerRef, string $schemaRef, AggregationQuery $query) use (&$capturedQuery, &$capturedSchema) {
				$capturedQuery = $query;
				$capturedSchema = $schemaRef;
				return ['value' => 120.0, 'backend' => 'php-fallback', 'cached' => false];
			});

		$payload = [
			'resourceId' => 'res-7',
			'@self' => ['uuid' => 'obj-1'],
		];
		$aggregates = [
			'billableHoursThisPeriod' => [
				'schema' => 'TimeEntry',
				'metric' => 'sum',
				'field' => 'hours',
				'filters' => [
					'resourceId' => '@self.resourceId',
					'billable' => true,
				],
			],
		];

		$result = $this->resolver->resolveAll($payload, $aggregates, 'reg-1');

		$this->assertSame(120.0, $result['billableHoursThisPeriod']);
		$this->assertSame('TimeEntry', $capturedSchema);
		// @self.resourceId is parameterised to the saving object's own field.
		$this->assertSame('res-7', $capturedQuery->filter['resourceId']);
		$this->assertTrue($capturedQuery->filter['billable']);
		$this->assertSame('sum', $capturedQuery->metric);
		$this->assertSame('hours', $capturedQuery->field);
	}//end testScalarAggregateResolvesAndInjectsValue()

	/**
	 * A grouped aggregation injects a {stringKey: value} map.
	 *
	 * @return void
	 */
	public function testGroupedAggregateInjectsKeyValueMap(): void {
		$this->runner->method('runAdhocByRef')->willReturn(
			[
				'groups' => [
					['key' => 'open', 'value' => 3],
					['key' => 'closed', 'value' => 7],
				],
				'backend' => 'php-fallback',
				'cached' => false,
			]
		);

		$aggregates = [
			'byStatus' => [
				'schema' => 'Ticket',
				'metric' => 'count',
				'groupBy' => ['field' => 'status'],
			],
		];

		$result = $this->resolver->resolveAll(['@self' => []], $aggregates, 'reg-1');

		$this->assertSame(['open' => 3, 'closed' => 7], $result['byStatus']);
	}//end testGroupedAggregateInjectsKeyValueMap()

	/**
	 * When the runner throws, the aggregate resolves to null and the save is
	 * never failed (no exception propagates).
	 *
	 * @return void
	 */
	public function testRunnerThrowInjectsNullAndNeverRethrows(): void {
		$this->runner->method('runAdhocByRef')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning');

		$aggregates = [
			'total' => ['schema' => 'TimeEntry', 'metric' => 'sum', 'field' => 'hours'],
		];

		$result = $this->resolver->resolveAll(['@self' => []], $aggregates, 'reg-1');

		$this->assertNull($result['total']);
	}//end testRunnerThrowInjectsNullAndNeverRethrows()

	/**
	 * A malformed aggregate-reference (missing schema/metric) resolves to null
	 * without ever calling the runner.
	 *
	 * @return void
	 */
	public function testMissingSchemaOrMetricResolvesNull(): void {
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$result = $this->resolver->resolveAll(
			['@self' => []],
			['bad' => ['metric' => 'sum', 'field' => 'hours']],
			'reg-1'
		);

		$this->assertNull($result['bad']);
	}//end testMissingSchemaOrMetricResolvesNull()
}//end class
