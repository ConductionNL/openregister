<?php

/**
 * MagicTableIndexer creates relation and array GIN indexes and survives every failing statement.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer;
use OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class MagicTableIndexerBranchesTest extends TestCase {

	/**
	 * Statements the connection ran (failed ones included).
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * The logger behind the indexer.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * An indexer whose statements throw when they contain one of the given fragments.
	 *
	 * @param AbstractPlatform   $platform  The platform.
	 * @param array<int, string> $failing   Statement fragments that make executeStatement throw.
	 * @param bool               $trgm      Whether pg_trgm is installed.
	 *
	 * @return MagicTableIndexer
	 */
	private function indexer(AbstractPlatform $platform, array $failing = [], bool $trgm = false): MagicTableIndexer {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failing): int {
				$this->statements[] = $sql;
				foreach ($failing as $fragment) {
					if (str_contains($sql, $fragment) === true) {
						throw new RuntimeException('cannot create ' . $fragment);
					}
				}

				return 0;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn((int)$trgm);
		$prepared = $this->createMock(IPreparedStatement::class);
		$prepared->method('execute')->willReturn($result);
		$db->method('prepare')->willReturn($prepared);

		$this->logger = $this->createMock(LoggerInterface::class);
		return new MagicTableIndexer($db, $this->logger, new MagicPgTrgmProbe($db, $this->logger));
	}//end indexer()

	/**
	 * A schema with the given properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(array $properties): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		return $schema;
	}//end schema()

	/**
	 * Relation and reference properties of a PostgreSQL table.
	 *
	 * @return array<string, mixed>
	 */
	private static function relationProperties(): array {
		return [
			'owner' => ['type' => 'object', '$ref' => '#/person', 'objectConfiguration' => ['handling' => 'related-object']],
			'embedded' => ['type' => 'object', '$ref' => '#/address', 'objectConfiguration' => ['handling' => 'nested-object']],
			'tags' => ['type' => 'array', 'items' => ['$ref' => '#/tag']],
			'parts' => ['type' => 'array', 'items' => [], 'inversedBy' => 'whole'],
			'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
			'broken' => 'not a definition',
		];
	}//end relationProperties()

	/**
	 * On PostgreSQL, single relations get a btree index and reference arrays a GIN index.
	 *
	 * @return void
	 */
	public function testPostgresIndexesRelationsAndReferenceArrays(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new PostgreSQLPlatform())->create($run, $this->schema(self::relationProperties()));

		$this->assertTrue($run->isPostgres);
		$this->assertSame(['owner', 'tags (GIN)', 'parts (GIN)'], $run->relationIndexes);
		$this->assertContains('CREATE INDEX IF NOT EXISTS t_owner_rel_idx ON oc_t ("owner")', $this->statements);
		$this->assertContains('CREATE INDEX IF NOT EXISTS t_tags_arr_gin_idx ON oc_t USING GIN ("tags")', $this->statements);
		$this->assertStringNotContainsString('embedded', implode("\n", $this->statements));
		$this->assertStringNotContainsString('labels', implode("\n", $this->statements));
	}//end testPostgresIndexesRelationsAndReferenceArrays()

	/**
	 * On MySQL, reference arrays get no GIN index; single relations still get theirs.
	 *
	 * @return void
	 */
	public function testMysqlGivesReferenceArraysNoIndex(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');

		$this->indexer(new MySQLPlatform())->create($run, $this->schema(self::relationProperties()));

		$this->assertFalse($run->isPostgres);
		$this->assertSame(['owner'], $run->relationIndexes);
		$this->assertStringNotContainsString('GIN', implode("\n", $this->statements));
	}//end testMysqlGivesReferenceArraysNoIndex()

	/**
	 * A column index that fails is left out of the report and the pass goes on.
	 *
	 * @return void
	 */
	public function testAFailingColumnIndexIsNotReported(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');
		$properties = [
			'status' => ['type' => 'string', 'facetable' => true],
			'kind' => ['type' => 'string', 'facetable' => true],
		];

		$this->indexer(new MySQLPlatform(), ['t_status_facet_idx'])->create($run, $this->schema($properties));

		$this->assertSame(['kind'], $run->facetIndexes);
	}//end testAFailingColumnIndexIsNotReported()

	/**
	 * A duplicate MySQL (_deleted, _owner) index is ignored and the pass completes.
	 *
	 * @return void
	 */
	public function testAnExistingMysqlDeletedOwnerIndexIsIgnored(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');
		$indexer = $this->indexer(new MySQLPlatform(), ['t_deleted_owner_idx']);
		$debug = [];
		$this->logger->method('debug')->willReturnCallback(
			function (string $message, array $context) use (&$debug): void {
				$debug[] = [$message, $context];
			}
		);
		$this->logger->expects($this->never())->method('warning');

		$indexer->create($run, $this->schema(['status' => ['type' => 'string', 'facetable' => true]]));

		$this->assertSame('[MagicMapper] Skipped _deleted/_owner index', $debug[0][0]);
		$this->assertSame(['tableName' => 't', 'error' => 'cannot create t_deleted_owner_idx'], $debug[0][1]);
		$this->assertSame('[MagicMapper] Created table indexes', $debug[1][0]);
		$this->assertSame(['status'], $run->facetIndexes);
	}//end testAnExistingMysqlDeletedOwnerIndexIsIgnored()

	/**
	 * A failing baseline _name trigram index is logged at debug and the pass completes.
	 *
	 * @return void
	 */
	public function testAFailingNameTrigramIndexIsSkipped(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');
		$indexer = $this->indexer(new PostgreSQLPlatform(), ['t_name_trgm_idx'], true);
		$debug = [];
		$this->logger->method('debug')->willReturnCallback(
			function (string $message, array $context) use (&$debug): void {
				$debug[] = [$message, $context];
			}
		);
		$this->logger->expects($this->never())->method('warning');

		$indexer->create($run, $this->schema(['title' => ['type' => 'string', 'searchable' => true]]));

		$this->assertSame('[MagicMapper] Skipped baseline _name trgm index', $debug[0][0]);
		$this->assertSame(['tableName' => 't', 'error' => 'cannot create t_name_trgm_idx'], $debug[0][1]);
		$this->assertSame(['title'], $run->searchableIndexes);
		$this->assertSame('[MagicMapper] Created table indexes', $debug[1][0]);
	}//end testAFailingNameTrigramIndexIsSkipped()

	/**
	 * A failing baseline index stops the pass with a warning instead of an exception.
	 *
	 * @return void
	 */
	public function testAFailingBaselineIndexStopsThePassWithAWarning(): void {
		$run = new MagicTableIndexRun('t', 'oc_t');
		$indexer = $this->indexer(new MySQLPlatform(), ['t_uuid_idx']);
		$this->logger->expects($this->once())->method('warning')->with(
			'[MagicMapper] Failed to create some table indexes',
			['tableName' => 't', 'error' => 'cannot create t_uuid_idx']
		);
		$this->logger->expects($this->never())->method('debug');

		$indexer->create($run, $this->schema(['status' => ['type' => 'string', 'facetable' => true]]));

		$this->assertCount(1, $this->statements);
		$this->assertSame([], $run->facetIndexes);
	}//end testAFailingBaselineIndexStopsThePassWithAWarning()
}//end class
