<?php

/**
 * FileSidebarService Test
 *
 * Unit tests for the FileSidebarService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileSidebarService;
use OCA\OpenRegister\Service\RiskLevelService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for FileSidebarService.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service
 */
class FileSidebarServiceTest extends TestCase
{
    private FileSidebarService $service;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IDBConnection&MockObject $db;
    private ChunkMapper&MockObject $chunkMapper;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private GdprEntityMapper&MockObject $gdprEntityMapper;
    private RiskLevelService&MockObject $riskLevelService;
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->registerMapper       = $this->createMock(RegisterMapper::class);
        $this->schemaMapper         = $this->createMock(SchemaMapper::class);
        $this->db                   = $this->createMock(IDBConnection::class);
        $this->chunkMapper          = $this->createMock(ChunkMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->gdprEntityMapper     = $this->createMock(GdprEntityMapper::class);
        $this->riskLevelService     = $this->createMock(RiskLevelService::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->service = new FileSidebarService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->db,
            $this->chunkMapper,
            $this->entityRelationMapper,
            $this->gdprEntityMapper,
            $this->riskLevelService,
            $this->logger
        );
    }//end setUp()

    /**
     * Test getObjectsForFile returns empty array when no registers exist.
     *
     * @return void
     */
    public function testGetObjectsForFileReturnsEmptyWhenNoRegisters(): void
    {
        $this->registerMapper->method('findAll')->willReturn([]);

        $result = $this->service->getObjectsForFile(42);

        $this->assertSame([], $result);
    }//end testGetObjectsForFileReturnsEmptyWhenNoRegisters()

    /**
     * Test getObjectsForFile returns empty when register fetch throws.
     *
     * @return void
     */
    public function testGetObjectsForFileReturnsEmptyOnRegisterException(): void
    {
        $this->registerMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->service->getObjectsForFile(42);

        $this->assertSame([], $result);
    }//end testGetObjectsForFileReturnsEmptyOnRegisterException()

    /**
     * Test getObjectsForFile skips registers with no schemas.
     *
     * @return void
     */
    public function testGetObjectsForFileSkipsRegistersWithNoSchemas(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getSchemas')->willReturn([]);

        $this->registerMapper->method('findAll')->willReturn([$register]);

        $result = $this->service->getObjectsForFile(42);

        $this->assertSame([], $result);
    }//end testGetObjectsForFileSkipsRegistersWithNoSchemas()

    /**
     * Test getExtractionStatus returns 'none' when no chunks exist.
     *
     * @return void
     */
    public function testGetExtractionStatusReturnsNoneWhenNoChunks(): void
    {
        $this->chunkMapper->method('findBySource')->willReturn([]);

        $result = $this->service->getExtractionStatus(99);

        $this->assertSame(99, $result['fileId']);
        $this->assertSame('none', $result['extractionStatus']);
        $this->assertSame(0, $result['chunkCount']);
        $this->assertSame(0, $result['entityCount']);
        $this->assertNull($result['extractedAt']);
        $this->assertSame([], $result['entities']);
        $this->assertFalse($result['anonymized']);
    }//end testGetExtractionStatusReturnsNoneWhenNoChunks()

    /**
     * Test getExtractionStatus returns completed with entities aggregated by type.
     *
     * @return void
     */
    public function testGetExtractionStatusReturnsCompletedWithEntities(): void
    {
        // Two chunks exist for this file.
        $this->chunkMapper->method('findBySource')->willReturn(['chunk1', 'chunk2']);
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(1700000000);

        // Two entity relations — one PERSON, one EMAIL.
        $relation1 = new EntityRelation();
        $relation1->setAnonymized(false);
        $relation1->setEntityId(10);

        $relation2 = new EntityRelation();
        $relation2->setAnonymized(false);
        $relation2->setEntityId(20);

        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation1, $relation2]);

        $entity1 = new GdprEntity();
        $entity1->setType('PERSON');

        $entity2 = new GdprEntity();
        $entity2->setType('EMAIL');

        $this->gdprEntityMapper->method('find')
            ->willReturnMap([
                [10, $entity1],
                [20, $entity2],
            ]);

        $this->riskLevelService->method('getRiskLevel')->willReturn('high');

        $result = $this->service->getExtractionStatus(55);

        $this->assertSame(55, $result['fileId']);
        $this->assertSame('completed', $result['extractionStatus']);
        $this->assertSame(2, $result['chunkCount']);
        $this->assertSame(2, $result['entityCount']);
        $this->assertSame('high', $result['riskLevel']);
        $this->assertNotNull($result['extractedAt']);
        $this->assertFalse($result['anonymized']);

        // Entities should contain PERSON and EMAIL each with count 1.
        $types = array_column($result['entities'], 'type');
        $this->assertContains('PERSON', $types);
        $this->assertContains('EMAIL', $types);
    }//end testGetExtractionStatusReturnsCompletedWithEntities()

    /**
     * Test getExtractionStatus sets anonymized true when any relation is anonymized.
     *
     * @return void
     */
    public function testGetExtractionStatusDetectsAnonymization(): void
    {
        $this->chunkMapper->method('findBySource')->willReturn(['chunk1']);
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);

        $relation = new EntityRelation();
        $relation->setAnonymized(true);
        $relation->setEntityId(30);

        $this->entityRelationMapper->method('findByFileId')->willReturn([$relation]);

        $entity = new GdprEntity();
        $entity->setType('SSN');

        $this->gdprEntityMapper->method('find')->willReturn($entity);
        $this->riskLevelService->method('getRiskLevel')->willReturn('very_high');

        $result = $this->service->getExtractionStatus(77);

        $this->assertTrue($result['anonymized']);
        $this->assertNull($result['extractedAt']);
        $this->assertSame('completed', $result['extractionStatus']);
    }//end testGetExtractionStatusDetectsAnonymization()
}//end class
