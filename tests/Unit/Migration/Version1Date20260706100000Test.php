<?php

declare(strict_types=1);

/**
 * Tests for Version1Date20260706100000 — pgvector ANN sidecar table + HNSW index.
 *
 * Verifies: (a) postSchemaChange() is a logged no-op on non-PostgreSQL
 * platforms, (b) the PostgreSQL path creates the extension, the unprefixed
 * dimension-sized sidecar table (cascade FK to the main vectors table) and the
 * HNSW index, (c) idempotence when the sidecar/index already exist, (d) a
 * failing CREATE EXTENSION degrades to a warning instead of failing the
 * migration, and (e) a missing table is a no-op.
 *
 * The sidecar is deliberately unprefixed (invisible to Doctrine's
 * introspectSchema(), which throws on the pgvector column type) — the tests
 * pin that the created table name carries NO oc_ prefix.
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
use OCA\OpenRegister\Migration\Version1Date20260706100000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class Version1Date20260706100000Test extends TestCase
{
    private IDBConnection&MockObject $connection;
    private IConfig&MockObject $config;
    private Version1Date20260706100000 $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(IDBConnection::class);
        $this->config     = $this->createMock(IConfig::class);
        $this->config->method('getSystemValueString')->with('dbtableprefix', 'oc_')->willReturn('oc_');

        $this->migration = new Version1Date20260706100000($this->connection, $this->config);
    }//end setUp()

    /**
     * Build a schema wrapper that reports openregister_vectors present/absent.
     */
    private function makeSchema(bool $hasTable): ISchemaWrapper&MockObject
    {
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->with('openregister_vectors')->willReturn($hasTable);

        return $schema;
    }//end makeSchema()

    /**
     * Build an IResult mock returning the given fetchOne value.
     */
    private function makeResult(mixed $fetchOne): IResult&MockObject
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn($fetchOne);

        return $result;
    }//end makeResult()

    public function testPostSchemaChangeNoOpWhenTableMissing(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->makeSchema(false);

        $this->connection->expects($this->never())->method('getDatabasePlatform');
        $this->connection->expects($this->never())->method('executeStatement');

        $this->migration->postSchemaChange($output, fn () => $schema, []);
    }//end testPostSchemaChangeNoOpWhenTableMissing()

    public function testPostSchemaChangeSkipsWithInfoOnNonPostgres(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->makeSchema(true);

        $this->connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $this->connection->expects($this->never())->method('executeStatement');

        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('unsupported database platform'));

        $this->migration->postSchemaChange($output, fn () => $schema, []);
    }//end testPostSchemaChangeSkipsWithInfoOnNonPostgres()

    public function testPostSchemaChangeSkipsWithWarningOnEmptyPrefix(): void
    {
        $connection = $this->createMock(IDBConnection::class);
        $config     = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('dbtableprefix', 'oc_')->willReturn('');

        $migration = new Version1Date20260706100000($connection, $config);

        $output = $this->createMock(IOutput::class);
        $schema = $this->makeSchema(true);

        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->never())->method('executeStatement');

        $output->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('empty dbtableprefix'));

        $migration->postSchemaChange($output, fn () => $schema, []);
    }//end testPostSchemaChangeSkipsWithWarningOnEmptyPrefix()

    public function testPostSchemaChangeCreatesSidecarAndHnswIndexOnPostgres(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->makeSchema(true);

        $this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        // Queries: modal-dimension lookup → 768; sidecar-exists → false; index-exists → false.
        $this->connection->method('executeQuery')
            ->willReturnCallback(
                function (string $sql) {
                    if (str_contains($sql, 'embedding_dimensions') === true) {
                        return $this->makeResult(768);
                    }

                    return $this->makeResult(false);
                }
            );

        $statements = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(
                function (string $sql) use (&$statements): int {
                    $statements[] = $sql;
                    return 0;
                }
            );

        $this->migration->postSchemaChange($output, fn () => $schema, []);

        $this->assertContains('CREATE EXTENSION IF NOT EXISTS vector', $statements);

        $create = array_values(array_filter($statements, fn (string $s) => str_contains($s, 'CREATE TABLE')));
        $this->assertCount(1, $create);
        // Unprefixed sidecar — MUST NOT carry the oc_ prefix (Doctrine invisibility).
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS openregister_vec_ann', $create[0]);
        $this->assertStringNotContainsString('oc_openregister_vec_ann', $create[0]);
        $this->assertStringContainsString('embedding vector(768) NOT NULL', $create[0]);
        // Cascade delete from the (prefixed) main table.
        $this->assertStringContainsString('REFERENCES oc_openregister_vectors (id) ON DELETE CASCADE', $create[0]);

        $index = array_values(array_filter($statements, fn (string $s) => str_contains($s, 'CREATE INDEX')));
        $this->assertCount(1, $index);
        $this->assertStringContainsString('USING hnsw (embedding vector_cosine_ops)', $index[0]);
        $this->assertStringContainsString('idx_or_vec_ann_hnsw', $index[0]);
    }//end testPostSchemaChangeCreatesSidecarAndHnswIndexOnPostgres()

    public function testPostSchemaChangeIdempotentWhenSidecarAndIndexExist(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->makeSchema(true);

        $this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        // Sidecar-exists and index-exists checks return a hit.
        $this->connection->method('executeQuery')
            ->willReturnCallback(
                function (string $sql) {
                    if (str_contains($sql, 'embedding_dimensions') === true) {
                        return $this->makeResult(768);
                    }

                    return $this->makeResult(1);
                }
            );

        $statements = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(
                function (string $sql) use (&$statements): int {
                    $statements[] = $sql;
                    return 0;
                }
            );

        $this->migration->postSchemaChange($output, fn () => $schema, []);

        // Only the idempotent CREATE EXTENSION runs; no CREATE TABLE/INDEX.
        $this->assertSame(['CREATE EXTENSION IF NOT EXISTS vector'], $statements);
    }//end testPostSchemaChangeIdempotentWhenSidecarAndIndexExist()

    public function testPostSchemaChangeWarnsAndSkipsWhenExtensionUnavailable(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->makeSchema(true);

        $this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        // CREATE EXTENSION fails (no privileges) and pg_extension reports absent.
        $this->connection->method('executeStatement')
            ->willThrowException(new \Exception('permission denied to create extension'));
        $this->connection->method('executeQuery')
            ->willReturnCallback(
                function (string $sql) {
                    if (str_contains($sql, 'pg_extension') === true) {
                        return $this->makeResult(false);
                    }

                    return $this->makeResult(false);
                }
            );

        $output->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('pgvector extension is not installed'));

        $this->migration->postSchemaChange($output, fn () => $schema, []);
    }//end testPostSchemaChangeWarnsAndSkipsWhenExtensionUnavailable()
}//end class
