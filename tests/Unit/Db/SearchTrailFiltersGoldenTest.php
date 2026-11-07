<?php

/**
 * Golden test: the filter predicates and the recorded search parameters of a search trail.
 *
 * Pins, before SearchTrailMapper's filter and parameter extraction were split
 * into small units, every builder call count() makes for a spread of column
 * filters, and every field the mapper records on a trail from a query.
 *
 * Regenerate (only when an outcome is meant to change) with
 * UPDATE_GOLDEN=1 phpunit tests/Unit/Db/SearchTrailFiltersGoldenTest.php.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class SearchTrailFiltersGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/search-trail-filters-golden.json';

	/**
	 * Column filter cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function filterCases(): array {
		return [
			'none' => [],
			'one column' => ['user' => 'alice'],
			'system key skipped' => ['_limit' => 5, '_search' => 'x', 'user' => 'bob'],
			'unknown column skipped' => ['nope' => 'x', 'register' => '3'],
			'is not null' => ['search_term' => 'IS NOT NULL'],
			'is null' => ['schema' => 'IS NULL'],
			'array of values' => ['user' => ['alice', 'IS NULL', 'IS NOT NULL', 'bob']],
			'empty array' => ['user' => []],
			'comma list' => ['register' => '1, 2 ,3'],
			'integer value' => ['page' => 2],
			'boolean value' => ['published_only' => true],
			'null value' => ['session' => null],
			'several' => ['user' => 'alice', 'schema' => 'IS NULL', 'register' => '4,5', 'size' => ['10']],
		];
	}//end filterCases()

	/**
	 * Query cases for the recorded parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function queryCases(): array {
		return [
			'empty' => [],
			'term and paging' => ['_search' => 'tax', '_page' => 2, '_limit' => 20, '_offset' => 40],
			'facets' => ['_facets' => 'x', '_facetable' => true],
			'facetable not true' => ['_facetable' => 'true'],
			'numeric register and schema' => ['@self' => ['register' => 5, 'schema' => '7']],
			'uuid register and schema' => ['@self' => ['register' => 'r-uuid', 'schema' => 's-uuid']],
			'null metadata' => ['@self' => ['register' => null]],
			'order string' => ['_order' => 'title'],
			'order array' => ['_order' => ['title' => 'asc', 'created' => 'desc']],
			'filters and query parameters' => ['name' => 'x', '@self' => ['register' => 1], '_page' => 1, 'status' => ['a', 'b']],
		];
	}//end queryCases()

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::filterCases() as $name => $filters) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = [[['count' => '3']]];
			$result = $this->mapper()->count($filters);
			$actual['count: ' . $name] = ['result' => $result, 'queries' => $this->queryLog];
		}

		foreach (self::queryCases() as $name => $query) {
			$actual['extract: ' . $name] = $this->extracted(query: $query);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string) json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testOutcomesMatchGolden()

	/**
	 * The fields extractSearchParameters() records for one query.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>
	 */
	private function extracted(array $query): array {
		$trail = new SearchTrail();
		$method = new ReflectionMethod(SearchTrailMapper::class, 'extractSearchParameters');
		try {
			$method->invoke($this->mapper(), $trail, $query);
		} catch (\Throwable $e) {
			return ['thrown' => get_class($e)];
		}

		return [
			'updated' => array_keys($trail->getUpdatedFields()),
			'searchTerm' => $trail->getSearchTerm(),
			'page' => $trail->getPage(),
			'limit' => $trail->getLimit(),
			'offset' => $trail->getOffset(),
			'facetsRequested' => $trail->getFacetsRequested(),
			'facetableRequested' => $trail->getFacetableRequested(),
			'register' => $trail->getRegister(),
			'registerUuid' => $trail->getRegisterUuid(),
			'schema' => $trail->getSchema(),
			'schemaUuid' => $trail->getSchemaUuid(),
			'sort' => $trail->getSortParameters(),
			'filters' => $trail->getFilters(),
			'queryParameters' => $trail->getQueryParameters(),
		];
	}//end extracted()

	/**
	 * A mapper on a recording connection.
	 *
	 * @return SearchTrailMapper
	 */
	private function mapper(): SearchTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new SearchTrailMapper(
			$db,
			$this->createMock(IRequest::class),
			$this->createMock(IUserSession::class),
			new NullLogger()
		);
	}//end mapper()
}//end class
