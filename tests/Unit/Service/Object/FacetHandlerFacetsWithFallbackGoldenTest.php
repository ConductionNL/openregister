<?php

/**
 * FacetHandler::calculateFacetsWithFallback() builds facets and their scoped queries exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\FacetResponseCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Golden record of the facet calculation: every query sent to the mapper (the caller's own query,
 * and one scoped to the schema of each non-aggregated field), the log lines, and the facets and
 * performance metadata returned. The caller's query carries forged owner, user and tenancy keys,
 * so the record shows the scoped queries keep every one of them and only swap the schema. Covers
 * restrictive and open filters, empty results, fields configured only as non-aggregated, titled
 * and untitled facet keys, colliding and numeric keys, scoped queries that fail or miss the field,
 * per-facet metrics and malformed scoped results. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\FacetHandler
 */
class FacetHandlerFacetsWithFallbackGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/facet-handler-facets-with-fallback-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->outcome(scenario: $scenario);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()

	/**
	 * The scenarios: the facet query, the facetable config, the base facets and the scoped facets by schema id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$forged = [
			'@self'         => ['owner' => 'mallory', 'organisation' => 'other-org', 'schemas' => [3, 4], 'register' => 2],
			'_schemas'      => [3, 4],
			'userId'        => '',
			'_multitenancy' => false,
			'_rbac'         => false,
		];
		$base = [
			'@self'  => ['register' => ['type' => 'terms', 'buckets' => [['key' => 2, 'results' => 5, 'label' => 'Reg']]]],
			'colour' => ['type' => 'terms', 'buckets' => [['key' => 'red', 'results' => 3], ['key' => 'blue', 'results' => 1]]],
			'size'   => ['type' => 'terms', 'buckets' => [['key' => 'L', 'results' => 2]]],
			'tag'    => ['type' => 'terms', 'buckets' => [['key' => 'x', 'results' => 1]]],
		];
		$scopedTag = ['tag' => ['type' => 'terms', 'buckets' => [['key' => 'scoped', 'results' => 7]]], '_metrics' => ['tag' => 1.5]];
		$nonAgg    = ['field' => 'tag', 'schemaId' => 3, 'facetConfig' => ['title' => 'Tag Name', 'order' => 9]];
		return [
			'no filters'                 => ['query' => [], 'config' => [], 'base' => $base],
			'empty, search filter'       => ['query' => ['_search' => 'zzz'], 'config' => [], 'base' => ['colour' => ['type' => 'terms', 'buckets' => []]]],
			'empty, field filter'        => ['query' => ['colour' => 'green'], 'config' => [], 'base' => []],
			'empty, empty field filter'  => ['query' => ['colour' => '', '@self' => ['register' => 2]], 'config' => [], 'base' => []],
			'results, field filter'      => ['query' => ['colour' => 'red'], 'config' => [], 'base' => $base],
			'metrics'                    => ['query' => ['_search' => 'q'], 'config' => [], 'base' => $base + ['_metrics' => ['colour' => 2.25, 'size' => ['buckets' => [['results' => 4]]]]]],
			'object field config'        => ['query' => [], 'config' => ['object_fields' => ['colour' => ['facetConfig' => ['title' => 'Kleur', 'order' => 1]], 'size' => []]], 'base' => $base],
			'non-aggregated only'        => ['query' => $forged, 'config' => ['non_aggregated_fields' => [$nonAgg]], 'base' => $base, 'scoped' => [3 => $scopedTag]],
			'non-aggregated and object'  => ['query' => $forged, 'config' => ['object_fields' => ['tag' => []], 'non_aggregated_fields' => [$nonAgg]], 'base' => $base, 'scoped' => [3 => $scopedTag]],
			'untitled key'               => ['query' => ['colour' => 'red'], 'config' => ['non_aggregated_fields' => [['field' => 'size', 'schemaId' => 4, 'facetConfig' => []]]], 'base' => $base, 'scoped' => [4 => ['size' => ['type' => 'terms', 'buckets' => [['key' => 'S', 'results' => 1]]]]]],
			'scoped misses the field'    => ['query' => [], 'config' => ['non_aggregated_fields' => [$nonAgg]], 'base' => $base, 'scoped' => [3 => ['other' => ['buckets' => []]]]],
			'scoped fails'               => ['query' => ['_search' => 'x'], 'config' => ['non_aggregated_fields' => [$nonAgg, ['field' => 'size', 'schemaId' => 4, 'facetConfig' => ['title' => 'Maat']]]], 'base' => $base, 'scoped' => [3 => 'throw', 4 => ['size' => ['buckets' => [['key' => 'M', 'results' => 2]]]]]],
			'colliding keys'             => ['query' => [], 'config' => ['non_aggregated_fields' => [$nonAgg, ['field' => 'tag', 'schemaId' => 4, 'facetConfig' => ['title' => 'Tag Name']]]], 'base' => $base, 'scoped' => [3 => $scopedTag, 4 => ['tag' => ['buckets' => [['key' => 'four', 'results' => 4]]]]]],
			'key equals a base facet'    => ['query' => [], 'config' => ['object_fields' => ['colour' => []], 'non_aggregated_fields' => [['field' => 'tag', 'schemaId' => 3, 'facetConfig' => ['title' => 'colour']]]], 'base' => $base, 'scoped' => [3 => $scopedTag]],
			'numeric key'                => ['query' => [], 'config' => ['non_aggregated_fields' => [['field' => 'tag', 'schemaId' => 3, 'facetConfig' => ['title' => '2024']]]], 'base' => $base, 'scoped' => [3 => $scopedTag]],
			'numeric base key'           => ['query' => [], 'config' => [], 'base' => $base + [7 => ['buckets' => [['key' => 'n', 'results' => 1]]]]],
			'string schema id'           => ['query' => [], 'config' => ['non_aggregated_fields' => [['field' => 'tag', 'schemaId' => '3', 'facetConfig' => []]]], 'base' => $base, 'scoped' => ['3' => $scopedTag]],
			'scalar scoped facet'        => ['query' => [], 'config' => ['non_aggregated_fields' => [['field' => 'tag', 'schemaId' => 3, 'facetConfig' => []]]], 'base' => $base, 'scoped' => [3 => ['tag' => 5]]],
			'field named _metrics'       => ['query' => [], 'config' => ['non_aggregated_fields' => [['field' => '_metrics', 'schemaId' => 3, 'facetConfig' => []]]], 'base' => $base, 'scoped' => [3 => ['_metrics' => ['buckets' => [['key' => 'm', 'results' => 1]]]]]],
			'self without schemas'       => ['query' => ['@self' => ['register' => 2], 'size' => 'L'], 'config' => ['non_aggregated_fields' => [['field' => 'size', 'schemaId' => 4, 'facetConfig' => []]]], 'base' => $base, 'scoped' => [4 => ['size' => ['buckets' => [['key' => 'L', 'results' => 2]]]]]],
		];
	}//end scenarios()

	/**
	 * Run one scenario and record the mapper queries, the log lines and the result.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed> The recorded outcome.
	 */
	private function outcome(array $scenario): array {
		$record = ['queries' => [], 'logs' => []];
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('getSimpleFacets')->willReturnCallback(
			function (array $query) use (&$record, $scenario) {
				$record['queries'][] = $query;
				if (count($record['queries']) === 1) {
					return $scenario['base'];
				}

				$scoped = $scenario['scoped'][$query['@self']['schema']] ?? [];
				if ($scoped === 'throw') {
					throw new Exception('scoped query failed');
				}

				return $scoped;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use (&$record, $level): void {
					unset($context['line']);
					if (isset($context['file']) === true) {
						$context['file'] = basename($context['file']);
					}

					$record['logs'][] = [$level, $message, $context];
				}
			);
		}

		$handler = new FacetHandler(
			$mapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(FacetResponseCache::class),
			$logger
		);

		$method = new ReflectionMethod(FacetHandler::class, 'calculateFacetsWithFallback');
		$args   = [$scenario['query'], $scenario['config']];
		if ($method->getNumberOfParameters() === 3) {
			$args = [$scenario['query'], ['ignored' => true], $scenario['config']];
		}

		try {
			$record['result'] = $method->invokeArgs($handler, $args);
		} catch (Throwable $e) {
			$record['threw'] = get_class($e);
		}

		return json_decode((string) json_encode($record), true);
	}//end outcome()
}//end class
