<?php

/**
 * ObjectMetricSourceTest.
 *
 * Pins the search query objectCount/objectSum descriptors are turned into
 * (slug resolution, filter operators, date tokens), the sum over mixed result
 * rows, the recursive groupBy facet expansion, and the fail-soft path when a
 * schema slug does not resolve.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability\Source;

use DateTimeImmutable;
use OCA\OpenRegister\AppHost\SchemaScopeLookups;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers query building, count/sum aggregation, grouping and resolution failure.
 */
class ObjectMetricSourceTest extends TestCase {
	private ObjectOperationsInterface&MockObject $objects;

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	private ObjectMetricSource $source;

	protected function setUp(): void {
		$this->objects   = $this->createMock(ObjectOperationsInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$catalog         = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($this->registers);
		$catalog->method('schemas')->willReturn($this->schemas);
		$this->source    = new ObjectMetricSource($this->objects, new SchemaScopeLookups($catalog, $this->logger));
	}

	private function schemaWithId(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}

	private function registerWithId(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}

	public function testKindIsObjectCount(): void {
		$this->assertSame('objectCount', $this->source->kind());
	}

	public function testUnresolvableSchemaLogsWarningAndEmitsEmptySample(): void {
		$this->schemas->expects($this->once())->method('find')
			->with('ghost', [], false, false)
			->willThrowException(new DoesNotExistException('no such schema'));
		$this->objects->expects($this->never())->method('countSearchObjects');
		$this->logger->expects($this->once())->method('warning')->with(
			'[AppHost\\Metrics] objectCount "tickets" (app pipelinq) could not resolve register/schema: no such schema',
			$this->callback(static fn (array $ctx): bool => isset($ctx['file'], $ctx['line']))
		);

		$result = $this->source->collect('pipelinq', new MetricDescriptor('tickets', 'gauge', 'objectCount', ['schema' => 'ghost']));

		$this->assertCount(1, $result);
		$this->assertSame('tickets', $result[0]->name);
		$this->assertSame('gauge', $result[0]->type);
		$this->assertSame('objectCount for ghost', $result[0]->help);
		$this->assertSame([], $result[0]->samples);
	}

	public function testCountBuildsQueryWithRegisterAndEveryFilterOperator(): void {
		$this->schemas->method('find')->with('ticket', [], false, false)->willReturn($this->schemaWithId(5));
		$this->registers->expects($this->once())->method('find')->with('crm', false, false)->willReturn($this->registerWithId(3));
		$this->objects->expects($this->once())->method('countSearchObjects')->with(
			[
				'@self'    => ['schema' => 5, 'register' => 3],
				'status'   => 'open',
				'owner'    => ['ne' => 'bot'],
				'priority' => ['gte' => 2, 'lt' => 9],
				'title'    => ['like' => '%urgent%'],
			],
			false,
			false
		)->willReturn(12);

		$descriptor = new MetricDescriptor(
			'tickets',
			'gauge',
			'objectCount',
			[
				'register' => 'crm',
				'schema'   => 'ticket',
				'filter'   => [
					'status'   => ['eq' => 'open'],
					'owner'    => ['neq' => 'bot'],
					'priority' => ['gte' => 2, 'lt' => 9],
					'title'    => ['like' => '%urgent%'],
					'ignored'  => ['between' => 1],
				],
			],
			'Open tickets'
		);

		$result = $this->source->collect('pipelinq', $descriptor);

		$this->assertSame('Open tickets', $result[0]->help);
		$this->assertSame([['labels' => [], 'value' => 12]], $result[0]->samples);
	}

	public function testEmptyRegisterSlugIsNotLookedUp(): void {
		$this->schemas->method('find')->willReturn($this->schemaWithId(5));
		$this->registers->expects($this->never())->method('find');
		$this->objects->expects($this->once())->method('countSearchObjects')
			->with(['@self' => ['schema' => 5]], false, false)->willReturn(0);

		$result = $this->source->collect('app', new MetricDescriptor('n', 'gauge', 'objectCount', ['register' => '', 'schema' => 's']));

		$this->assertSame([['labels' => [], 'value' => 0]], $result[0]->samples);
	}

	public function testNowAndTodayTokensResolveToCurrentDateTime(): void {
		$this->schemas->method('find')->willReturn($this->schemaWithId(5));
		$captured = null;
		$this->objects->method('countSearchObjects')->willReturnCallback(
			function (array $query) use (&$captured): int {
				$captured = $query;
				return 1;
			}
		);

		$before = new DateTimeImmutable('now');
		$this->source->collect('app', new MetricDescriptor('n', 'gauge', 'objectCount', [
			'schema' => 's',
			'filter' => ['dueAt' => ['lt' => 'now'], 'day' => ['eq' => 'today'], 'raw' => ['eq' => 'nowish']],
		]));
		$after = new DateTimeImmutable('now');

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured['dueAt']['lt']);
		$this->assertGreaterThanOrEqual($before->format('Y-m-d H:i:s'), $captured['dueAt']['lt']);
		$this->assertLessThanOrEqual($after->format('Y-m-d H:i:s'), $captured['dueAt']['lt']);
		$this->assertContains($captured['day'], [$before->format('Y-m-d'), $after->format('Y-m-d')]);
		$this->assertSame('nowish', $captured['raw']);
	}

