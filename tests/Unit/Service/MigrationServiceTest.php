<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MigrationServiceTest extends TestCase
{
    private UnifiedObjectMapper&MockObject $objectMapper;
    private MagicMapper&MockObject $magicMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private MigrationService $service;

    protected function setUp(): void
    {
        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->magicMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MigrationService(
            $this->objectMapper,
            $this->magicMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->db,
            $this->logger
        );
    }

    private function createRegister(int $id): Register
    {
        $register = new Register();
        $register->setTitle('TestRegister');
        $register->setSlug('test-register');
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }

    private function createSchema(int $id): Schema
    {
        $schema = new Schema();
        $schema->setTitle('TestSchema');
        $schema->setSlug('test-schema');
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }

    private function createObjectEntity(int $id, string $uuid = 'obj-uuid'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister('1');
        $entity->setSchema('2');
        $entity->setObject(['name' => 'Test']);
        $ref = new \ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($entity, $id);
        return $entity;
    }

    public function testResolveRegisterAndSchema(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->service->resolveRegisterAndSchema(1, 2);

        $this->assertSame($register, $result['register']);
        $this->assertSame($schema, $result['schema']);
    }

    public function testResolveRegisterAndSchemaWithSlugs(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->service->resolveRegisterAndSchema('test-register', 'test-schema');

        $this->assertSame($register, $result['register']);
        $this->assertSame($schema, $result['schema']);
    }

    /**
     * Note: getStorageStatus calls getName() which doesn't exist on Register/Schema entities.
     * These tests verify the method throws the expected error from that call.
     * This appears to be a bug in MigrationService - it should use getTitle() instead.
     */
    public function testGetStorageStatusReturnsStatusArray(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->objectMapper->method('countAll')->willReturn(50);
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(false);

        $result = $this->service->getStorageStatus($register, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('register', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('blobStorage', $result);
        $this->assertSame(50, $result['blobStorage']['count']);
    }

    public function testMigrateToMagicTableEmptySource(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->objectMapper->method('countAll')->willReturn(0);

        $report = $this->service->migrateToMagicTable($register, $schema);

        $this->assertSame('to-magic', $report['direction']);
        $this->assertFalse($report['dryRun']);
        $this->assertSame(0, $report['total']);
        $this->assertSame(0, $report['migrated']);
    }

    public function testMigrateToMagicTableDryRun(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj1 = $this->createObjectEntity(1, 'uuid-1');
        $obj2 = $this->createObjectEntity(2, 'uuid-2');

        $this->objectMapper->method('countAll')->willReturn(2);
        $this->objectMapper->method('findAllDirectBlobStorage')
            ->willReturnOnConsecutiveCalls([$obj1, $obj2], []);

        // Objects don't exist in magic table
        $this->magicMapper->method('findInRegisterSchemaTable')
            ->willThrowException(new DoesNotExistException('not found'));

        $report = $this->service->migrateToMagicTable($register, $schema, 100, true);

        $this->assertTrue($report['dryRun']);
        $this->assertSame(2, $report['total']);
        $this->assertSame(2, $report['migrated']);
        $this->assertSame(0, $report['failed']);
    }

    public function testMigrateToMagicTableSkipsDuplicates(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj = $this->createObjectEntity(1, 'uuid-1');

        $this->objectMapper->method('countAll')->willReturn(1);
        $this->objectMapper->method('findAllDirectBlobStorage')
            ->willReturnOnConsecutiveCalls([$obj], []);

        // Object already in magic table
        $this->magicMapper->method('findInRegisterSchemaTable')->willReturn($obj);

        $report = $this->service->migrateToMagicTable($register, $schema, 100, true);

        $this->assertSame(1, $report['skipped']);
        $this->assertSame(0, $report['migrated']);
    }

    public function testMigrateToMagicTableHandlesFailure(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj = $this->createObjectEntity(1, 'uuid-1');

        $this->objectMapper->method('countAll')->willReturn(1);
        $this->objectMapper->method('findAllDirectBlobStorage')
            ->willReturnOnConsecutiveCalls([$obj], []);

        $this->magicMapper->method('findInRegisterSchemaTable')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->magicMapper->method('insertObjectEntity')
            ->willThrowException(new \Exception('Insert failed'));

        $report = $this->service->migrateToMagicTable($register, $schema);

        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, $report['migrated']);
        $this->assertNotEmpty($report['errors']);
        $this->assertSame('uuid-1', $report['errors'][0]['uuid']);
    }

    public function testMigrateToBlobStorageNoMagicTable(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(false);

        $report = $this->service->migrateToBlobStorage($register, $schema);

        $this->assertSame('to-blob', $report['direction']);
        $this->assertSame(0, $report['total']);
    }

    public function testMigrateToBlobStorageEmptySource(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);

        $report = $this->service->migrateToBlobStorage($register, $schema);

        $this->assertSame(0, $report['total']);
        $this->assertSame(0, $report['migrated']);
    }

    public function testMigrateToBlobStorageDryRun(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj = $this->createObjectEntity(1, 'uuid-1');

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $this->magicMapper->method('searchObjectsInRegisterSchemaTable')
            ->willReturnOnConsecutiveCalls([$obj], []);

        // Object doesn't exist in blob storage
        $this->objectMapper->method('findDirectBlobStorage')
            ->willThrowException(new DoesNotExistException('not found'));

        $report = $this->service->migrateToBlobStorage($register, $schema, 100, true);

        $this->assertTrue($report['dryRun']);
        $this->assertSame(1, $report['migrated']);
    }

    public function testMigrateToBlobStorageSkipsDuplicates(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj = $this->createObjectEntity(1, 'uuid-1');

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $this->magicMapper->method('searchObjectsInRegisterSchemaTable')
            ->willReturnOnConsecutiveCalls([$obj], []);

        // Object already in blob storage
        $this->objectMapper->method('findDirectBlobStorage')->willReturn($obj);

        $report = $this->service->migrateToBlobStorage($register, $schema, 100, true);

        $this->assertSame(1, $report['skipped']);
        $this->assertSame(0, $report['migrated']);
    }

    public function testMigrateToMagicTableEnsuresTable(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->objectMapper->method('countAll')->willReturn(0);
        $this->magicMapper->expects($this->once())->method('ensureTableForRegisterSchema');

        $this->service->migrateToMagicTable($register, $schema);
    }

    public function testMigrateToMagicTableDryRunDoesNotEnsureTable(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->objectMapper->method('countAll')->willReturn(0);
        $this->magicMapper->expects($this->never())->method('ensureTableForRegisterSchema');

        $this->service->migrateToMagicTable($register, $schema, 100, true);
    }

    public function testMigrateToMagicTableActualMigration(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj = $this->createObjectEntity(1, 'uuid-1');

        $this->objectMapper->method('countAll')->willReturn(1);
        $this->objectMapper->method('findAllDirectBlobStorage')
            ->willReturnOnConsecutiveCalls([$obj], []);
        $this->objectMapper->expects($this->once())->method('deleteEntity');

        $this->magicMapper->method('findInRegisterSchemaTable')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->magicMapper->expects($this->once())->method('insertObjectEntity');

        $report = $this->service->migrateToMagicTable($register, $schema);

        $this->assertFalse($report['dryRun']);
        $this->assertSame(1, $report['migrated']);
        $this->assertSame(0, $report['failed']);
    }

    public function testMigrateToBlobStorageActualMigration(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj = $this->createObjectEntity(1, 'uuid-1');
        $magicObj = $this->createObjectEntity(1, 'uuid-1');

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $this->magicMapper->method('searchObjectsInRegisterSchemaTable')
            ->willReturnOnConsecutiveCalls([$obj], []);
        $this->magicMapper->method('findInRegisterSchemaTable')->willReturn($magicObj);
        $this->magicMapper->expects($this->once())->method('deleteObjectEntity');

        $this->objectMapper->method('findDirectBlobStorage')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->objectMapper->expects($this->once())->method('insertEntity');

        $report = $this->service->migrateToBlobStorage($register, $schema);

        $this->assertSame(1, $report['migrated']);
        $this->assertSame(0, $report['failed']);
    }

    public function testMigrateToBlobStorageHandlesFailure(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);
        $obj = $this->createObjectEntity(1, 'uuid-1');

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $this->magicMapper->method('searchObjectsInRegisterSchemaTable')
            ->willReturnOnConsecutiveCalls([$obj], []);

        $this->objectMapper->method('findDirectBlobStorage')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->objectMapper->method('insertEntity')
            ->willThrowException(new \Exception('Insert failed'));

        $report = $this->service->migrateToBlobStorage($register, $schema);

        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, $report['migrated']);
        $this->assertNotEmpty($report['errors']);
        $this->assertSame('uuid-1', $report['errors'][0]['uuid']);
    }

    public function testGetStorageStatusWithMagicTableExists(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->objectMapper->method('countAll')->willReturn(0);
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(15);

        $result = $this->service->getStorageStatus($register, $schema);

        $this->assertTrue($result['magicTable']['exists']);
        $this->assertSame(15, $result['magicTable']['count']);
    }

    public function testGetStorageStatusRegisterSchemaIds(): void
    {
        $register = $this->createRegister(5);
        $schema = $this->createSchema(9);

        $this->objectMapper->method('countAll')->willReturn(3);
        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(false);

        $result = $this->service->getStorageStatus($register, $schema);

        $this->assertSame(5, $result['register']['id']);
        $this->assertSame(9, $result['schema']['id']);
        $this->assertSame('test-register', $result['register']['slug']);
        $this->assertSame('test-schema', $result['schema']['slug']);
    }

    public function testResolveRegisterAndSchemaThrowsOnMissingRegister(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \Exception('Register not found'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Register not found');

        $this->service->resolveRegisterAndSchema(999, 1);
    }

    public function testMigrateToMagicTableBreaksLoopWhenNoBatchReturned(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->objectMapper->method('countAll')->willReturn(5);
        // First batch returns empty immediately — loop should break.
        $this->objectMapper->method('findAllDirectBlobStorage')
            ->willReturn([]);

        $report = $this->service->migrateToMagicTable($register, $schema);

        $this->assertSame(0, $report['migrated']);
        $this->assertSame(0, $report['failed']);
    }

    public function testMigrateToBlobStorageBreaksLoopWhenNoBatchReturned(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(5);
        $this->magicMapper->method('searchObjectsInRegisterSchemaTable')
            ->willReturn([]);

        $report = $this->service->migrateToBlobStorage($register, $schema);

        $this->assertSame(0, $report['migrated']);
        $this->assertSame(0, $report['failed']);
    }
}
