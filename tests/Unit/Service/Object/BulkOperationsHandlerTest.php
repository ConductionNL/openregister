<?php

declare(strict_types=1);

/**
 * BulkOperationsHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkOperationsHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObjects;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for BulkOperationsHandler
 *
 * Tests bulk save, delete, publish, depublish, and schema/register-wide operations.
 */
class BulkOperationsHandlerTest extends TestCase
{
    /** @var BulkOperationsHandler */
    private BulkOperationsHandler $handler;

    /** @var SaveObjects&MockObject */
    private SaveObjects $saveObjectsHandler;

    /** @var UnifiedObjectMapper&MockObject */
    private UnifiedObjectMapper $objectMapper;

    /** @var PermissionHandler&MockObject */
    private PermissionHandler $permissionHandler;

    /** @var CacheHandler&MockObject */
    private CacheHandler $cacheHandler;

    /** @var MagicMapper&MockObject */
    private MagicMapper $magicMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saveObjectsHandler = $this->createMock(SaveObjects::class);
        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->permissionHandler = $this->createMock(PermissionHandler::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->magicMapper = $this->createMock(MagicMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new BulkOperationsHandler(
            $this->saveObjectsHandler,
            $this->objectMapper,
            $this->permissionHandler,
            $this->cacheHandler,
            $this->magicMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->logger
        );
    }

    // =========================================================================
    // saveObjects
    // =========================================================================

    public function testSaveObjectsDelegatesToSaveObjectsHandler(): void
    {
        $objects = [['name' => 'Test']];
        $bulkResult = [
            'statistics' => ['objectsCreated' => 1, 'objectsUpdated' => 0],
            'objects' => $objects,
        ];

        $this->saveObjectsHandler->expects($this->once())
            ->method('saveObjects')
            ->willReturn($bulkResult);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->saveObjects($objects);

        $this->assertSame($bulkResult, $result);
    }

    public function testSaveObjectsSkipsCacheInvalidationWhenNoObjectsAffected(): void
    {
        $bulkResult = [
            'statistics' => ['objectsCreated' => 0, 'objectsUpdated' => 0],
        ];

        $this->saveObjectsHandler->method('saveObjects')
            ->willReturn($bulkResult);

        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $result = $this->handler->saveObjects([]);

        $this->assertSame($bulkResult, $result);
    }

    public function testSaveObjectsCacheInvalidationFailureDoesNotBreakOperation(): void
    {
        $bulkResult = [
            'statistics' => ['objectsCreated' => 5, 'objectsUpdated' => 0],
        ];

        $this->saveObjectsHandler->method('saveObjects')
            ->willReturn($bulkResult);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new Exception('Cache error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->handler->saveObjects([['name' => 'Test']]);

        $this->assertSame($bulkResult, $result);
    }

    public function testSaveObjectsWithRegisterAndSchema(): void
    {
        $register = new Register();
        $schema = new Schema();

        $bulkResult = [
            'statistics' => ['objectsCreated' => 2, 'objectsUpdated' => 1],
        ];

        $this->saveObjectsHandler->expects($this->once())
            ->method('saveObjects')
            ->willReturn($bulkResult);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->saveObjects(
            [['name' => 'A'], ['name' => 'B']],
            $register,
            $schema
        );

        $this->assertSame($bulkResult, $result);
    }

    // =========================================================================
    // deleteObjects
    // =========================================================================

    public function testDeleteObjectsReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->handler->deleteObjects([]);

        $this->assertSame([], $result);
    }

    public function testDeleteObjectsWithRbacFiltering(): void
    {
        $uuids = ['uuid-1', 'uuid-2', 'uuid-3'];
        $filteredUuids = ['uuid-1', 'uuid-3'];

        $this->permissionHandler->expects($this->once())
            ->method('filterUuidsForPermissions')
            ->willReturn($filteredUuids);

        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->willReturn([1, 3]);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->deleteObjects($uuids);

        $this->assertSame([1, 3], $result);
    }

    public function testDeleteObjectsWithoutRbacOrMultitenancy(): void
    {
        $uuids = ['uuid-1'];

        $this->permissionHandler->expects($this->never())
            ->method('filterUuidsForPermissions');

        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->willReturn([1]);

        $result = $this->handler->deleteObjects($uuids, false, false);

        $this->assertSame([1], $result);
    }

    public function testDeleteObjectsSkipsCacheWhenNothingDeleted(): void
    {
        $this->permissionHandler->method('filterUuidsForPermissions')
            ->willReturn([]);

        $this->objectMapper->method('deleteObjects')
            ->willReturn([]);

        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $result = $this->handler->deleteObjects(['uuid-1']);

        $this->assertSame([], $result);
    }

    public function testDeleteObjectsCacheFailureDoesNotBreak(): void
    {
        $this->permissionHandler->method('filterUuidsForPermissions')
            ->willReturn(['uuid-1']);

        $this->objectMapper->method('deleteObjects')
            ->willReturn([1]);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new Exception('Cache fail'));

        $result = $this->handler->deleteObjects(['uuid-1']);

        $this->assertSame([1], $result);
    }