	public function testSumAddsNumericFieldAcrossArrayAndEntityRowsAndSkipsTheRest(): void {
		$this->schemas->method('find')->willReturn($this->schemaWithId(5));
		$this->objects->expects($this->never())->method('countSearchObjects');

		$entity = new ObjectEntity();
		$entity->setObject(['amount' => '2.5']);
		$nonArrayData = new class {
			public function getObject(): string {
				return 'amount=100';
			}
		};

		$this->objects->expects($this->once())->method('searchObjects')
			->with(['@self' => ['schema' => 5]], false, false)
			->willReturn([
				['amount' => 10],
				$entity,
				['amount' => 'n/a'],
				['other' => 7],
				$nonArrayData,
				new \stdClass(),
				'scalar-row',
			]);

		$result = $this->source->collect('app', new MetricDescriptor('revenue', 'gauge', 'objectSum', ['schema' => 'invoice', 'field' => 'amount']));

		$this->assertSame('objectSum for invoice', $result[0]->help);
		$this->assertSame([['labels' => [], 'value' => 12.5]], $result[0]->samples);
	}

	public function testGroupByExpandsFacetBucketsRecursivelyAndDropsZeroGroups(): void {
		$this->schemas->method('find')->willReturn($this->schemaWithId(5));
		$base = ['@self' => ['schema' => 5]];

		$facetQueries = [];
		$this->objects->method('getFacetsForObjects')->willReturnCallback(
			function (array $query) use (&$facetQueries): array {
				$facetQueries[] = $query;
				if (isset($query['_facets']['status']) === true) {
					return ['_status' => ['data' => ['buckets' => [
						['value' => 'open'], ['value' => 'closed'], ['value' => ''], ['value' => null],
					]]]];
				}

				return ['kind' => ['buckets' => [['key' => 'bug'], ['key' => 'task']]]];
			}
		);

		$counts = [
			'open|bug'    => 4,
			'open|task'   => 0,
			'closed|bug'  => 1,
			'closed|task' => 2,
		];
		$this->objects->method('countSearchObjects')->willReturnCallback(
			static fn (array $query): int => $counts[$query['status'] . '|' . $query['kind']]
		);

		$result = $this->source->collect('app', new MetricDescriptor('tickets', 'gauge', 'objectCount', [
			'schema'  => 's',
			'groupBy' => ['a' => 'status', 'b' => 'kind'],
		]));

		$this->assertSame(
			[
				['labels' => ['status' => 'open', 'kind' => 'bug'], 'value' => 4],
				['labels' => ['status' => 'closed', 'kind' => 'bug'], 'value' => 1],
				['labels' => ['status' => 'closed', 'kind' => 'task'], 'value' => 2],
			],
			$result[0]->samples
		);
		$this->assertSame($base + ['_facets' => ['status' => ['type' => 'terms']]], $facetQueries[0]);
		$this->assertSame($base + ['status' => 'open', '_facets' => ['kind' => ['type' => 'terms']]], $facetQueries[1]);
		$this->assertCount(3, $facetQueries);
	}

	public function testGroupedSumDropsZeroFloatGroups(): void {
		$this->schemas->method('find')->willReturn($this->schemaWithId(5));
		$this->objects->method('getFacetsForObjects')->willReturn(['_currency' => ['data' => ['buckets' => [
			['value' => 'EUR'], ['value' => 'USD'],
		]]]]);
		$this->objects->method('searchObjects')->willReturnCallback(
			static fn (array $query): array => $query['currency'] === 'EUR' ? [['amount' => 3]] : [['amount' => 0]]
		);

		$result = $this->source->collect('app', new MetricDescriptor('revenue', 'gauge', 'objectSum', [
			'schema'  => 's',
			'field'   => 'amount',
			'groupBy' => ['currency'],
		]));

		$this->assertSame([['labels' => ['currency' => 'EUR'], 'value' => 3.0]], $result[0]->samples);
	}

	public function testGroupByWithNoFacetBucketsYieldsNoSamples(): void {
		$this->schemas->method('find')->willReturn($this->schemaWithId(5));
		$this->objects->method('getFacetsForObjects')->willReturn([]);
		$this->objects->expects($this->never())->method('countSearchObjects');

		$result = $this->source->collect('app', new MetricDescriptor('n', 'gauge', 'objectCount', ['schema' => 's', 'groupBy' => ['status']]));

		$this->assertCount(1, $result);
		$this->assertSame([], $result[0]->samples);
	}

	public function testNonArrayGroupByFallsBackToSingleUngroupedCount(): void {
		$this->schemas->method('find')->willReturn($this->schemaWithId(5));
		$this->objects->expects($this->never())->method('getFacetsForObjects');
		$this->objects->expects($this->once())->method('countSearchObjects')->willReturn(7);

		$result = $this->source->collect('app', new MetricDescriptor('n', 'gauge', 'objectCount', ['schema' => 's', 'groupBy' => 'status']));

		$this->assertSame([['labels' => [], 'value' => 7]], $result[0]->samples);
	}
}
