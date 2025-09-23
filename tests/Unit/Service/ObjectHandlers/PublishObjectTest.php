<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Service\ObjectHandlers\PublishObject;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for PublishObject
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class PublishObjectTest extends TestCase
{
    private PublishObject $publishObject;
    private $objectEntityMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);

        $this->publishObject = new PublishObject(
            $this->objectEntityMapper
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(PublishObject::class, $this->publishObject);
    }

    /**
     * Test publish method with valid UUID
     */
    public function testPublishWithValidUuid(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $result = $this->publishObject->publish($uuid);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test publish method with non-existing UUID
     */
    public function testPublishWithNonExistingUuid(): void
    {
        $uuid = 'non-existing-uuid';
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Object not found');

        $this->publishObject->publish($uuid);
    }

    /**
     * Test publish method with custom date
     */
    public function testPublishWithCustomDate(): void
    {
        $uuid = 'test-uuid-123';
        $customDate = new \DateTime('2024-01-01 12:00:00');
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $result = $this->publishObject->publish($uuid, $customDate);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test publish method with RBAC disabled
     */
    public function testPublishWithRbacDisabled(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $result = $this->publishObject->publish($uuid, null, false);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test publish method with multitenancy disabled
     */
    public function testPublishWithMultitenancyDisabled(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $result = $this->publishObject->publish($uuid, null, true, false);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }
}
