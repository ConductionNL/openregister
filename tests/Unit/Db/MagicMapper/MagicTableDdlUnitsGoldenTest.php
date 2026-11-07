<?php

/**
 * Golden tests pinning the outputs of the table DDL helpers before and after
 * their units were split: number column sizing, sync statistics, upsert SQL
 * pieces, the cross-table UNION and the table handler's small decisions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkUpsertSql;
use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableScan;
use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicUpsertChunkRun;
use OCA\OpenRegister\Db\MagicMapper\TableSyncColumns;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/magic-table-columns/spec.md
 */
class MagicTableDdlUnitsGoldenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		MagicMapper::clearAllStaticCaches();
	}//end setUp()

	public function testNumberColumnSizeEdges(): void {
		$column = new MagicNumberColumn();
		$this->assertSame(['precision' => 16, 'scale' => 2], $column->size(propertyConfig: ['multipleOf' => 0.01]));
		$this->assertSame(['precision' => 17, 'scale' => 3], $column->size(propertyConfig: ['multipleOf' => '0.005']));
		$this->assertSame(['precision' => 14, 'scale' => 0], $column->size(propertyConfig: ['multipleOf' => 5]));
		$this->assertSame(['precision' => 20, 'scale' => 6], $column->size(propertyConfig: ['multipleOf' => -0.5]));
		$this->assertSame(['precision' => 20, 'scale' => 6], $column->size(propertyConfig: ['multipleOf' => 'abc']));
		$this->assertSame(['precision' => 20, 'scale' => 6], $column->size(propertyConfig: ['multipleOf' => true]));
		$this->assertSame(['precision' => 20, 'scale' => 6], $column->size(propertyConfig: ['multipleOf' => 0]));
		$this->assertSame(['precision' => 44, 'scale' => 30], $column->size(propertyConfig: ['multipleOf' => '1e-40']));
	}//end testNumberColumnSizeEdges()

	public function testNumberColumnRefusalEdges(): void {
		$column = new MagicNumberColumn();
		$this->assertSame('has more than 2 decimals', $column->refusal('12.345', 16, 2));
		$this->assertNull($column->refusal('12.340', 16, 2));
		$this->assertSame('has more than 14 digits before the point', $column->refusal(10 ** 15, 20, 6));
		$this->assertSame('has more than 14 digits before the point', $column->refusal('1e14', 20, 6));
		$this->assertNull($column->refusal('1e13', 20, 6));
		$this->assertNull($column->refusal('abc', 20, 6));
		$this->assertNull($column->refusal(true, 20, 6));
		$this->assertNull($column->refusal([1], 20, 6));
		$this->assertSame('is not a finite number', $column->refusal(INF, 20, 6));
		$this->assertSame('has more than 2 decimals', $column->refusal(1.005, 16, 2));
		$this->assertSame('has more than 14 digits before the point', $column->refusal(1.0e15, 16, 2));
		$this->assertNull($column->refusal(0.1 + 0.2, 16, 2));
	}//end testNumberColumnRefusalEdges()

	public function testNumberColumnWideningEdges(): void {
		$column = new MagicNumberColumn();
		$this->assertSame(['precision' => 12, 'scale' => 4], $column->widening(['type' => 'DECIMAL', 'precision' => 10, 'scale' => 2], 10, 4));
		$this->assertNull($column->widening(['type' => 'decimal', 'precision' => '20', 'scale' => '6'], 20, 6));
		$this->assertNull($column->widening(['type' => 'decimal', 'precision' => 20], 20, 6));
		$this->assertNull($column->widening([], 20, 6));
		$this->assertSame(['precision' => 65, 'scale' => 30], $column->widening(['type' => 'numeric', 'precision' => 60, 'scale' => 10], 60, 40));
	}//end testNumberColumnWideningEdges()

	public function testCreatedStatistics(): void {
		$columns = new TableSyncColumns(required: ['id' => [], 'uuid' => [], 'title' => [], 'size' => []]);
		$this->assertSame(
			[
				'success' => true,
				'created' => true,
				'metadataProperties' => 2,
				'regularProperties' => 2,
				'totalProperties' => 4,
				'columnsAdded' => 4,
				'columnsDeRequired' => 0,
				'columnsDropped' => 0,
				'columnsUnchanged' => 0,
				'columnsAddedList' => ['id', 'uuid', 'title', 'size'],
				'columnsDeRequiredList' => [],
				'columnsDroppedList' => [],
			],
			$columns->createdStatistics()
		);
	}//end testCreatedStatistics()

	public function testUpdatedStatistics(): void {
		$changes = [
			'columnsAdded' => ['size'],
			'columnsDeRequired' => ['title'],
			'columnsReRequired' => [],
			'columnsDropped' => ['old', 'older'],
		];
		$columns = new TableSyncColumns(required: ['id' => [], 'title' => [], 'size' => []], changes: $changes);
		$this->assertSame(
			[
				'success' => true,
				'metadataProperties' => 1,
				'regularProperties' => 2,
				'totalProperties' => 3,
				'columnsAdded' => 1,
				'columnsDeRequired' => 1,
				'columnsReRequired' => 0,
				'columnsDropped' => 2,
				'columnsUnchanged' => 2,
				'columnsAddedList' => ['size'],
				'columnsDeRequiredList' => ['title'],
				'columnsReRequiredList' => [],
				'columnsDroppedList' => ['old', 'older'],
			],
			$columns->updatedStatistics(current: ['id' => [], 'title' => [], 'old' => [], 'older' => [], 'x' => []])
		);
	}//end testUpdatedStatistics()

	public function testUpdateClauseBranches(): void {
		$this->assertSame('"_updated" = NOW()', MagicBulkUpsertSql::updateClause('_updated', true, 'oc_t', true));
		$this->assertSame('`_updated` = NOW()', MagicBulkUpsertSql::updateClause('_updated', false, 'oc_t', false));
		$this->assertSame(
			'"_slug" = COALESCE(NULLIF("oc_t"."_slug", \'\'), EXCLUDED."_slug")',
			MagicBulkUpsertSql::updateClause('_slug', true, 'oc_t', true)
		);
		$this->assertSame('`_slug` = COALESCE(NULLIF(`_slug`, \'\'), VALUES(`_slug`))', MagicBulkUpsertSql::updateClause('_slug', false, 'oc_t', true));
		$this->assertSame('"_slug" = EXCLUDED."_slug"', MagicBulkUpsertSql::updateClause('_slug', true, 'oc_t', false));
		$this->assertSame('`_title` = VALUES(`_title`)', MagicBulkUpsertSql::updateClause('_title', false, 'oc_t', true));
	}//end testUpdateClauseBranches()

	public function testValuesAndParameters(): void {
		$run = new MagicUpsertChunkRun(tableName: 't', chunkNumber: 1);
		$run->rows = [
			['_uuid' => 'a', 'flag' => true],
			['_uuid' => 'b', 'other' => false],
		];
		$run->collectColumnsAndUuids();
		$this->assertSame(['_uuid', 'flag', 'other'], $run->columns);
		$this->assertSame(
			[
				'(:p0,:p1,:p2),(:p3,:p4,:p5)',
				['p0' => 'a', 'p1' => 1, 'p2' => null, 'p3' => 'b', 'p4' => null, 'p5' => 0],
			],
			$run->valuesAndParameters()
		);
	}//end testValuesAndParameters()

	public function testClassify(): void {
		$run = new MagicUpsertChunkRun(tableName: 't', chunkNumber: 1);
		$run->operationStartTime = '2026-01-01 10:00:00';
		$run->existingUuids = ['u1' => true, 'u2' => true, 'u3' => true];
		$run->preUpdateRows = ['u1' => ['_uuid' => 'u1', 'old' => 1]];

		$this->assertSame(
			['_uuid' => 'new', 'object_status' => 'created', 'operation_start_time' => '2026-01-01 10:00:00'],
			$run->classify(['_uuid' => 'new'])
		);
		$this->assertSame(
			[
				'_uuid' => 'u1',
				'_updated' => '2026-01-01 10:00:00',
				'object_status' => 'updated',
				'_pre_update_row' => ['_uuid' => 'u1', 'old' => 1],
				'operation_start_time' => '2026-01-01 10:00:00',
			],
			$run->classify(['_uuid' => 'u1', '_updated' => '2026-01-01 10:00:00'])
		);
		$this->assertSame('updated', $run->classify(['_uuid' => 'u2', '_updated' => '2026-01-02 00:00:00'])['object_status']);
		$this->assertArrayNotHasKey('_pre_update_row', $run->classify(['_uuid' => 'u2', '_updated' => '2026-01-02 00:00:00']));
		$this->assertSame('unchanged', $run->classify(['_uuid' => 'u3', '_updated' => '2025-12-31 23:59:59'])['object_status']);
		$this->assertSame('unchanged', $run->classify(['_uuid' => 'u3'])['object_status']);
	}//end testClassify()

	public function testUnionStatement(): void {
		$scan = new MagicCrossTableScan(label: 'test');
		$scan->predicate = '_uuid = ?';
		$scan->parameters = ['x'];
		$scan->tables = ['oc_a' => ['registerId' => 1, 'schemaId' => 2], 'oc_b' => ['registerId' => 1, 'schemaId' => 3]];
		$this->assertSame(
			[
				"SELECT 'oc_a' AS _source_table, _uuid AS found_uuid FROM oc_a WHERE _uuid = ? AND _deleted IS NULL"
				." UNION ALL SELECT 'oc_b' AS _source_table, _uuid AS found_uuid FROM oc_b WHERE _uuid = ? AND _deleted IS NULL",
				['x', 'x'],
			],
			$scan->unionStatement()
		);

		$scan->includeDeleted = true;
		$this->assertStringNotContainsString('_deleted', $scan->unionStatement()[0]);

		$scan->tables = [];
		$this->assertSame(['', []], $scan->unionStatement());
	}//end testUnionStatement()

	public function testMagicMappingEnabled(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->exactly(4))->method('getValueString')
			->with('openregister', 'magic_mapping_enabled', 'false')
			->willReturnOnConsecutiveCalls('false', 'true', 'false', 'true');
		$handler = $this->handler(appConfig: $appConfig, db: $this->createMock(IDBConnection::class));

		$this->assertTrue($handler->isMagicMappingEnabled(new Register(), $this->schemaWith(['magicMapping' => true])));
		$this->assertTrue($handler->isMagicMappingEnabledForSchema($this->schemaWith(['magicMapping' => true])));
		$this->assertFalse($handler->isMagicMappingEnabled(new Register(), $this->schemaWith(['magicMapping' => 1])));
		$this->assertTrue($handler->isMagicMappingEnabled(new Register(), $this->schemaWith(['magicMapping' => 'true'])));
		$this->assertFalse($handler->isMagicMappingEnabledForSchema($this->schemaWith(null)));
		$this->assertTrue($handler->isMagicMappingEnabledForSchema($this->schemaWith([])));
	}//end testMagicMappingEnabled()

	public function testExistingRegisterSchemaTablesAreParsedAndCached(): void {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->expects($this->once())->method('execute')->with(['oc_openregister_table_%']);
		$statement->method('fetchAll')->willReturn(
			[
				['tablename' => 'oc_openregister_table_5_12'],
				['tablename' => 'oc_openregister_table_5_x'],
				['tablename' => 'oc_other_5_12'],
				['tablename' => 'oc_openregister_table_7_3'],
			]
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturn($statement);

		$handler = $this->handler(appConfig: $this->createMock(IAppConfig::class), db: $db);
		$this->assertSame(
			[
				['registerId' => 5, 'schemaId' => 12, 'tableName' => 'oc_openregister_table_5_12'],
				['registerId' => 7, 'schemaId' => 3, 'tableName' => 'oc_openregister_table_7_3'],
			],
			$handler->getExistingRegisterSchemaTables()
		);
		$this->assertNotNull(MagicMapper::getTableExistsCache(key: '5_12'));
	}//end testExistingRegisterSchemaTablesAreParsedAndCached()

	public function testExistingRegisterSchemaTablesFailureIsEmpty(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willThrowException(new Exception('no pg_tables'));
		$handler = $this->handler(appConfig: $this->createMock(IAppConfig::class), db: $db);
		$this->assertSame([], $handler->getExistingRegisterSchemaTables());
	}//end testExistingRegisterSchemaTablesFailureIsEmpty()

	public function testEnsureFailureIsWrapped(): void {
		$magicMapper = $this->magicMapper();
		$magicMapper->method('checkTableExistsInDatabase')->willReturn(false);
		$magicMapper->method('createTableForRegisterSchema')->willThrowException(new Exception('boom'));
		$handler = new MagicTableHandler(
			db: $this->createMock(IDBConnection::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			magicMapper: $magicMapper
		);

		$register = new Register();
		$register->setId(5);
		$register->setTitle('Reg');
		$schema = new Schema();
		$schema->setId(12);
		$schema->setTitle('Sch');

		try {
			$handler->ensureTableForRegisterSchema($register, $schema);
			$this->fail('Expected an exception');
		} catch (Exception $e) {
			$this->assertSame("Failed to create/update table for register 'Reg' + schema 'Sch': boom", $e->getMessage());
			$this->assertSame('boom', $e->getPrevious()?->getMessage());
		}
	}//end testEnsureFailureIsWrapped()

	public function testForcedEnsureDropsAndRecreatesAnExistingTable(): void {
		$magicMapper = $this->magicMapper();
		$magicMapper->method('checkTableExistsInDatabase')->willReturn(true);
		$magicMapper->expects($this->once())->method('dropTable')->with('openregister_table_5_12');
		$magicMapper->expects($this->once())->method('invalidateTableCache')->with('5_12');
		$magicMapper->expects($this->once())->method('createTableForRegisterSchema')->willReturn(true);
		$handler = new MagicTableHandler(
			db: $this->createMock(IDBConnection::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			magicMapper: $magicMapper
		);

		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(12);
		$this->assertTrue($handler->ensureTableForRegisterSchema($register, $schema, true));
	}//end testForcedEnsureDropsAndRecreatesAnExistingTable()

	/**
	 * A schema whose configuration is the given value.
	 *
	 * @param array|null $configuration The configuration.
	 *
	 * @return Schema&MockObject
	 */
	private function schemaWith(?array $configuration): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn($configuration);
		return $schema;
	}//end schemaWith()

	/**
	 * A MagicMapper mock whose cache key is "registerId_schemaId".
	 *
	 * @return MagicMapper&MockObject
	 */
	private function magicMapper(): MagicMapper&MockObject {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('getCacheKey')->willReturnCallback(
			static fn (int $registerId, int $schemaId): string => $registerId.'_'.$schemaId
		);
		return $magicMapper;
	}//end magicMapper()

	/**
	 * A handler over the given app config and connection.
	 *
	 * @param IAppConfig    $appConfig App config.
	 * @param IDBConnection $db        Connection.
	 *
	 * @return MagicTableHandler
	 */
	private function handler(IAppConfig $appConfig, IDBConnection $db): MagicTableHandler {
		return new MagicTableHandler(
			db: $db,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			magicMapper: $this->magicMapper()
		);
	}//end handler()
}//end class
