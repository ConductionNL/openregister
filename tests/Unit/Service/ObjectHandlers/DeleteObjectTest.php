<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Service\ObjectHandlers\DeleteObject;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test class for DeleteObject
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class DeleteObjectTest extends TestCase
{
    private DeleteObject $deleteObject;
    private $objectEntityMapper;
    private $fileService;
    private $objectCacheService;
    private $schemaCacheService;
    private $schemaFacetCacheService;
    private $auditTrailMapper;
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->objectCacheService = $this->createMock(ObjectCacheService::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheService::class);
        $this->schemaFacetCacheService = $this->createMock(SchemaFacetCacheService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->deleteObject = new DeleteObject(
            $this->objectEntityMapper,
            $this->fileService,
            $this->objectCacheService,
            $this->schemaCacheService,
            $this->schemaFacetCacheService,
            $this->auditTrailMapper,
            $this->logger
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(DeleteObject::class, $this->deleteObject);
    }

    /**
     * Test delete method with valid UUID
     */
    public function testDeleteWithValidUuid(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('delete')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $result = $this->deleteObject->deleteObject($register, $schema, $uuid);

        $this->assertTrue($result);
    }

    /**
     * Test delete method with non-existing UUID
     */
    public function testDeleteWithNonExistingUuid(): void
    {
        $uuid = 'non-existing-uuid';
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $result = $this->deleteObject->deleteObject($register, $schema, $uuid);

        $this->assertFalse($result);
    }

    /**
     * Test delete method with soft delete
     */
    public function testDeleteWithSoftDelete(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('delete')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $result = $this->deleteObject->deleteObject($register, $schema, $uuid);

        $this->assertTrue($result);
    }

    /**
     * Test delete method with hard delete
     */
    public function testDeleteWithHardDelete(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('delete')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $result = $this->deleteObject->deleteObject($register, $schema, $uuid);

        $this->assertTrue($result);
    }

    /**
     * Test delete method with RBAC disabled
     */
    public function testDeleteWithRbacDisabled(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('delete')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $result = $this->deleteObject->deleteObject($register, $schema, $uuid, null, false);

        $this->assertTrue($result);
    }

    /**
     * Test delete method with multitenancy disabled
     */
    public function testDeleteWithMultitenancyDisabled(): void
    {
        $uuid = 'test-uuid-123';
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($uuid)
            ->willReturn($objectEntity);
            
        $this->objectEntityMapper->expects($this->once())
            ->method('delete')
            ->with($objectEntity)
            ->willReturn($objectEntity);

        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $result = $this->deleteObject->deleteObject($register, $schema, $uuid, null, true, false);

        $this->assertTrue($result);
    }
}
