<?php

/**
 * Behaviour tests for SchemaTableMigrator planning, counting and migrate edge paths.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SharedSchema
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

namespace OCA\OpenRegister\Tests\Unit\Service\SharedSchema;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator;
use OCA\OpenRegister\Service\SharedSchema\SplitTableColumns;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers planUnmapped(), countRows(), the absent-source short-circuit of migrate(),
 * the tolerated `_uri` rewrite failure, and the prefix / quote fallbacks.
 *
 * @covers \OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator
 */
class SchemaTableMigratorBehaviourTest extends TestCase {

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * @var IConfig&MockObject
	 */
	private IConfig $config;

	/**
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $magic;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db     = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->magic  = $this->createMock(MagicMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The migrator under test.
	 *
	 * @return SchemaTableMigrator
	 */
	private function migrator(): SchemaTableMigrator {
		return new SchemaTableMigrator(db: $this->db, config: $this->config, magicMapper: $this->magic, logger: $this->logger);
	}//end migrator()

	/**
	 * Make the information_schema probe answer the given value (or throw).
	 *
	 * @param mixed $answer What fetchOne() returns; a Throwable is thrown from prepare().
	 *
	 * @return void
	 */
	private function tableProbe(mixed $answer): void {
		if ($answer instanceof \Throwable) {
			$this->db->method('prepare')->willThrowException($answer);
			return;
		}

		$prepared = $this->createMock(IPreparedStatement::class);
		$prepared->method('fetchOne')->willReturn($answer);
		$this->db->method('prepare')->willReturn($prepared);
	}//end tableProbe()

	/**
	 * The bare table name is stem + register + schema.
	 *
	 * @return void
	 */
	public function testTableNameForJoinsRegisterAndSchema(): void {
		$this->assertSame('openregister_table_4_17', $this->migrator()->tableNameFor(registerId: 4, schemaId: 17));
	}//end testTableNameForJoinsRegisterAndSchema()

	/**
	 * With an existing source table and a configured definition, the definition decides the target.
	 *
	 * @return void
	 */
	public function testPlanUnmappedUsesExistingTableAgainstDefinition(): void {
		$this->magic->expects($this->once())->method('getExistingTableColumns')
			->with('openregister_table_1_2')
			->willReturn(['_id' => [], '_uuid' => [], 'colour' => [], 'size' => []]);
		$seen = [];
		$this->magic->method('buildTableColumnsFromSchema')->willReturnCallback(
			static function (Schema $schema) use (&$seen): array {
				$seen[] = $schema->getTitle();
				return ['_id' => [], '_uuid' => [], 'size' => []];
			}
		);

		$unmapped = $this->migrator()->planUnmapped(
			split: new SplitTableColumns(
				table: 'openregister_table_1_2',
				definition: ['title' => 'own'],
				content: ['title' => 'shared']
			)
		);

		$this->assertSame(['colour'], $unmapped);
		$this->assertSame(['own'], $seen, 'Only the register definition is used when the source table exists.');
	}//end testPlanUnmappedUsesExistingTableAgainstDefinition()

	/**
	 * An absent source table falls back to the shared content for the source, and to the
	 * content again for the target when no definition is configured.
	 *
	 * @return void
	 */
	public function testPlanUnmappedFallsBackToContentWhenTableMissingAndNoDefinition(): void {
		$this->magic->method('getExistingTableColumns')->willThrowException(new RuntimeException('no table'));
		$seen = [];
		$this->magic->method('buildTableColumnsFromSchema')->willReturnCallback(
			static function (Schema $schema) use (&$seen): array {
				$seen[] = $schema->getTitle();
				return ['_uuid' => [], 'name' => []];
			}
		);

		$unmapped = $this->migrator()->planUnmapped(split: new SplitTableColumns(table: 'openregister_table_1_2', definition: null, content: ['title' => 'shared']));

		$this->assertSame([], $unmapped);
		$this->assertSame(['shared', 'shared'], $seen);
	}//end testPlanUnmappedFallsBackToContentWhenTableMissingAndNoDefinition()

