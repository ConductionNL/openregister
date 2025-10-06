<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Service\ObjectHandlers\GetObject;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for GetObject
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class GetObjectTest extends TestCase
{
    private GetObject $getObject;
    private $objectEntityMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);

        $this->getObject = new GetObject(
            $this->objectEntityMapper,
            $this->createMock(\OCA\OpenRegister\Service\FileService::class),
            $this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class)
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(GetObject::class, $this->getObject);
    }

    /**
     * Test find method with existing object
     */
    public function testFindWithExistingObject(): void
    {
        $id = 'test-id-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($objectEntity);

        $result = $this->getObject->find($id);

        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
    }

    /**
     * Test find method with non-existing object
     */
    public function testFindWithNonExistingObject(): void
    {
        $id = 'non-existing-id';
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Object not found');

        $this->getObject->find($id);
    }

    /**
     * Test findAll method
     */
    public function testFindAll(): void
    {
        $objects = [
            $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class),
            $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class)
        ];
        
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($objects);

        $result = $this->getObject->findAll();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result[0]);
    }

    /**
     * Test findAll method with empty result
     */
    public function testFindAllWithEmptyResult(): void
    {
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->getObject->findAll();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test findAll method with filters
     */
    public function testFindAllWithFilters(): void
    {
        $filters = ['register' => 123];
        $objects = [
            $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class)
        ];
        
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($objects);

        $result = $this->getObject->findAll(null, null, $filters);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Test findRelated method - basic test
     */
    public function testFindRelated(): void
    {
        // This test is skipped due to complex mocking requirements
        $this->markTestSkipped('Complex mocking required for Dot object - needs proper setup');
    }
}
