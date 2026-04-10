<?php

/**
 * TextExtractionService Gap Coverage Tests
 *
 * Tests for uncovered methods in TextExtractionService including
 * statistics, discovery, retry, and safe chunking operations.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapper;
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
 * Gap coverage tests for TextExtractionService
 *
 * Tests cover:
 * - chunkDocument with safe inputs (short text, default options)
 * - Text cleaning (null bytes, line endings, whitespace)
 * - Statistics retrieval (getStats)
 * - Discovery methods (discoverUntrackedFiles)
 * - Retry methods (retryFailedExtractions)
 * - Pending file extraction (extractPendingFiles)
 */
class TextExtractionServiceGapTest extends TestCase
{
    /** @var TextExtractionService */
    private TextExtractionService $service;

    /** @var MockObject|FileMapper */
    private $fileMapper;

    /** @var MockObject|ChunkMapper */
    private $chunkMapper;

    /** @var MockObject|IRootFolder */
    private $rootFolder;

    /** @var MockObject|IDBConnection */
    private $db;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var MockObject|MagicMapper */
    private $objectMapper;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|EntityRecognitionHandler */
    private $entityHandler;

    /** @var MockObject|GdprEntityMapper */
    private $entityMapper;

    /** @var MockObject|EntityRelationMapper */
    private $entityRelationMapper;

    /** @var MockObject|SettingsService */
    private $settingsService;

    /** @var MockObject|RiskLevelService */
    private $riskLevelService;

