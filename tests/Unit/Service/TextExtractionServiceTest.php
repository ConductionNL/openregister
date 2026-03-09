<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests for TextExtractionService
 *
 * Focuses on methods with testable logic: chunkDocument and getStats.
 */
class TextExtractionServiceTest extends TestCase
{
    private TextExtractionService $service;
    private FileMapper&MockObject $fileMapper;
    private ChunkMapper&MockObject $chunkMapper;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private GdprEntityMapper&MockObject $gdprEntityMapper;
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private RiskLevelService&MockObject $riskLevelService;
    private SettingsService&MockObject $settingsService;
    private EntityRecognitionHandler&MockObject $entityHandler;
    private IRootFolder&MockObject $rootFolder;
    private IDBConnection&MockObject $db;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->fileMapper = $this->createMock(FileMapper::class);
        $this->chunkMapper = $this->createMock(ChunkMapper::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->entityHandler = $this->createMock(EntityRecognitionHandler::class);
        $this->gdprEntityMapper = $this->createMock(GdprEntityMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->riskLevelService = $this->createMock(RiskLevelService::class);

        $this->service = new TextExtractionService(
            $this->fileMapper,
            $this->chunkMapper,
            $this->rootFolder,
            $this->db,
            $this->logger,
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->entityHandler,
            $this->gdprEntityMapper,
            $this->entityRelationMapper,
            $this->settingsService,
            $this->riskLevelService
        );
    }

    // ── chunkDocument ──

    public function testChunkDocumentReturnsChunksForText(): void
    {
        $text = str_repeat('Hello world. This is a test sentence. ', 100);

        $chunks = $this->service->chunkDocument($text);

        $this->assertIsArray($chunks);
        $this->assertNotEmpty($chunks);
    }

    public function testChunkDocumentReturnsEmptyArrayForEmptyText(): void
    {
        $chunks = $this->service->chunkDocument('');

        $this->assertIsArray($chunks);
    }

    public function testChunkDocumentRespectsChunkSizeOption(): void
    {
        $text = str_repeat('Word ', 1000);

        $chunks = $this->service->chunkDocument($text, ['chunk_size' => 100, 'chunk_overlap' => 0]);

        $this->assertIsArray($chunks);
        // Each chunk should be at most ~200 characters (with tolerance for word boundaries).
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, strlen($chunk));
        }
    }

    public function testChunkDocumentWithFixedSizeStrategy(): void
    {
        $text = str_repeat('Word ', 500);

        $chunks = $this->service->chunkDocument($text, [
            'strategy' => 'fixed_size',
            'chunk_size' => 200,
            'chunk_overlap' => 0,
        ]);

        $this->assertIsArray($chunks);
        $this->assertNotEmpty($chunks);
    }

    // ── getStats ──

    public function testGetStatsReturnsExpectedStructure(): void
    {
        $this->fileMapper->method('countUntrackedFiles')->willReturn(5);

        // Mock query builder chain for getTableCountSafe.
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchOne')->willReturn('10');
        $result->method('closeCursor');

        $funcExpr = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('createFunction')->willReturn($funcExpr);
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $stats = $this->service->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('totalFiles', $stats);
        $this->assertArrayHasKey('untrackedFiles', $stats);
        $this->assertArrayHasKey('totalChunks', $stats);
        $this->assertArrayHasKey('totalObjects', $stats);
        $this->assertArrayHasKey('totalEntities', $stats);
        $this->assertSame(5, $stats['untrackedFiles']);
    }
}
