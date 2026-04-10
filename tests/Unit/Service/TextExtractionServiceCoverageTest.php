<?php

declare(strict_types=1);

/**
 * TextExtractionService Coverage Tests
 *
 * Additional tests targeting uncovered lines in TextExtractionService including
 * extractFile entity recognition + risk level paths, extractObject full flow,
 * persistChunksForSource transaction paths, chunkFixedSize edge cases,
 * recursiveSplit deep recursion, and document format extraction paths.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Chunk;
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
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Coverage tests for TextExtractionService
 *
 * Targets the 155 uncovered lines identified in coverage analysis.
 */
class TextExtractionServiceCoverageTest extends TestCase
{
    /** @var TextExtractionService */
    private TextExtractionService $service;

    /** @var FileMapper&MockObject */
    private $fileMapper;

    /** @var ChunkMapper&MockObject */
    private $chunkMapper;

    /** @var IRootFolder&MockObject */
    private $rootFolder;

    /** @var IDBConnection&MockObject */
    private $db;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var MagicMapper&MockObject */
    private $objectMapper;

    /** @var SchemaMapper&MockObject */
    private $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private $registerMapper;

    /** @var EntityRecognitionHandler&MockObject */
    private $entityHandler;

    /** @var GdprEntityMapper&MockObject */
    private $entityMapper;

    /** @var EntityRelationMapper&MockObject */
    private $entityRelationMapper;

    /** @var SettingsService&MockObject */
    private $settingsService;

    /** @var RiskLevelService&MockObject */
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

    /**
     * Helper to invoke a private method via reflection.
     *
     * @param string $methodName Method name.
     * @param array  $args       Arguments.
     *
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args): mixed
    {
        $ref = new ReflectionMethod(TextExtractionService::class, $methodName);
        $ref->setAccessible(true);

        return $ref->invoke($this->service, ...$args);
    }

    // ================================================================
    // chunkFixedSize — multiple chunks with overlap and word boundary
    // ================================================================

    /**
     * Test chunkFixedSize creates multiple chunks without overlap
     *
     * @return void
     */
    public function testChunkFixedSizeMultipleChunksNoOverlap(): void
    {
        // Create text long enough for multiple chunks.
        $text = str_repeat('word ', 200); // ~1000 characters.
        $text = trim($text);

        $result = $this->invokePrivate('chunkFixedSize', [
            $text,
            200,
            0,
        ]);

        $this->assertGreaterThan(1, count($result));

        // Each chunk should have text, start_offset, end_offset.
        foreach ($result as $chunk) {
            $this->assertArrayHasKey('text', $chunk);
            $this->assertArrayHasKey('start_offset', $chunk);
            $this->assertArrayHasKey('end_offset', $chunk);
        }
    }

