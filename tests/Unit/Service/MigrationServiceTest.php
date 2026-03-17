<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\MigrationService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MigrationServiceTest extends TestCase
{
    private MagicMapper&MockObject $magicMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private MigrationService $service;

    protected function setUp(): void
    {
        $this->magicMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MigrationService(
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

    public function testResolveRegisterAndSchemaThrowsOnMissingRegister(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \Exception('Register not found'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Register not found');

        $this->service->resolveRegisterAndSchema(999, 1);
    }

    public function testGetStorageStatusWithMagicTableExists(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(true);
        $this->magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(15);

        $result = $this->service->getStorageStatus($register, $schema);

        $this->assertArrayHasKey('register', $result);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('magicTable', $result);
        $this->assertTrue($result['magicTable']['exists']);
        $this->assertSame(15, $result['magicTable']['count']);
    }

    public function testGetStorageStatusWithNoMagicTable(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(false);

        $result = $this->service->getStorageStatus($register, $schema);

        $this->assertFalse($result['magicTable']['exists']);
        $this->assertSame(0, $result['magicTable']['count']);
    }

    public function testGetStorageStatusRegisterSchemaIds(): void
    {
        $register = $this->createRegister(5);
        $schema = $this->createSchema(9);

        $this->magicMapper->method('existsTableForRegisterSchema')->willReturn(false);

        $result = $this->service->getStorageStatus($register, $schema);

        $this->assertSame(5, $result['register']['id']);
        $this->assertSame(9, $result['schema']['id']);
        $this->assertSame('test-register', $result['register']['slug']);
        $this->assertSame('test-schema', $result['schema']['slug']);
    }

    public function testMigrateToMagicTableReturnsStubReport(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $report = $this->service->migrateToMagicTable($register, $schema);

        $this->assertSame('to-magic', $report['direction']);
        $this->assertFalse($report['dryRun']);
        $this->assertSame(0, $report['total']);
        $this->assertSame(0, $report['migrated']);
        $this->assertArrayHasKey('message', $report);
        $this->assertStringContainsString('BlobMigrationJob', $report['message']);
    }

    public function testMigrateToMagicTableDryRunReturnsStubReport(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $report = $this->service->migrateToMagicTable($register, $schema, 100, true);

        $this->assertTrue($report['dryRun']);
        $this->assertSame(0, $report['total']);
    }

    public function testMigrateToBlobStorageReturnsStubReport(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $report = $this->service->migrateToBlobStorage($register, $schema);

        $this->assertSame('to-blob', $report['direction']);
        $this->assertFalse($report['dryRun']);
        $this->assertSame(0, $report['total']);
        $this->assertSame(0, $report['migrated']);
        $this->assertArrayHasKey('message', $report);
        $this->assertStringContainsString('no longer supported', $report['message']);
    }

    public function testMigrateToBlobStorageDryRunReturnsStubReport(): void
    {
        $register = $this->createRegister(1);
        $schema = $this->createSchema(2);

        $report = $this->service->migrateToBlobStorage($register, $schema, 100, true);

        $this->assertTrue($report['dryRun']);
        $this->assertSame(0, $report['total']);
    }
}
