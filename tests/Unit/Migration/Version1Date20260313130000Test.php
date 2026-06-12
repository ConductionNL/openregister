<?php

declare(strict_types=1);

/**
 * Migration Version1Date20260313130000 Tests
 *
 * Tests that the published/depublished column drop migration is idempotent
 * and handles both present and absent columns correctly.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20260313130000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the published/depublished column drop migration.
 */
class Version1Date20260313130000Test extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var Version1Date20260313130000 */
    private Version1Date20260313130000 $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->migration = new Version1Date20260313130000($this->logger);
    }

    /**
     * Test migration handles tables WITHOUT published columns (idempotent).
     */
    public function testMigrationIdempotentWithoutColumns(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->createMock(ISchemaWrapper::class);

        // No magic tables, no objects table.
        $schema->method('getTableNames')->willReturn(['other_table']);
        $schema->method('hasTable')->with('openregister_objects')->willReturn(false);

        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('No tables'));

        $result = $this->migration->changeSchema($output, fn () => $schema, []);
        $this->assertNull($result, 'Should return null when no changes needed');
    }

    /**
     * Test migration drops columns from magic tables that have them.
     */
    public function testMigrationDropsColumnsFromMagicTables(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->createMock(ISchemaWrapper::class);

        $table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
        $table->method('hasColumn')
            ->willReturnMap([
                ['_published', true],
                ['_depublished', true],
            ]);
        $table->method('hasIndex')
            ->willReturnMap([
                ['idx__published', true],
            ]);
        $table->expects($this->exactly(2))->method('dropColumn');
        $table->expects($this->once())->method('dropIndex');

        $schema->method('getTableNames')->willReturn(['or_reg_schema']);
        $schema->method('getTable')->with('or_reg_schema')->willReturn($table);
        $schema->method('hasTable')->with('openregister_objects')->willReturn(false);

        $result = $this->migration->changeSchema($output, fn () => $schema, []);
        $this->assertSame($schema, $result, 'Should return schema when changes were made');
    }

    /**
     * Test migration skips magic tables without published columns.
     */
    public function testMigrationSkipsMagicTablesWithoutColumns(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->createMock(ISchemaWrapper::class);

        $table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
        $table->method('hasColumn')->willReturn(false);
        $table->method('hasIndex')->willReturn(false);
        $table->expects($this->never())->method('dropColumn');
        $table->expects($this->never())->method('dropIndex');

        $schema->method('getTableNames')->willReturn(['or_reg_schema']);
        $schema->method('getTable')->with('or_reg_schema')->willReturn($table);
        $schema->method('hasTable')->with('openregister_objects')->willReturn(false);

        $output->expects($this->once())
            ->method('info')
            ->with($this->stringContains('No tables'));

        $result = $this->migration->changeSchema($output, fn () => $schema, []);
        $this->assertNull($result);
    }

    /**
     * Test migration skips non-magic tables.
     */
    public function testMigrationSkipsNonMagicTables(): void
    {
        $output = $this->createMock(IOutput::class);
        $schema = $this->createMock(ISchemaWrapper::class);

        $schema->method('getTableNames')->willReturn([
            'users',
            'openregister_objects',
            'preferences',
        ]);
        $schema->expects($this->never())->method('getTable');
        $schema->method('hasTable')->with('openregister_objects')->willReturn(false);

        $result = $this->migration->changeSchema($output, fn () => $schema, []);
        $this->assertNull($result);
    }
}
