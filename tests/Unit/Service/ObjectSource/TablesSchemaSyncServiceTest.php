<?php

/**
 * Unit tests for TablesSchemaSyncService.
 *
 * Covers the auto-seed reconcile (design D7/D8) with the OpenRegister mappers
 * mocked and plain table descriptors as input — no Tables app required. Asserts:
 * deterministic idempotent slugs, seeding a schema per table with the
 * `x-openregister-object-source` binding + managed marker, never overwriting a
 * hand-authored schema, retiring the schema of a removed/deleted table, and the
 * relation-target lookup.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for TablesSchemaSyncService.
 */
class TablesSchemaSyncServiceTest extends TestCase
{

    /**
     * The mocked register mapper.
     *
     * @var RegisterMapper&MockObject
     */
    private $registerMapper;

    /**
     * The mocked schema mapper.
     *
     * @var SchemaMapper&MockObject
     */
    private $schemaMapper;

    /**
     * The service under test.
     *
     * @var TablesSchemaSyncService
     */
    private TablesSchemaSyncService $service;

    /**
     * Build the service before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $columnMapper         = new TablesColumnMapper(new TablesUuidDeriver(), new NullLogger());

        $this->service = new TablesSchemaSyncService(
            $this->registerMapper,
            $this->schemaMapper,
            $columnMapper,
            new NullLogger()
        );
    }//end setUp()

    /**
     * Build the `tables` register with a given schema list.
     *
     * @param array<int, mixed> $schemas The schema refs the register carries.
     *
     * @return Register The register.
     */
    private function register(array $schemas=[]): Register
    {
        $register = new Register();
        $register->setId(30);
        $register->setSlug('tables');
        $register->setSchemas($schemas);
        return $register;
    }//end register()

    /**
     * Build a schema bound to a table id, optionally managed by the sync.
     *
     * @param int  $id      The schema id.
     * @param int  $tableId The bound table id.
     * @param bool $managed Whether the sync-managed marker is set.
     *
     * @return Schema The schema.
     */
    private function schema(int $id, int $tableId, bool $managed): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug('nc-x-t'.$tableId);
        $schema->setConfiguration(
            [
                'x-openregister-object-source' => [
                    'provider' => 'tables',
                    'readOnly' => true,
                    'config'   => ['tableId' => $tableId, 'managed' => $managed],
                ],
            ]
        );
        return $schema;
    }//end schema()

    /**
     * The deterministic slug follows `nc-<slug(title)>-t<tableId>`.
     *
     * @return void
     */
    public function testDeterministicSlug(): void
    {
        $this->assertSame('nc-speeltoestellen-inspectie-t7', $this->service->deterministicSlug('Speeltoestellen inspectie', 7));
        $this->assertSame('nc-table-t9', $this->service->deterministicSlug('', 9));
    }//end testDeterministicSlug()

    /**
     * Reconcile seeds one schema per table with the object-source binding.
     *
     * @return void
     */
    public function testReconcileSeedsSchemaPerTable(): void
    {
        $this->registerMapper->method('find')->willReturn($this->register());
        $this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('none'));

        $captured = [];
        $this->schemaMapper->method('createFromArray')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured[] = $object;
                $schema = new Schema();
                $schema->setId(count($captured) + 100);
                $schema->setSlug($object['slug']);
                return $schema;
            }
        );

        $tables = [
            ['id' => 7, 'title' => 'Playground', 'columns' => [['id' => 1, 'title' => 'Name', 'technicalName' => 'name', 'type' => 'text', 'mandatory' => true]]],
        ];

        $stats = $this->service->reconcile(tables: $tables);

        $this->assertSame(1, $stats['seeded']);
        $this->assertSame('nc-playground-t7', $captured[0]['slug']);
        $binding = $captured[0]['configuration']['x-openregister-object-source'];
        $this->assertSame('tables', $binding['provider']);
        $this->assertSame(7, $binding['config']['tableId']);
        $this->assertTrue($binding['config']['managed']);
        $this->assertSame(['name'], $captured[0]['required']);
    }//end testReconcileSeedsSchemaPerTable()

    /**
     * A hand-authored schema (no managed marker) is never overwritten.
     *
     * @return void
     */
    public function testHandAuthoredSchemaNotOverwritten(): void
    {
        $this->registerMapper->method('find')->willReturn($this->register());
        $this->schemaMapper->method('find')->willReturn($this->schema(id: 200, tableId: 7, managed: false));
        $this->schemaMapper->expects($this->never())->method('updateFromArray');
        $this->schemaMapper->expects($this->never())->method('createFromArray');

        $stats = $this->service->reconcile(
            tables: [['id' => 7, 'title' => 'Playground', 'columns' => []]]
        );

        $this->assertSame(0, $stats['seeded']);
        $this->assertSame(1, $stats['skipped']);
    }//end testHandAuthoredSchemaNotOverwritten()

    /**
     * A managed schema whose table is gone is retired.
     *
     * @return void
     */
    public function testRetireMissingTable(): void
    {
        $managed  = $this->schema(id: 200, tableId: 99, managed: true);
        $register = $this->register([200]);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturnCallback(
            function ($ref) use ($managed) {
                if ((string) $ref === '200') {
                    return $managed;
                }

                throw new DoesNotExistException('none');
            }
        );
        $this->schemaMapper->expects($this->once())->method('delete')->with($managed);

        // Reconcile with an empty table set ⇒ the table-99 schema must be retired.
        $stats = $this->service->reconcile(tables: []);

        $this->assertSame(1, $stats['retired']);
    }//end testRetireMissingTable()

    /**
     * retireByTableId deletes the single bound managed schema.
     *
     * @return void
     */
    public function testRetireByTableId(): void
    {
        $managed  = $this->schema(id: 200, tableId: 42, managed: true);
        $register = $this->register([200]);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($managed);
        $this->schemaMapper->expects($this->once())->method('delete')->with($managed);

        $this->assertTrue($this->service->retireByTableId(tableId: 42));
    }//end testRetireByTableId()

    /**
     * hasManagedSchemaForTableId reflects the managed schema set.
     *
     * @return void
     */
    public function testHasManagedSchemaForTableId(): void
    {
        $managed  = $this->schema(id: 200, tableId: 12, managed: true);
        $register = $this->register([200]);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($managed);

        $this->assertTrue($this->service->hasManagedSchemaForTableId(tableId: 12));
        $this->assertFalse($this->service->hasManagedSchemaForTableId(tableId: 99));
    }//end testHasManagedSchemaForTableId()
}//end class
