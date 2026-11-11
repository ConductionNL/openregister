<?php

/**
 * FacetHandler's public entry points answer exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\FacetLookups;
use OCA\OpenRegister\Service\Object\FacetResponseCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of getFacetsForObjects() and getFacetableFields(): the facet
 * config forms (missing, empty, a string, a list), the response cache key, hit and store, the
 * paging keys dropped from the facet query, the schema lookups each schema filter form makes
 * (plural under @self or _schemas, singular list or id, an id that fails, none), the mapper
 * queries, the transformed facets with their inferred data types and the log lines. Timings are masked. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\FacetHandler
 * @covers \OCA\OpenRegister\Service\Object\FacetCalculation
 * @covers \OCA\OpenRegister\Service\Object\FacetListing
 * @covers \OCA\OpenRegister\Service\Object\FacetEntry
 * @covers \OCA\OpenRegister\Service\Object\FacetableFieldDiscovery
 * @covers \OCA\OpenRegister\Service\Object\FacetRun
 * @covers \OCA\OpenRegister\Service\Object\NonAggregatedFacetKey
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 */
class FacetHandlerEntryPointsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/facet-handler-entry-points-golden.json';

	/** @var array<int, mixed> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $world = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->facetScenarios() as $name => $scenario) {
			$actual['facets: ' . $name] = $this->outcome(scenario: $scenario, call: fn (FacetHandler $h) => $h->getFacetsForObjects($scenario['query']));
		}

		foreach ($this->fieldScenarios() as $name => $query) {
			$actual['fields: ' . $name] = $this->outcome(scenario: [], call: fn (FacetHandler $h) => $h->getFacetableFields($query));
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * The getFacetsForObjects() scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function facetScenarios(): array {
		$base = [
			'@self'  => [
				'register' => ['type' => 'terms', 'buckets' => [['key' => 2, 'results' => 5]]],
				'created'  => ['type' => 'date_histogram', 'buckets' => [['key' => '2026-01', 'results' => 1]]],
				'custom'   => ['type' => 'terms', 'buckets' => [['value' => 'v', 'count' => '3', 'label' => 'V']]],
			],
			'colour' => ['type' => 'terms', 'buckets' => [['key' => 'red', 'results' => 3]]],
			'weight' => ['type' => 'terms', 'buckets' => [['key' => 1.5, 'results' => 2]]],
			'age'    => ['type' => 'terms', 'buckets' => [['key' => 40, 'results' => 2]]],
			'price'  => ['type' => 'range', 'buckets' => []],
			'born'   => ['type' => 'date_histogram', 'buckets' => [['key' => '1990', 'results' => 1]]],
			'empty'  => ['buckets' => []],
		];
		$paged = ['_facets' => ['colour'], '_limit' => 5, '_offset' => 10, '_page' => 3, '_facetable' => true, 'colour' => 'red'];

		return [
			'no facets'              => ['query' => ['colour' => 'red']],
			'empty facets'           => ['query' => ['_facets' => '']],
			'empty list'             => ['query' => ['_facets' => []]],
			'string facets'          => ['query' => ['_facets' => 'extend'], 'base' => $base],
			'list, paged'            => ['query' => $paged, 'base' => $base],
			'cached'                 => ['query' => ['_facets' => ['colour']], 'cached' => ['facets' => ['from' => 'cache']]],
			'plural under @self'     => ['query' => ['_facets' => ['tag'], '@self' => ['schemas' => ['3', 4]]], 'base' => $base, 'scoped' => ['tag' => ['buckets' => [['key' => 'x', 'results' => 1]]]]],
			'plural _schemas'        => ['query' => ['_facets' => ['tag'], '_schemas' => [3]], 'base' => $base],
			'empty plural'           => ['query' => ['_facets' => ['tag'], '_schemas' => [], '@self' => ['schema' => 3]], 'base' => $base],
			'plural not a list'      => ['query' => ['_facets' => ['tag'], '_schemas' => '3'], 'base' => $base],
			'singular list'          => ['query' => ['_facets' => ['tag'], '@self' => ['schema' => [3, 4]]], 'base' => $base],
			'singular id'            => ['query' => ['_facets' => ['tag'], '@self' => ['schema' => 3]], 'base' => $base],
			'singular id fails'      => ['query' => ['_facets' => ['tag'], '@self' => ['schema' => 99]], 'base' => $base],
			'no schema filter'       => ['query' => ['_facets' => ['tag'], '_search' => 'q'], 'base' => []],
			'metrics'                => ['query' => ['_facets' => ['colour']], 'base' => $base + ['_metrics' => ['colour' => 1.25]]],
		];
	}//end facetScenarios()

	/**
	 * The getFacetableFields() queries.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fieldScenarios(): array {
		return [
			'all schemas'    => [],
			'one schema'     => ['@self' => ['schema' => 3]],
			'plural'         => ['_schemas' => [3, 4]],
			'failing schema' => ['@self' => ['schema' => 99]],
		];
	}//end fieldScenarios()

	/**
	 * Run one call on a fresh handler and record what happened.
	 *
	 * @param array<string, mixed>          $scenario The world.
	 * @param callable(FacetHandler): mixed $call     The call.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(array $scenario, callable $call): array {
		$this->calls = [];
		$this->world = $scenario;
		try {
			$result = $call($this->handler());
		} catch (Exception $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		if (isset($result['performance_metadata']['total_execution_time_ms']) === true) {
			$result['performance_metadata']['total_execution_time_ms'] = 'masked';
		}

		return json_decode((string) json_encode(['result' => $result, 'calls' => $this->calls]), true);
	}//end outcome()

	/**
	 * Build the handler over recording doubles.
	 *
	 * @return FacetHandler
	 */
	private function handler(): FacetHandler {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('getSimpleFacets')->willReturnCallback(
			function (array $query): array {
				$this->calls[] = ['getSimpleFacets', $query];
				if (isset($query['@self']['schema']) === true && is_int($query['@self']['schema']) === true && isset($this->world['scoped']) === true) {
					return $this->world['scoped'];
				}

				return $this->world['base'] ?? [];
			}
		);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->calls[] = ['schema.find', $args];
				if ((int) $args[0] === 99) {
					throw new Exception('schema gone');
				}

				return $this->schema(id: (int) $args[0]);
			}
		);
		$schemas->method('findMultiple')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = ['schema.findMultiple', $args];
				return array_map(fn ($id): Schema => $this->schema(id: (int) $id), $args[0]);
			}
		);
		$schemas->method('findAll')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = ['schema.findAll', $args];
				return [$this->schema(id: 3), $this->schema(id: 4)];
			}
		);

		$cache = $this->createMock(FacetResponseCache::class);
		$cache->method('keyFor')->willReturnCallback(
			function (...$args): string {
				$this->calls[] = ['cache.keyFor', $args];
				return 'facets:' . md5((string) json_encode($args));
			}
		);
		$cache->method('get')->willReturnCallback(
			function (...$args): ?array {
				$this->calls[] = ['cache.get', $args];
				return $this->world['cached'] ?? null;
			}
		);
		$cache->method('put')->willReturnCallback(
			function (...$args): void {
				$args[1]['performance_metadata']['total_execution_time_ms'] = 'masked';
				$this->calls[] = ['cache.put', $args];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					unset($context['line'], $context['file']);
					if (isset($context['executionTime']) === true) {
						$context['executionTime'] = 'masked';
					}

					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return new FacetHandler(new FacetLookups($mapper, $schemas), $cache, $logger);
	}//end handler()

	/**
	 * A schema with an aggregated and a non-aggregated facetable property.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties(
			[
				'colour' => ['type' => 'string', 'facetable' => ['title' => 'Kleur ' . $id, 'order' => $id]],
				'tag'    => ['type' => 'string', 'facetable' => ['aggregated' => false, 'title' => 'Tag ' . $id]],
				'born'   => ['type' => 'string', 'format' => 'date', 'facetable' => true],
			]
		);
		return $schema;
	}//end schema()
}//end class
