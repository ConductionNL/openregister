<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class RiskLevelServiceTest extends TestCase
{
    private RiskLevelService $service;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private IFilesMetadataManager&MockObject $metadataManager;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->metadataManager = $this->createMock(IFilesMetadataManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RiskLevelService(
            $this->entityRelationMapper,
            $this->metadataManager,
            $this->logger
        );
    }

    // ── computeRiskLevel ──

    public function testComputeRiskLevelReturnsNoneWhenNoEntities(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([]);
        $this->assertSame(RiskLevelService::RISK_NONE, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelReturnsLowForLocationEntity(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([
            ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_LOCATION],
        ]);
        $this->assertSame(RiskLevelService::RISK_LOW, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelReturnsMediumForPersonEntity(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([
            ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_PERSON],
        ]);
        $this->assertSame(RiskLevelService::RISK_MEDIUM, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelReturnsHighForEmailEntity(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([
            ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_EMAIL],
        ]);
        $this->assertSame(RiskLevelService::RISK_HIGH, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelReturnsVeryHighForSsnEntity(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([
            ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_SSN],
        ]);
        $this->assertSame(RiskLevelService::RISK_VERY_HIGH, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelTakesHighestRisk(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([
            ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_LOCATION],
            ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_EMAIL],
        ]);
        $this->assertSame(RiskLevelService::RISK_HIGH, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelEscalatesWhenAboveThreshold(): void
    {
        // 51 low-risk entities should escalate from low to medium.
        $entities = array_fill(0, 51, ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_LOCATION]);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn($entities);
        $this->assertSame(RiskLevelService::RISK_MEDIUM, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelDoesNotEscalateVeryHigh(): void
    {
        // 51 very_high entities stay at very_high.
        $entities = array_fill(0, 51, ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_SSN]);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn($entities);
        $this->assertSame(RiskLevelService::RISK_VERY_HIGH, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelDoesNotEscalateAtThreshold(): void
    {
        // Exactly 50 entities: no escalation.
        $entities = array_fill(0, 50, ['entity_type' => EntityRecognitionHandler::ENTITY_TYPE_LOCATION]);
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn($entities);
        $this->assertSame(RiskLevelService::RISK_LOW, $this->service->computeRiskLevel(1));
    }

    public function testComputeRiskLevelFallsBackToLowForUnknownEntityType(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([
            ['entity_type' => 'UNKNOWN_TYPE'],
        ]);
        $this->assertSame(RiskLevelService::RISK_LOW, $this->service->computeRiskLevel(1));
    }

    // ── updateRiskLevel ──

    public function testUpdateRiskLevelComputesAndPersists(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([]);

        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->expects($this->once())->method('setString');
        $this->metadataManager->method('getMetadata')->willReturn($metadata);
        $this->metadataManager->expects($this->once())->method('saveMetadata');

        $result = $this->service->updateRiskLevel(1);
        $this->assertSame(RiskLevelService::RISK_NONE, $result);
    }

    public function testUpdateRiskLevelHandlesMetadataException(): void
    {
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([]);
        $this->metadataManager->method('getMetadata')
            ->willThrowException(new \Exception('Metadata error'));

        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->updateRiskLevel(1);
        $this->assertSame(RiskLevelService::RISK_NONE, $result);
    }

    // ── getRiskLevel ──

    public function testGetRiskLevelReturnsStoredValue(): void
    {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('hasKey')->willReturn(true);
        $metadata->method('getString')->willReturn(RiskLevelService::RISK_HIGH);
        $this->metadataManager->method('getMetadata')->willReturn($metadata);

        $this->assertSame(RiskLevelService::RISK_HIGH, $this->service->getRiskLevel(1));
    }

    public function testGetRiskLevelReturnsNoneWhenNoMetadata(): void
    {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('hasKey')->willReturn(false);
        $this->metadataManager->method('getMetadata')->willReturn($metadata);

        $this->assertSame(RiskLevelService::RISK_NONE, $this->service->getRiskLevel(1));
    }

    public function testGetRiskLevelReturnsNoneOnException(): void
    {
        $this->metadataManager->method('getMetadata')
            ->willThrowException(new \Exception('error'));

        $this->assertSame(RiskLevelService::RISK_NONE, $this->service->getRiskLevel(1));
    }

    // ── initMetadataKey ──

    public function testInitMetadataKeyCallsManager(): void
    {
        $this->metadataManager->expects($this->once())
            ->method('initMetadata')
            ->with(
                RiskLevelService::METADATA_KEY,
                IMetadataValueWrapper::TYPE_STRING,
                true,
                IMetadataValueWrapper::EDIT_FORBIDDEN
            );

        $this->service->initMetadataKey();
    }

    // ── getAllRiskLevels ──

    public function testGetAllRiskLevelsReturnsExpectedLevels(): void
    {
        $levels = RiskLevelService::getAllRiskLevels();
        $this->assertCount(5, $levels);
        $this->assertArrayHasKey(RiskLevelService::RISK_NONE, $levels);
        $this->assertArrayHasKey(RiskLevelService::RISK_VERY_HIGH, $levels);
    }
}
