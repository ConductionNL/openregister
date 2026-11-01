<?php

/**
 * The query and the buckets of a single-table terms facet, call by call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every query-builder call a terms facet over one magic table makes, and the facet built from the rows.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TermsFacetUnion
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Support\FilterParams
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableTermsFacet
 */
class MagicFacetHandlerTableTermsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const KNOWN = '0d4a0f1e-5b8c-4c1e-9a7d-3f2b6c8e9a01';

	private const OTHER = '7e2c9b41-1d3a-4f6e-8b5c-2a9d0e7f4c12';

	private const UNKNOWN = '9f8e7d6c-5b4a-4321-8fed-cba987654321';

	/**
	 * Every information_schema probe, one table name per probe.
	 *
	 * @var array<int, string>
	 */
	private array $probes = [];

	/**
	 * Every name lookup, one list of ids per call.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $lookups = [];

	/**
	 * Every debug and warning message logged.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * A handler over a recording connection.
	 *
	 * @param array<string, mixed> $world columns (table => columns), rows (row sets in query order), filtered (bool).
	 *
	 * @return MagicFacetHandler The handler.
	 */
	private function handler(array $world): MagicFacetHandler {
		$this->queuedRows = $world['rows'] ?? [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($world): IPreparedStatement {
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturnCallback(
					function (array $params) use ($statement, $world): IResult {
						$this->probes[] = $params[0];
						$rows = array_map(static fn (string $col): array => ['col' => $col], $world['columns'][$params[0]] ?? []);
						$rows[] = false;
						$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
						return $this->createMock(IResult::class);
					}
				);
				return $statement;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->messages[] = $level . ': ' . $message;
				}
			);
		}

		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->method('getMultipleObjectNames')->willReturnCallback(
			function (array $ids): array {
				$this->lookups[] = array_values($ids);
				return array_intersect_key([self::KNOWN => 'Ontvangen', self::OTHER => 'Afgehandeld'], array_flip($ids));
			}
		);

		$searchHandler = null;
		if (($world['filtered'] ?? false) === true) {
			$searchHandler = $this->createMock(MagicSearchHandler::class);
			$searchHandler->method('buildFilteredQuery')->willReturnCallback(
				function (array $query, Schema $schema, string $tableName, ?int $registerId = null) {
					$qb = $this->recordingBuilder();
					$this->queryLog[] = '#' . $this->builderCount . ' buildFilteredQuery(' . $this->renderArgs([$query, $schema->getId(), $tableName, $registerId]) . ')';
					return $qb;
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('nc_');

		return new MagicFacetHandler(
			db: $db,
			logger: $logger,
			cacheHandler: $cacheHandler,
			searchHandler: $searchHandler,
			config: $config
		);
	}//end handler()

	/**
	 * A register with id 7.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(7);
		return $register;
	}//end register()

	/**
	 * A schema with id 3 and the given properties.
	 *
	 * @param array<string, array> $properties The property definitions.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	/**
	 * The facets without the timing block.
	 *
	 * @param array<string, mixed> $facets The facets.
	 *
	 * @return array<string, mixed> The facets.
	 */
	private function withoutMetrics(array $facets): array {
		unset($facets['_metrics']);
		return $facets;
	}//end withoutMetrics()

	/**
	 * Without a search handler the facet builds its own query: the deleted
	 * lens, @self filters, object filters and the search term, with UUID
	 * buckets named in one lookup and the NULL group as the missing bucket.
	 *
	 * @return void
	 */
	public function testAnObjectFieldFacetBuildsItsOwnFilteredQuery(): void {
		$schema = $this->schema(
			[
				'status' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'status'],
				'tags' => ['type' => 'array'],
				'title' => ['type' => 'string'],
			]
		);
		$facets = $this->handler(
			[
				'columns' => ['nc_openregister_table_7_3' => ['status', 'tags', 'title', '_owner']],
				'rows' => [
					[
						['facet_value' => null, 'doc_count' => '4'],
						['facet_value' => '["' . self::KNOWN . '","' . self::UNKNOWN . '"]', 'doc_count' => '2'],
						['facet_value' => self::KNOWN, 'doc_count' => '3'],
						['facet_value' => 'open', 'doc_count' => '1'],
					],
				],
			]
		)->getSimpleFacets(
			'openregister_table_7_3',
			[
				'_facets' => ['status' => ['type' => 'terms', 'title' => 'Status']],
				'@self' => ['owner' => 'alice', 'missing' => 'x', 'organisation' => ['o1', 'o2']],
				'tags' => 'red',
				'title' => ['a', 'b'],
				'_search' => ' Zaak ',
			],
			$this->register(),
			$schema
		);

		$this->assertSame(
			[
				'status' => [
					'type' => 'terms',
					'buckets' => [
						['key' => self::KNOWN, 'results' => 5, 'label' => 'Ontvangen'],
						['key' => self::UNKNOWN, 'results' => 2, 'label' => '9f8e7d6c...'],
						['key' => 'open', 'results' => 1, 'label' => 'open'],
					],
					'missing' => ['results' => 4],
					'title' => 'Status',
				],
			],
			$this->withoutMetrics($facets)
		);
		$this->assertSame(
			[
				'#1 selectAlias(status, facet_value)',
				'#1 addSelect(COUNT(*) as doc_count)',
				'#1 from(openregister_table_7_3, NULL)',
				'#1 groupBy(status)',
				'#1 orderBy(CASE WHEN status IS NULL THEN 0 ELSE 1 END, ASC)',
				'#1 addOrderBy(doc_count, DESC)',
				'#1 setMaxResults(10000)',
				'#1 andWhere(_deleted IS NULL)',
				'#1 param :p1 = alice, 2',
				'#1 expr.eq(_owner, :p1, NULL)',
				'#1 andWhere(eq(_owner, :p1, NULL))',
				'#1 param :p2 = ["red"], 2',
				'#1 andWhere(tags::jsonb @> :p2)',
				'#1 param :p3 = ["a","b"], 102',
				'#1 expr.in(title, :p3, NULL)',
				'#1 andWhere(in(title, :p3, NULL))',
				'#1 expr.orX()',
				'#1 param :p4 = %zaak%, 2',
				'#1 expr.like(LOWER(status), :p4, NULL)',
				'#1 param :p5 = %Zaak%, 2',
				'#1 param :p6 = %zaak%, 2',
				'#1 expr.like(LOWER(title), :p6, NULL)',
				'#1 param :p7 = %Zaak%, 2',
				'#1 andWhere(orX()+[LOWER(status) ILIKE LOWER(:p5), LOWER(title) ILIKE LOWER(:p7)])',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
		$this->assertSame(['nc_openregister_table_7_3'], $this->probes);
		$this->assertSame([[self::KNOWN, self::UNKNOWN]], $this->lookups);
	}//end testAnObjectFieldFacetBuildsItsOwnFilteredQuery()

	/**
	 * With a search handler the facet groups the handler's filtered query on
	 * the aliased column, and the register is handed on.
	 *
	 * @return void
	 */
	public function testWithASearchHandlerTheFilteredQueryIsGrouped(): void {
		$schema = $this->schema(['status' => ['type' => 'string']]);
		$facets = $this->handler(
			[
				'columns' => ['nc_openregister_table_7_3' => ['status']],
				'filtered' => true,
				'rows' => [[['facet_value' => 'open', 'doc_count' => '6'], ['facet_value' => 'closed', 'doc_count' => '2']]],
			]
		)->getSimpleFacets('openregister_table_7_3', ['_facets' => ['status' => ['type' => 'terms']], 'q' => 1], $this->register(), $schema);

		$this->assertSame(
			[
				'status' => [
					'type' => 'terms',
					'buckets' => [
						['key' => 'open', 'results' => 6, 'label' => 'open'],
						['key' => 'closed', 'results' => 2, 'label' => 'closed'],
					],
					'missing' => ['results' => 0],
				],
			],
			$this->withoutMetrics($facets)
		);
		$this->assertSame(
			[
				'#1 selectAlias(status, facet_value)',
				'#1 addSelect(COUNT(*) as doc_count)',
				'#1 from(openregister_table_7_3, NULL)',
				'#1 groupBy(status)',
				'#1 orderBy(CASE WHEN status IS NULL THEN 0 ELSE 1 END, ASC)',
				'#1 addOrderBy(doc_count, DESC)',
				'#1 setMaxResults(10000)',
				'#1 andWhere(_deleted IS NULL)',
				'#1 andWhere(1 = 0)',
				'#2 buildFilteredQuery({"q":1}, 3, openregister_table_7_3, 7)',
				'#2 selectAlias(t.status, facet_value)',
				'#2 addSelect(COUNT(*) as doc_count)',
				'#2 groupBy(t.status)',
				'#2 orderBy(CASE WHEN t.status IS NULL THEN 0 ELSE 1 END, ASC)',
				'#2 addOrderBy(doc_count, DESC)',
				'#2 setMaxResults(10000)',
				'#2 executeQuery()',
			],
			$this->queryLog
		);
		$this->assertSame([], $this->lookups);
	}//end testWithASearchHandlerTheFilteredQueryIsGrouped()

	/**
	 * A metadata facet labels through the field label, not the batch lookup,
	 * and a repeated facet is served from the per-request cache.
	 *
	 * @return void
	 */
	public function testAMetadataFacetLabelsPerValueAndIsCached(): void {
		$handler = $this->handler(
			[
				'columns' => ['nc_openregister_table_7_3' => ['_owner']],
				'rows' => [[['facet_value' => self::OTHER, 'doc_count' => '2'], ['facet_value' => 'bob', 'doc_count' => '1']]],
			]
		);
		$query = ['_facets' => ['@self' => ['owner' => ['type' => 'terms']]]];
		$first = $handler->getSimpleFacets('openregister_table_7_3', $query, $this->register(), $this->schema([]));
		$second = $handler->getSimpleFacets('openregister_table_7_3', $query, $this->register(), $this->schema([]));

		$expected = [
			'@self' => [
				'owner' => [
					'type' => 'terms',
					'buckets' => [
						['key' => self::OTHER, 'results' => 2, 'label' => 'Afgehandeld'],
						['key' => 'bob', 'results' => 1, 'label' => 'bob'],
					],
					'missing' => ['results' => 0],
				],
			],
		];
		$this->assertSame($expected, $this->withoutMetrics($first));
		$this->assertSame($expected, $this->withoutMetrics($second));
		$this->assertSame(
			[
				'#1 selectAlias(_owner, facet_value)',
				'#1 addSelect(COUNT(*) as doc_count)',
				'#1 from(openregister_table_7_3, NULL)',
				'#1 groupBy(_owner)',
				'#1 orderBy(CASE WHEN _owner IS NULL THEN 0 ELSE 1 END, ASC)',
				'#1 addOrderBy(doc_count, DESC)',
				'#1 setMaxResults(10000)',
				'#1 andWhere(_deleted IS NULL)',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
		$this->assertSame(1, $this->builderCount);
		$this->assertSame([[self::OTHER]], $this->lookups);
	}//end testAMetadataFacetLabelsPerValueAndIsCached()

	/**
	 * A property that names an integration reference type is never looked up
	 * as an object name: its UUID values are their own label.
	 *
	 * @return void
	 */
	public function testAReferenceTypePropertyIsNotLookedUp(): void {
		$schema = $this->schema(['group' => ['type' => 'string', 'referenceType' => 'nextcloud-group']]);
		$facets = $this->handler(
			[
				'columns' => ['nc_openregister_table_7_3' => ['group']],
				'rows' => [[['facet_value' => self::KNOWN, 'doc_count' => '1']]],
			]
		)->getSimpleFacets('openregister_table_7_3', ['_facets' => ['group' => ['type' => 'terms']]], $this->register(), $schema);

		$this->assertSame(
			['group' => ['type' => 'terms', 'buckets' => [['key' => self::KNOWN, 'results' => 1, 'label' => self::KNOWN]], 'missing' => ['results' => 0]]],
			$this->withoutMetrics($facets)
		);
		$this->assertSame(
			[
				'#1 selectAlias(group, facet_value)',
				'#1 addSelect(COUNT(*) as doc_count)',
				'#1 from(openregister_table_7_3, NULL)',
				'#1 groupBy(group)',
				'#1 orderBy(CASE WHEN group IS NULL THEN 0 ELSE 1 END, ASC)',
				'#1 addOrderBy(doc_count, DESC)',
				'#1 setMaxResults(10000)',
				'#1 andWhere(_deleted IS NULL)',
				'#1 executeQuery()',
			],
			$this->queryLog
		);
		$this->assertSame([], $this->lookups);
	}//end testAReferenceTypePropertyIsNotLookedUp()

	/**
	 * A column the table lacks gives an empty facet without a query, and
	 * the empty result is cached too.
	 *
	 * @return void
	 */
	public function testAMissingColumnGivesAnEmptyFacetWithoutAQuery(): void {
		$handler = $this->handler(['columns' => ['nc_openregister_table_7_3' => ['title']]]);
		$query = ['_facets' => ['status' => ['type' => 'terms']]];
		$first = $handler->getSimpleFacets('openregister_table_7_3', $query, $this->register(), $this->schema([]));
		$second = $handler->getSimpleFacets('openregister_table_7_3', $query, $this->register(), $this->schema([]));

		$expected = ['status' => ['type' => 'terms', 'buckets' => [], 'missing' => ['results' => 0]]];
		$this->assertSame($expected, $this->withoutMetrics($first));
		$this->assertSame($expected, $this->withoutMetrics($second));
		$this->assertSame([], $this->queryLog);
		$this->assertSame(['debug: [MagicFacetHandler] Column does not exist for facet'], $this->messages);
	}//end testAMissingColumnGivesAnEmptyFacetWithoutAQuery()
}//end class