	/**
	 * When the column builder refuses a definition, the prediction is empty and a warning is logged.
	 *
	 * @return void
	 */
	public function testPlanUnmappedLogsWhenColumnsCannotBePredicted(): void {
		$this->magic->method('getExistingTableColumns')->willReturn(['_id' => [], 'a' => [], 'b' => []]);
		$this->magic->method('buildTableColumnsFromSchema')->willThrowException(new RuntimeException('bad definition'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not predict columns: bad definition'));

		$unmapped = $this->migrator()->planUnmapped(split: new SplitTableColumns(table: 't', definition: ['title' => 'x'], content: []));

		$this->assertSame(['a', 'b'], $unmapped, 'Without a predicted target, every non-key column is unmapped.');
	}//end testPlanUnmappedLogsWhenColumnsCannotBePredicted()

	/**
	 * countRows() reports -1 for an absent table without querying it.
	 *
	 * @return void
	 */
	public function testCountRowsReportsMinusOneForAbsentTable(): void {
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->tableProbe(answer: false);
		$this->db->expects($this->never())->method('executeQuery');

		$this->assertSame(-1, $this->migrator()->countRows(table: 'openregister_table_1_2'));
	}//end testCountRowsReportsMinusOneForAbsentTable()

	/**
	 * A failing information_schema probe counts as absent.
	 *
	 * @return void
	 */
	public function testCountRowsTreatsProbeFailureAsAbsent(): void {
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->tableProbe(answer: new RuntimeException('down'));
		$this->db->expects($this->never())->method('executeQuery');

		$this->assertSame(-1, $this->migrator()->countRows(table: 'openregister_table_1_2'));
	}//end testCountRowsTreatsProbeFailureAsAbsent()

	/**
	 * An existing table is counted with the configured prefix and the platform quote.
	 *
	 * @return void
	 */
	public function testCountRowsCountsWithPrefixAndBacktickOnMariaDb(): void {
		$this->config->method('getSystemValue')->with('dbtableprefix', 'oc_')->willReturn('nc_');
		$this->tableProbe(answer: 1);
		$this->db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn('42');
		$this->db->expects($this->once())->method('executeQuery')
			->with('SELECT COUNT(*) AS c FROM `nc_openregister_table_1_2`')
			->willReturn($result);

		$this->assertSame(42, $this->migrator()->countRows(table: 'openregister_table_1_2'));
	}//end testCountRowsCountsWithPrefixAndBacktickOnMariaDb()

	/**
	 * An empty configured prefix falls back to `oc_`, and an unknown platform to double quotes.
	 *
	 * @return void
	 */
	public function testCountRowsDefaultsEmptyPrefixAndQuoteOnPlatformFailure(): void {
		$this->config->method('getSystemValue')->willReturn('');
		$this->tableProbe(answer: 1);
		$this->db->method('getDatabasePlatform')->willThrowException(new RuntimeException('no platform'));
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(7);
		$this->db->expects($this->once())->method('executeQuery')
			->with('SELECT COUNT(*) AS c FROM "oc_t"')
			->willReturn($result);

		$this->assertSame(7, $this->migrator()->countRows(table: 't'));
	}//end testCountRowsDefaultsEmptyPrefixAndQuoteOnPlatformFailure()

	/**
	 * A failing count query reports -1.
	 *
	 * @return void
	 */
	public function testCountRowsReportsMinusOneWhenQueryFails(): void {
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->tableProbe(answer: 1);
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$this->db->method('executeQuery')->willThrowException(new RuntimeException('locked'));

		$this->assertSame(-1, $this->migrator()->countRows(table: 't'));
	}//end testCountRowsReportsMinusOneWhenQueryFails()

	/**
	 * An unsafe table name is refused rather than interpolated, and reported as -1.
	 *
	 * @return void
	 */
	public function testCountRowsRefusesUnsafeTableName(): void {
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->tableProbe(answer: 1);
		$this->db->expects($this->never())->method('executeQuery');

		$this->assertSame(-1, $this->migrator()->countRows(table: 't; DROP TABLE x'));
	}//end testCountRowsRefusesUnsafeTableName()

	/**
	 * migrate() with no source table moves nothing and touches no DDL.
	 *
	 * @return void
	 */
	public function testMigrateWithoutSourceTableIsANoOp(): void {
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->tableProbe(answer: false);
		$this->magic->expects($this->never())->method('ensureTableForRegisterSchema');
		$this->db->expects($this->never())->method('executeStatement');

		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(8);

		$this->assertSame(
			['rows' => 0, 'unmapped' => [], 'backup' => null],
			$this->migrator()->migrate(table: new RegisterSchema(register: $register, schema: $schema), oldId: 2)
		);
	}//end testMigrateWithoutSourceTableIsANoOp()

	/**
	 * A failing `_uri` rewrite is logged and the split still completes with the restamped count.
	 *
	 * @return void
	 */
	public function testMigrateToleratesUriRewriteFailure(): void {
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->tableProbe(answer: 1);
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$this->magic->method('getTableNameForRegisterSchema')->willReturn('openregister_table_3_8');
		$this->magic->method('getExistingTableColumns')->willReturnCallback(
			static fn (string $tableName): array => $tableName === 'openregister_table_3_2'
				? ['_id' => [], '_uuid' => [], 'legacy' => []]
				: ['_id' => [], '_uuid' => []]
		);

		$executed = [];
		$this->db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$executed): int {
				$executed[] = $sql;
				if (str_contains($sql, '_uri') === true) {
					throw new RuntimeException('no _uri column');
				}

				return 5;
			}
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(8);

		$result = $this->migrator()->migrate(table: new RegisterSchema(register: $register, schema: $schema), oldId: 2);

		$this->assertSame(
			['rows' => 5, 'unmapped' => ['legacy'], 'backup' => 'oc_openregister_table_3_2_predupe'],
			$result
		);
		$this->assertSame('ALTER TABLE "oc_openregister_table_3_2" RENAME TO "oc_openregister_table_3_2_predupe"', end($executed));
		$this->assertStringContainsString('Could not rewrite _uri on oc_openregister_table_3_8: no _uri column', $warnings[0]);
		$this->assertStringContainsString('1 unmapped column(s): legacy', $warnings[1]);
	}//end testMigrateToleratesUriRewriteFailure()

	/**
	 * A completed split copies the mapped columns, then repoints `_schema` and
	 * the register-bounded `_uri` segment from the old schema id to the new one.
	 *
	 * @return void
	 */
	public function testMigrateRestampsSchemaAndUriWithBoundParameters(): void {
		$this->config->method('getSystemValue')->willReturn('oc_');
		$this->tableProbe(answer: 1);
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$this->magic->method('getTableNameForRegisterSchema')->willReturn('openregister_table_3_8');
		$this->magic->method('getExistingTableColumns')->willReturn(['_id' => [], '_uuid' => []]);

		$executed = [];
		$this->db->method('executeStatement')->willReturnCallback(
			static function (string $sql, array $params=[]) use (&$executed): int {
				$executed[] = [$sql, $params];
				return 4;
			}
		);

		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(8);

		$result = $this->migrator()->migrate(table: new RegisterSchema(register: $register, schema: $schema), oldId: 2);

		$this->assertSame(4, $result['rows']);
		$this->assertSame(
			[
				['INSERT INTO "oc_openregister_table_3_8" ("_uuid") SELECT "_uuid" FROM "oc_openregister_table_3_2"', []],
				['UPDATE "oc_openregister_table_3_8" SET _schema = :new WHERE _schema = :old', ['new' => '8', 'old' => '2']],
				[
					'UPDATE "oc_openregister_table_3_8" SET _uri = REPLACE(_uri, :old, :new) WHERE _uri LIKE :match',
					['old' => '/3/2/', 'new' => '/3/8/', 'match' => '%/3/2/%'],
				],
				['ALTER TABLE "oc_openregister_table_3_2" RENAME TO "oc_openregister_table_3_2_predupe"', []],
			],
			$executed
		);
	}//end testMigrateRestampsSchemaAndUriWithBoundParameters()
}//end class
