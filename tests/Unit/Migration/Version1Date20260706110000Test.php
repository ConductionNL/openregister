<?php

declare(strict_types=1);

/**
 * Tests for Version1Date20260706110000 — pg_trgm extension bootstrap.
 *
 * Verifies: (a) postSchemaChange() is a logged no-op on non-PostgreSQL
 * platforms, (b) the PostgreSQL path issues CREATE EXTENSION IF NOT EXISTS
 * pg_trgm, (c) a CREATE EXTENSION failure where the extension already exists
 * is tolerated (info, not warning), and (d) a CREATE EXTENSION failure where
 * the extension is genuinely absent warns but never throws. The migration adds
 * no column and no table, so — unlike the sibling vector/tsvector migrations —
 * it never touches Doctrine schema introspection.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/searchable-property-index/tasks.md#1.1
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Migration\Version1Date20260706110000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class Version1Date20260706110000Test extends TestCase
{
    private IDBConnection&MockObject $connection;
    private Version1Date20260706110000 $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(IDBConnection::class);
        $this->migration  = new Version1Date20260706110000($this->connection);
    }//end setUp()

    /**
     * A bare schema-closure result; this migration does not gate on any table.
     */
    private function schemaClosure(): \Closure
    {
        $schema = $this->createMock(ISchemaWrapper::class);
        return fn () => $schema;
    }//end schemaClosure()

    /**
     * Build an IResult mock returning the given fetchOne value.
     */
    private function makeResult(mixed $fetchOne): IResult&MockObject
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn($fetchOne);

        return $result;
    }//end makeResult()

    public function testSkipsWithInfoOnNonPostgres(): void
    {
        $output = $this->createMock(IOutput::class);

        $this->connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $this->connection->expects($this->never())->method('executeStatement');

        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('unsupported database platform'));

        $this->migration->postSchemaChange($output, $this->schemaClosure(), []);
    }//end testSkipsWithInfoOnNonPostgres()

    public function testCreatesPgTrgmExtensionOnPostgres(): void
    {
        $output = $this->createMock(IOutput::class);

        $this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $statements = [];
        $this->connection->method('executeStatement')
            ->willReturnCallback(
                function (string $sql) use (&$statements): int {
                    $statements[] = $sql;
                    return 0;
                }
            );

        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('pg_trgm extension is available'));

        $this->migration->postSchemaChange($output, $this->schemaClosure(), []);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('CREATE EXTENSION IF NOT EXISTS pg_trgm', $statements[0]);
        // No column and no table are ever added — Doctrine introspection is untouched.
        $this->assertStringNotContainsString('ADD COLUMN', $statements[0]);
        $this->assertStringNotContainsString('CREATE TABLE', $statements[0]);
    }//end testCreatesPgTrgmExtensionOnPostgres()

    public function testToleratesCreateFailureWhenExtensionAlreadyPresent(): void
    {
        $output = $this->createMock(IOutput::class);

        $this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $this->connection->method('executeStatement')
            ->willThrowException(new \OCP\DB\Exception('permission denied to create extension'));
        // The follow-up existence check reports the extension IS present.
        $this->connection->method('executeQuery')->willReturn($this->makeResult(1));

        $output->expects($this->never())->method('warning');
        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('already installed'));

        $this->migration->postSchemaChange($output, $this->schemaClosure(), []);
    }//end testToleratesCreateFailureWhenExtensionAlreadyPresent()

    public function testWarnsWhenExtensionCannotBeCreated(): void
    {
        $output = $this->createMock(IOutput::class);

        $this->connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $this->connection->method('executeStatement')
            ->willThrowException(new \OCP\DB\Exception('permission denied to create extension'));
        // The follow-up existence check reports the extension is genuinely absent.
        $this->connection->method('executeQuery')->willReturn($this->makeResult(false));

        $output->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('pg_trgm extension is not installed'));

        // Must not throw — a failed bootstrap degrades to the unindexed path.
        $this->migration->postSchemaChange($output, $this->schemaClosure(), []);
    }//end testWarnsWhenExtensionCannotBeCreated()
}//end class