    // =========================================================================
    // publishObjects
    // =========================================================================

    public function testPublishObjectsReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->handler->publishObjects([]);

        $this->assertSame([], $result);
    }

    public function testPublishObjectsWithPermissionFiltering(): void
    {
        $uuids = ['uuid-1', 'uuid-2'];

        $this->permissionHandler->expects($this->once())
            ->method('filterUuidsForPermissions')
            ->willReturn(['uuid-1']);

        $this->objectMapper->expects($this->once())
            ->method('publishObjects')
            ->willReturn(['uuid-1']);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->publishObjects($uuids);

        $this->assertSame(['uuid-1'], $result);
    }

    public function testPublishObjectsWithDatetime(): void
    {
        $datetime = new DateTime('2024-01-01');

        $this->permissionHandler->method('filterUuidsForPermissions')
            ->willReturn(['uuid-1']);

        $this->objectMapper->expects($this->once())
            ->method('publishObjects')
            ->willReturn(['uuid-1']);

        $result = $this->handler->publishObjects(['uuid-1'], $datetime);

        $this->assertSame(['uuid-1'], $result);
    }

    public function testPublishObjectsSkipsCacheWhenNonePublished(): void
    {
        $this->permissionHandler->method('filterUuidsForPermissions')
            ->willReturn([]);

        $this->objectMapper->method('publishObjects')
            ->willReturn([]);

        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $result = $this->handler->publishObjects(['uuid-1']);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // depublishObjects
    // =========================================================================

    public function testDepublishObjectsReturnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->handler->depublishObjects([]);

        $this->assertSame([], $result);
    }

    public function testDepublishObjectsWithFiltering(): void
    {
        $this->permissionHandler->method('filterUuidsForPermissions')
            ->willReturn(['uuid-1']);

        $this->objectMapper->expects($this->once())
            ->method('depublishObjects')
            ->willReturn(['uuid-1']);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->depublishObjects(['uuid-1', 'uuid-2']);

        $this->assertSame(['uuid-1'], $result);
    }

    public function testDepublishObjectsCacheFailureDoesNotBreak(): void
    {
        $this->permissionHandler->method('filterUuidsForPermissions')
            ->willReturn(['uuid-1']);

        $this->objectMapper->method('depublishObjects')
            ->willReturn(['uuid-1']);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new Exception('Cache fail'));

        $result = $this->handler->depublishObjects(['uuid-1']);

        $this->assertSame(['uuid-1'], $result);
    }

    // =========================================================================
    // publishObjectsBySchema
    // =========================================================================

    public function testPublishObjectsBySchemaWithResults(): void
    {
        $result = [
            'published_count' => 3,
            'published_uuids' => ['uuid-1', 'uuid-2', 'uuid-3'],
            'schema_id' => 42,
        ];

        $this->objectMapper->expects($this->once())
            ->method('publishObjectsBySchema')
            ->willReturn($result);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $actual = $this->handler->publishObjectsBySchema(42);

        $this->assertSame($result, $actual);
    }

    public function testPublishObjectsBySchemaSkipsCacheWhenNonePublished(): void
    {
        $result = [
            'published_count' => 0,
            'published_uuids' => [],
            'schema_id' => 42,
        ];

        $this->objectMapper->method('publishObjectsBySchema')
            ->willReturn($result);

        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $actual = $this->handler->publishObjectsBySchema(42);

        $this->assertSame($result, $actual);
    }

    // =========================================================================
    // deleteObjectsBySchema
    // =========================================================================

    public function testDeleteObjectsBySchemaUsesBlobStorageWhenNoMagicMapping(): void
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 10);
        $schema->setSlug('test-schema');

        $register = new Register();
        $refReg = new ReflectionClass($register);
        $idPropReg = $refReg->getProperty('id');
        $idPropReg->setAccessible(true);
        $idPropReg->setValue($register, 1);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectMapper->expects($this->once())
            ->method('deleteObjectsBySchema')
            ->willReturn(['deleted_count' => 5, 'deleted_uuids' => ['a', 'b', 'c', 'd', 'e']]);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->deleteObjectsBySchema(1, 10);

        $this->assertSame(5, $result['deleted_count']);
        $this->assertCount(5, $result['deleted_uuids']);
        $this->assertSame(10, $result['schema_id']);
    }

    public function testDeleteObjectsBySchemaThrowsOnFailure(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Schema not found'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Schema not found');

        $this->handler->deleteObjectsBySchema(1, 999);
    }

    // =========================================================================
    // deleteObjectsByRegister
    // =========================================================================

    public function testDeleteObjectsByRegisterWithResults(): void
    {
        $result = [
            'deleted_count' => 10,
            'deleted_uuids' => ['a', 'b'],
            'register_id' => 5,
        ];

        $this->objectMapper->expects($this->once())
            ->method('deleteObjectsByRegister')
            ->willReturn($result);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $actual = $this->handler->deleteObjectsByRegister(5);

        $this->assertSame($result, $actual);
    }

    public function testDeleteObjectsByRegisterSkipsCacheWhenNoneDeleted(): void
    {
        $result = [
            'deleted_count' => 0,
            'deleted_uuids' => [],
            'register_id' => 5,
        ];

        $this->objectMapper->method('deleteObjectsByRegister')
            ->willReturn($result);

        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $actual = $this->handler->deleteObjectsByRegister(5);

        $this->assertSame($result, $actual);
    }

    public function testDeleteObjectsByRegisterCacheFailureDoesNotBreak(): void
    {
        $result = [
            'deleted_count' => 3,
            'deleted_uuids' => ['a', 'b', 'c'],
            'register_id' => 5,
        ];

        $this->objectMapper->method('deleteObjectsByRegister')
            ->willReturn($result);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new Exception('Cache fail'));

        $actual = $this->handler->deleteObjectsByRegister(5);

        $this->assertSame($result, $actual);
    }

    // =========================================================================
    // deleteObjectsBySchema — magic table path
    // =========================================================================

    public function testDeleteObjectsBySchemaUsesMagicTableWhenEnabled(): void
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 20);
        $schema->setSlug('magic-schema');

        $register = new Register();
        $refReg = new ReflectionClass($register);
        $idPropReg = $refReg->getProperty('id');
        $idPropReg->setAccessible(true);
        $idPropReg->setValue($register, 2);
        // Enable magic mapping via configuration: new format {"schemas": {"<slug>": {"magicMapping": true}}}.
        $register->setConfiguration(['schemas' => ['magic-schema' => ['magicMapping' => true]]]);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->registerMapper->method('find')->willReturn($register);

        $this->magicMapper->expects($this->once())
            ->method('deleteObjectsBySchema')
            ->willReturn(7);

        $this->objectMapper->expects($this->never())
            ->method('deleteObjectsBySchema');

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->deleteObjectsBySchema(2, 20);

        $this->assertSame(7, $result['deleted_count']);
        $this->assertSame([], $result['deleted_uuids']);
        $this->assertSame(20, $result['schema_id']);
    }

    public function testDeleteObjectsBySchemaSkipsCacheWhenNoneDeleted(): void
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 10);
        $schema->setSlug('test-schema');

        $register = new Register();
        $refReg = new ReflectionClass($register);
        $idPropReg = $refReg->getProperty('id');
        $idPropReg->setAccessible(true);
        $idPropReg->setValue($register, 1);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectMapper->method('deleteObjectsBySchema')
            ->willReturn(['deleted_count' => 0, 'deleted_uuids' => []]);

        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $result = $this->handler->deleteObjectsBySchema(1, 10);

        $this->assertSame(0, $result['deleted_count']);
    }

    public function testDeleteObjectsBySchemaWithHardDelete(): void
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 10);
        $schema->setSlug('test-schema');

        $register = new Register();
        $refReg = new ReflectionClass($register);
        $idPropReg = $refReg->getProperty('id');
        $idPropReg->setAccessible(true);
        $idPropReg->setValue($register, 1);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectMapper->expects($this->once())
            ->method('deleteObjectsBySchema')
            ->with($this->equalTo(10), $this->equalTo(true))
            ->willReturn(['deleted_count' => 3, 'deleted_uuids' => ['x', 'y', 'z']]);

        $result = $this->handler->deleteObjectsBySchema(1, 10, true);

        $this->assertSame(3, $result['deleted_count']);
    }

    // =========================================================================
    // publishObjectsBySchema — publishAll flag
    // =========================================================================

    public function testPublishObjectsBySchemaWithPublishAll(): void
    {
        $result = [
            'published_count' => 10,
            'published_uuids' => array_fill(0, 10, 'uuid'),
            'schema_id' => 5,
        ];

        $this->objectMapper->expects($this->once())
            ->method('publishObjectsBySchema')
            ->with($this->equalTo(5), $this->equalTo(true))
            ->willReturn($result);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $actual = $this->handler->publishObjectsBySchema(5, true);

        $this->assertSame(10, $actual['published_count']);
    }

    public function testPublishObjectsBySchemaPublishCacheFailureDoesNotBreak(): void
    {
        $result = [
            'published_count' => 2,
            'published_uuids' => ['a', 'b'],
            'schema_id' => 7,
        ];

        $this->objectMapper->method('publishObjectsBySchema')
            ->willReturn($result);

        $this->cacheHandler->method('invalidateForObjectChange')
            ->willThrowException(new Exception('Cache fail'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $actual = $this->handler->publishObjectsBySchema(7);

        $this->assertSame($result, $actual);
    }

    // =========================================================================
    // depublishObjects — without rbac/multitenancy
    // =========================================================================

    public function testDepublishObjectsWithoutRbacOrMultitenancy(): void
    {
        $this->permissionHandler->expects($this->never())
            ->method('filterUuidsForPermissions');

        $this->objectMapper->expects($this->once())
            ->method('depublishObjects')
            ->willReturn(['uuid-1']);

        $result = $this->handler->depublishObjects(['uuid-1'], true, false, false);

        $this->assertSame(['uuid-1'], $result);
    }

    public function testDepublishObjectsSkipsCacheWhenNoneDepublished(): void
    {
        $this->permissionHandler->method('filterUuidsForPermissions')
            ->willReturn([]);

        $this->objectMapper->method('depublishObjects')
            ->willReturn([]);

        $this->cacheHandler->expects($this->never())
            ->method('invalidateForObjectChange');

        $result = $this->handler->depublishObjects(['uuid-1']);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // saveObjects — stats with only updated objects
    // =========================================================================

    public function testSaveObjectsCacheInvalidationCountsUpdates(): void
    {
        $bulkResult = [
            'statistics' => ['objectsCreated' => 0, 'objectsUpdated' => 3],
        ];

        $this->saveObjectsHandler->method('saveObjects')
            ->willReturn($bulkResult);

        $this->cacheHandler->expects($this->once())
            ->method('invalidateForObjectChange');

        $result = $this->handler->saveObjects([['name' => 'A'], ['name' => 'B'], ['name' => 'C']]);

        $this->assertSame($bulkResult, $result);
    }
}