    /**
     * Set up test dependencies
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->fileMapper           = $this->createMock(FileMapper::class);
        $this->chunkMapper          = $this->createMock(ChunkMapper::class);
        $this->rootFolder           = $this->createMock(IRootFolder::class);
        $this->db                   = $this->createMock(IDBConnection::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->objectMapper   = $this->createMock(MagicMapper::class);
        $this->schemaMapper         = $this->createMock(SchemaMapper::class);
        $this->registerMapper       = $this->createMock(RegisterMapper::class);
        $this->entityHandler        = $this->createMock(EntityRecognitionHandler::class);
        $this->entityMapper         = $this->createMock(GdprEntityMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->settingsService      = $this->createMock(SettingsService::class);
        $this->riskLevelService     = $this->createMock(RiskLevelService::class);

        $this->service = new TextExtractionService(
            $this->fileMapper,
            $this->chunkMapper,
            $this->rootFolder,
            $this->db,
            $this->logger,
            $this->objectMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->entityHandler,
            $this->entityMapper,
            $this->entityRelationMapper,
            $this->settingsService,
            $this->riskLevelService
        );
    }

    // =============================================
    // chunkDocument tests — safe inputs only
    // =============================================

    /**
     * Test chunkDocument with short text returns single chunk
     *
     * @return void
     */
    public function testChunkDocumentShortTextSingleChunk(): void
    {
        $shortText = 'This is a short piece of text that fits in one chunk easily.';

        $result = $this->service->chunkDocument($shortText, [
            'chunk_size'    => 1000,
            'chunk_overlap' => 200,
        ]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('text', $result[0]);
        $this->assertArrayHasKey('start_offset', $result[0]);
        $this->assertArrayHasKey('end_offset', $result[0]);
    }

    /**
     * Test chunkDocument with default options for short text
     *
     * @return void
     */
    public function testChunkDocumentWithDefaultOptions(): void
    {
        $text = 'A simple test text for default chunking options that is short enough.';

        $result = $this->service->chunkDocument($text);

        $this->assertGreaterThan(0, count($result));
        $this->assertEquals($text, $result[0]['text']);
    }

    /**
     * Test chunkDocument cleans text (removes null bytes)
     *
     * @return void
     */
    public function testChunkDocumentRemovesNullBytes(): void
    {
        $dirtyText = "Hello\0World this is a test with null bytes\0 in it.";

        $result = $this->service->chunkDocument($dirtyText, [
            'chunk_size' => 2000,
        ]);

        $this->assertCount(1, $result);
        $this->assertStringNotContainsString("\0", $result[0]['text']);
    }

    /**
     * Test chunkDocument normalizes line endings
     *
     * @return void
     */
    public function testChunkDocumentNormalizesLineEndings(): void
    {
        $text = "Line one\r\nLine two\rLine three\nLine four";

        $result = $this->service->chunkDocument($text, [
            'chunk_size' => 2000,
        ]);

        $this->assertCount(1, $result);
        $this->assertStringNotContainsString("\r", $result[0]['text']);
    }

    /**
     * Test chunkDocument with unknown strategy falls back to recursive
     *
     * @return void
     */
    public function testChunkDocumentUnknownStrategyFallback(): void
    {
        $text = 'Short text for fallback strategy test.';

        $result = $this->service->chunkDocument($text, [
            'chunk_size' => 2000,
            'strategy'   => 'UNKNOWN_STRATEGY',
        ]);

        $this->assertGreaterThan(0, count($result));
        $this->assertEquals($text, $result[0]['text']);
    }

    /**
     * Test chunkDocument reduces excessive whitespace
     *
     * @return void
     */
    public function testChunkDocumentReducesWhitespace(): void
    {
        $text = "Word1     Word2\t\tWord3";

        $result = $this->service->chunkDocument($text, [
            'chunk_size' => 2000,
        ]);

        $this->assertCount(1, $result);
        $chunkText = $result[0]['text'];
        $this->assertDoesNotMatchRegularExpression('/[ \t]{2,}/', $chunkText);
    }

    /**
     * Test chunkDocument with recursive strategy on paragraph text that fits in one chunk
     *
     * @return void
     */
    public function testChunkDocumentRecursiveSmallParagraphs(): void
    {
        $text = "First paragraph.\n\nSecond paragraph.";

        $result = $this->service->chunkDocument($text, [
            'chunk_size'    => 2000,
            'chunk_overlap' => 10,
            'strategy'      => 'RECURSIVE_CHARACTER',
        ]);

        $this->assertCount(1, $result);
    }

    // =============================================
    // getStats tests (public method, line 1214)
    // =============================================

    /**
     * Test getStats returns expected keys
     *
     * @return void
     */
    public function testGetStatsReturnsExpectedKeys(): void
    {
        $this->fileMapper
            ->method('countUntrackedFiles')
            ->willReturn(5);

        $result = $this->service->getStats();

        $this->assertArrayHasKey('totalFiles', $result);
        $this->assertArrayHasKey('untrackedFiles', $result);
        $this->assertArrayHasKey('totalChunks', $result);
        $this->assertArrayHasKey('totalObjects', $result);
        $this->assertArrayHasKey('totalEntities', $result);
        $this->assertEquals(5, $result['untrackedFiles']);
    }

    /**
     * Test getStats with zero untracked files
     *
     * @return void
     */
    public function testGetStatsWithZeroUntrackedFiles(): void
    {
        $this->fileMapper
            ->method('countUntrackedFiles')
            ->willReturn(0);

        $result = $this->service->getStats();
        $this->assertEquals(0, $result['untrackedFiles']);
    }

    // =============================================
    // discoverUntrackedFiles tests
    // =============================================

    /**
     * Test discoverUntrackedFiles with no files
     *
     * @return void
     */
    public function testDiscoverUntrackedFilesNoFiles(): void
    {
        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([]);

        $result = $this->service->discoverUntrackedFiles(10);

        $this->assertEquals(0, $result['discovered']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test discoverUntrackedFiles handles outer exception
     *
     * @return void
     */
    public function testDiscoverUntrackedFilesHandlesException(): void
    {
        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->service->discoverUntrackedFiles(10);

        $this->assertEquals(0, $result['discovered']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('DB error', $result['error']);
    }

    /**
     * Test discoverUntrackedFiles with default limit
     *
     * @return void
     */
    public function testDiscoverUntrackedFilesDefaultLimit(): void
    {
        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([]);

        $result = $this->service->discoverUntrackedFiles();

        $this->assertEquals(0, $result['total']);
    }

    // =============================================
    // retryFailedExtractions tests
    // =============================================

    /**
     * Test retryFailedExtractions with no files
     *
     * @return void
     */
    public function testRetryFailedExtractionsNoFiles(): void
    {
        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([]);

        $result = $this->service->retryFailedExtractions(10);

        $this->assertEquals(0, $result['retried']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test retryFailedExtractions with default limit
     *
     * @return void
     */
    public function testRetryFailedExtractionsDefaultLimit(): void
    {
        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([]);

        $result = $this->service->retryFailedExtractions();

        $this->assertEquals(0, $result['total']);
    }

    // =============================================
    // extractPendingFiles tests
    // =============================================

    /**
     * Test extractPendingFiles with no pending files
     *
     * @return void
     */
    public function testExtractPendingFilesNoPending(): void
    {
        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([]);

        $result = $this->service->extractPendingFiles(10);

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test extractPendingFiles with default limit
     *
     * @return void
     */
    public function testExtractPendingFilesDefaultLimit(): void
    {
        $this->fileMapper
            ->method('findUntrackedFiles')
            ->willReturn([]);

        $result = $this->service->extractPendingFiles();

        $this->assertEquals(0, $result['total']);
    }
}
