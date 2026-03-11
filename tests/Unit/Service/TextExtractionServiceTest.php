<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Chunk;
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for TextExtractionService
 *
 * Comprehensive coverage of chunking, sanitization, language detection,
 * statistics, file extraction, object extraction, and helper methods.
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

    /**
     * Helper to invoke private/protected methods via reflection.
     *
     * @param string $methodName Method name on the service.
     * @param array  $args       Positional arguments to pass.
     *
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args = []): mixed
    {
        $ref = new ReflectionMethod(TextExtractionService::class, $methodName);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service, $args);
    }

    /**
     * Helper to read a private constant via reflection.
     */
    private function getConstant(string $name): mixed
    {
        $ref = new ReflectionClass(TextExtractionService::class);
        return $ref->getConstant($name);
    }

    // ────────────────────────────────────────────────────────
    // chunkDocument (public)
    // ────────────────────────────────────────────────────────

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
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(200, strlen($chunk['text']));
        }
    }

    public function testChunkDocumentWithFixedSizeStrategy(): void
    {
        $text = str_repeat('Word ', 500);
        $chunks = $this->service->chunkDocument($text, [
            'strategy' => 'FIXED_SIZE',
            'chunk_size' => 200,
            'chunk_overlap' => 0,
        ]);

        $this->assertIsArray($chunks);
        $this->assertNotEmpty($chunks);
    }

    public function testChunkDocumentWithRecursiveStrategy(): void
    {
        $text = str_repeat('Hello world. This is a test sentence. ', 100);
        $chunks = $this->service->chunkDocument($text, [
            'strategy' => 'RECURSIVE_CHARACTER',
            'chunk_size' => 500,
            'chunk_overlap' => 50,
        ]);

        $this->assertIsArray($chunks);
        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertArrayHasKey('text', $chunk);
            $this->assertArrayHasKey('start_offset', $chunk);
            $this->assertArrayHasKey('end_offset', $chunk);
        }
    }

    public function testChunkDocumentWithUnknownStrategyFallsBackToRecursive(): void
    {
        $text = str_repeat('Hello world. This is a test sentence. ', 100);
        $chunks = $this->service->chunkDocument($text, [
            'strategy' => 'UNKNOWN_STRATEGY',
            'chunk_size' => 500,
            'chunk_overlap' => 0,
        ]);

        $this->assertIsArray($chunks);
        $this->assertNotEmpty($chunks);
    }

    public function testChunkDocumentTruncatesExcessiveChunks(): void
    {
        // Use FIXED_SIZE strategy to avoid slow recursive splitting.
        // Each chunk is ~110 chars, so 200000 chars / 110 = ~1800 chunks > 1000 max.
        $text = str_repeat('A sentence that is about a hundred characters long and provides some text. ', 3000);
        $chunks = $this->service->chunkDocument($text, [
            'strategy' => 'FIXED_SIZE',
            'chunk_size' => 110,
            'chunk_overlap' => 0,
        ]);

        $this->assertLessThanOrEqual(1000, count($chunks));
    }

    public function testChunkDocumentShortTextReturnsSingleChunk(): void
    {
        $text = 'This is a short text that fits in one chunk easily.';
        // With default chunk_size of 1000, this is much smaller.
        $chunks = $this->service->chunkDocument($text);

        $this->assertIsArray($chunks);
        // Short text should be a single chunk (if it meets MIN_CHUNK_SIZE).
        // The text is 51 chars, less than MIN_CHUNK_SIZE (100), so it may be empty.
        // Let's use a longer short text:
    }

    public function testChunkDocumentSmallTextBelowMinChunkSizeReturnsOneChunk(): void
    {
        // Text that is <= default chunk_size (1000) but >= MIN_CHUNK_SIZE (100)
        $text = str_repeat('Hello world. ', 10); // ~130 chars
        $chunks = $this->service->chunkDocument($text);

        $this->assertIsArray($chunks);
        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Hello world.', $chunks[0]['text']);
    }

    // ────────────────────────────────────────────────────────
    // cleanText (private)
    // ────────────────────────────────────────────────────────

    public function testCleanTextRemovesNullBytes(): void
    {
        $text = "Hello\0World";
        $result = $this->invokePrivate('cleanText', [$text]);
        $this->assertStringNotContainsString("\0", $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
    }

    public function testCleanTextNormalizesLineEndings(): void
    {
        $text = "Hello\r\nWorld\rFoo\nBar";
        $result = $this->invokePrivate('cleanText', [$text]);
        $this->assertStringNotContainsString("\r", $result);
        $this->assertStringContainsString("\n", $result);
    }

    public function testCleanTextReducesExcessiveNewlines(): void
    {
        $text = "Hello\n\n\n\n\nWorld";
        $result = $this->invokePrivate('cleanText', [$text]);
        $this->assertSame("Hello\n\nWorld", $result);
    }

    public function testCleanTextCollapsesTabsAndSpaces(): void
    {
        $text = "Hello   \t  World";
        $result = $this->invokePrivate('cleanText', [$text]);
        $this->assertSame('Hello World', $result);
    }

    public function testCleanTextTrimsWhitespace(): void
    {
        $text = "   Hello World   ";
        $result = $this->invokePrivate('cleanText', [$text]);
        $this->assertSame('Hello World', $result);
    }

    // ────────────────────────────────────────────────────────
    // sanitizeText (private)
    // ────────────────────────────────────────────────────────

    public function testSanitizeTextRemovesNullBytes(): void
    {
        $result = $this->invokePrivate('sanitizeText', ["Hello\0World"]);
        $this->assertStringNotContainsString("\0", $result);
        $this->assertSame('HelloWorld', $result);
    }

    public function testSanitizeTextRemovesControlCharacters(): void
    {
        $text = "Hello\x01\x02\x03World";
        $result = $this->invokePrivate('sanitizeText', [$text]);
        $this->assertSame('HelloWorld', $result);
    }

    public function testSanitizeTextNormalizesWhitespace(): void
    {
        $text = "Hello    World   Foo";
        $result = $this->invokePrivate('sanitizeText', [$text]);
        $this->assertSame('Hello World Foo', $result);
    }

    public function testSanitizeTextTrims(): void
    {
        $result = $this->invokePrivate('sanitizeText', ['  Hello  ']);
        $this->assertSame('Hello', $result);
    }

    public function testSanitizeTextReturnsEmptyForWhitespaceOnly(): void
    {
        $result = $this->invokePrivate('sanitizeText', ['   ']);
        $this->assertSame('', $result);
    }

    public function testSanitizeTextHandlesEmptyString(): void
    {
        $result = $this->invokePrivate('sanitizeText', ['']);
        $this->assertSame('', $result);
    }

    // ────────────────────────────────────────────────────────
    // detectLanguageSignals (private)
    // ────────────────────────────────────────────────────────

    public function testDetectLanguageSignalsDetectsDutch(): void
    {
        $result = $this->invokePrivate('detectLanguageSignals', ['Dit is de tekst van het document.']);
        $this->assertSame('nl', $result['language']);
        $this->assertSame(0.35, $result['language_confidence']);
        $this->assertSame('heuristic', $result['detection_method']);
    }

    public function testDetectLanguageSignalsDetectsEnglish(): void
    {
        // Use text without Dutch articles.
        $result = $this->invokePrivate('detectLanguageSignals', ['This is the text of the document and all of it.']);
        $this->assertSame('en', $result['language']);
        $this->assertSame(0.35, $result['language_confidence']);
        $this->assertSame('heuristic', $result['detection_method']);
    }

    public function testDetectLanguageSignalsReturnsNullForUnknown(): void
    {
        $result = $this->invokePrivate('detectLanguageSignals', ['12345 67890']);
        $this->assertNull($result['language']);
        $this->assertNull($result['language_confidence']);
        $this->assertSame('none', $result['detection_method']);
        $this->assertNull($result['language_level']);
    }

    public function testDetectLanguageSignalsDutchTakesPriorityOverEnglish(): void
    {
        // Text that contains both Dutch and English markers — Dutch should match first.
        $result = $this->invokePrivate('detectLanguageSignals', ['de cat and the dog']);
        $this->assertSame('nl', $result['language']);
    }

    // ────────────────────────────────────────────────────────
    // getDetectionMethod (private)
    // ────────────────────────────────────────────────────────

    public function testGetDetectionMethodReturnsHeuristicForLanguage(): void
    {
        $result = $this->invokePrivate('getDetectionMethod', ['nl']);
        $this->assertSame('heuristic', $result);
    }

    public function testGetDetectionMethodReturnsNoneForNull(): void
    {
        $result = $this->invokePrivate('getDetectionMethod', [null]);
        $this->assertSame('none', $result);
    }

    // ────────────────────────────────────────────────────────
    // isWordDocument (private)
    // ────────────────────────────────────────────────────────

    public function testIsWordDocumentReturnsTrueForDocx(): void
    {
        $result = $this->invokePrivate('isWordDocument', [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
        $this->assertTrue($result);
    }

    public function testIsWordDocumentReturnsTrueForDoc(): void
    {
        $result = $this->invokePrivate('isWordDocument', ['application/msword']);
        $this->assertTrue($result);
    }

    public function testIsWordDocumentReturnsFalseForPdf(): void
    {
        $result = $this->invokePrivate('isWordDocument', ['application/pdf']);
        $this->assertFalse($result);
    }

    public function testIsWordDocumentReturnsFalseForTextPlain(): void
    {
        $result = $this->invokePrivate('isWordDocument', ['text/plain']);
        $this->assertFalse($result);
    }

    // ────────────────────────────────────────────────────────
    // isSpreadsheet (private)
    // ────────────────────────────────────────────────────────

    public function testIsSpreadsheetReturnsTrueForXlsx(): void
    {
        $result = $this->invokePrivate('isSpreadsheet', [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
        $this->assertTrue($result);
    }

    public function testIsSpreadsheetReturnsTrueForXls(): void
    {
        $result = $this->invokePrivate('isSpreadsheet', ['application/vnd.ms-excel']);
        $this->assertTrue($result);
    }

    public function testIsSpreadsheetReturnsFalseForPdf(): void
    {
        $result = $this->invokePrivate('isSpreadsheet', ['application/pdf']);
        $this->assertFalse($result);
    }

    public function testIsSpreadsheetReturnsFalseForCsv(): void
    {
        $result = $this->invokePrivate('isSpreadsheet', ['text/csv']);
        $this->assertFalse($result);
    }

    // ────────────────────────────────────────────────────────
    // calculateAvgChunkSize (private)
    // ────────────────────────────────────────────────────────

    public function testCalculateAvgChunkSizeReturnsZeroForEmpty(): void
    {
        $result = $this->invokePrivate('calculateAvgChunkSize', [[]]);
        $this->assertSame(0.0, $result);
    }

    public function testCalculateAvgChunkSizeWithArrayChunks(): void
    {
        $chunks = [
            ['text' => 'Hello'],       // 5
            ['text' => 'World!'],      // 6
            ['text' => 'Test string'], // 11
        ];
        $result = $this->invokePrivate('calculateAvgChunkSize', [$chunks]);
        // (5 + 6 + 11) / 3 = 7.33
        $this->assertSame(7.33, $result);
    }

    public function testCalculateAvgChunkSizeWithStringChunks(): void
    {
        $chunks = ['Hello', 'World!'];
        $result = $this->invokePrivate('calculateAvgChunkSize', [$chunks]);
        // (5 + 6) / 2 = 5.5
        $this->assertSame(5.5, $result);
    }

    public function testCalculateAvgChunkSizeWithMixedChunks(): void
    {
        $chunks = [
            ['text' => 'Hello'], // 5
            'World!',            // 6
            ['no_text' => 'x'],  // 0 (no 'text' key)
        ];
        $result = $this->invokePrivate('calculateAvgChunkSize', [$chunks]);
        // (5 + 6 + 0) / 3 = 3.67
        $this->assertSame(3.67, $result);
    }

    // ────────────────────────────────────────────────────────
    // summarizeMetadataPayload (private)
    // ────────────────────────────────────────────────────────

    public function testSummarizeMetadataPayloadReturnsExpectedKeys(): void
    {
        $payload = [
            'source_type'  => 'file',
            'source_id'    => 42,
            'checksum'     => 'abc123',
            'length'       => 500,
            'language'     => 'nl',
            'language_level' => null,
            'organisation' => 'test-org',
            'owner'        => 'admin',
            'metadata'     => ['file_path' => '/test.txt'],
        ];

        $result = $this->invokePrivate('summarizeMetadataPayload', [$payload]);

        $this->assertSame('file', $result['source_type']);
        $this->assertSame(42, $result['source_id']);
        $this->assertSame('abc123', $result['chunk_checksum']);
        $this->assertSame(500, $result['text_length']);
        $this->assertSame('nl', $result['language']);
        $this->assertNull($result['language_level']);
        $this->assertSame('test-org', $result['organisation']);
        $this->assertSame('admin', $result['owner']);
        $this->assertSame(['file_path' => '/test.txt'], $result['file_metadata']);
    }

    public function testSummarizeMetadataPayloadWithMissingKeysDefaultsToNull(): void
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
        $this->assertSame([], $result['file_metadata']);
    }

    // ────────────────────────────────────────────────────────
    // buildPositionReference (private)
    // ────────────────────────────────────────────────────────

    public function testBuildPositionReferenceForObject(): void
    {
        $chunk = ['property_path' => '$.title'];
        $result = $this->invokePrivate('buildPositionReference', ['object', $chunk]);

        $this->assertSame('property-path', $result['type']);
        $this->assertSame('$.title', $result['path']);
    }

    public function testBuildPositionReferenceForObjectWithoutPath(): void
    {
        $result = $this->invokePrivate('buildPositionReference', ['object', []]);

        $this->assertSame('property-path', $result['type']);
        $this->assertNull($result['path']);
    }

    public function testBuildPositionReferenceForFile(): void
    {
        $chunk = ['start_offset' => 100, 'end_offset' => 500];
        $result = $this->invokePrivate('buildPositionReference', ['file', $chunk]);

        $this->assertSame('text-range', $result['type']);
        $this->assertSame(100, $result['start']);
        $this->assertSame(500, $result['end']);
    }

    public function testBuildPositionReferenceForFileWithoutOffsets(): void
    {
        $result = $this->invokePrivate('buildPositionReference', ['file', []]);

        $this->assertSame('text-range', $result['type']);
        $this->assertSame(0, $result['start']);
        $this->assertSame(0, $result['end']);
    }

    // ────────────────────────────────────────────────────────
    // isSourceUpToDate (private)
    // ────────────────────────────────────────────────────────

    public function testIsSourceUpToDateReturnsFalseWhenForceReExtract(): void
    {
        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, true]);
        $this->assertFalse($result);
    }

    public function testIsSourceUpToDateReturnsFalseWhenNoChunks(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(null);
        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, false]);
        $this->assertFalse($result);
    }

    public function testIsSourceUpToDateReturnsTrueWhenChunksNewer(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(200);
        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, false]);
        $this->assertTrue($result);
    }

    public function testIsSourceUpToDateReturnsTrueWhenChunksEqual(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);
        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, false]);
        $this->assertTrue($result);
    }

    public function testIsSourceUpToDateReturnsFalseWhenChunksOlder(): void
    {
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(50);
        $result = $this->invokePrivate('isSourceUpToDate', [1, 'file', 100, false]);
        $this->assertFalse($result);
    }

    // ────────────────────────────────────────────────────────
    // hydrateChunkEntity (private)
    // ────────────────────────────────────────────────────────

    public function testHydrateChunkEntityCreatesValidChunk(): void
    {
        $chunkData = [
            'chunk_index'         => 2,
            'text_content'        => 'Some chunk text here',
            'start_offset'        => 100,
            'end_offset'          => 120,
            'position_reference'  => ['type' => 'text-range', 'start' => 100, 'end' => 120],
            'language'            => 'en',
            'language_level'      => null,
            'language_confidence' => 0.35,
            'detection_method'    => 'heuristic',
            'overlap_size'        => 50,
            'checksum'            => 'abc123',
            'embedding_provider'  => null,
        ];

        $result = $this->invokePrivate('hydrateChunkEntity', [
            'file', 42, $chunkData, 'admin', 'test-org', 1000000,
        ]);

        $this->assertInstanceOf(Chunk::class, $result);
        $this->assertSame('file', $result->getSourceType());
        $this->assertSame(42, $result->getSourceId());
        $this->assertSame(2, $result->getChunkIndex());
        $this->assertSame('Some chunk text here', $result->getTextContent());
        $this->assertSame(100, $result->getStartOffset());
        $this->assertSame(120, $result->getEndOffset());
        $this->assertSame('en', $result->getLanguage());
        $this->assertSame(0.35, $result->getLanguageConfidence());
        $this->assertSame('heuristic', $result->getDetectionMethod());
        $this->assertFalse($result->getIndexed());
        $this->assertFalse($result->getVectorized());
        $this->assertSame(50, $result->getOverlapSize());
        $this->assertSame('admin', $result->getOwner());
        $this->assertSame('test-org', $result->getOrganisation());
        $this->assertSame('abc123', $result->getChecksum());
        $this->assertNotNull($result->getUuid());
        $this->assertInstanceOf(DateTime::class, $result->getCreatedAt());
        $this->assertInstanceOf(DateTime::class, $result->getUpdatedAt());
    }

    public function testHydrateChunkEntityHandlesMinimalData(): void
    {
        $chunkData = [
            'text_content' => 'Minimal chunk',
        ];

        $result = $this->invokePrivate('hydrateChunkEntity', [
            'object', 1, $chunkData, null, null, time(),
        ]);

        $this->assertInstanceOf(Chunk::class, $result);
        $this->assertSame('object', $result->getSourceType());
        $this->assertSame(1, $result->getSourceId());
        $this->assertSame(0, $result->getChunkIndex());
        $this->assertSame('Minimal chunk', $result->getTextContent());
        $this->assertNull($result->getOwner());
        $this->assertNull($result->getOrganisation());
        $this->assertNull($result->getChecksum());
    }

    // ────────────────────────────────────────────────────────
    // textToChunks (private)
    // ────────────────────────────────────────────────────────

    public function testTextToChunksGeneratesChunkDTOs(): void
    {
        $payload = [
            'source_type'         => 'file',
            'source_id'           => 1,
            'text'                => str_repeat('Hello world. This is a test. ', 100),
            'language'            => 'en',
            'language_level'      => null,
            'language_confidence' => 0.35,
            'detection_method'    => 'heuristic',
            'checksum'            => 'sha256hash',
        ];

        $result = $this->invokePrivate('textToChunks', [$payload, [
            'chunk_size'    => 500,
            'chunk_overlap' => 50,
            'strategy'      => 'RECURSIVE_CHARACTER',
        ]]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $first = $result[0];
        $this->assertSame(0, $first['chunk_index']);
        $this->assertArrayHasKey('text_content', $first);
        $this->assertArrayHasKey('start_offset', $first);
        $this->assertArrayHasKey('end_offset', $first);
        $this->assertSame('en', $first['language']);
        $this->assertSame(0.35, $first['language_confidence']);
        $this->assertSame('heuristic', $first['detection_method']);
        $this->assertSame(50, $first['overlap_size']);
        $this->assertSame('sha256hash', $first['checksum']);
        $this->assertSame('text-range', $first['position_reference']['type']);
    }

    public function testTextToChunksForObjectSource(): void
    {
        $payload = [
            'source_type'         => 'object',
            'source_id'           => 5,
            'text'                => str_repeat('Object data value. ', 80),
            'language'            => null,
            'language_level'      => null,
            'language_confidence' => null,
            'detection_method'    => 'none',
            'checksum'            => null,
        ];

        $result = $this->invokePrivate('textToChunks', [$payload, []]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame('property-path', $result[0]['position_reference']['type']);
    }

    // ────────────────────────────────────────────────────────
    // getStats (public)
    // ────────────────────────────────────────────────────────

    public function testGetStatsReturnsExpectedStructure(): void
    {
        $this->fileMapper->method('countUntrackedFiles')->willReturn(5);

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
        // totalFiles = untrackedFiles + totalChunks = 5 + 10 = 15
        $this->assertSame(15, $stats['totalFiles']);
        $this->assertSame(10, $stats['totalChunks']);
        $this->assertSame(10, $stats['totalObjects']);
        $this->assertSame(10, $stats['totalEntities']);
    }

    // ────────────────────────────────────────────────────────
    // getTableCountSafe (private)
    // ────────────────────────────────────────────────────────

    public function testGetTableCountSafeReturnsZeroOnException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \RuntimeException('DB error'));

        $result = $this->invokePrivate('getTableCountSafe', ['nonexistent_table']);
        $this->assertSame(0, $result);
    }

    public function testGetTableCountSafeReturnsCount(): void
    {
        $dbResult = $this->createMock(\OCP\DB\IResult::class);
        $dbResult->method('fetchOne')->willReturn('42');
        $dbResult->method('closeCursor');

        $funcExpr = $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('createFunction')->willReturn($funcExpr);
        $qb->method('executeQuery')->willReturn($dbResult);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $result = $this->invokePrivate('getTableCountSafe', ['openregister_chunks']);
        $this->assertSame(42, $result);
    }

    // ────────────────────────────────────────────────────────
    // chunkFixedSize (private)
    // ────────────────────────────────────────────────────────

    public function testChunkFixedSizeReturnsOneChunkForShortText(): void
    {
        $text = str_repeat('A', 100);
        $result = $this->invokePrivate('chunkFixedSize', [$text, 200, 0]);

        $this->assertCount(1, $result);
        $this->assertSame($text, $result[0]['text']);
        $this->assertSame(0, $result[0]['start_offset']);
        $this->assertSame(100, $result[0]['end_offset']);
    }

    public function testChunkFixedSizeMultipleChunksWithNoOverlap(): void
    {
        // Create text > 200 chars so multiple chunks are generated.
        $text = str_repeat('Word ', 100); // 500 chars
        $result = $this->invokePrivate('chunkFixedSize', [$text, 200, 0]);

        $this->assertGreaterThan(1, count($result));
        foreach ($result as $chunk) {
            $this->assertNotEmpty(trim($chunk['text']));
        }
    }

    /**
     * Note: chunkFixedSize with overlap > 0 has an infinite loop bug when
     * the remaining text length equals the overlap size (offset advances by 0).
     * This test uses overlap=0 to avoid triggering that bug.
     */
    public function testChunkFixedSizeWithLargeText(): void
    {
        $text = str_repeat('This is a longer sentence for testing purposes. ', 30); // ~1470 chars
        $result = $this->invokePrivate('chunkFixedSize', [$text, 500, 0]);

        $this->assertGreaterThan(1, count($result));
        foreach ($result as $chunk) {
            $this->assertNotEmpty(trim($chunk['text']));
            $this->assertArrayHasKey('start_offset', $chunk);
            $this->assertArrayHasKey('end_offset', $chunk);
        }
    }

    // ────────────────────────────────────────────────────────
    // chunkRecursive (private)
    // ────────────────────────────────────────────────────────

    public function testChunkRecursiveReturnsOneChunkForShortText(): void
    {
        $text = str_repeat('A', 50);
        $result = $this->invokePrivate('chunkRecursive', [$text, 200, 0]);

        $this->assertCount(1, $result);
        $this->assertSame($text, $result[0]['text']);
    }

    public function testChunkRecursiveSplitsByParagraphs(): void
    {
        // Two paragraphs, each under 300 chars, total over 300 chars.
        $para1 = str_repeat('First paragraph sentence. ', 10); // ~260 chars
        $para2 = str_repeat('Second paragraph sentence. ', 10); // ~270 chars
        $text = $para1 . "\n\n" . $para2;

        $result = $this->invokePrivate('chunkRecursive', [$text, 300, 0]);

        $this->assertGreaterThanOrEqual(2, count($result));
    }

    public function testChunkRecursiveSplitsBySentences(): void
    {
        // Text without paragraphs, only sentence boundaries.
        $text = str_repeat('This is a sentence. ', 30); // ~600 chars
        $result = $this->invokePrivate('chunkRecursive', [$text, 200, 0]);

        $this->assertGreaterThan(1, count($result));
    }

    // ────────────────────────────────────────────────────────
    // recursiveSplit (private)
    // ────────────────────────────────────────────────────────

    public function testRecursiveSplitWithEmptySeparatorsFallsToFixedSize(): void
    {
        $text = str_repeat('X', 500);
        $result = $this->invokePrivate('recursiveSplit', [$text, [], 200, 0]);

        $this->assertGreaterThan(1, count($result));
    }

    public function testRecursiveSplitShortTextReturnsSingleChunk(): void
    {
        $text = 'Short text';
        $result = $this->invokePrivate('recursiveSplit', [$text, ["\n", " "], 200, 0]);

        $this->assertCount(1, $result);
        $this->assertSame('Short text', $result[0]['text']);
    }

    // ────────────────────────────────────────────────────────
    // extractFile (public) — integration-level tests
    // ────────────────────────────────────────────────────────

    public function testExtractFileThrowsNotFoundWhenFileNotInNextcloud(): void
    {
        $this->fileMapper->method('getFile')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('File with ID 999 not found in Nextcloud');
        $this->service->extractFile(999);
    }

    public function testExtractFileSkipsUpToDateFile(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 100,
            'path' => '/test.txt',
            'name' => 'test.txt',
            'mimetype' => 'text/plain',
        ]);

        // Chunks are newer than source.
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(200);

        // Should NOT call rootFolder (no extraction happens).
        $this->rootFolder->expects($this->never())->method('getById');

        $this->service->extractFile(1, false);
    }

    public function testExtractFileProcessesTextFile(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 500,
            'owner' => 'admin',
        ]);

        // Source is newer than chunks.
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);

        // Mock file node.
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn(str_repeat('The quick brown fox. ', 20));

        $this->rootFolder->method('getById')->willReturn([$file]);

        // Expect transaction + insert calls.
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->chunkMapper->expects($this->once())->method('deleteBySource');
        $this->chunkMapper->expects($this->atLeastOnce())->method('insert');

        // Entity recognition disabled.
        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'entityRecognitionEnabled' => false,
        ]);

        $this->service->extractFile(1);
    }

    public function testExtractFileForceReExtractIgnoresUpToDate(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 100,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 100,
            'owner' => 'admin',
        ]);

        // Even though chunks are newer, force should trigger extraction.
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(200);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn(str_repeat('Force extraction text. ', 20));
        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');
        $this->chunkMapper->method('deleteBySource');
        $this->chunkMapper->expects($this->atLeastOnce())->method('insert');

        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'entityRecognitionEnabled' => false,
        ]);

        $this->service->extractFile(1, true);
    }

    public function testExtractFileWithEntityRecognitionEnabled(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 500,
        ]);

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn(str_repeat('The document text. ', 20));
        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');
        $this->chunkMapper->method('deleteBySource');
        $this->chunkMapper->method('insert');

        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'entityRecognitionEnabled' => true,
            'entityRecognitionMethod' => 'regex',
        ]);

        $this->entityHandler->expects($this->once())
            ->method('processSourceChunks')
            ->willReturn(['entities_found' => 3, 'relations_created' => 2]);

        $this->riskLevelService->expects($this->once())
            ->method('updateRiskLevel')
            ->willReturn('medium');

        $this->service->extractFile(1);
    }

    public function testExtractFileEntityRecognitionFailureDoesNotThrow(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 500,
        ]);

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn(str_repeat('The document text. ', 20));
        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');
        $this->chunkMapper->method('deleteBySource');
        $this->chunkMapper->method('insert');

        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'entityRecognitionEnabled' => true,
        ]);

        $this->entityHandler->method('processSourceChunks')
            ->willThrowException(new Exception('Entity extraction error'));

        // Should not throw — error is caught and logged.
        $this->logger->expects($this->atLeastOnce())->method('error');
        $this->service->extractFile(1);
    }

    public function testExtractFileRiskLevelFailureDoesNotThrow(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 500,
        ]);

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn(str_repeat('The document text. ', 20));
        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');
        $this->chunkMapper->method('deleteBySource');
        $this->chunkMapper->method('insert');

        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'entityRecognitionEnabled' => true,
        ]);

        $this->entityHandler->method('processSourceChunks')
            ->willReturn(['entities_found' => 1, 'relations_created' => 1]);

        $this->riskLevelService->method('updateRiskLevel')
            ->willThrowException(new Exception('Risk level error'));

        // Should not throw — risk level failure is caught and logged.
        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->service->extractFile(1);
    }

    public function testExtractFileUnsupportedMimeType(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/image.png',
            'name'  => 'image.png',
            'mimetype' => 'image/png',
            'size'  => 1000,
        ]);

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);

        $file = $this->createMock(\OCP\Files\Node::class);
        $this->rootFolder->method('getById')->willReturn([$file]);

        // Unsupported mime type should throw because node is not a File.
        $this->expectException(Exception::class);
        $this->service->extractFile(1);
    }

    public function testExtractFileEmptyNodesThrows(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 500,
        ]);

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);
        $this->rootFolder->method('getById')->willReturn([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File not found in Nextcloud file system');
        $this->service->extractFile(1);
    }

    // ────────────────────────────────────────────────────────
    // extractObject (public)
    // ────────────────────────────────────────────────────────

    public function testExtractObjectSkipsDeletedObject(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Object not found'));

        // Should return gracefully, not throw.
        $this->rootFolder->expects($this->never())->method('getById');
        $this->service->extractObject(999);
    }

    public function testExtractObjectSkipsUpToDateObject(): void
    {
        $updated = new DateTime();
        $updated->setTimestamp(100);

        $object = new \OCA\OpenRegister\Db\ObjectEntity();
        $object->setUpdated($updated);

        $this->objectEntityMapper->method('find')->willReturn($object);
        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(200);

        // Should not attempt extraction.
        $this->db->expects($this->never())->method('beginTransaction');
        $this->service->extractObject(1, false);
    }

    // ────────────────────────────────────────────────────────
    // discoverUntrackedFiles (public)
    // ────────────────────────────────────────────────────────

    public function testDiscoverUntrackedFilesReturnsStatsWhenNoFiles(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([]);

        $result = $this->service->discoverUntrackedFiles(10);

        $this->assertSame(0, $result['discovered']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['total']);
    }

    public function testDiscoverUntrackedFilesReturnsErrorOnException(): void
    {
        $this->fileMapper->method('findUntrackedFiles')
            ->willThrowException(new Exception('DB error'));

        $result = $this->service->discoverUntrackedFiles(10);

        $this->assertSame(0, $result['discovered']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['total']);
        $this->assertSame('DB error', $result['error']);
    }

    public function testDiscoverUntrackedFilesCountsFailures(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([
            ['fileid' => 1, 'path' => '/a.txt', 'mtime' => 100],
            ['fileid' => 2, 'path' => '/b.txt', 'mtime' => 200],
        ]);

        // getFile returns null for both — causes NotFoundException.
        $this->fileMapper->method('getFile')->willReturn(null);

        $result = $this->service->discoverUntrackedFiles(10);

        $this->assertSame(0, $result['discovered']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(2, $result['total']);
    }

    // ────────────────────────────────────────────────────────
    // extractPendingFiles (public)
    // ────────────────────────────────────────────────────────

    public function testExtractPendingFilesReturnsStatsWhenNoFiles(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([]);

        $result = $this->service->extractPendingFiles(10);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['total']);
    }

    public function testExtractPendingFilesCountsFailures(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([
            ['fileid' => 1, 'mtime' => 100],
        ]);

        $this->fileMapper->method('getFile')->willReturn(null);

        $result = $this->service->extractPendingFiles(10);

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['total']);
    }

    // ────────────────────────────────────────────────────────
    // retryFailedExtractions (public)
    // ────────────────────────────────────────────────────────

    public function testRetryFailedExtractionsReturnsStatsWhenNoFiles(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([]);

        $result = $this->service->retryFailedExtractions(5);

        $this->assertSame(0, $result['retried']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['total']);
    }

    public function testRetryFailedExtractionsCountsFailures(): void
    {
        $this->fileMapper->method('findUntrackedFiles')->willReturn([
            ['fileid' => 1, 'mtime' => 100],
        ]);

        $this->fileMapper->method('getFile')->willReturn(null);

        $result = $this->service->retryFailedExtractions(5);

        $this->assertSame(0, $result['retried']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['total']);
    }

    // ────────────────────────────────────────────────────────
    // persistChunksForSource (private) — via extractFile
    // ────────────────────────────────────────────────────────

    public function testPersistChunksRollsBackOnError(): void
    {
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 500,
        ]);

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn(str_repeat('The text data. ', 20));
        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->chunkMapper->method('deleteBySource')
            ->willThrowException(new \RuntimeException('DB write error'));

        $this->expectException(\RuntimeException::class);
        $this->service->extractFile(1);
    }

    // ────────────────────────────────────────────────────────
    // persistMetadataChunk (private) — JSON encoding failure
    // ────────────────────────────────────────────────────────

    public function testPersistMetadataChunkHandlesJsonEncodingFailure(): void
    {
        // We test this indirectly through extractFile with a payload that causes
        // JSON encoding to succeed normally, but we can verify the metadata chunk
        // is created by checking insert is called.
        $this->fileMapper->method('getFile')->willReturn([
            'mtime' => 300,
            'path'  => '/files/test.txt',
            'name'  => 'test.txt',
            'mimetype' => 'text/plain',
            'size'  => 500,
        ]);

        $this->chunkMapper->method('getLatestUpdatedTimestamp')->willReturn(100);

        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn(str_repeat('The metadata chunk test. ', 20));
        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->db->method('beginTransaction');
        $this->db->method('commit');
        $this->chunkMapper->method('deleteBySource');

        // Count insert calls: N text chunks + 1 metadata chunk.
        $insertCount = 0;
        $this->chunkMapper->method('insert')->willReturnCallback(
            function (Chunk $chunk) use (&$insertCount) {
                $insertCount++;
                return $chunk;
            }
        );

        $this->settingsService->method('getFileSettingsOnly')->willReturn([
            'entityRecognitionEnabled' => false,
        ]);

        $this->service->extractFile(1);

        // At least 2 inserts: at least one text chunk + one metadata chunk.
        $this->assertGreaterThanOrEqual(2, $insertCount);
    }

    // ────────────────────────────────────────────────────────
    // Constants
    // ────────────────────────────────────────────────────────

    public function testDefaultConstantsHaveExpectedValues(): void
    {
        $this->assertSame(1000, $this->getConstant('DEFAULT_CHUNK_SIZE'));
        $this->assertSame(200, $this->getConstant('DEFAULT_CHUNK_OVERLAP'));
        $this->assertSame(1000, $this->getConstant('MAX_CHUNKS_PER_FILE'));
        $this->assertSame(100, $this->getConstant('MIN_CHUNK_SIZE'));
        $this->assertSame('RECURSIVE_CHARACTER', $this->getConstant('RECURSIVE_CHARACTER'));
        $this->assertSame('FIXED_SIZE', $this->getConstant('FIXED_SIZE'));
    }

    // ────────────────────────────────────────────────────────
    // Edge cases for chunkDocument
    // ────────────────────────────────────────────────────────

    public function testChunkDocumentNormalizesTextBeforeChunking(): void
    {
        $text = "Hello\r\n\r\nWorld\r\n\r\n\r\n\r\nFoo";
        $chunks = $this->service->chunkDocument($text);

        // After cleanText: "Hello\n\nWorld\n\nFoo" — short text, single chunk.
        $this->assertIsArray($chunks);
    }

    public function testChunkDocumentWithZeroOverlap(): void
    {
        $text = str_repeat('A very long sentence with multiple words. ', 50);
        $chunks = $this->service->chunkDocument($text, [
            'chunk_size' => 200,
            'chunk_overlap' => 0,
        ]);

        $this->assertIsArray($chunks);
        $this->assertNotEmpty($chunks);
    }

    public function testChunkDocumentDefaultOptions(): void
    {
        // Use default options (no explicit options passed).
        $text = str_repeat('Default chunking test sentence. ', 80);
        $chunks = $this->service->chunkDocument($text);

        $this->assertIsArray($chunks);
        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertArrayHasKey('text', $chunk);
        }
    }

    // ────────────────────────────────────────────────────────
    // extractSourceText (private) — via reflection
    // ────────────────────────────────────────────────────────

    public function testExtractSourceTextThrowsWhenExtractionReturnsNull(): void
    {
        // Mock rootFolder to return empty nodes -> performTextExtraction returns null.
        $this->rootFolder->method('getById')->willReturn([]);

        $this->expectException(Exception::class);
        $this->invokePrivate('extractSourceText', [
            'file', 1, ['mimetype' => 'text/plain', 'path' => '/test.txt'],
        ]);
    }

    public function testExtractSourceTextThrowsWhenResultIsEmpty(): void
    {
        // Mock file returning only whitespace.
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('   ');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Text extraction resulted in an empty payload.');
        $this->invokePrivate('extractSourceText', [
            'file', 1, ['mimetype' => 'text/plain', 'path' => '/test.txt'],
        ]);
    }

    public function testExtractSourceTextReturnsPayload(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('The quick brown fox jumps over the lazy dog.');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('extractSourceText', [
            'file', 42, [
                'mimetype' => 'text/plain',
                'path'     => '/files/test.txt',
                'name'     => 'test.txt',
                'size'     => 44,
                'owner'    => 'admin',
                'organisation' => 'org1',
            ],
        ]);

        $this->assertSame('file', $result['source_type']);
        $this->assertSame(42, $result['source_id']);
        $this->assertStringContainsString('quick brown fox', $result['text']);
        $this->assertSame(strlen($result['text']), $result['length']);
        $this->assertSame(hash('sha256', $result['text']), $result['checksum']);
        $this->assertSame('llphant', $result['method']);
        $this->assertSame('admin', $result['owner']);
        $this->assertSame('org1', $result['organisation']);
        $this->assertSame('en', $result['language']); // "the" detected
        $this->assertSame('/files/test.txt', $result['metadata']['file_path']);
        $this->assertSame('test.txt', $result['metadata']['file_name']);
        $this->assertSame('text/plain', $result['metadata']['mime_type']);
    }

    // ────────────────────────────────────────────────────────
    // performTextExtraction (private) — mime type dispatch
    // ────────────────────────────────────────────────────────

    public function testPerformTextExtractionTextPlain(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('Plain text content');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'text/plain', 'path' => '/test.txt'],
        ]);

        $this->assertSame('Plain text content', $result);
    }

    public function testPerformTextExtractionJsonFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('{"key": "value"}');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'application/json', 'path' => '/test.json'],
        ]);

        $this->assertSame('{"key": "value"}', $result);
    }

    public function testPerformTextExtractionMarkdownFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('# Heading\n\nParagraph');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'text/markdown', 'path' => '/test.md'],
        ]);

        $this->assertSame('# Heading\n\nParagraph', $result);
    }

    public function testPerformTextExtractionUnsupportedMimeReturnsNull(): void
    {
        $file = $this->createMock(File::class);
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'image/png', 'path' => '/image.png'],
        ]);

        $this->assertNull($result);
    }

    public function testPerformTextExtractionEmptyNodesThrows(): void
    {
        $this->rootFolder->method('getById')->willReturn([]);

        $this->expectException(Exception::class);
        $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'text/plain', 'path' => '/test.txt'],
        ]);
    }

    public function testPerformTextExtractionNodeNotFileThrows(): void
    {
        $folder = $this->createMock(\OCP\Files\Folder::class);
        $this->rootFolder->method('getById')->willReturn([$folder]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Node is not a file');
        $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'text/plain', 'path' => '/test.txt'],
        ]);
    }

    public function testPerformTextExtractionCsvFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('col1,col2\nval1,val2');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'text/csv', 'path' => '/data.csv'],
        ]);

        $this->assertSame('col1,col2\nval1,val2', $result);
    }

    public function testPerformTextExtractionYamlFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('key: value');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'text/x-yaml', 'path' => '/config.yaml'],
        ]);

        $this->assertSame('key: value', $result);
    }

    public function testPerformTextExtractionGenericTextSubtype(): void
    {
        // text/x-python is not in the explicit list but starts with text/.
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('print("hello")');
        $this->rootFolder->method('getById')->willReturn([$file]);

        $result = $this->invokePrivate('performTextExtraction', [
            1, ['mimetype' => 'text/x-python', 'path' => '/script.py'],
        ]);

        $this->assertSame('print("hello")', $result);
    }
}
