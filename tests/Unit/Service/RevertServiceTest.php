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
        $object = $this->createMock(ObjectEntity::class);
        $object->id = $objectId;
        $object->register = $register;
        $object->schema = $schema;
        $object->method('isLocked')->willReturn(false);

        // Create mock reverted object
        $revertedObject = $this->createMock(ObjectEntity::class);
        $revertedObject->id = $objectId;

        // Create mock saved object
        $savedObject = $this->createMock(ObjectEntity::class);
        $savedObject->id = $objectId;

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

        // Mock event dispatcher
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ObjectRevertedEvent::class));

        $result = $this->revertService->revert($register, $schema, $objectId, $until);

        $this->assertEquals($savedObject, $result);
    }

    /**
     * Test revert method with non-existent object
     */
    public function testRevertWithNonExistentObject(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectId = 'non-existent-id';
        $until = 123;

        // Mock object entity mapper to throw exception
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($objectId)
            ->willThrowException(new DoesNotExistException('Object not found'));

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessage('Object not found');

        $this->revertService->revert($register, $schema, $objectId, $until);
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
        $object = $this->createMock(ObjectEntity::class);
        $object->id = $objectId;
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
        $userId = 'test-user';

        // Create mock object that is locked
        $object = $this->createMock(ObjectEntity::class);
        $object->id = $objectId;
        $object->register = $register;
        $object->schema = $schema;
        $object->method('isLocked')->willReturn(true);
        $object->method('getLockedBy')->willReturn('different-user');

        // Mock container to return user ID
        $this->container->expects($this->once())
            ->method('get')
            ->with('userId')
            ->willReturn($userId);

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($objectId)
            ->willReturn($object);

        $this->expectException(LockedException::class);
        $this->expectExceptionMessage('Object is locked by different-user');

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
        $userId = 'test-user';

        // Create mock object that is locked by same user
        $object = $this->createMock(ObjectEntity::class);
        $object->id = $objectId;
        $object->register = $register;
        $object->schema = $schema;
        $object->method('isLocked')->willReturn(true);
        $object->method('getLockedBy')->willReturn($userId);

        // Create mock reverted object
        $revertedObject = $this->createMock(ObjectEntity::class);
        $revertedObject->id = $objectId;

        // Create mock saved object
        $savedObject = $this->createMock(ObjectEntity::class);
        $savedObject->id = $objectId;

        // Mock container to return user ID
        $this->container->expects($this->once())
            ->method('get')
            ->with('userId')
            ->willReturn($userId);

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

        // Mock event dispatcher
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ObjectRevertedEvent::class));

        $result = $this->revertService->revert($register, $schema, $objectId, $until);

        $this->assertEquals($savedObject, $result);
    }

    /**
     * Test revert method with overwriteVersion flag
     */
    public function testRevertWithOverwriteVersion(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $objectId = 'test-object-id';
        $until = 123;
        $overwriteVersion = true;

        // Create mock object
        $object = $this->createMock(ObjectEntity::class);
        $object->id = $objectId;
        $object->register = $register;
        $object->schema = $schema;
        $object->method('isLocked')->willReturn(false);

        // Create mock reverted object
        $revertedObject = $this->createMock(ObjectEntity::class);
        $revertedObject->id = $objectId;

        // Create mock saved object
        $savedObject = $this->createMock(ObjectEntity::class);
        $savedObject->id = $objectId;

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

        // Mock event dispatcher
        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(ObjectRevertedEvent::class));

        $result = $this->revertService->revert($register, $schema, $objectId, $until, $overwriteVersion);

        $this->assertEquals($savedObject, $result);
    }
}