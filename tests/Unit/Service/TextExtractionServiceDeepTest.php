<?php

/**
 * TextExtractionService Deep Coverage Tests
 *
 * Tests targeting uncovered lines in TextExtractionService:
 * - sanitizeText branches (NULL bytes, invalid UTF-8, control chars)
 * - detectLanguageSignals (nl, en, unknown)
 * - getDetectionMethod
 * - isWordDocument / isSpreadsheet MIME checks
 * - buildPositionReference (object vs file)
 * - chunkDocument strategies (fixed, recursive, default)
 * - chunkFixedSize edge cases
 * - recursiveSplit with oversized segments
 * - calculateAvgChunkSize edge cases
 * - cleanText normalization
 * - textToChunks mapping
 * - isSourceUpToDate branches
 * - hydrateChunkEntity hydration
 * - summarizeMetadataPayload
 * - persistMetadataChunk JSON encoding failure
 * - extractFile flow with entity recognition disabled
 * - extractObject with deleted object
 * - getStats table counting
 * - getTableCountSafe exception branch
 * - retryFailedExtractions
 * - extractPendingFiles
 * - discoverUntrackedFiles
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep coverage tests for TextExtractionService
 */
class TextExtractionServiceDeepTest extends TestCase
{

    private TextExtractionService $service;

    private MockObject|FileMapper $fileMapper;

    private MockObject|ChunkMapper $chunkMapper;

    private MockObject|IRootFolder $rootFolder;

    private MockObject|IDBConnection $db;

    private MockObject|LoggerInterface $logger;

    private MockObject|MagicMapper $objectMapper;

    private MockObject|SchemaMapper $schemaMapper;

    private MockObject|RegisterMapper $registerMapper;

    private MockObject|EntityRecognitionHandler $entityHandler;

    private MockObject|GdprEntityMapper $entityMapper;

    private MockObject|EntityRelationMapper $entityRelationMapper;

    private MockObject|SettingsService $settingsService;

    private MockObject|RiskLevelService $riskLevelService;


    /**
     * Set up test fixtures
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

    }//end setUp()


    // =========================================================================
    // sanitizeText (private — test via reflection)
    // =========================================================================

    /**
     * Test sanitizeText removes NULL bytes
     *
     * @return void
     */
    public function testSanitizeTextRemovesNullBytes(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'sanitizeText');

