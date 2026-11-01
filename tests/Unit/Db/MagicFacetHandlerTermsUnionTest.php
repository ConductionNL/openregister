<?php

/**
 * The SQL and the buckets of a multi-table terms facet, statement by statement.
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

use Doctrine\DBAL\Platforms\MySQLPlatform;
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
use RuntimeException;

/**
 * Every statement a union terms facet prepares, and the facet built from the rows.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TermsFacetUnion
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicFacetHandlerTermsUnionTest extends TestCase {

	private const KNOWN = '0d4a0f1e-5b8c-4c1e-9a7d-3f2b6c8e9a01';

	private const OTHER = '7e2c9b41-1d3a-4f6e-8b5c-2a9d0e7f4c12';

	/**
	 * Every SQL statement prepared, in order.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * Every SQL statement prepared, as sent.
	 *
	 * @var array<int, string>
	 */
	private array $rawStatements = [];

	/**
	 * Every buildWhereConditionsSql call, as [registerId, tableName, query].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $conditionCalls = [];

	/**
	 * Every warning logged, as [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Every name lookup, one list of ids per call.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $lookups = [];

	/**
	 * A handler over a recording connection.
	 *
	 * @param array<string, mixed> $world platform, columns (table => columns), rows, fails, conditions (table => list).
	 *
	 * @return MagicFacetHandler The handler.
	 */
	private function handler(array $world): MagicFacetHandler {
		$db = $this->createMock(IDBConnection::class);
		$platform = PostgreSQLPlatform::class;
		if (($world['platform'] ?? 'postgres') === 'mysql') {
			$platform = MySQLPlatform::class;
		}

		$db->method('getDatabasePlatform')->willReturn($this->createMock($platform));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($world): IPreparedStatement {
				$this->statements[] = preg_replace('/\s+/', ' ', $sql);
				$this->rawStatements[] = $sql;
				$statement = $this->createMock(IPreparedStatement::class);
				if (str_contains($sql, 'information_schema') === true) {
					$statement->method('execute')->willReturnCallback(
						function (array $params) use ($statement, $world): IResult {
							$columns = $world['columns'][$params[0]] ?? [];
							$rows = array_map(static fn (string $col): array => ['col' => $col], $columns);
							$rows[] = false;
							$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
							return $this->createMock(IResult::class);
						}
					);
					return $statement;
				}

				if (($world['fails'] ?? false) === true) {
					$statement->method('execute')->willThrowException(new RuntimeException('db gone'));
					return $statement;
				}

				$rows = $world['rows'] ?? [];
				$rows[] = false;
				$statement->method('execute')->willReturn($this->createMock(IResult::class));
				$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				return $statement;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['line']);
				$this->warnings[] = [$message, $context];
			}
		);

		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->method('getMultipleObjectNames')->willReturnCallback(
			function (array $ids): array {
				$this->lookups[] = $ids;
				return array_intersect_key([self::KNOWN => 'Ontvangen', self::OTHER => 'Afgehandeld'], array_flip($ids));
			}
		);

		$searchHandler = null;
		if (isset($world['conditions']) === true) {
			$searchHandler = $this->createMock(MagicSearchHandler::class);
			$searchHandler->method('buildWhereConditionsSql')->willReturnCallback(
				function (array $query, Schema $schema, ?array $existingColumns = null, ?int $registerId = null, ?string $tableName = null) use ($world): array {
					$this->conditionCalls[] = [$registerId, $tableName, $query];
					return $world['conditions'][$tableName] ?? [];
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
	 * One table configuration.
	 *
	 * @param int                  $schemaId   The schema id, which also names the table.
	 * @param array<string, array> $properties The schema's property definitions.
	 *
	 * @return array<string, mixed> The table configuration.
	 */
	private function table(int $schemaId, array $properties): array {
		$schema = $this->getMockBuilder(Schema::class)->disableOriginalConstructor()
			->onlyMethods(['getProperties'])->addMethods(['getId'])->getMock();
		$schema->method('getProperties')->willReturn($properties);
		$schema->method('getId')->willReturn($schemaId);
		$register = $this->getMockBuilder(Register::class)->disableOriginalConstructor()->addMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(7);

		return ['tableName' => 'openregister_table_7_'.$schemaId, 'schema' => $schema, 'register' => $register];
	}//end table()

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
	 * An object-field facet over four tables on Postgres: one table lacks the
	 * column, one is ruled out by its filters, and the other two are unioned
	 * with their own filter conditions.
	 *
	 * @return void
	 */
	public function testAnObjectFieldFacetOverFourTables(): void {
		$status = ['status' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'status']];
		$tables = [$this->table(2, $status), $this->table(3, []), $this->table(4, $status), $this->table(5, $status)];
		$facets = $this->handler(
			[
				'columns' => [
					'nc_openregister_table_7_2' => ['status', 'title'],
					'nc_openregister_table_7_3' => ['title'],
					'nc_openregister_table_7_4' => ['status'],
					'nc_openregister_table_7_5' => ['status'],
				],
				'conditions' => [
					'openregister_table_7_2' => ['a = 1', "b IN ('x')"],
					'openregister_table_7_4' => ['c = 2', '1=0'],
				],
				'rows' => [
					['facet_value' => null, 'doc_count' => '5'],
					['facet_value' => '["'.self::KNOWN.'","'.self::OTHER.'"]', 'doc_count' => '2'],
					['facet_value' => self::KNOWN, 'doc_count' => '3'],
					['facet_value' => '"open"', 'doc_count' => '1'],
				],
			]
		)->getSimpleFacetsUnion($tables, ['_facets' => ['status' => ['type' => 'terms', 'title' => 'Status']], 'q' => 'x']);

		$this->assertSame(
			[
				'status' => [
					'type' => 'terms',
					'buckets' => [
						['key' => self::KNOWN, 'results' => 5, 'label' => 'Ontvangen'],
						['key' => self::OTHER, 'results' => 2, 'label' => 'Afgehandeld'],
						['key' => 'open', 'results' => 1, 'label' => 'open'],
					],
					'missing' => ['results' => 5],
					'title' => 'Status',
				],
			],
			$this->withoutMetrics($facets)
		);
		$probe = 'SELECT LOWER(column_name) as col FROM information_schema.columns WHERE table_name = ?';
		$this->assertSame(
			[
				$probe,
				$probe,
				$probe,
				$probe,
				"SELECT facet_value, SUM(cnt) as doc_count FROM ( SELECT status::text as facet_value, COUNT(*) as cnt FROM nc_openregister_table_7_2 WHERE 1=1 AND a = 1 AND b IN ('x') GROUP BY status::text UNION ALL SELECT status::text as facet_value, COUNT(*) as cnt FROM nc_openregister_table_7_5 WHERE 1=1 GROUP BY status::text ) combined GROUP BY facet_value ORDER BY CASE WHEN facet_value IS NULL THEN 0 ELSE 1 END ASC, doc_count DESC LIMIT 10000",
			],
			$this->statements
		);
		$this->assertSame(
			[
				[7, 'openregister_table_7_2', ['q' => 'x']],
				[7, 'openregister_table_7_4', ['q' => 'x']],
				[7, 'openregister_table_7_5', ['q' => 'x']],
			],
			$this->conditionCalls
		);
		$this->assertSame(
			"SELECT facet_value, SUM(cnt) as doc_count FROM (\nSELECT status::text as facet_value, COUNT(*) as cnt FROM nc_openregister_table_7_2 WHERE 1=1 AND a = 1 AND b IN ('x') GROUP BY status::text\nUNION ALL\nSELECT status::text as facet_value, COUNT(*) as cnt FROM nc_openregister_table_7_5 WHERE 1=1 GROUP BY status::text\n) combined GROUP BY facet_value ORDER BY CASE WHEN facet_value IS NULL THEN 0 ELSE 1 END ASC, doc_count DESC LIMIT 10000",
			end($this->rawStatements)
		);
		$this->assertSame([[self::KNOWN, self::OTHER]], $this->lookups);
		$this->assertSame([], $this->warnings);
	}//end testAnObjectFieldFacetOverFourTables()

	/**
	 * A metadata facet on MySQL casts to CHAR, runs without a search handler,
	 * and labels each bucket through the field label, not the batch lookup.
	 *
	 * @return void
	 */
	public function testAMetadataFacetOnMySql(): void {
		$tables = [$this->table(2, []), $this->table(3, [])];
		$facets = $this->handler(
			[
				'platform' => 'mysql',
				'columns' => [
					'nc_openregister_table_7_2' => ['_owner'],
					'nc_openregister_table_7_3' => ['_owner'],
				],
				'rows' => [
					['facet_value' => 'alice', 'doc_count' => '2'],
					['facet_value' => self::OTHER, 'doc_count' => '4'],
					['facet_value' => '', 'doc_count' => '9'],
				],
			]
		)->getSimpleFacetsUnion($tables, ['_facets' => ['@self' => ['owner' => ['type' => 'terms']]]]);

		$this->assertSame(
			[
				'@self' => [
					'owner' => [
						'type' => 'terms',
						'buckets' => [
							['key' => self::OTHER, 'results' => 4, 'label' => 'Afgehandeld'],
							['key' => 'alice', 'results' => 2, 'label' => 'alice'],
						],
						'missing' => ['results' => 0],
					],
				],
			],
			$this->withoutMetrics($facets)
		);
		$this->assertSame(
			'SELECT facet_value, SUM(cnt) as doc_count FROM ( SELECT CAST(_owner AS CHAR) as facet_value, COUNT(*) as cnt FROM nc_openregister_table_7_2 WHERE 1=1 GROUP BY CAST(_owner AS CHAR) UNION ALL SELECT CAST(_owner AS CHAR) as facet_value, COUNT(*) as cnt FROM nc_openregister_table_7_3 WHERE 1=1 GROUP BY CAST(_owner AS CHAR) ) combined GROUP BY facet_value ORDER BY CASE WHEN facet_value IS NULL THEN 0 ELSE 1 END ASC, doc_count DESC LIMIT 10000',
			end($this->statements)
		);
		$this->assertSame([[self::OTHER]], $this->lookups);
	}//end testAMetadataFacetOnMySql()

	/**
	 * A metadata facet key that is not a plain column name never reaches SQL.
	 *
	 * @return void
	 */
	public function testAnUnsafeMetadataFieldIsRefused(): void {
		$facets = $this->handler(['columns' => ['nc_openregister_table_7_2' => ['_owner']]])
			->getSimpleFacetsUnion([$this->table(2, [])], ['_facets' => ['@self' => ['owner) OR (1=1' => ['type' => 'terms']]]]);

		$this->assertSame(
			['@self' => ['owner) OR (1=1' => ['type' => 'terms', 'buckets' => [], 'missing' => ['results' => 0]]]],
			$this->withoutMetrics($facets)
		);
		$this->assertSame([], $this->statements);
		$this->assertSame(
			[['[MagicFacetHandler] Rejected unsafe terms-facet field name', ['file' => (new \ReflectionClass(MagicFacetHandler::class))->getFileName(), 'field' => '_owner) OR (1=1']]],
			$this->warnings
		);
	}//end testAnUnsafeMetadataFieldIsRefused()

	/**
	 * When every table is ruled out, no union statement is prepared.
	 *
	 * @return void
	 */
	public function testEveryTableRuledOutPreparesNoUnion(): void {
		$facets = $this->handler(
			[
				'columns' => ['nc_openregister_table_7_2' => ['_owner'], 'nc_openregister_table_7_3' => []],
				'conditions' => ['openregister_table_7_2' => ['1=0']],
			]
		)->getSimpleFacetsUnion([$this->table(2, []), $this->table(3, [])], ['_facets' => ['@self' => ['owner' => ['type' => 'terms']]]]);

		$this->assertSame(
			['@self' => ['owner' => ['type' => 'terms', 'buckets' => [], 'missing' => ['results' => 0]]]],
			$this->withoutMetrics($facets)
		);
		$this->assertCount(2, $this->statements);
		$this->assertSame([[7, 'openregister_table_7_2', []]], $this->conditionCalls);
	}//end testEveryTableRuledOutPreparesNoUnion()

	/**
	 * A failing union statement yields an empty facet and logs the SQL.
	 *
	 * @return void
	 */
	public function testAFailingUnionYieldsAnEmptyFacet(): void {
		$facets = $this->handler(['columns' => ['nc_openregister_table_7_2' => ['_owner']], 'fails' => true])
			->getSimpleFacetsUnion([$this->table(2, [])], ['_facets' => ['@self' => ['owner' => ['type' => 'terms']]]]);

		$this->assertSame(
			['@self' => ['owner' => ['type' => 'terms', 'buckets' => [], 'missing' => ['results' => 0]]]],
			$this->withoutMetrics($facets)
		);
		$this->assertSame(
			[
				[
					'[MagicFacetHandler] UNION facet query failed',
					[
						'file' => (new \ReflectionClass(MagicFacetHandler::class))->getFileName(),
						'field' => '_owner',
						'error' => 'db gone',
						'sql' => end($this->rawStatements),
					],
				],
			],
			$this->warnings
		);
	}//end testAFailingUnionYieldsAnEmptyFacet()
}//end class
