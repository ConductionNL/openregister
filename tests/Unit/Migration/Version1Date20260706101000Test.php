<?php

declare(strict_types=1);

/**
 * Tests for Version1Date20260706101000 — functional tsvector GIN index on chunks.
 *
 * Verifies: (a) postSchemaChange() is a logged no-op on non-PostgreSQL
 * platforms, (b) the PostgreSQL path creates the functional
 * to_tsvector('simple', text_content) GIN index (an expression index — a
 * STORED tsvector generated column breaks Doctrine schema introspection),
 * (c) idempotence when the index already exists, and (d) a missing table is
 * a no-op.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#7.5
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Migration\Version1Date20260706101000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class Version1Date20260706101000Test extends TestCase {
	private IDBConnection&MockObject $connection;
	private IConfig&MockObject $config;
	private Version1Date20260706101000 $migration;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->with('dbtableprefix', 'oc_')->willReturn('oc_');

		$this->migration = new Version1Date20260706101000($this->connection, $this->config);
	}//end setUp()

	/**
	 * Build a schema wrapper that reports openregister_chunks present/absent.
	 */
	private function makeSchema(bool $hasTable): ISchemaWrapper&MockObject {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_chunks')->willReturn($hasTable);

		return $schema;
	}//end makeSchema()

	/**
	 * Build an IResult mock returning the given fetchOne value.
	 */
	private function makeResult(mixed $fetchOne): IResult&MockObject {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($fetchOne);

		return $result;
	}//end makeResult()

	public function testPostSchemaChangeNoOpWhenTableMissing(): void {
		$output = $this->createMock(IOutput::class);
		$schema = $this->makeSchema(false);

		$this->connection->expects($this->never())->method('getDatabasePlatform');
		$this->connection->expects($this->never())->method('executeStatement');

		$this->migration->postSchemaChange($output, fn () => $schema, []);
	}//end testPostSchemaChangeNoOpWhenTableMissing()

	public function testPostSchemaChangeSkipsWithInfoOnNonPostgres(): void {
		$output = $this->createMock(IOutput::class);
		$schema = $this->makeSchema(true);

		$this->connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$this->connection->expects($this->never())->method('executeStatement');

		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('unsupported database platform'));

		$this->migration->postSchemaChange($output, fn () => $schema, []);
	}//end testPostSchemaChangeSkipsWithInfoOnNonPostgres()

	public function testPostSchemaChangeCreatesFunctionalGinIndexOnPostgres(): void {
		$output = $this->createMock(IOutput::class);
		$schema = $this->makeSchema(true);

		$this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		// Index-exists check reports absent.
		$this->connection->method('executeQuery')->willReturn($this->makeResult(false));

		$statements = [];
		$this->connection->method('executeStatement')
			->willReturnCallback(
				function (string $sql) use (&$statements): int {
					$statements[] = $sql;
					return 0;
				}
			);

		$this->migration->postSchemaChange($output, fn () => $schema, []);

		$this->assertCount(1, $statements);
		$this->assertStringContainsString('oc_openregister_chunks', $statements[0]);
		$this->assertStringContainsString('idx_or_chunks_text_search_gin', $statements[0]);
		// Functional (expression) index — no column is added to the table.
		$this->assertStringContainsString("USING gin (to_tsvector('simple', text_content))", $statements[0]);
		$this->assertStringNotContainsString('ADD COLUMN', $statements[0]);
	}//end testPostSchemaChangeCreatesFunctionalGinIndexOnPostgres()

	public function testPostSchemaChangeIdempotentWhenIndexExists(): void {
		$output = $this->createMock(IOutput::class);
		$schema = $this->makeSchema(true);

		$this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$this->connection->method('executeQuery')->willReturn($this->makeResult(1));
		$this->connection->expects($this->never())->method('executeStatement');

		$this->migration->postSchemaChange($output, fn () => $schema, []);
	}//end testPostSchemaChangeIdempotentWhenIndexExists()
}//end class
