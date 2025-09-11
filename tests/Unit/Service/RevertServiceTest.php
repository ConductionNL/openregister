<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\RevertService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use PHPUnit\Framework\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\Exception\LockedException;
use Psr\Container\ContainerInterface;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Test class for RevertService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class RevertServiceTest extends TestCase
{
    private RevertService $revertService;
    private AuditTrailMapper $auditTrailMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private ContainerInterface $container;
    private IEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        // Create RevertService instance
        $this->revertService = new RevertService(
            $this->auditTrailMapper,
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->container,
            $this->eventDispatcher
        );
    }

    /**
     * Test revert method with valid data
     */
    public function testRevertWithValidData(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectId = 'test-object-id';
        $until = 123; // audit trail ID

        // Create mock object
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getRegister', 'getSchema', 'setRegister', 'setSchema', 'getLockedBy'])
            ->onlyMethods(['__toString', 'isLocked'])
            ->getMock();
        $object->method('__toString')->willReturn($objectId);
        $object->method('getRegister')->willReturn($register);
        $object->method('getSchema')->willReturn($schema);
        $object->method('setRegister')->willReturn($object);
        $object->method('setSchema')->willReturn($object);
        $object->method('isLocked')->willReturn(false);

        // Create mock reverted object
        $revertedObject = $this->createMock(ObjectEntity::class);
        $revertedObject->method('__toString')->willReturn($objectId);

        // Create mock saved object
        $savedObject = $this->createMock(ObjectEntity::class);
        $savedObject->method('__toString')->willReturn($objectId);

        // Mock mappers
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($objectId)
            ->willReturn($object);

        $this->auditTrailMapper->expects($this->once())
            ->method('revertObject')
            ->with($objectId, $until, false)
            ->willReturn($revertedObject);

        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->with($revertedObject)
            ->willReturn($savedObject);

        // Mock container (not called when object is not locked)
        $this->container->expects($this->never())
            ->method('get');

        // Mock event dispatcher
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ObjectRevertedEvent::class));

        $result = $this->revertService->revert($register, $schema, $objectId, $until);

        $this->assertEquals($savedObject, $result);
    }

    /**
     * Test revert method with wrong register/schema
     */
    public function testRevertWithWrongRegisterSchema(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectId = 'test-object-id';
        $until = 123;

        // Create mock object with different register/schema
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getRegister', 'getSchema', 'setRegister', 'setSchema', 'getLockedBy'])
            ->onlyMethods(['__toString', 'isLocked'])
            ->getMock();
        $object->method('__toString')->willReturn($objectId);
        $object->method('getRegister')->willReturn('different-register');
        $object->method('getSchema')->willReturn('different-schema');

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($objectId)
            ->willReturn($object);

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessage('Object not found in specified register/schema');

        $this->revertService->revert($register, $schema, $objectId, $until);
    }

    /**
     * Test revert method with locked object
     */
    public function testRevertWithLockedObject(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectId = 'test-object-id';
        $until = 123;

        // Create mock object
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getRegister', 'getSchema', 'setRegister', 'setSchema', 'getLockedBy'])
            ->onlyMethods(['__toString', 'isLocked'])
            ->getMock();
        $object->method('__toString')->willReturn($objectId);
        $object->method('getRegister')->willReturn($register);
        $object->method('getSchema')->willReturn($schema);
        $object->method('setRegister')->willReturn($object);
        $object->method('setSchema')->willReturn($object);
        $object->method('isLocked')->willReturn(true);
        $object->method('getLockedBy')->willReturn('other-user');

        // Mock mappers
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($objectId)
            ->willReturn($object);

        // Mock container
        $this->container->expects($this->once())
            ->method('get')
            ->with('userId')
            ->willReturn('test-user');

        $this->expectException(LockedException::class);
        $this->expectExceptionMessage('Object is locked by other-user');

        $this->revertService->revert($register, $schema, $objectId, $until);
    }

    /**
     * Test revert method with locked object by same user
     */
    public function testRevertWithLockedObjectBySameUser(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectId = 'test-object-id';
        $until = 123;

        // Create mock object
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getRegister', 'getSchema', 'setRegister', 'setSchema', 'getLockedBy'])
            ->onlyMethods(['__toString', 'isLocked'])
            ->getMock();
        $object->method('__toString')->willReturn($objectId);
        $object->method('getRegister')->willReturn($register);
        $object->method('getSchema')->willReturn($schema);
        $object->method('setRegister')->willReturn($object);
        $object->method('setSchema')->willReturn($object);
        $object->method('isLocked')->willReturn(true);
        $object->method('getLockedBy')->willReturn('test-user');

        // Create mock reverted object
        $revertedObject = $this->createMock(ObjectEntity::class);
        $revertedObject->method('__toString')->willReturn($objectId);

        // Create mock saved object
        $savedObject = $this->createMock(ObjectEntity::class);
        $savedObject->method('__toString')->willReturn($objectId);

        // Mock mappers
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($objectId)
            ->willReturn($object);

        $this->auditTrailMapper->expects($this->once())
            ->method('revertObject')
            ->with($objectId, $until, false)
            ->willReturn($revertedObject);

        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->with($revertedObject)
            ->willReturn($savedObject);

        // Mock container
        $this->container->expects($this->once())
            ->method('get')
            ->with('userId')
            ->willReturn('test-user');

        // Mock event dispatcher
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ObjectRevertedEvent::class));

        $result = $this->revertService->revert($register, $schema, $objectId, $until);

        $this->assertEquals($savedObject, $result);
    }

    /**
     * Test revert method with overwrite version
     */
    public function testRevertWithOverwriteVersion(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectId = 'test-object-id';
        $until = 123;
        $overwriteVersion = true;

        // Create mock object
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getRegister', 'getSchema', 'setRegister', 'setSchema', 'getLockedBy'])
            ->onlyMethods(['__toString', 'isLocked'])
            ->getMock();
        $object->method('__toString')->willReturn($objectId);
        $object->method('getRegister')->willReturn($register);
        $object->method('getSchema')->willReturn($schema);
        $object->method('setRegister')->willReturn($object);
        $object->method('setSchema')->willReturn($object);
        $object->method('isLocked')->willReturn(false);

        // Create mock reverted object
        $revertedObject = $this->createMock(ObjectEntity::class);
        $revertedObject->method('__toString')->willReturn($objectId);

        // Create mock saved object
        $savedObject = $this->createMock(ObjectEntity::class);
        $savedObject->method('__toString')->willReturn($objectId);

        // Mock mappers
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($objectId)
            ->willReturn($object);

        $this->auditTrailMapper->expects($this->once())
            ->method('revertObject')
            ->with($objectId, $until, $overwriteVersion)
            ->willReturn($revertedObject);

        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->with($revertedObject)
            ->willReturn($savedObject);

        // Mock container (not called when object is not locked)
        $this->container->expects($this->never())
            ->method('get');

        // Mock event dispatcher
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ObjectRevertedEvent::class));

        $result = $this->revertService->revert($register, $schema, $objectId, $until, $overwriteVersion);

        $this->assertEquals($savedObject, $result);
    }
}