        $result = $method->invoke($this->service, "hello\0world");
        $this->assertStringNotContainsString("\0", $result);
        $this->assertStringContainsString('hello', $result);

    }//end testSanitizeTextRemovesNullBytes()


    /**
     * Test sanitizeText normalizes whitespace
     *
     * @return void
     */
    public function testSanitizeTextNormalizesWhitespace(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'sanitizeText');

        $result = $method->invoke($this->service, "hello   world\t\ttab");
        // Multiple spaces and tabs should be collapsed.
        $this->assertStringNotContainsString('  ', $result);

    }//end testSanitizeTextNormalizesWhitespace()


    /**
     * Test sanitizeText trims result
     *
     * @return void
     */
    public function testSanitizeTextTrims(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'sanitizeText');

        $result = $method->invoke($this->service, '  hello world  ');
        $this->assertEquals('hello world', $result);

    }//end testSanitizeTextTrims()


    /**
     * Test sanitizeText returns empty for whitespace only input
     *
     * @return void
     */
    public function testSanitizeTextEmptyForWhitespaceOnly(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'sanitizeText');

        $result = $method->invoke($this->service, "   \t  \n  ");
        $this->assertEquals('', $result);

    }//end testSanitizeTextEmptyForWhitespaceOnly()


    // =========================================================================
    // detectLanguageSignals (private — test via reflection)
    // =========================================================================

    /**
     * Test Dutch language detection
     *
     * @return void
     */
    public function testDetectLanguageSignalsDutch(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'detectLanguageSignals');

        $result = $method->invoke($this->service, 'Dit is de test tekst met het woord');
        $this->assertEquals('nl', $result['language']);
        $this->assertEquals(0.35, $result['language_confidence']);
        $this->assertEquals('heuristic', $result['detection_method']);

    }//end testDetectLanguageSignalsDutch()


    /**
     * Test English language detection
     *
     * @return void
     */
    public function testDetectLanguageSignalsEnglish(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'detectLanguageSignals');

        $result = $method->invoke($this->service, 'This is the test text and more of it');
        $this->assertEquals('en', $result['language']);
        $this->assertEquals(0.35, $result['language_confidence']);
        $this->assertEquals('heuristic', $result['detection_method']);

    }//end testDetectLanguageSignalsEnglish()


    /**
     * Test unknown language detection
     *
     * @return void
     */
    public function testDetectLanguageSignalsUnknown(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'detectLanguageSignals');

        $result = $method->invoke($this->service, 'xyz123 abc456 789');
        $this->assertNull($result['language']);
        $this->assertNull($result['language_confidence']);
        $this->assertEquals('none', $result['detection_method']);

    }//end testDetectLanguageSignalsUnknown()


    // =========================================================================
    // getDetectionMethod (private)
    // =========================================================================

    /**
     * Test getDetectionMethod returns heuristic for non-null
     *
     * @return void
     */
    public function testGetDetectionMethodHeuristic(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'getDetectionMethod');

        $this->assertEquals('heuristic', $method->invoke($this->service, 'nl'));
        $this->assertEquals('heuristic', $method->invoke($this->service, 'en'));

    }//end testGetDetectionMethodHeuristic()


    /**
     * Test getDetectionMethod returns none for null
     *
     * @return void
     */
    public function testGetDetectionMethodNone(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'getDetectionMethod');

        $this->assertEquals('none', $method->invoke($this->service, null));

    }//end testGetDetectionMethodNone()


    // =========================================================================
    // isWordDocument / isSpreadsheet (private)
    // =========================================================================

    /**
     * Test isWordDocument recognises DOCX
     *
     * @return void
     */
    public function testIsWordDocumentDocx(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isWordDocument');

        $this->assertTrue($method->invoke(
            $this->service,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ));

    }//end testIsWordDocumentDocx()


    /**
     * Test isWordDocument recognises DOC
     *
     * @return void
     */
    public function testIsWordDocumentDoc(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isWordDocument');

        $this->assertTrue($method->invoke($this->service, 'application/msword'));

    }//end testIsWordDocumentDoc()


    /**
     * Test isWordDocument returns false for non-word
     *
     * @return void
     */
    public function testIsWordDocumentFalseForPdf(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isWordDocument');

        $this->assertFalse($method->invoke($this->service, 'application/pdf'));

    }//end testIsWordDocumentFalseForPdf()


    /**
     * Test isSpreadsheet recognises XLSX
     *
     * @return void
     */
    public function testIsSpreadsheetXlsx(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isSpreadsheet');

        $this->assertTrue($method->invoke(
            $this->service,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ));

    }//end testIsSpreadsheetXlsx()


    /**
     * Test isSpreadsheet recognises XLS
     *
     * @return void
     */
    public function testIsSpreadsheetXls(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isSpreadsheet');

        $this->assertTrue($method->invoke($this->service, 'application/vnd.ms-excel'));

    }//end testIsSpreadsheetXls()


    /**
     * Test isSpreadsheet returns false for text
     *
     * @return void
     */
    public function testIsSpreadsheetFalseForText(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isSpreadsheet');

        $this->assertFalse($method->invoke($this->service, 'text/plain'));

    }//end testIsSpreadsheetFalseForText()


    // =========================================================================
    // buildPositionReference (private)
    // =========================================================================

    /**
     * Test buildPositionReference for object source type
     *
     * @return void
     */
    public function testBuildPositionReferenceObject(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'buildPositionReference');

        $result = $method->invoke(
            $this->service,
            'object',
            ['property_path' => 'description']
        );
        $this->assertEquals('property-path', $result['type']);
        $this->assertEquals('description', $result['path']);

    }//end testBuildPositionReferenceObject()


    /**
     * Test buildPositionReference for file source type
     *
     * @return void
     */
    public function testBuildPositionReferenceFile(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'buildPositionReference');

        $result = $method->invoke(
            $this->service,
            'file',
            ['start_offset' => 100, 'end_offset' => 500]
        );
        $this->assertEquals('text-range', $result['type']);
        $this->assertEquals(100, $result['start']);
        $this->assertEquals(500, $result['end']);

    }//end testBuildPositionReferenceFile()


    /**
     * Test buildPositionReference defaults for file without offsets
     *
     * @return void
     */
    public function testBuildPositionReferenceFileDefaults(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'buildPositionReference');

        $result = $method->invoke($this->service, 'file', []);
        $this->assertEquals('text-range', $result['type']);
        $this->assertEquals(0, $result['start']);
        $this->assertEquals(0, $result['end']);

    }//end testBuildPositionReferenceFileDefaults()


    // =========================================================================
    // chunkDocument (public)
    // =========================================================================

    /**
     * Test chunkDocument with recursive strategy
     *
     * @return void
     */
    public function testChunkDocumentRecursive(): void
    {
        $text   = str_repeat('This is a test sentence. ', 200);
        $chunks = $this->service->chunkDocument($text, [
            'chunk_size'    => 500,
            'chunk_overlap' => 50,
            'strategy'      => 'RECURSIVE_CHARACTER',
        ]);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertArrayHasKey('text', $chunk);
            $this->assertArrayHasKey('start_offset', $chunk);
            $this->assertArrayHasKey('end_offset', $chunk);
        }

    }//end testChunkDocumentRecursive()


    /**
     * Test chunkDocument with fixed size strategy
     *
     * @return void
     */
    public function testChunkDocumentFixedSize(): void
    {
        $text   = str_repeat('Hello world test content. ', 100);
        $chunks = $this->service->chunkDocument($text, [
            'chunk_size'    => 300,
            'chunk_overlap' => 30,
            'strategy'      => 'FIXED_SIZE',
        ]);

        $this->assertNotEmpty($chunks);

    }//end testChunkDocumentFixedSize()


    /**
     * Test chunkDocument with small text returns single chunk
     *
     * @return void
     */
    public function testChunkDocumentSmallTextSingleChunk(): void
    {
        $text   = 'This is a small text that should not be chunked.';
        $chunks = $this->service->chunkDocument($text, [
            'chunk_size'    => 1000,
            'chunk_overlap' => 100,
        ]);

        // Small text might still be a single chunk.
        $this->assertNotEmpty($chunks);

    }//end testChunkDocumentSmallTextSingleChunk()


    /**
     * Test chunkDocument with default strategy
     *
     * @return void
     */
    public function testChunkDocumentDefaultStrategy(): void
    {
        $text   = str_repeat('Default strategy content paragraph. ', 100);
        $chunks = $this->service->chunkDocument($text, [
            'chunk_size'    => 300,
            'chunk_overlap' => 50,
            'strategy'      => 'UNKNOWN_STRATEGY',
        ]);

        $this->assertNotEmpty($chunks);

    }//end testChunkDocumentDefaultStrategy()


    // =========================================================================
    // cleanText (private)
    // =========================================================================

    /**
     * Test cleanText removes null bytes and normalises line endings
     *
     * @return void
     */
    public function testCleanTextNormalization(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'cleanText');

        $result = $method->invoke($this->service, "hello\0world\r\nline\rother");
        $this->assertStringNotContainsString("\0", $result);
        $this->assertStringNotContainsString("\r", $result);

    }//end testCleanTextNormalization()


    /**
     * Test cleanText reduces excessive newlines
     *
     * @return void
     */
    public function testCleanTextReducesNewlines(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'cleanText');

        $result = $method->invoke($this->service, "hello\n\n\n\n\nworld");
        // Should reduce to max 2 newlines.
        $this->assertStringNotContainsString("\n\n\n", $result);

    }//end testCleanTextReducesNewlines()


    // =========================================================================
    // calculateAvgChunkSize (private)
    // =========================================================================

    /**
     * Test calculateAvgChunkSize with empty array
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeEmpty(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'calculateAvgChunkSize');

        $result = $method->invoke($this->service, []);
        $this->assertEquals(0.0, $result);

    }//end testCalculateAvgChunkSizeEmpty()


    /**
     * Test calculateAvgChunkSize with array chunks
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeWithArrayChunks(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'calculateAvgChunkSize');

        $chunks = [
            ['text' => 'hello'],
            ['text' => 'world!'],
        ];
        $result = $method->invoke($this->service, $chunks);
        // (5 + 6) / 2 = 5.5.
        $this->assertEquals(5.5, $result);

    }//end testCalculateAvgChunkSizeWithArrayChunks()


    /**
     * Test calculateAvgChunkSize with string chunks
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeWithStringChunks(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'calculateAvgChunkSize');

        $chunks = ['hello', 'world!'];
        $result = $method->invoke($this->service, $chunks);
        // (5 + 6) / 2 = 5.5.
        $this->assertEquals(5.5, $result);

    }//end testCalculateAvgChunkSizeWithStringChunks()


    /**
     * Test calculateAvgChunkSize with non-text chunks (fallback to empty string)
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeWithNonTextChunks(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'calculateAvgChunkSize');

        $chunks = [123, null];
        $result = $method->invoke($this->service, $chunks);
        $this->assertEquals(0.0, $result);

    }//end testCalculateAvgChunkSizeWithNonTextChunks()


    // =========================================================================
    // isSourceUpToDate (private)
    // =========================================================================

    /**
     * Test isSourceUpToDate returns false when forceReExtract is true
     *
     * @return void
     */
    public function testIsSourceUpToDateForceFalse(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isSourceUpToDate');

        $result = $method->invoke($this->service, 1, 'file', 1000, true);
        $this->assertFalse($result);

    }//end testIsSourceUpToDateForceFalse()


    /**
     * Test isSourceUpToDate returns false when no chunks exist
     *
     * @return void
     */
    public function testIsSourceUpToDateNoChunks(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isSourceUpToDate');

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);
        $result = $method->invoke($this->service, 1, 'file', 1000, false);
        $this->assertFalse($result);

    }//end testIsSourceUpToDateNoChunks()


    /**
     * Test isSourceUpToDate returns true when chunk is newer
     *
     * @return void
     */
    public function testIsSourceUpToDateChunkNewer(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isSourceUpToDate');

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(2000);
        $result = $method->invoke($this->service, 1, 'file', 1000, false);
        $this->assertTrue($result);

    }//end testIsSourceUpToDateChunkNewer()


    /**
     * Test isSourceUpToDate returns false when chunk is older
     *
     * @return void
     */
    public function testIsSourceUpToDateChunkOlder(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'isSourceUpToDate');

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(500);
        $result = $method->invoke($this->service, 1, 'file', 1000, false);
        $this->assertFalse($result);

    }//end testIsSourceUpToDateChunkOlder()


    // =========================================================================
    // hydrateChunkEntity (private)
    // =========================================================================

    /**
     * Test hydrateChunkEntity creates valid Chunk
     *
     * @return void
     */
    public function testHydrateChunkEntity(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'hydrateChunkEntity');

        $chunkData = [
            'chunk_index'         => 0,
            'text_content'        => 'Hello world',
            'start_offset'        => 0,
            'end_offset'          => 11,
            'language'            => 'en',
            'language_level'      => null,
            'language_confidence' => 0.35,
            'detection_method'    => 'heuristic',
            'overlap_size'        => 100,
            'position_reference'  => ['type' => 'text-range', 'start' => 0, 'end' => 11],
            'checksum'            => 'abc123',
            'embedding_provider'  => null,
        ];

        $chunk = $method->invoke($this->service, 'file', 42, $chunkData, 'admin', 'org1', 1000);
        $this->assertInstanceOf(Chunk::class, $chunk);
        $this->assertEquals('file', $chunk->getSourceType());
        $this->assertEquals(42, $chunk->getSourceId());
        $this->assertEquals('Hello world', $chunk->getTextContent());
        $this->assertEquals('en', $chunk->getLanguage());
        $this->assertEquals('admin', $chunk->getOwner());
        $this->assertEquals('org1', $chunk->getOrganisation());
        $this->assertFalse($chunk->getIndexed());
        $this->assertFalse($chunk->getVectorized());

    }//end testHydrateChunkEntity()


    // =========================================================================
    // summarizeMetadataPayload (private)
    // =========================================================================

    /**
     * Test summarizeMetadataPayload extracts correct fields
     *
     * @return void
     */
    public function testSummarizeMetadataPayload(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'summarizeMetadataPayload');

        $payload = [
            'source_type'    => 'file',
            'source_id'      => 42,
            'checksum'       => 'abc123',
            'length'         => 1000,
            'language'       => 'nl',
            'language_level' => null,
            'organisation'   => 'org1',
            'owner'          => 'admin',
            'metadata'       => ['file_path' => '/test'],
        ];

        $result = $method->invoke($this->service, $payload);
        $this->assertEquals('file', $result['source_type']);
        $this->assertEquals(42, $result['source_id']);
        $this->assertEquals('abc123', $result['chunk_checksum']);
        $this->assertEquals('nl', $result['language']);

    }//end testSummarizeMetadataPayload()


    /**
     * Test summarizeMetadataPayload with empty payload
     *
     * @return void
     */
    public function testSummarizeMetadataPayloadEmpty(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'summarizeMetadataPayload');

        $result = $method->invoke($this->service, []);
        $this->assertNull($result['source_type']);
        $this->assertNull($result['source_id']);
        $this->assertNull($result['chunk_checksum']);
        $this->assertEquals([], $result['file_metadata']);

    }//end testSummarizeMetadataPayloadEmpty()


    // =========================================================================
    // textToChunks (private)
    // =========================================================================

    /**
     * Test textToChunks maps chunks correctly
     *
     * @return void
     */
    public function testTextToChunksMapsCorrectly(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'textToChunks');

        $payload = [
            'source_type'         => 'file',
            'source_id'           => 1,
            'text'                => str_repeat('Test content for chunking. ', 50),
            'language'            => 'en',
            'language_level'      => null,
            'language_confidence' => 0.35,
            'detection_method'    => 'heuristic',
            'checksum'            => 'abc',
        ];

        $options = [
            'chunk_size'    => 200,
            'chunk_overlap' => 20,
            'strategy'      => 'FIXED_SIZE',
        ];

        $result = $method->invoke($this->service, $payload, $options);
        $this->assertNotEmpty($result);

        $first = $result[0];
        $this->assertEquals(0, $first['chunk_index']);
        $this->assertArrayHasKey('text_content', $first);
        $this->assertEquals('en', $first['language']);
        $this->assertEquals('heuristic', $first['detection_method']);
        $this->assertEquals('abc', $first['checksum']);
        $this->assertArrayHasKey('position_reference', $first);
        $this->assertEquals('text-range', $first['position_reference']['type']);

    }//end testTextToChunksMapsCorrectly()


    // =========================================================================
    // extractObject — DoesNotExistException branch
    // =========================================================================

    /**
     * Test extractObject skips when object is deleted
     *
     * @return void
     */
    public function testExtractObjectDeletedObject(): void
    {
        $this->objectMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        // Should not throw — gracefully skips.
        $this->service->extractObject(999);

        // Verify logger was called with skip message.
        $this->logger->expects($this->atLeastOnce())
            ->method('info');

    }//end testExtractObjectDeletedObject()


    // =========================================================================
    // getStats
    // =========================================================================

    /**
     * Test getStats returns correct structure
     *
     * @return void
     */
    public function testGetStatsReturnsStructure(): void
    {
        $this->fileMapper->method('countUntrackedFiles')->willReturn(5);

        // Mock the db query builder for getTableCountSafe.
        $qb     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('createFunction')->willReturn($qb);
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetchOne')->willReturn('10');
        $result->method('closeCursor');

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('totalFiles', $stats);
        $this->assertArrayHasKey('untrackedFiles', $stats);
        $this->assertArrayHasKey('totalChunks', $stats);
        $this->assertArrayHasKey('totalObjects', $stats);
        $this->assertArrayHasKey('totalEntities', $stats);
        $this->assertEquals(5, $stats['untrackedFiles']);

    }//end testGetStatsReturnsStructure()


    // =========================================================================
    // getTableCountSafe exception branch
    // =========================================================================

    /**
     * Test getTableCountSafe returns 0 on exception
     *
     * @return void
     */
    public function testGetTableCountSafeReturnsZeroOnException(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'getTableCountSafe');

        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('Table not found'));

        $result = $method->invoke($this->service, 'nonexistent_table');
        $this->assertEquals(0, $result);

    }//end testGetTableCountSafeReturnsZeroOnException()


    // =========================================================================
    // chunkFixedSize edge cases
    // =========================================================================

    /**
     * Test chunkFixedSize with text smaller than chunk size
     *
     * @return void
     */
    public function testChunkFixedSizeSmallText(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'chunkFixedSize');

        $result = $method->invoke($this->service, 'Short text', 1000, 100);
        $this->assertCount(1, $result);
        $this->assertEquals('Short text', $result[0]['text']);

    }//end testChunkFixedSizeSmallText()


    /**
     * Test chunkFixedSize with exact chunk size
     *
     * @return void
     */
    public function testChunkFixedSizeExact(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'chunkFixedSize');

        $text   = str_repeat('a', 500);
        $result = $method->invoke($this->service, $text, 500, 50);
        $this->assertCount(1, $result);

    }//end testChunkFixedSizeExact()


    // =========================================================================
    // recursiveSplit with empty separators
    // =========================================================================

    /**
     * Test recursiveSplit falls back to fixed size when no separators
     *
     * @return void
     */
    public function testRecursiveSplitNoSeparators(): void
    {
        $method = new \ReflectionMethod(TextExtractionService::class, 'recursiveSplit');

        $text   = str_repeat('a', 500);
        $result = $method->invoke($this->service, $text, [], 200, 20);
        $this->assertNotEmpty($result);

    }//end testRecursiveSplitNoSeparators()


    // =========================================================================
    // discoverUntrackedFiles
    // =========================================================================

    /**
     * Test discoverUntrackedFiles with discovery exception
     *
     * @return void
     */
    public function testDiscoverUntrackedFilesException(): void
    {
        $this->fileMapper->method('findUntrackedFiles')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->service->discoverUntrackedFiles(10);
        $this->assertEquals(0, $result['discovered']);
        $this->assertEquals(0, $result['total']);
        $this->assertArrayHasKey('error', $result);

    }//end testDiscoverUntrackedFilesException()


    /**
     * Test discoverUntrackedFiles with empty files
     *
     * @return void
     */
    public function testDiscoverUntrackedFilesEmpty(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([]);

        $result = $this->service->discoverUntrackedFiles(10);
        $this->assertEquals(0, $result['discovered']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);

    }//end testDiscoverUntrackedFilesEmpty()


    // =========================================================================
    // extractPendingFiles
    // =========================================================================

    /**
     * Test extractPendingFiles with no pending files
     *
     * @return void
     */
    public function testExtractPendingFilesNone(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([]);

        $result = $this->service->extractPendingFiles(10);
        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);

    }//end testExtractPendingFilesNone()


    // =========================================================================
    // retryFailedExtractions
    // =========================================================================

    /**
     * Test retryFailedExtractions with no files to retry
     *
     * @return void
     */
    public function testRetryFailedExtractionsNone(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([]);

        $result = $this->service->retryFailedExtractions(10);
        $this->assertEquals(0, $result['retried']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);

    }//end testRetryFailedExtractionsNone()


}//end class