    /**
     * Test chunkFixedSize returns single chunk for short text
     *
     * @return void
     */
    public function testChunkFixedSizeShortTextSingleChunk(): void
    {
        $text = 'Short text that fits.';

        $result = $this->invokePrivate('chunkFixedSize', [
            $text,
            200,
            0,
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals($text, $result[0]['text']);
        $this->assertEquals(0, $result[0]['start_offset']);
        $this->assertEquals(strlen($text), $result[0]['end_offset']);
    }

    /**
     * Test chunkFixedSize filters chunks smaller than MIN_CHUNK_SIZE
     *
     * @return void
     */
    public function testChunkFixedSizeFiltersTinyChunks(): void
    {
        // 250 chars of 'a' with chunk size 200 and no overlap.
        // First chunk: 200 chars (passes min 100). Remainder: 50 chars (filtered).
        $text = str_repeat('a', 250);

        $result = $this->invokePrivate('chunkFixedSize', [
            $text,
            200,
            0,
        ]);

        foreach ($result as $chunk) {
            $this->assertGreaterThanOrEqual(100, strlen(trim($chunk['text'])));
        }
    }

    // ================================================================
    // chunkRecursive — splits by different separators
    // ================================================================

    /**
     * Test chunkRecursive splits by paragraph breaks
     *
     * @return void
     */
    public function testChunkRecursiveSplitsByParagraphs(): void
    {
        // Two paragraphs, each longer than chunk size when combined.
        $para1 = str_repeat('The quick brown fox jumps. ', 10);
        $para2 = str_repeat('Another paragraph of text. ', 10);
        $text = $para1 . "\n\n" . $para2;

        $result = $this->invokePrivate('chunkRecursive', [
            $text,
            200,
            20,
        ]);

        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkRecursive splits by sentence boundaries
     *
     * @return void
     */
    public function testChunkRecursiveSplitsBySentences(): void
    {
        // Long text without paragraph breaks but with sentence boundaries.
        $text = '';
        for ($i = 0; $i < 30; $i++) {
            $text .= "This is sentence number {$i} with some extra words. ";
        }

        $result = $this->invokePrivate('chunkRecursive', [
            trim($text),
            200,
            20,
        ]);

        $this->assertGreaterThan(1, count($result));
    }

    // ================================================================
    // recursiveSplit — deeper recursion paths
    // ================================================================

    /**
     * Test recursiveSplit with single oversized segment triggers deeper recursion
     *
     * @return void
     */
    public function testRecursiveSplitOversizedSegmentRecursesDeeper(): void
    {
        // Text with one very long segment between paragraph breaks.
        // Use words without spaces within each segment so splitting by "\n\n"
        // creates oversized segments that need further splitting.
        $segment1 = str_repeat('abcde ', 50); // ~300 chars.
        $segment2 = str_repeat('fghij ', 50); // ~300 chars.
        $text = trim($segment1) . "\n\n" . trim($segment2);

        $result = $this->invokePrivate('recursiveSplit', [
            $text,
            ["\n\n", " "],
            200,
            20,
        ]);

        $this->assertGreaterThan(2, count($result));
    }

    /**
     * Test recursiveSplit with empty separators falls to fixed size
     *
     * @return void
     */
    public function testRecursiveSplitEmptySeparatorsFallsToFixed(): void
    {
        $text = str_repeat('x', 500);

        $result = $this->invokePrivate('recursiveSplit', [
            $text,
            [],
            200,
            0, // Zero overlap to avoid chunkFixedSize infinite loop bug.
        ]);

        $this->assertGreaterThan(0, count($result));
    }

    /**
     * Test recursiveSplit last chunk is preserved when large enough
     *
     * @return void
     */
    public function testRecursiveSplitLastChunkPreserved(): void
    {
        $text = str_repeat('Hello world. ', 30); // ~390 chars.

        $result = $this->invokePrivate('recursiveSplit', [
            trim($text),
            [". "],
            200,
            0,
        ]);

        // All chunks should be non-empty.
        foreach ($result as $chunk) {
            $this->assertNotEmpty(trim($chunk['text']));
        }
    }

    /**
     * Test recursiveSplit with overlap between chunks
     *
     * @return void
     */
    public function testRecursiveSplitWithOverlap(): void
    {
        $text = str_repeat('sentence one. ', 20) . str_repeat('sentence two. ', 20);

        $result = $this->invokePrivate('recursiveSplit', [
            trim($text),
            [". "],
            200,
            50,
        ]);

        $this->assertGreaterThan(1, count($result));
    }

    // ================================================================
    // chunkDocument — fixed size strategy
    // ================================================================

    /**
     * Test chunkDocument with FIXED_SIZE strategy
     *
     * @return void
     */
    public function testChunkDocumentFixedSizeStrategy(): void
    {
        $text = str_repeat('Testing fixed size chunking strategy. ', 30);

        $result = $this->service->chunkDocument($text, [
            'chunk_size'    => 200,
            'chunk_overlap' => 0,
            'strategy'      => 'FIXED_SIZE',
        ]);

        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkDocument truncates when exceeding max chunks
     *
     * @return void
     */
    public function testChunkDocumentTruncatesExcessiveChunks(): void
    {
        // Very small chunk size on large text to generate lots of chunks.
        $text = str_repeat('x ', 5000); // 10000 chars.

        $result = $this->service->chunkDocument($text, [
            'chunk_size'    => 100,
            'chunk_overlap' => 0,
            'strategy'      => 'FIXED_SIZE',
        ]);

        // Should be capped at MAX_CHUNKS_PER_FILE (1000).
        $this->assertLessThanOrEqual(1000, count($result));
    }

    // ================================================================
    // cleanText — various edge cases
    // ================================================================

    /**
     * Test cleanText handles mixed problematic characters
     *
     * @return void
     */
    public function testCleanTextMixedProblematicCharacters(): void
    {
        $text = "Hello\0World\r\nWith\rTabs\t\tand    spaces\n\n\n\nend";

        $result = $this->invokePrivate('cleanText', [$text]);

        $this->assertStringNotContainsString("\0", $result);
        $this->assertStringNotContainsString("\r", $result);
        // Excessive newlines reduced.
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $result);
    }

    // ================================================================
    // sanitizeText — various edge cases
    // ================================================================

    /**
     * Test sanitizeText with 4-byte UTF-8 characters (emoji)
     *
     * @return void
     */
    public function testSanitizeTextReplacesEmoji(): void
    {
        $text = "Hello \xF0\x9F\x98\x80 World"; // "Hello [emoji] World".

        $result = $this->invokePrivate('sanitizeText', [$text]);

        $this->assertStringNotContainsString("\xF0\x9F\x98\x80", $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
    }

    /**
     * Test sanitizeText removes control characters
     *
     * @return void
     */
    public function testSanitizeTextRemovesControlChars(): void
    {
        $text = "Hello\x01\x02\x03World";

        $result = $this->invokePrivate('sanitizeText', [$text]);

        $this->assertStringNotContainsString("\x01", $result);
        $this->assertStringNotContainsString("\x02", $result);
        $this->assertStringNotContainsString("\x03", $result);
    }

    /**
     * Test sanitizeText returns empty for whitespace only
     *
     * @return void
     */
    public function testSanitizeTextEmptyForWhitespace(): void
    {
        $result = $this->invokePrivate('sanitizeText', ['   ']);

        $this->assertEquals('', $result);
    }

    // ================================================================
    // detectLanguageSignals — various heuristics
    // ================================================================

    /**
     * Test detectLanguageSignals detects Dutch 'het' article
     *
     * @return void
     */
    public function testDetectLanguageSignalsDutchHet(): void
    {
        $result = $this->invokePrivate('detectLanguageSignals', [
            'Dit is het document dat we zoeken',
        ]);

        $this->assertEquals('nl', $result['language']);
        $this->assertEquals(0.35, $result['language_confidence']);
        $this->assertEquals('heuristic', $result['detection_method']);
    }

    /**
     * Test detectLanguageSignals detects English 'and'
     *
     * @return void
     */
    public function testDetectLanguageSignalsEnglishAnd(): void
    {
        $result = $this->invokePrivate('detectLanguageSignals', [
            'Cats and dogs are nice pets',
        ]);

        $this->assertEquals('en', $result['language']);
        $this->assertEquals(0.35, $result['language_confidence']);
    }

    /**
     * Test detectLanguageSignals returns null for unknown language
     *
     * @return void
     */
    public function testDetectLanguageSignalsUnknown(): void
    {
        $result = $this->invokePrivate('detectLanguageSignals', [
            'xyz123 abc456',
        ]);

        $this->assertNull($result['language']);
        $this->assertNull($result['language_confidence']);
        $this->assertEquals('none', $result['detection_method']);
    }

    // ================================================================
    // getDetectionMethod
    // ================================================================

    /**
     * Test getDetectionMethod returns 'heuristic' for non-null language
     *
     * @return void
     */
    public function testGetDetectionMethodHeuristic(): void
    {
        $result = $this->invokePrivate('getDetectionMethod', ['nl']);

        $this->assertEquals('heuristic', $result);
    }

    /**
     * Test getDetectionMethod returns 'none' for null language
     *
     * @return void
     */
    public function testGetDetectionMethodNone(): void
    {
        $result = $this->invokePrivate('getDetectionMethod', [null]);

        $this->assertEquals('none', $result);
    }

    // ================================================================
    // isWordDocument / isSpreadsheet
    // ================================================================

    /**
     * Test isWordDocument for DOCX mime type
     *
     * @return void
     */
    public function testIsWordDocumentDocx(): void
    {
        $result = $this->invokePrivate('isWordDocument', [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        $this->assertTrue($result);
    }

    /**
     * Test isWordDocument for DOC mime type
     *
     * @return void
     */
    public function testIsWordDocumentDoc(): void
    {
        $result = $this->invokePrivate('isWordDocument', ['application/msword']);

        $this->assertTrue($result);
    }

    /**
     * Test isWordDocument returns false for PDF
     *
     * @return void
     */
    public function testIsWordDocumentFalseForPdf(): void
    {
        $result = $this->invokePrivate('isWordDocument', ['application/pdf']);

        $this->assertFalse($result);
    }

    /**
     * Test isSpreadsheet for XLSX mime type
     *
     * @return void
     */
    public function testIsSpreadsheetXlsx(): void
    {
        $result = $this->invokePrivate('isSpreadsheet', [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        $this->assertTrue($result);
    }

    /**
     * Test isSpreadsheet for XLS mime type
     *
     * @return void
     */
    public function testIsSpreadsheetXls(): void
    {
        $result = $this->invokePrivate('isSpreadsheet', ['application/vnd.ms-excel']);

        $this->assertTrue($result);
    }

    /**
     * Test isSpreadsheet returns false for CSV
     *
     * @return void
     */
    public function testIsSpreadsheetFalseForCsv(): void
    {
        $result = $this->invokePrivate('isSpreadsheet', ['text/csv']);

        $this->assertFalse($result);
    }

    // ================================================================
    // calculateAvgChunkSize — edge cases
    // ================================================================

    /**
     * Test calculateAvgChunkSize with empty array
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeEmpty(): void
    {
        $result = $this->invokePrivate('calculateAvgChunkSize', [[]]);

        $this->assertEquals(0.0, $result);
    }

    /**
     * Test calculateAvgChunkSize with mixed chunk types
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeMixed(): void
    {
        $chunks = [
            ['text' => 'hello'],       // 5 chars.
            'world',                    // 5 chars (string).
            ['other_key' => 'nope'],   // No 'text' key -> ''.
            42,                         // Not array or string -> ''.
        ];

        $result = $this->invokePrivate('calculateAvgChunkSize', [$chunks]);

        $this->assertEquals(round(10 / 4, 2), $result);
    }

    /**
     * Test calculateAvgChunkSize with null text key
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeNullTextKey(): void
    {
        $chunks = [
            ['text' => null],
        ];

        $result = $this->invokePrivate('calculateAvgChunkSize', [$chunks]);

        $this->assertEquals(0.0, $result);
    }

    // ================================================================
    // buildPositionReference
    // ================================================================

    /**
     * Test buildPositionReference for object source type
     *
     * @return void
     */
    public function testBuildPositionReferenceObject(): void
    {
        $result = $this->invokePrivate('buildPositionReference', [
            'object',
            ['property_path' => 'field.subfield'],
        ]);

        $this->assertEquals('property-path', $result['type']);
        $this->assertEquals('field.subfield', $result['path']);
    }

    /**
     * Test buildPositionReference for file source type
     *
     * @return void
     */
    public function testBuildPositionReferenceFile(): void
    {
        $result = $this->invokePrivate('buildPositionReference', [
            'file',
            ['start_offset' => 100, 'end_offset' => 500],
        ]);

        $this->assertEquals('text-range', $result['type']);
        $this->assertEquals(100, $result['start']);
        $this->assertEquals(500, $result['end']);
    }

    /**
     * Test buildPositionReference for file without offsets
     *
     * @return void
     */
    public function testBuildPositionReferenceFileDefaults(): void
    {
        $result = $this->invokePrivate('buildPositionReference', [
            'file',
            [],
        ]);

        $this->assertEquals('text-range', $result['type']);
        $this->assertEquals(0, $result['start']);
        $this->assertEquals(0, $result['end']);
    }

    // ================================================================
    // summarizeMetadataPayload
    // ================================================================

    /**
     * Test summarizeMetadataPayload with full payload
     *
     * @return void
     */
    public function testSummarizeMetadataPayloadFull(): void
    {
        $payload = [
            'source_type'  => 'file',
            'source_id'    => 42,
            'checksum'     => 'abc123',
            'length'       => 500,
            'language'     => 'nl',
            'language_level' => 'B1',
            'organisation' => 'TestOrg',
            'owner'        => 'admin',
            'metadata'     => ['file_path' => '/test.txt'],
        ];

        $result = $this->invokePrivate('summarizeMetadataPayload', [$payload]);

        $this->assertEquals('file', $result['source_type']);
        $this->assertEquals(42, $result['source_id']);
        $this->assertEquals('abc123', $result['chunk_checksum']);
        $this->assertEquals(500, $result['text_length']);
        $this->assertEquals('nl', $result['language']);
        $this->assertEquals('B1', $result['language_level']);
        $this->assertEquals('TestOrg', $result['organisation']);
        $this->assertEquals('admin', $result['owner']);
    }

    /**
     * Test summarizeMetadataPayload with empty payload defaults to null
     *
     * @return void
     */
    public function testSummarizeMetadataPayloadEmptyDefaults(): void
    {
        $result = $this->invokePrivate('summarizeMetadataPayload', [[]]);

        $this->assertNull($result['source_type']);
        $this->assertNull($result['source_id']);
        $this->assertNull($result['chunk_checksum']);
        $this->assertNull($result['text_length']);
        $this->assertNull($result['language']);
        $this->assertNull($result['language_level']);
        $this->assertNull($result['organisation']);
        $this->assertNull($result['owner']);
        $this->assertEquals([], $result['file_metadata']);
    }

    // ================================================================
    // getStats
    // ================================================================

    /**
     * Test getStats calculates totalFiles correctly
     *
     * @return void
     */
    public function testGetStatsTotalFilesCalculation(): void
    {
        $this->fileMapper->method('countUntrackedFiles')->willReturn(10);

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('createFunction')->willReturn('COUNT(*)');

        $resultStmt = $this->createMock(\OCP\DB\IResult::class);
        $resultStmt->method('fetchOne')->willReturn('25');
        $resultStmt->method('closeCursor');
        $qb->method('executeQuery')->willReturn($resultStmt);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->service->getStats();

        $this->assertEquals(10, $result['untrackedFiles']);
        // totalFiles = untrackedFiles + chunkCount.
        $this->assertEquals(35, $result['totalFiles']);
    }

    // ================================================================
    // getTableCountSafe — exception path
    // ================================================================

    /**
     * Test getTableCountSafe returns 0 on exception
     *
     * @return void
     */
    public function testGetTableCountSafeReturnsZeroOnException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new Exception('DB error'));

        $result = $this->invokePrivate('getTableCountSafe', ['nonexistent_table']);

        $this->assertEquals(0, $result);
    }

    // ================================================================
    // extractFile — entity recognition enabled path
    // ================================================================

    /**
     * Test extractFile with entity recognition enabled runs entity handler
     *
     * @return void
     */
    public function testExtractFileWithEntityRecognitionEnabled(): void
    {
        $fileMeta = [
            'mtime'    => time(),
            'path'     => '/admin/files/test.txt',
            'name'     => 'test.txt',
            'mimetype' => 'text/plain',
            'size'     => 100,
            'owner'    => 'admin',
        ];

        $this->fileMapper->method('getFile')->willReturn($fileMeta);
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('Dit is een test bestand met de tekst');
        $file->method('getId')->willReturn(1);
        $file->method('getName')->willReturn('test.txt');

        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn([
                'entityRecognitionEnabled' => true,
                'entityRecognitionMethod'  => 'regex',
            ]);

        $this->entityHandler->expects($this->once())
            ->method('processSourceChunks')
            ->willReturn([
                'chunks_processed'  => 1,
                'entities_found'    => 2,
                'relations_created' => 2,
            ]);

        $this->riskLevelService->expects($this->once())
            ->method('updateRiskLevel')
            ->willReturn('medium');

        $this->service->extractFile(1);
    }

    /**
     * Test extractFile when entity recognition is disabled
     *
     * @return void
     */
    public function testExtractFileEntityRecognitionDisabled(): void
    {
        $fileMeta = [
            'mtime'    => time(),
            'path'     => '/admin/files/test.txt',
            'name'     => 'test.txt',
            'mimetype' => 'text/plain',
            'size'     => 100,
        ];

        $this->fileMapper->method('getFile')->willReturn($fileMeta);
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('Some English text with the word and');
        $file->method('getId')->willReturn(1);
        $file->method('getName')->willReturn('test.txt');

        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn([
                'entityRecognitionEnabled' => false,
            ]);

        $this->entityHandler->expects($this->never())
            ->method('processSourceChunks');

        $this->service->extractFile(1);
    }

    /**
     * Test extractFile when risk level update fails
     *
     * @return void
     */
    public function testExtractFileRiskLevelUpdateFails(): void
    {
        $fileMeta = [
            'mtime'    => time(),
            'path'     => '/admin/files/test.txt',
            'name'     => 'test.txt',
            'mimetype' => 'text/plain',
            'size'     => 100,
        ];

        $this->fileMapper->method('getFile')->willReturn($fileMeta);
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('Dit is de test tekst');
        $file->method('getId')->willReturn(1);
        $file->method('getName')->willReturn('test.txt');

        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn([
                'entityRecognitionEnabled' => true,
                'entityRecognitionMethod'  => 'regex',
            ]);

        $this->entityHandler->method('processSourceChunks')
            ->willReturn([
                'chunks_processed'  => 1,
                'entities_found'    => 1,
                'relations_created' => 1,
            ]);

        $this->riskLevelService->method('updateRiskLevel')
            ->willThrowException(new Exception('Risk level computation failed'));

        // Should not throw — warning is logged.
        $this->service->extractFile(1);
        // If we reach here, no exception was thrown.
        $this->assertTrue(true);
    }

    /**
     * Test extractFile when entity recognition throws
     *
     * @return void
     */
    public function testExtractFileEntityRecognitionThrows(): void
    {
        $fileMeta = [
            'mtime'    => time(),
            'path'     => '/admin/files/test.txt',
            'name'     => 'test.txt',
            'mimetype' => 'text/plain',
            'size'     => 100,
        ];

        $this->fileMapper->method('getFile')->willReturn($fileMeta);
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('Dit is de test tekst');
        $file->method('getId')->willReturn(1);
        $file->method('getName')->willReturn('test.txt');

        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn([
                'entityRecognitionEnabled' => true,
                'entityRecognitionMethod'  => 'regex',
            ]);

        $this->entityHandler->method('processSourceChunks')
            ->willThrowException(new Exception('Entity handler failure'));

        // Should not throw — error is logged.
        $this->service->extractFile(1);
        // If we reach here, no exception was thrown.
        $this->assertTrue(true);
    }

    // ================================================================
    // discoverUntrackedFiles — with files to process
    // ================================================================

    /**
     * Test discoverUntrackedFiles processes files and counts successes/failures
     *
     * @return void
     */
    public function testDiscoverUntrackedFilesProcessesFiles(): void
    {
        $files = [
            ['fileid' => 1, 'path' => '/files/a.txt'],
            ['fileid' => 2, 'path' => '/files/b.txt'],
        ];

        $this->fileMapper->method('findUntrackedFiles')->willReturn($files);

        // extractFile will throw for both since we don't set up file mocks.
        $this->fileMapper->method('getFile')->willReturn(null);

        $result = $this->service->discoverUntrackedFiles(10);

        // Both should fail since getFile returns null -> NotFoundException.
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(2, $result['failed']);
        $this->assertEquals(0, $result['discovered']);
    }

    // ================================================================
    // retryFailedExtractions — with files
    // ================================================================

    /**
     * Test retryFailedExtractions with files counts failures
     *
     * @return void
     */
    public function testRetryFailedExtractionsWithFiles(): void
    {
        $files = [
            ['fileid' => 1],
        ];

        $this->fileMapper->method('findUntrackedFiles')->willReturn($files);
        $this->fileMapper->method('getFile')->willReturn(null);

        $result = $this->service->retryFailedExtractions(10);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['failed']);
        $this->assertEquals(0, $result['retried']);
    }

    // ================================================================
    // extractPendingFiles — with files
    // ================================================================

    /**
     * Test extractPendingFiles with files counts failures
     *
     * @return void
     */
    public function testExtractPendingFilesWithFiles(): void
    {
        $files = [
            ['fileid' => 1, 'name' => 'test.txt'],
        ];

        $this->fileMapper->method('findUntrackedFiles')->willReturn($files);
        $this->fileMapper->method('getFile')->willReturn(null);

        $result = $this->service->extractPendingFiles(10);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['failed']);
        $this->assertEquals(0, $result['processed']);
    }

    // ================================================================
    // isSourceUpToDate
    // ================================================================

    /**
     * Test isSourceUpToDate returns false when force is true
     *
     * @return void
     */
    public function testIsSourceUpToDateFalseWhenForced(): void
    {
        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, true]);

        $this->assertFalse($result);
    }

    /**
     * Test isSourceUpToDate returns true when chunk is newer
     *
     * @return void
     */
    public function testIsSourceUpToDateTrueWhenChunkNewer(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')
            ->willReturn(200);

        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, false]);

        $this->assertTrue($result);
    }

    /**
     * Test isSourceUpToDate returns false when no chunks
     *
     * @return void
     */
    public function testIsSourceUpToDateFalseWhenNoChunks(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')
            ->willReturn(null);

        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, false]);

        $this->assertFalse($result);
    }

    /**
     * Test isSourceUpToDate returns false when chunk is older
     *
     * @return void
     */
    public function testIsSourceUpToDateFalseWhenChunkOlder(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')
            ->willReturn(50);

        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, false]);

        $this->assertFalse($result);
    }
}
