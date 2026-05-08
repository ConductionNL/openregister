<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch coverage tests for EntityRecognitionHandler — targets remaining
 * uncovered branches in detection methods, category mapping, entity storage.
 */
class EntityRecognitionHandlerBranchTest extends TestCase
{
    private EntityRecognitionHandler $handler;
    private ChunkMapper&MockObject $chunkMapper;
    private GdprEntityMapper&MockObject $entityMapper;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private SettingsService&MockObject $settingsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chunkMapper = $this->createMock(ChunkMapper::class);
        $this->entityMapper = $this->createMock(GdprEntityMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        $this->handler = new EntityRecognitionHandler(
            $this->chunkMapper,
            $this->entityMapper,
            $this->entityRelationMapper,
            $this->db,
            $this->logger,
            $this->settingsService
        );
    }

    /**
     * Create Chunk mock with addMethods for magic getters.
     */
    private function createChunkMock(
        int $id,
        string $sourceType,
        int $sourceId,
        string $textContent,
        int $chunkIndex = 0
    ): Chunk&MockObject {
        $mock = $this->getMockBuilder(Chunk::class)
            ->addMethods(['getId', 'getSourceType', 'getSourceId', 'getTextContent', 'getChunkIndex'])
            ->getMock();
        $mock->method('getId')->willReturn($id);
        $mock->method('getSourceType')->willReturn($sourceType);
        $mock->method('getSourceId')->willReturn($sourceId);
        $mock->method('getTextContent')->willReturn($textContent);
        $mock->method('getChunkIndex')->willReturn($chunkIndex);
        return $mock;
    }

    /**
     * Create Chunk mock where getTextContent throws.
     */
    private function createChunkMockThrowsOnText(int $id): Chunk&MockObject
    {
        $mock = $this->getMockBuilder(Chunk::class)
            ->addMethods(['getId', 'getSourceType', 'getSourceId', 'getTextContent', 'getChunkIndex'])
            ->getMock();
        $mock->method('getId')->willReturn($id);
        $mock->method('getSourceType')->willReturn('file');
        $mock->method('getSourceId')->willReturn(1);
        $mock->method('getTextContent')->willThrowException(new \Exception('Chunk error'));
        $mock->method('getChunkIndex')->willReturn(0);
        return $mock;
    }

    /**
     * Create a real GdprEntity with id set (avoids mock issues with Entity::getId).
     */
    private function createGdprEntity(int $id): GdprEntity
    {
        $entity = new GdprEntity();
        $entity->setId($id);
        return $entity;
    }

    public function testExtractFromChunkWithEmptyText(): void
    {
        $chunk = $this->createChunkMock(1, 'file', 42, '');
        $result = $this->handler->extractFromChunk($chunk);
        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
        $this->assertSame([], $result['entities']);
    }

    public function testExtractFromChunkWithWhitespace(): void
    {
        $chunk = $this->createChunkMock(1, 'file', 42, '   ');
        $result = $this->handler->extractFromChunk($chunk);
        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkLLMFallsBackToRegex(): void
    {
        $chunk = $this->createChunkMock(1, 'object', 99, 'No entities here at all.');
        $result = $this->handler->extractFromChunk($chunk, ['method' => 'llm']);
        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkHybridMethod(): void
    {
        $chunk = $this->createChunkMock(1, 'file', 1, 'No PII data here.');
        $result = $this->handler->extractFromChunk($chunk, ['method' => 'hybrid']);
        $this->assertSame(0, $result['entities_found']);
    }

    public function testExtractFromChunkUnknownMethodThrows(): void
    {
        $chunk = $this->createChunkMock(1, 'file', 1, 'Some text');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown detection method');
        $this->handler->extractFromChunk($chunk, ['method' => 'unknown_method']);
    }

    public function testProcessSourceChunksWithNoChunks(): void
    {
        $this->chunkMapper->method('findBySource')->willReturn([]);
        $result = $this->handler->processSourceChunks('file', 1);
        $this->assertSame(0, $result['chunks_processed']);
    }

    public function testProcessSourceChunksFiltersMetadataChunks(): void
    {
        $chunk = $this->createChunkMock(1, 'file', 1, 'text', -1);
        $this->chunkMapper->method('findBySource')->willReturn([$chunk]);
        $result = $this->handler->processSourceChunks('file', 1);
        $this->assertSame(0, $result['chunks_processed']);
    }

    public function testProcessSourceChunksHandlesChunkException(): void
    {
        $chunk = $this->createChunkMockThrowsOnText(1);
        $this->chunkMapper->method('findBySource')->willReturn([$chunk]);
        $result = $this->handler->processSourceChunks('file', 1);
        $this->assertSame(0, $result['chunks_processed']);
    }

    public function testHighConfidenceThresholdFiltersLowConfidence(): void
    {
        $chunk = $this->createChunkMock(1, 'file', 1, 'Call +31612345678 now');
        $result = $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'confidence_threshold' => 0.95,
        ]);
        $this->assertSame(0, $result['entities_found']);
    }

    public function testPresidioFallsBackToRegexWhenNotConfigured(): void
    {
        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'presidioApiEndpoint' => '',
        ]);

        $chunk = $this->createChunkMock(1, 'file', 1, 'No entities here');
        $result = $this->handler->extractFromChunk($chunk, ['method' => 'presidio']);
        $this->assertSame(0, $result['entities_found']);
    }

    public function testOpenAnonymiserFallsBackToRegex(): void
    {
        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'openAnonymiserApiEndpoint' => '',
        ]);

        $chunk = $this->createChunkMock(1, 'file', 1, 'No entities here');
        $result = $this->handler->extractFromChunk($chunk, ['method' => 'openanonymiser']);
        $this->assertSame(0, $result['entities_found']);
    }

    private function setupDbMock(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('1=1');
        $qb->method('createNamedParameter')->willReturn('?');
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturn(false);
        $qb->method('executeQuery')->willReturn($result);
    }

    public function testEntityTypeFilterInRegex(): void
    {
        $this->setupDbMock();

        $newEntity = $this->createGdprEntity(1);
        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);
        $this->entityMapper->method('insert')->willReturn($newEntity);

        $chunk = $this->createChunkMock(1, 'file', 1, 'Email: test@example.com Phone: +31612345678');
        $result = $this->handler->extractFromChunk($chunk, [
            'method' => 'regex',
            'entity_types' => [EntityRecognitionHandler::ENTITY_TYPE_PHONE],
        ]);
        $this->assertIsArray($result);
    }

    public function testExtractEmailAndCreateEntity(): void
    {
        $this->setupDbMock();
        $chunk = $this->createChunkMock(1, 'file', 42, 'Contact us at test@example.com for info.');

        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);

        $newEntity = $this->createGdprEntity(1);
        $this->entityMapper->method('insert')->willReturn($newEntity);
        $this->entityRelationMapper->expects($this->once())->method('insert');

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);
        $this->assertGreaterThan(0, $result['entities_found']);
    }

    public function testExtractIbanViaRegex(): void
    {
        $this->setupDbMock();
        $chunk = $this->createChunkMock(1, 'object', 1, 'Bank account: NL91ABNA0417164300');

        $this->entityMapper->method('findEntitiesPublic')->willReturn([]);

        $newEntity = $this->createGdprEntity(1);
        $this->entityMapper->method('insert')->willReturn($newEntity);
        $this->entityRelationMapper->method('insert')->willReturn(new EntityRelation());

        $result = $this->handler->extractFromChunk($chunk, ['method' => 'regex']);
        $this->assertGreaterThanOrEqual(1, $result['entities_found']);
    }
}
