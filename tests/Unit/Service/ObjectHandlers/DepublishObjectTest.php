<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Service\ObjectHandlers\DepublishObject;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for DepublishObject
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class DepublishObjectTest extends TestCase
{
    private DepublishObject $depublishObject;
    private $objectEntityMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);

        $this->depublishObject = new DepublishObject(
            $this->objectEntityMapper
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(DepublishObject::class, $this->depublishObject);
    }

    /**
     * Test depublish method with valid UUID
     */
    public function testDepublishWithValidUuid(): void
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

        $result = $this->depublishObject->depublish($uuid);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test depublish method with non-existing UUID
     */
    public function testDepublishWithNonExistingUuid(): void
    {
        $uuid = 'non-existing-uuid';
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Object not found');

        $this->depublishObject->depublish($uuid);
    }

    /**
     * Test depublish method with custom date
     */
    public function testDepublishWithCustomDate(): void
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

        $result = $this->depublishObject->depublish($uuid, $customDate);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test depublish method with RBAC disabled
     */
    public function testDepublishWithRbacDisabled(): void
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

        $result = $this->depublishObject->depublish($uuid, null, false);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test depublish method with multitenancy disabled
     */
    public function testDepublishWithMultitenancyDisabled(): void
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

        $result = $this->depublishObject->depublish($uuid, null, true, false);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }
}
