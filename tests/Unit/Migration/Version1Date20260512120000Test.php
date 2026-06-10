<?php

declare(strict_types=1);

/*
 * Tests for Version1Date20260512120000 — adds `bases` (JSON, nullable)
 * and `skip_anonymization` (BOOLEAN, default false) columns to
 * `openregister_entity_relations`.
 *
 * Verifies: (a) changeSchema() adds both columns when missing,
 * (b) is idempotent — neither column added a second time when already
 * present, and (c) is a no-op when the entity_relations table itself
 * is absent.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/entity-relation-grondslagen/tasks.md "1.1 Add a new migration class"
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20260512120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-test the bases / skip_anonymization migration.
 *
 * Mock-driven (no live DB) — verifies the migration's column-add /
 * idempotency / table-absent branches at the schema-wrapper API level.
 */
class Version1Date20260512120000Test extends TestCase
{

    private Version1Date20260512120000 $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = new Version1Date20260512120000();
    }//end setUp()

    public function testChangeSchemaAddsBothColumnsWhenMissing(): void
    {
        $output = $this->createMock(IOutput::class);

        $table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
        $table->method('hasColumn')->willReturnMap(
            [
                ['bases', false],
                ['skip_anonymization', false],
            ]
        );

        $addedColumns = [];
        $table->expects($this->exactly(2))
            ->method('addColumn')
            ->willReturnCallback(
                function (string $name, string $type, array $options) use (&$addedColumns): void {
                    $addedColumns[$name] = [
                        'type'    => $type,
                        'options' => $options,
                    ];
                }
            );

        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->with('openregister_entity_relations')->willReturn(true);
        $schema->method('getTable')->with('openregister_entity_relations')->willReturn($table);

        $result = $this->migration->changeSchema($output, fn () => $schema, []);

        $this->assertSame($schema, $result);
        $this->assertArrayHasKey('bases', $addedColumns);
        $this->assertSame(Types::JSON, $addedColumns['bases']['type']);
        $this->assertFalse($addedColumns['bases']['options']['notnull']);

        $this->assertArrayHasKey('skip_anonymization', $addedColumns);
        $this->assertSame(Types::BOOLEAN, $addedColumns['skip_anonymization']['type']);
        $this->assertTrue($addedColumns['skip_anonymization']['options']['notnull']);
        $this->assertFalse($addedColumns['skip_anonymization']['options']['default']);
    }//end testChangeSchemaAddsBothColumnsWhenMissing()

    public function testChangeSchemaIsIdempotentWhenBothColumnsAlreadyExist(): void
    {
        $output = $this->createMock(IOutput::class);

        $table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
        $table->method('hasColumn')->willReturnMap(
            [
                ['bases', true],
                ['skip_anonymization', true],
            ]
        );
        $table->expects($this->never())->method('addColumn');

        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->with('openregister_entity_relations')->willReturn(true);
        $schema->method('getTable')->with('openregister_entity_relations')->willReturn($table);

        $result = $this->migration->changeSchema($output, fn () => $schema, []);
        $this->assertSame($schema, $result);
    }//end testChangeSchemaIsIdempotentWhenBothColumnsAlreadyExist()

    public function testChangeSchemaAddsOnlyMissingColumn(): void
    {
        $output = $this->createMock(IOutput::class);

        $table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
        // `bases` already present, `skip_anonymization` missing — only
        // the missing one MUST be added (the migration is per-column
        // idempotent, not all-or-nothing).
        $table->method('hasColumn')->willReturnMap(
            [
                ['bases', true],
                ['skip_anonymization', false],
            ]
        );

        $addedColumns = [];
        $table->expects($this->once())
            ->method('addColumn')
            ->willReturnCallback(
                function (string $name) use (&$addedColumns): void {
                    $addedColumns[] = $name;
                }
            );

        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->with('openregister_entity_relations')->willReturn(true);
        $schema->method('getTable')->with('openregister_entity_relations')->willReturn($table);

        $this->migration->changeSchema($output, fn () => $schema, []);
        $this->assertSame(['skip_anonymization'], $addedColumns);
    }//end testChangeSchemaAddsOnlyMissingColumn()

    public function testChangeSchemaIsNoOpWhenTableMissing(): void
    {
        $output = $this->createMock(IOutput::class);

        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->with('openregister_entity_relations')->willReturn(false);
        $schema->expects($this->never())->method('getTable');

        $result = $this->migration->changeSchema($output, fn () => $schema, []);
        // The migration returns the schema unchanged so the runner sees
        // it as completed; the early-return is the no-op guard.
        $this->assertSame($schema, $result);
    }//end testChangeSchemaIsNoOpWhenTableMissing()
}//end class
