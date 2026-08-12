<?php

declare(strict_types=1);

/*
 * Tests for Version1Date20260809000000 — creates the two schema-cache tables.
 *
 * The bug this migration repairs was invisible for its whole life: the
 * handlers queried `openregister_schema_cache` and
 * `openregister_schema_facet_cache`, no migration created either, and a
 * `catch` logging at `debug` swallowed every failure. So these tests do not
 * merely assert "a table is created" — they assert the created table carries
 * EVERY column the handlers actually write, because a migration that creates
 * the table with the wrong columns would fail in exactly the same silent way.
 *
 * The expected column sets below are derived from the handlers' own
 * statements, named in each test.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OpenRegister\Migration\Version1Date20260809000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1Date20260809000000Test extends TestCase {

	private Version1Date20260809000000 $migration;

	/**
	 * Column names captured per table during a changeSchema() call.
	 *
	 * @var array<string, list<string>>
	 */
	private array $columns = [];

	/**
	 * Plain (non-unique) index names captured per table.
	 *
	 * @var array<string, list<string>>
	 */
	private array $indexes = [];

	/**
	 * UNIQUE index names captured per table, kept apart from plain indexes.
	 *
	 * These are recorded separately on purpose. Collapsing both into one list
	 * made the uniqueness assertion untestable: downgrading
	 * addUniqueIndex() to addIndex() under the same name still satisfied a
	 * check that only looked for the name. Uniqueness is the property that
	 * makes the handlers' update-then-insert upsert correct, so it is the
	 * property the test has to assert.
	 *
	 * @var array<string, list<string>>
	 */
	private array $uniqueIndexes = [];

	/**
	 * Primary key column lists captured per table.
	 *
	 * @var array<string, list<string>>
	 */
	private array $primaryKeys = [];

	protected function setUp(): void {
		parent::setUp();
		$this->migration = new Version1Date20260809000000();
		$this->columns = [];
		$this->indexes = [];
		$this->uniqueIndexes = [];
		$this->primaryKeys = [];
	}//end setUp()

	/**
	 * Build an ISchemaWrapper whose createTable() records what it is asked for.
	 *
	 * @param list<string> $existingTables Tables that already exist.
	 *
	 * @return ISchemaWrapper
	 */
	private function recordingSchema(array $existingTables = []): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);

		$schema->method('hasTable')->willReturnCallback(
			static function (string $name) use ($existingTables): bool {
				return in_array($name, $existingTables, true);
			}
		);

		$schema->method('createTable')->willReturnCallback(
			function (string $name): Table {
				$this->columns[$name] = [];
				$this->indexes[$name] = [];
				$this->uniqueIndexes[$name] = [];
				$this->primaryKeys[$name] = [];

				$table = $this->createMock(Table::class);

				$table->method('addColumn')->willReturnCallback(
					function (string $column) use ($name): Table {
						$this->columns[$name][] = $column;
						return $this->createMock(Table::class);
					}
				);

				$table->method('setPrimaryKey')->willReturnCallback(
					function (array $cols) use ($name): Table {
						$this->primaryKeys[$name] = $cols;
						return $this->createMock(Table::class);
					}
				);

				$table->method('addUniqueIndex')->willReturnCallback(
					function (array $cols, string $indexName) use ($name): Table {
						$this->uniqueIndexes[$name][] = $indexName;
						return $this->createMock(Table::class);
					}
				);

				$table->method('addIndex')->willReturnCallback(
					function (array $cols, string $indexName) use ($name): Table {
						$this->indexes[$name][] = $indexName;
						return $this->createMock(Table::class);
					}
				);

				return $table;
			}
		);

		return $schema;
	}//end recordingSchema()

	/**
	 * The schema cache table must carry every column setCachedData() writes.
	 *
	 * Source of truth: SchemaCacheHandler::setCachedData(), whose insert names
	 * schema_id, cache_key, cache_data, created, updated and expires, and
	 * whose read keys on (schema_id, cache_key).
	 *
	 * @return void
	 */
	public function testCreatesSchemaCacheTableWithEveryColumnTheHandlerWrites(): void {
		$schema = $this->recordingSchema();
		$result = $this->migration->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertArrayHasKey('openregister_schema_cache', $this->columns);

		$expected = ['id', 'schema_id', 'cache_key', 'cache_data', 'created', 'updated', 'expires'];
		foreach ($expected as $column) {
			$this->assertContains(
				$column,
				$this->columns['openregister_schema_cache'],
				"openregister_schema_cache is missing the '{$column}' column, which SchemaCacheHandler writes."
			);
		}

		$this->assertSame(['id'], $this->primaryKeys['openregister_schema_cache']);
		$this->assertContains(
			'or_schema_cache_key_uniq',
			$this->uniqueIndexes['openregister_schema_cache'],
			'Without a UNIQUE index on (schema_id, cache_key) the handler update-then-insert upsert can duplicate a key.'
		);
	}//end testCreatesSchemaCacheTableWithEveryColumnTheHandlerWrites()

	/**
	 * The facet cache table must carry every column setCachedFacetData() writes.
	 *
	 * Source of truth: FacetCacheHandler::setCachedFacetData(), whose insert
	 * names schema_id, facet_type, field_name, facet_config, cache_data,
	 * created, updated and expires; its update arm keys on
	 * (schema_id, field_name) and getCacheStatistics() groups by facet_type.
	 *
	 * @return void
	 */
	public function testCreatesFacetCacheTableWithEveryColumnTheHandlerWrites(): void {
		$schema = $this->recordingSchema();
		$this->migration->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertArrayHasKey('openregister_schema_facet_cache', $this->columns);

		$expected = [
			'id',
			'schema_id',
			'facet_type',
			'field_name',
			'facet_config',
			'cache_data',
			'created',
			'updated',
			'expires',
		];
		foreach ($expected as $column) {
			$this->assertContains(
				$column,
				$this->columns['openregister_schema_facet_cache'],
				"openregister_schema_facet_cache is missing the '{$column}' column, which FacetCacheHandler writes."
			);
		}

		$this->assertSame(['id'], $this->primaryKeys['openregister_schema_facet_cache']);
		$this->assertContains(
			'or_facet_cache_field_uniq',
			$this->uniqueIndexes['openregister_schema_facet_cache'],
			'setCachedFacetData() updates on (schema_id, field_name); without a UNIQUE index its upsert can duplicate.'
		);
		$this->assertContains('or_facet_cache_type_idx', $this->indexes['openregister_schema_facet_cache']);
	}//end testCreatesFacetCacheTableWithEveryColumnTheHandlerWrites()

	/**
	 * Re-running the migration must not try to create either table again.
	 *
	 * @return void
	 */
	public function testIsIdempotentWhenBothTablesAlreadyExist(): void {
		$schema = $this->recordingSchema(
			['openregister_schema_cache', 'openregister_schema_facet_cache']
		);
		$schema->expects($this->never())->method('createTable');

		$result = $this->migration->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertNull($result, 'An unchanged schema must be reported as null, not returned.');
	}//end testIsIdempotentWhenBothTablesAlreadyExist()

	/**
	 * The migration must create the tables the handlers actually name.
	 *
	 * This is the regression guard for the original defect, and it is
	 * deliberately NOT a text scan. A grep for `createTable('openregister_...')`
	 * cannot see `createTable(self::TABLE)` or `createTable(tableName: '...')`,
	 * and it matches the same string in a comment — it fails in both
	 * directions at once. Writing this check that way produced two false
	 * "no migration creates it" results in this very repository
	 * (openregister_flow_state, which uses a constant, and
	 * openregister_tenant_keys, which uses a named argument).
	 *
	 * So the table names are read off the handlers by reflection and compared
	 * against what the migration actually asked the schema to create. Rename
	 * the constant on either side and this fails.
	 *
	 * @return void
	 */
	public function testMigrationCreatesExactlyTheTablesTheHandlersQuery(): void {
		$schemaCacheTable = (new \ReflectionClass(\OCA\OpenRegister\Service\Schemas\SchemaCacheHandler::class))
			->getConstant('CACHE_TABLE');
		$facetCacheTable = (new \ReflectionClass(\OCA\OpenRegister\Service\Schemas\FacetCacheHandler::class))
			->getConstant('FACET_CACHE_TABLE');

		$this->assertIsString($schemaCacheTable);
		$this->assertIsString($facetCacheTable);

		$schema = $this->recordingSchema();
		$this->migration->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$created = array_keys($this->columns);
		sort($created);

		$expected = [$facetCacheTable, $schemaCacheTable];
		sort($expected);

		$this->assertSame(
			$expected,
			$created,
			'The migration must create exactly the tables SchemaCacheHandler and FacetCacheHandler query. '
			. 'If this fails, one side was renamed and the cache is silently dead again.'
		);
	}//end testMigrationCreatesExactlyTheTablesTheHandlersQuery()

	/**
	 * A half-applied state must still create the table that is missing.
	 *
	 * @return void
	 */
	public function testCreatesOnlyTheMissingTable(): void {
		$schema = $this->recordingSchema(['openregister_schema_cache']);
		$result = $this->migration->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertArrayNotHasKey('openregister_schema_cache', $this->columns);
		$this->assertArrayHasKey('openregister_schema_facet_cache', $this->columns);
	}//end testCreatesOnlyTheMissingTable()
}//end class
