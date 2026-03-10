<?php

/**
 * Integration tests for TextExtractionService and EntityRecognitionHandler
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @group DB
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Integration tests for TextExtractionService and EntityRecognitionHandler
 *
 * Tests document chunking, text sanitization, language detection, entity recognition,
 * and various private methods via reflection to maximize PCOV coverage.
 *
 * @group DB
 */
class TextExtractionServiceIntegrationTest extends TestCase
{

    /**
     * The text extraction service instance.
     *
     * @var TextExtractionService
     */
    private TextExtractionService $service;

    /**
     * The entity recognition handler instance.
     *
     * @var EntityRecognitionHandler
     */
    private EntityRecognitionHandler $entityHandler;

    /**
     * Database connection for cleanup.
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Chunk mapper.
     *
     * @var ChunkMapper
     */
    private ChunkMapper $chunkMapper;

    /**
     * Entity mapper.
     *
     * @var GdprEntityMapper
     */
    private GdprEntityMapper $entityMapper;

    /**
     * Entity relation mapper.
     *
     * @var EntityRelationMapper
     */
    private EntityRelationMapper $entityRelationMapper;

    /**
     * Track entity IDs for cleanup.
     *
     * @var int[]
     */
    private array $createdEntityIds = [];

    /**
     * Track relation IDs for cleanup.
     *
     * @var int[]
     */
    private array $createdRelationIds = [];

    /**
     * Track chunk IDs for cleanup.
     *
     * @var int[]
     */
    private array $createdChunkIds = [];

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service              = \OC::$server->get(TextExtractionService::class);
        $this->entityHandler        = \OC::$server->get(EntityRecognitionHandler::class);
        $this->db                   = \OC::$server->get(IDBConnection::class);
        $this->chunkMapper          = \OC::$server->get(ChunkMapper::class);
        $this->entityMapper         = \OC::$server->get(GdprEntityMapper::class);
        $this->entityRelationMapper = \OC::$server->get(EntityRelationMapper::class);
    }

    /**
     * Clean up all created test data.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up entity relations.
        foreach ($this->createdRelationIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_entity_relations')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
                    ->executeStatement();
            } catch (\Throwable $e) {
                // Ignore cleanup errors.
            }
        }

        // Clean up entities.
        foreach ($this->createdEntityIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_entities')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
                    ->executeStatement();
            } catch (\Throwable $e) {
                // Ignore cleanup errors.
            }
        }

        // Clean up chunks.
        foreach ($this->createdChunkIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_chunks')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
                    ->executeStatement();
            } catch (\Throwable $e) {
                // Ignore cleanup errors.
            }
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helper: invoke private/protected methods via reflection
    // -----------------------------------------------------------------------

    /**
     * Call a private or protected method on an object.
     *
     * @param object $object     Object instance.
     * @param string $methodName Method name.
     * @param array  $args       Arguments.
     *
     * @return mixed
     */
    private function invokePrivate(object $object, string $methodName, array $args = []): mixed
    {
        $ref = new ReflectionMethod($object, $methodName);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }

    // =======================================================================
    // TextExtractionService — chunkDocument (public)
    // =======================================================================

    /**
     * Test chunkDocument with short text returns single chunk.
     *
     * @return void
     */
    public function testChunkDocumentShortText(): void
    {
        $text   = 'This is a short text that should not be chunked.';
        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('text', $result[0]);
        $this->assertArrayHasKey('start_offset', $result[0]);
        $this->assertArrayHasKey('end_offset', $result[0]);
    }

    /**
     * Test chunkDocument with long text produces multiple chunks.
     *
     * @return void
     */
    public function testChunkDocumentLongText(): void
    {
        $text   = str_repeat('This is a paragraph of text for testing chunking. ', 30);
        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));

        foreach ($result as $chunk) {
            $this->assertArrayHasKey('text', $chunk);
            $this->assertArrayHasKey('start_offset', $chunk);
            $this->assertArrayHasKey('end_offset', $chunk);
            $this->assertNotEmpty(trim($chunk['text']));
        }
    }

    /**
     * Test chunkDocument with custom chunk size.
     *
     * @return void
     */
    public function testChunkDocumentCustomSize(): void
    {
        $text   = str_repeat('Sentence with enough words for chunking. ', 20);
        $result = $this->service->chunkDocument($text, [
            'chunk_size'    => 500,
            'chunk_overlap' => 50,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkDocument with fixed size strategy.
     *
     * @return void
     */
    public function testChunkDocumentFixedSizeStrategy(): void
    {
        $text   = str_repeat('Testing fixed size chunking strategy. ', 20);
        $result = $this->service->chunkDocument($text, [
            'strategy'      => 'FIXED_SIZE',
            'chunk_size'    => 500,
            'chunk_overlap' => 0,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkDocument with recursive character strategy.
     *
     * @return void
     */
    public function testChunkDocumentRecursiveStrategy(): void
    {
        $paragraphs = [];
        for ($i = 0; $i < 20; $i++) {
            $paragraphs[] = 'This is paragraph number ' . $i . '. It contains some text that is '
                . 'meaningful for testing the recursive character splitting strategy.';
        }
        $text   = implode("\n\n", $paragraphs);
        $result = $this->service->chunkDocument($text, [
            'strategy'   => 'RECURSIVE_CHARACTER',
            'chunk_size' => 500,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkDocument preserves text content for short input.
     *
     * @return void
     */
    public function testChunkDocumentPreservesContent(): void
    {
        $text   = 'A short text that fits in one chunk without splitting.';
        $result = $this->service->chunkDocument($text);

        $this->assertCount(1, $result);
        $this->assertSame($text, $result[0]['text']);
    }

    /**
     * Test chunkDocument with empty text.
     *
     * @return void
     */
    public function testChunkDocumentEmptyText(): void
    {
        $result = $this->service->chunkDocument('');
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(1, count($result));
    }

    /**
     * Test chunkDocument with whitespace-only text.
     *
     * @return void
     */
    public function testChunkDocumentWhitespaceOnly(): void
    {
        $result = $this->service->chunkDocument("   \n\n   \t   ");
        $this->assertIsArray($result);
    }

    /**
     * Test chunkDocument with special UTF-8 characters.
     *
     * @return void
     */
    public function testChunkDocumentSpecialChars(): void
    {
        $text   = str_repeat("Line with UTF-8: Geachte heer/mevrouw, deze tekst bevat speciale tekens.\n", 15);
        $result = $this->service->chunkDocument($text, ['chunk_size' => 500]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
    }

    /**
     * Test chunkDocument strips null bytes.
     *
     * @return void
     */
    public function testChunkDocumentNullBytes(): void
    {
        $text   = "Text with\0null\0bytes that should be removed.";
        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        if (count($result) > 0) {
            $this->assertStringNotContainsString("\0", $result[0]['text']);
        }
    }

    /**
     * Test chunkDocument normalizes line endings.
     *
     * @return void
     */
    public function testChunkDocumentNormalizesLineEndings(): void
    {
        $text   = "Line one\r\nLine two\rLine three\nLine four";
        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        if (count($result) > 0) {
            $this->assertStringNotContainsString("\r", $result[0]['text']);
        }
    }

    /**
     * Test chunk offsets are sequential.
     *
     * @return void
     */
    public function testChunkOffsetsSequential(): void
    {
        $text   = str_repeat('This sentence is used for testing sequential chunk offsets. ', 20);
        $result = $this->service->chunkDocument($text, [
            'chunk_size'    => 500,
            'chunk_overlap' => 0,
        ]);

        foreach ($result as $chunk) {
            $this->assertGreaterThanOrEqual(0, $chunk['start_offset']);
            $this->assertGreaterThan($chunk['start_offset'], $chunk['end_offset']);
        }
    }

    /**
     * Test chunkDocument with default strategy (unknown falls back to recursive).
     *
     * @return void
     */
    public function testChunkDocumentDefaultStrategy(): void
    {
        $text   = str_repeat('Testing default strategy fallback with enough text. ', 25);
        $result = $this->service->chunkDocument($text, [
            'strategy'   => 'UNKNOWN_STRATEGY',
            'chunk_size' => 500,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
    }

    /**
     * Test fixed size chunking without overlap produces valid chunks.
     *
     * @return void
     */
    public function testChunkDocumentFixedSizeNoOverlap(): void
    {
        $text   = str_repeat('Overlap test sentence with meaningful content for chunking purposes. ', 20);
        $result = $this->service->chunkDocument($text, [
            'strategy'      => 'FIXED_SIZE',
            'chunk_size'    => 500,
            'chunk_overlap' => 0,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
        foreach ($result as $chunk) {
            $this->assertNotEmpty(trim($chunk['text']));
        }
    }

    /**
     * Test recursive chunking with sentence breaks.
     *
     * @return void
     */
    public function testChunkDocumentRecursiveWithSentences(): void
    {
        $sentences = [];
        for ($i = 0; $i < 50; $i++) {
            $sentences[] = "This is sentence number {$i} which is reasonably long to test splitting";
        }
        $text   = implode(". ", $sentences) . ".";
        $result = $this->service->chunkDocument($text, [
            'strategy'   => 'RECURSIVE_CHARACTER',
            'chunk_size' => 300,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test recursive chunking with a very large single paragraph (forces sub-split).
     *
     * @return void
     */
    public function testChunkDocumentRecursiveLargeParagraph(): void
    {
        // A single long string without paragraph or sentence breaks
        $text   = str_repeat('word ', 500);
        $result = $this->service->chunkDocument($text, [
            'strategy'   => 'RECURSIVE_CHARACTER',
            'chunk_size' => 200,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    // =======================================================================
    // TextExtractionService — getStats (public)
    // =======================================================================

    /**
     * Test getStats returns statistics array.
     *
     * @return void
     */
    public function testGetStats(): void
    {
        $result = $this->service->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('totalFiles', $result);
        $this->assertArrayHasKey('untrackedFiles', $result);
        $this->assertArrayHasKey('totalChunks', $result);
        $this->assertArrayHasKey('totalObjects', $result);
        $this->assertArrayHasKey('totalEntities', $result);
    }

    // =======================================================================
    // TextExtractionService — extractFile / extractObject error paths
    // =======================================================================

    /**
     * Test extractFile with nonexistent file throws.
     *
     * @return void
     */
    public function testExtractFileNonexistent(): void
    {
        $this->expectException(\Exception::class);
        $this->service->extractFile(999999999);
    }

    /**
     * Test extractObject with nonexistent object does not crash.
     *
     * @return void
     */
    public function testExtractObjectNonexistent(): void
    {
        // extractObject silently returns for nonexistent objects (DoesNotExistException caught).
        $this->service->extractObject(999999999);
        $this->assertTrue(true);
    }

    // =======================================================================
    // TextExtractionService — discoverUntrackedFiles / extractPending / retry
    // =======================================================================

    /**
     * Test discoverUntrackedFiles returns array.
     *
     * @return void
     */
    public function testDiscoverUntrackedFiles(): void
    {
        $result = $this->service->discoverUntrackedFiles(10);
        $this->assertIsArray($result);
    }

    /**
     * Test extractPendingFiles returns array.
     *
     * @return void
     */
    public function testExtractPendingFiles(): void
    {
        $result = $this->service->extractPendingFiles(10);
        $this->assertIsArray($result);
    }

    /**
     * Test retryFailedExtractions returns array.
     *
     * @return void
     */
    public function testRetryFailedExtractions(): void
    {
        $result = $this->service->retryFailedExtractions(10);
        $this->assertIsArray($result);
    }

    // =======================================================================
    // TextExtractionService — private sanitizeText via reflection
    // =======================================================================

    /**
     * Test sanitizeText removes null bytes.
     *
     * @return void
     */
    public function testSanitizeTextRemovesNullBytes(): void
    {
        $result = $this->invokePrivate($this->service, 'sanitizeText', ["Hello\0World"]);
        $this->assertStringNotContainsString("\0", $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
    }

    /**
     * Test sanitizeText removes control characters.
     *
     * @return void
     */
    public function testSanitizeTextRemovesControlChars(): void
    {
        $text   = "Hello\x01\x02\x03World";
        $result = $this->invokePrivate($this->service, 'sanitizeText', [$text]);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
    }

    /**
     * Test sanitizeText normalizes whitespace.
     *
     * @return void
     */
    public function testSanitizeTextNormalizesWhitespace(): void
    {
        $text   = "Hello    World    \t\t  Test";
        $result = $this->invokePrivate($this->service, 'sanitizeText', [$text]);
        $this->assertSame('Hello World Test', $result);
    }

    /**
     * Test sanitizeText trims result.
     *
     * @return void
     */
    public function testSanitizeTextTrims(): void
    {
        $result = $this->invokePrivate($this->service, 'sanitizeText', ['  hello  ']);
        $this->assertSame('hello', $result);
    }

    /**
     * Test sanitizeText with empty string.
     *
     * @return void
     */
    public function testSanitizeTextEmpty(): void
    {
        $result = $this->invokePrivate($this->service, 'sanitizeText', ['']);
        $this->assertSame('', $result);
    }

    /**
     * Test sanitizeText with only whitespace.
     *
     * @return void
     */
    public function testSanitizeTextWhitespaceOnly(): void
    {
        $result = $this->invokePrivate($this->service, 'sanitizeText', ['   ']);
        $this->assertSame('', $result);
    }

    // =======================================================================
    // TextExtractionService — private detectLanguageSignals via reflection
    // =======================================================================

    /**
     * Test detectLanguageSignals detects Dutch.
     *
     * @return void
     */
    public function testDetectLanguageSignalsDutch(): void
    {
        $result = $this->invokePrivate($this->service, 'detectLanguageSignals', ['De gemeente heeft besloten het plan te wijzigen.']);
        $this->assertSame('nl', $result['language']);
        $this->assertSame(0.35, $result['language_confidence']);
        $this->assertSame('heuristic', $result['detection_method']);
    }

    /**
     * Test detectLanguageSignals detects English.
     *
     * @return void
     */
    public function testDetectLanguageSignalsEnglish(): void
    {
        $result = $this->invokePrivate($this->service, 'detectLanguageSignals', ['The quick brown fox jumps over the lazy dog.']);
        $this->assertSame('en', $result['language']);
        $this->assertSame(0.35, $result['language_confidence']);
        $this->assertSame('heuristic', $result['detection_method']);
    }

    /**
     * Test detectLanguageSignals returns null for unknown language.
     *
     * @return void
     */
    public function testDetectLanguageSignalsUnknown(): void
    {
        // Text without Dutch or English stop-words.
        $result = $this->invokePrivate($this->service, 'detectLanguageSignals', ['123 456 789']);
        $this->assertNull($result['language']);
        $this->assertNull($result['language_confidence']);
        $this->assertSame('none', $result['detection_method']);
    }

    // =======================================================================
    // TextExtractionService — private getDetectionMethod via reflection
    // =======================================================================

    /**
     * Test getDetectionMethod returns 'heuristic' when language provided.
     *
     * @return void
     */
    public function testGetDetectionMethodWithLanguage(): void
    {
        $result = $this->invokePrivate($this->service, 'getDetectionMethod', ['nl']);
        $this->assertSame('heuristic', $result);
    }

    /**
     * Test getDetectionMethod returns 'none' when language is null.
     *
     * @return void
     */
    public function testGetDetectionMethodNull(): void
    {
        $result = $this->invokePrivate($this->service, 'getDetectionMethod', [null]);
        $this->assertSame('none', $result);
    }

    // =======================================================================
    // TextExtractionService — private cleanText via reflection
    // =======================================================================

    /**
     * Test cleanText removes null bytes and normalizes whitespace.
     *
     * @return void
     */
    public function testCleanTextRemovesNullBytes(): void
    {
        $result = $this->invokePrivate($this->service, 'cleanText', ["Hello\0World"]);
        $this->assertStringNotContainsString("\0", $result);
    }

    /**
     * Test cleanText normalizes line endings.
     *
     * @return void
     */
    public function testCleanTextNormalizesLineEndings(): void
    {
        $result = $this->invokePrivate($this->service, 'cleanText', ["Line1\r\nLine2\rLine3"]);
        $this->assertStringNotContainsString("\r", $result);
        $this->assertStringContainsString("\n", $result);
    }

    /**
     * Test cleanText collapses excessive blank lines.
     *
     * @return void
     */
    public function testCleanTextCollapsesBlankLines(): void
    {
        $result = $this->invokePrivate($this->service, 'cleanText', ["Para1\n\n\n\n\nPara2"]);
        $this->assertSame("Para1\n\nPara2", $result);
    }

    /**
     * Test cleanText collapses tabs and spaces.
     *
     * @return void
     */
    public function testCleanTextCollapsesTabs(): void
    {
        $result = $this->invokePrivate($this->service, 'cleanText', ["Hello\t\t  World"]);
        $this->assertSame('Hello World', $result);
    }

    // =======================================================================
    // TextExtractionService — private isWordDocument / isSpreadsheet
    // =======================================================================

    /**
     * Test isWordDocument recognizes DOCX.
     *
     * @return void
     */
    public function testIsWordDocumentDocx(): void
    {
        $result = $this->invokePrivate(
            $this->service,
            'isWordDocument',
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']
        );
        $this->assertTrue($result);
    }

    /**
     * Test isWordDocument recognizes DOC.
     *
     * @return void
     */
    public function testIsWordDocumentDoc(): void
    {
        $result = $this->invokePrivate($this->service, 'isWordDocument', ['application/msword']);
        $this->assertTrue($result);
    }

    /**
     * Test isWordDocument rejects PDF.
     *
     * @return void
     */
    public function testIsWordDocumentRejectsPdf(): void
    {
        $result = $this->invokePrivate($this->service, 'isWordDocument', ['application/pdf']);
        $this->assertFalse($result);
    }

    /**
     * Test isSpreadsheet recognizes XLSX.
     *
     * @return void
     */
    public function testIsSpreadsheetXlsx(): void
    {
        $result = $this->invokePrivate(
            $this->service,
            'isSpreadsheet',
            ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
        $this->assertTrue($result);
    }

    /**
     * Test isSpreadsheet recognizes XLS.
     *
     * @return void
     */
    public function testIsSpreadsheetXls(): void
    {
        $result = $this->invokePrivate($this->service, 'isSpreadsheet', ['application/vnd.ms-excel']);
        $this->assertTrue($result);
    }

    /**
     * Test isSpreadsheet rejects plain text.
     *
     * @return void
     */
    public function testIsSpreadsheetRejectsText(): void
    {
        $result = $this->invokePrivate($this->service, 'isSpreadsheet', ['text/plain']);
        $this->assertFalse($result);
    }

    // =======================================================================
    // TextExtractionService — private calculateAvgChunkSize
    // =======================================================================

    /**
     * Test calculateAvgChunkSize with normal chunks.
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeNormal(): void
    {
        $chunks = [
            ['text' => 'Hello'],      // 5 chars
            ['text' => 'World test'], // 10 chars
        ];
        $result = $this->invokePrivate($this->service, 'calculateAvgChunkSize', [$chunks]);
        $this->assertSame(7.5, $result);
    }

    /**
     * Test calculateAvgChunkSize with empty array.
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeEmpty(): void
    {
        $result = $this->invokePrivate($this->service, 'calculateAvgChunkSize', [[]]);
        $this->assertSame(0.0, $result);
    }

    /**
     * Test calculateAvgChunkSize with string chunks.
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeStringChunks(): void
    {
        $chunks = ['Hello', 'World'];
        $result = $this->invokePrivate($this->service, 'calculateAvgChunkSize', [$chunks]);
        $this->assertSame(5.0, $result);
    }

    /**
     * Test calculateAvgChunkSize with non-text chunks.
     *
     * @return void
     */
    public function testCalculateAvgChunkSizeNonTextChunks(): void
    {
        $chunks = [['other' => 'data'], 42];
        $result = $this->invokePrivate($this->service, 'calculateAvgChunkSize', [$chunks]);
        $this->assertSame(0.0, $result);
    }

    // =======================================================================
    // TextExtractionService — private buildPositionReference
    // =======================================================================

    /**
     * Test buildPositionReference for object source type.
     *
     * @return void
     */
    public function testBuildPositionReferenceObject(): void
    {
        $chunk  = ['property_path' => 'data.name'];
        $result = $this->invokePrivate($this->service, 'buildPositionReference', ['object', $chunk]);
        $this->assertSame('property-path', $result['type']);
        $this->assertSame('data.name', $result['path']);
    }

    /**
     * Test buildPositionReference for file source type.
     *
     * @return void
     */
    public function testBuildPositionReferenceFile(): void
    {
        $chunk  = ['start_offset' => 100, 'end_offset' => 500];
        $result = $this->invokePrivate($this->service, 'buildPositionReference', ['file', $chunk]);
        $this->assertSame('text-range', $result['type']);
        $this->assertSame(100, $result['start']);
        $this->assertSame(500, $result['end']);
    }

    /**
     * Test buildPositionReference defaults for file without offsets.
     *
     * @return void
     */
    public function testBuildPositionReferenceFileDefaults(): void
    {
        $result = $this->invokePrivate($this->service, 'buildPositionReference', ['file', []]);
        $this->assertSame('text-range', $result['type']);
        $this->assertSame(0, $result['start']);
        $this->assertSame(0, $result['end']);
    }

    // =======================================================================
    // TextExtractionService — private summarizeMetadataPayload
    // =======================================================================

    /**
     * Test summarizeMetadataPayload returns correct structure.
     *
     * @return void
     */
    public function testSummarizeMetadataPayload(): void
    {
        $payload = [
            'source_type'    => 'file',
            'source_id'      => 42,
            'checksum'       => 'abc123',
            'length'         => 1000,
            'language'       => 'nl',
            'language_level' => null,
            'organisation'   => 'TestOrg',
            'owner'          => 'admin',
            'metadata'       => ['file_path' => '/test.txt'],
        ];
        $result = $this->invokePrivate($this->service, 'summarizeMetadataPayload', [$payload]);

        $this->assertSame('file', $result['source_type']);
        $this->assertSame(42, $result['source_id']);
        $this->assertSame('abc123', $result['chunk_checksum']);
        $this->assertSame(1000, $result['text_length']);
        $this->assertSame('nl', $result['language']);
        $this->assertSame('TestOrg', $result['organisation']);
        $this->assertSame('admin', $result['owner']);
        $this->assertSame(['file_path' => '/test.txt'], $result['file_metadata']);
    }

    /**
     * Test summarizeMetadataPayload with empty payload uses defaults.
     *
     * @return void
     */
    public function testSummarizeMetadataPayloadDefaults(): void
    {
        $result = $this->invokePrivate($this->service, 'summarizeMetadataPayload', [[]]);

        $this->assertNull($result['source_type']);
        $this->assertNull($result['source_id']);
        $this->assertNull($result['chunk_checksum']);
        $this->assertSame([], $result['file_metadata']);
    }

    // =======================================================================
    // TextExtractionService — private textToChunks via reflection
    // =======================================================================

    /**
     * Test textToChunks produces mapped chunks with metadata.
     *
     * @return void
     */
    public function testTextToChunks(): void
    {
        $payload = [
            'source_type'         => 'file',
            'source_id'           => 1,
            'text'                => str_repeat('Hello world testing chunk creation. ', 40),
            'language'            => 'en',
            'language_level'      => null,
            'language_confidence' => 0.35,
            'detection_method'    => 'heuristic',
            'checksum'            => 'test-checksum',
        ];
        $options = ['chunk_size' => 500, 'chunk_overlap' => 50];
        $result  = $this->invokePrivate($this->service, 'textToChunks', [$payload, $options]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
        $this->assertSame(0, $result[0]['chunk_index']);
        $this->assertSame('en', $result[0]['language']);
        $this->assertSame('test-checksum', $result[0]['checksum']);
        $this->assertArrayHasKey('position_reference', $result[0]);
    }

    // =======================================================================
    // TextExtractionService — private isSourceUpToDate via reflection
    // =======================================================================

    /**
     * Test isSourceUpToDate returns false when force flag is true.
     *
     * @return void
     */
    public function testIsSourceUpToDateForced(): void
    {
        $result = $this->invokePrivate($this->service, 'isSourceUpToDate', [99999, 'file', time(), true]);
        $this->assertFalse($result);
    }

    /**
     * Test isSourceUpToDate returns false for nonexistent source.
     *
     * @return void
     */
    public function testIsSourceUpToDateNonexistent(): void
    {
        $result = $this->invokePrivate($this->service, 'isSourceUpToDate', [99999, 'file', time(), false]);
        $this->assertFalse($result);
    }

    // =======================================================================
    // TextExtractionService — private getTableCountSafe via reflection
    // =======================================================================

    /**
     * Test getTableCountSafe with valid table.
     *
     * @return void
     */
    public function testGetTableCountSafeValidTable(): void
    {
        $result = $this->invokePrivate($this->service, 'getTableCountSafe', ['openregister_chunks']);
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    /**
     * Test getTableCountSafe with nonexistent table returns 0.
     *
     * @return void
     */
    public function testGetTableCountSafeInvalidTable(): void
    {
        $result = $this->invokePrivate($this->service, 'getTableCountSafe', ['nonexistent_table_xyz']);
        $this->assertSame(0, $result);
    }

    // =======================================================================
    // EntityRecognitionHandler — private detectWithRegex via reflection
    // =======================================================================

    /**
     * Test regex detection finds email addresses.
     *
     * @return void
     */
    public function testRegexDetectsEmails(): void
    {
        $text     = 'Contact us at info@example.com for more info.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithRegex', [$text, null, 0.5]);

        $emails = array_filter($entities, fn($e) => $e['type'] === 'EMAIL');
        $this->assertNotEmpty($emails);
        $email = array_values($emails)[0];
        $this->assertSame('info@example.com', $email['value']);
        $this->assertSame('personal_data', $email['category']);
        $this->assertGreaterThanOrEqual(0.9, $email['confidence']);
    }

    /**
     * Test regex detection finds IBAN numbers.
     *
     * @return void
     */
    public function testRegexDetectsIban(): void
    {
        $text     = 'Betaal naar NL91ABNA0417164300 alstublieft.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithRegex', [$text, null, 0.5]);

        $ibans = array_filter($entities, fn($e) => $e['type'] === 'IBAN');
        $this->assertNotEmpty($ibans);
        $iban = array_values($ibans)[0];
        $this->assertStringContainsString('NL91ABNA', $iban['value']);
        $this->assertSame('sensitive_pii', $iban['category']);
    }

    /**
     * Test regex detection finds phone numbers.
     *
     * @return void
     */
    public function testRegexDetectsPhoneNumbers(): void
    {
        $text     = 'Bel ons op +31612345678 voor vragen.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithRegex', [$text, null, 0.5]);

        $phones = array_filter($entities, fn($e) => $e['type'] === 'PHONE');
        $this->assertNotEmpty($phones);
    }

    /**
     * Test regex detection filters by entity type.
     *
     * @return void
     */
    public function testRegexFiltersByEntityType(): void
    {
        $text = 'Email: test@test.nl, IBAN: NL91ABNA0417164300, Phone: +31612345678';
        // Only request EMAIL type.
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithRegex', [$text, ['EMAIL'], 0.5]);

        foreach ($entities as $entity) {
            $this->assertSame('EMAIL', $entity['type']);
        }
    }

    /**
     * Test regex detection filters by confidence threshold.
     *
     * @return void
     */
    public function testRegexFiltersByConfidence(): void
    {
        $text = 'Email: test@test.nl, Phone: +31612345678';
        // High threshold: only EMAIL (0.9) should survive, PHONE (0.7) should not.
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithRegex', [$text, null, 0.85]);

        foreach ($entities as $entity) {
            $this->assertGreaterThanOrEqual(0.85, $entity['confidence']);
        }
    }

    /**
     * Test regex detection with no matches returns empty.
     *
     * @return void
     */
    public function testRegexNoMatches(): void
    {
        $text     = 'This text contains no PII data at all.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithRegex', [$text, null, 0.5]);

        $this->assertEmpty($entities);
    }

    /**
     * Test regex detection finds multiple emails.
     *
     * @return void
     */
    public function testRegexFindsMultipleEmails(): void
    {
        $text     = 'Contact alice@example.com or bob@example.com for details.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithRegex', [$text, null, 0.5]);

        $emails = array_filter($entities, fn($e) => $e['type'] === 'EMAIL');
        $this->assertCount(2, $emails);
    }

    // =======================================================================
    // EntityRecognitionHandler — private detectWithHybrid via reflection
    // =======================================================================

    /**
     * Test hybrid detection falls through to regex.
     *
     * @return void
     */
    public function testHybridDetectionFallsToRegex(): void
    {
        $text      = 'Send to admin@test.org please.';
        $entities  = $this->invokePrivate($this->entityHandler, 'detectWithHybrid', [$text, null, 0.5]);
        $emails    = array_filter($entities, fn($e) => $e['type'] === 'EMAIL');
        $this->assertNotEmpty($emails);
    }

    // =======================================================================
    // EntityRecognitionHandler — private detectWithLLM via reflection
    // =======================================================================

    /**
     * Test LLM detection falls back to regex.
     *
     * @return void
     */
    public function testLlmDetectionFallsToRegex(): void
    {
        $text     = 'Mail me at llm-test@domain.com.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithLLM', [$text, null, 0.5]);
        $emails   = array_filter($entities, fn($e) => $e['type'] === 'EMAIL');
        $this->assertNotEmpty($emails);
    }

    // =======================================================================
    // EntityRecognitionHandler — private detectWithPresidio (no endpoint)
    // =======================================================================

    /**
     * Test Presidio detection falls back to regex when no endpoint is configured.
     *
     * @return void
     */
    public function testPresidioFallsBackToRegex(): void
    {
        $text     = 'Reach us at presidio-test@example.com.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithPresidio', [$text, null, 0.5]);
        $emails   = array_filter($entities, fn($e) => $e['type'] === 'EMAIL');
        $this->assertNotEmpty($emails);
    }

    // =======================================================================
    // EntityRecognitionHandler — private detectWithOpenAnonymiser (no endpoint)
    // =======================================================================

    /**
     * Test OpenAnonymiser detection falls back to regex when no endpoint configured.
     *
     * @return void
     */
    public function testOpenAnonymiserFallsBackToRegex(): void
    {
        $text     = 'Mail naar anon-test@example.nl graag.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectWithOpenAnonymiser', [$text, null, 0.5]);
        $emails   = array_filter($entities, fn($e) => $e['type'] === 'EMAIL');
        $this->assertNotEmpty($emails);
    }

    // =======================================================================
    // EntityRecognitionHandler — private detectEntities (method routing)
    // =======================================================================

    /**
     * Test detectEntities with regex method.
     *
     * @return void
     */
    public function testDetectEntitiesRegexMethod(): void
    {
        $text     = 'Email: route@test.com.';
        $entities = $this->invokePrivate($this->entityHandler, 'detectEntities', [$text, 'regex', null, 0.5]);
        $this->assertNotEmpty($entities);
    }

    /**
     * Test detectEntities with unknown method throws.
     *
     * @return void
     */
    public function testDetectEntitiesUnknownMethod(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown detection method');
        $this->invokePrivate($this->entityHandler, 'detectEntities', ['text', 'nonexistent_method', null, 0.5]);
    }

    // =======================================================================
    // EntityRecognitionHandler — private getCategoryForType
    // =======================================================================

    /**
     * Test getCategoryForType returns correct categories for all known types.
     *
     * @return void
     */
    public function testGetCategoryForAllTypes(): void
    {
        $expectations = [
            'PERSON'       => 'personal_data',
            'EMAIL'        => 'personal_data',
            'PHONE'        => 'personal_data',
            'ADDRESS'      => 'personal_data',
            'IBAN'         => 'sensitive_pii',
            'SSN'          => 'sensitive_pii',
            'ORGANIZATION' => 'business_data',
            'LOCATION'     => 'contextual_data',
            'DATE'         => 'temporal_data',
            'UNKNOWN_TYPE' => 'contextual_data',
        ];

        foreach ($expectations as $type => $expectedCategory) {
            $result = $this->invokePrivate($this->entityHandler, 'getCategoryForType', [$type]);
            $this->assertSame($expectedCategory, $result, "Failed for type: {$type}");
        }
    }

    // =======================================================================
    // EntityRecognitionHandler — private extractContext
    // =======================================================================

    /**
     * Test extractContext extracts correct window around entity.
     *
     * @return void
     */
    public function testExtractContextMiddle(): void
    {
        $text   = 'The quick brown fox jumps over the lazy dog near a river.';
        // "fox" starts at position 16, ends at 19.
        $result = $this->invokePrivate($this->entityHandler, 'extractContext', [$text, 16, 19, 10]);
        $this->assertStringContainsString('fox', $result);
        // Window should extend before and after.
        $this->assertGreaterThan(3, strlen($result));
    }

    /**
     * Test extractContext at start of text.
     *
     * @return void
     */
    public function testExtractContextAtStart(): void
    {
        $text   = 'Hello World this is a test string.';
        $result = $this->invokePrivate($this->entityHandler, 'extractContext', [$text, 0, 5, 10]);
        $this->assertStringContainsString('Hello', $result);
    }

    /**
     * Test extractContext at end of text.
     *
     * @return void
     */
    public function testExtractContextAtEnd(): void
    {
        $text   = 'Hello World';
        $result = $this->invokePrivate($this->entityHandler, 'extractContext', [$text, 6, 11, 10]);
        $this->assertStringContainsString('World', $result);
    }

    /**
     * Test extractContext with zero window.
     *
     * @return void
     */
    public function testExtractContextZeroWindow(): void
    {
        $text   = 'Hello World Test';
        $result = $this->invokePrivate($this->entityHandler, 'extractContext', [$text, 6, 11, 0]);
        $this->assertSame('World', $result);
    }

    // =======================================================================
    // EntityRecognitionHandler — private mapToPresidioEntityTypes
    // =======================================================================

    /**
     * Test mapToPresidioEntityTypes maps correctly.
     *
     * @return void
     */
    public function testMapToPresidioEntityTypes(): void
    {
        $types  = ['PERSON', 'EMAIL', 'PHONE', 'IBAN', 'SSN'];
        $result = $this->invokePrivate($this->entityHandler, 'mapToPresidioEntityTypes', [$types]);

        $this->assertContains('PERSON', $result);
        $this->assertContains('EMAIL_ADDRESS', $result);
        $this->assertContains('PHONE_NUMBER', $result);
        $this->assertContains('IBAN_CODE', $result);
        $this->assertContains('US_SSN', $result);
    }

    /**
     * Test mapToPresidioEntityTypes skips unknown types.
     *
     * @return void
     */
    public function testMapToPresidioEntityTypesSkipsUnknown(): void
    {
        $types  = ['NONEXISTENT_TYPE'];
        $result = $this->invokePrivate($this->entityHandler, 'mapToPresidioEntityTypes', [$types]);
        $this->assertEmpty($result);
    }

    // =======================================================================
    // EntityRecognitionHandler — private mapFromPresidioEntityType
    // =======================================================================

    /**
     * Test mapFromPresidioEntityType maps known types.
     *
     * @return void
     */
    public function testMapFromPresidioEntityTypeKnown(): void
    {
        $this->assertSame('EMAIL', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['EMAIL_ADDRESS']));
        $this->assertSame('PHONE', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['PHONE_NUMBER']));
        $this->assertSame('IBAN', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['IBAN_CODE']));
        $this->assertSame('PERSON', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['PERSON']));
        $this->assertSame('DATE', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['DATE_TIME']));
        $this->assertSame('IP_ADDRESS', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['IP_ADDRESS']));
        $this->assertSame('CREDIT_CARD', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['CREDIT_CARD']));
        $this->assertSame('URL', $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['URL']));
    }

    /**
     * Test mapFromPresidioEntityType returns unknown type as-is.
     *
     * @return void
     */
    public function testMapFromPresidioEntityTypeUnknown(): void
    {
        $result = $this->invokePrivate($this->entityHandler, 'mapFromPresidioEntityType', ['MYSTERY_TYPE']);
        $this->assertSame('MYSTERY_TYPE', $result);
    }

    // =======================================================================
    // EntityRecognitionHandler — private buildAnalyzeRequestBody
    // =======================================================================

    /**
     * Test buildAnalyzeRequestBody without entity types.
     *
     * @return void
     */
    public function testBuildAnalyzeRequestBodyBasic(): void
    {
        $result = $this->invokePrivate($this->entityHandler, 'buildAnalyzeRequestBody', ['Test text', 'en', null]);
        $this->assertSame('Test text', $result['text']);
        $this->assertSame('en', $result['language']);
        $this->assertArrayNotHasKey('entities', $result);
    }

    /**
     * Test buildAnalyzeRequestBody with entity types.
     *
     * @return void
     */
    public function testBuildAnalyzeRequestBodyWithEntityTypes(): void
    {
        $result = $this->invokePrivate($this->entityHandler, 'buildAnalyzeRequestBody', ['Test', 'nl', ['EMAIL', 'PERSON']]);
        $this->assertSame('nl', $result['language']);
        $this->assertArrayHasKey('entities', $result);
        $this->assertContains('EMAIL_ADDRESS', $result['entities']);
        $this->assertContains('PERSON', $result['entities']);
    }

    /**
     * Test buildAnalyzeRequestBody with empty entity types array.
     *
     * @return void
     */
    public function testBuildAnalyzeRequestBodyEmptyEntityTypes(): void
    {
        $result = $this->invokePrivate($this->entityHandler, 'buildAnalyzeRequestBody', ['Test', 'en', []]);
        $this->assertArrayNotHasKey('entities', $result);
    }

    // =======================================================================
    // EntityRecognitionHandler — private convertApiResultsToEntities
    // =======================================================================

    /**
     * Test convertApiResultsToEntities with Presidio-style results.
     *
     * @return void
     */
    public function testConvertApiResultsPresidioStyle(): void
    {
        $apiResults = [
            ['entity_type' => 'EMAIL_ADDRESS', 'start' => 0, 'end' => 16, 'score' => 0.95],
        ];
        $text    = 'test@example.com is a valid email.';
        $result  = $this->invokePrivate($this->entityHandler, 'convertApiResultsToEntities', [
            $apiResults, $text, 0.5, 'presidio', 0,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('EMAIL', $result[0]['type']);
        $this->assertSame(0.95, $result[0]['confidence']);
        $this->assertSame('test@example.com', $result[0]['value']);
    }

    /**
     * Test convertApiResultsToEntities with OpenAnonymiser-style (text field).
     *
     * @return void
     */
    public function testConvertApiResultsOpenAnonymiserStyle(): void
    {
        $apiResults = [
            ['entity_type' => 'PERSON', 'start' => 0, 'end' => 8, 'score' => null, 'text' => 'John Doe'],
        ];
        $text   = 'John Doe is a person.';
        $result = $this->invokePrivate($this->entityHandler, 'convertApiResultsToEntities', [
            $apiResults, $text, 0.5, 'openanonymiser', 0.85,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('PERSON', $result[0]['type']);
        $this->assertSame('John Doe', $result[0]['value']);
        // Default confidence used since score was null.
        $this->assertSame(0.85, $result[0]['confidence']);
    }

    /**
     * Test convertApiResultsToEntities filters by confidence.
     *
     * @return void
     */
    public function testConvertApiResultsFiltersLowConfidence(): void
    {
        $apiResults = [
            ['entity_type' => 'EMAIL_ADDRESS', 'start' => 0, 'end' => 5, 'score' => 0.3],
        ];
        $result = $this->invokePrivate($this->entityHandler, 'convertApiResultsToEntities', [
            $apiResults, 'test text', 0.5, 'presidio', 0,
        ]);

        $this->assertEmpty($result);
    }

    /**
     * Test convertApiResultsToEntities with empty results.
     *
     * @return void
     */
    public function testConvertApiResultsEmpty(): void
    {
        $result = $this->invokePrivate($this->entityHandler, 'convertApiResultsToEntities', [
            [], 'text', 0.5, 'presidio', 0,
        ]);
        $this->assertEmpty($result);
    }

    // =======================================================================
    // EntityRecognitionHandler — extractFromChunk (public)
    // =======================================================================

    /**
     * Test extractFromChunk with empty text returns zero entities.
     *
     * @return void
     */
    public function testExtractFromChunkEmptyText(): void
    {
        $chunk = new Chunk();
        $chunk->setTextContent('');
        $chunk->setSourceType('file');
        $chunk->setSourceId(1);

        $result = $this->entityHandler->extractFromChunk($chunk);

        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
        $this->assertEmpty($result['entities']);
    }

    /**
     * Test extractFromChunk with whitespace-only text returns zero entities.
     *
     * @return void
     */
    public function testExtractFromChunkWhitespaceText(): void
    {
        $chunk = new Chunk();
        $chunk->setTextContent('   ');
        $chunk->setSourceType('file');
        $chunk->setSourceId(1);

        $result = $this->entityHandler->extractFromChunk($chunk);

        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
    }

    /**
     * Test extractFromChunk with text without entities returns zero.
     *
     * @return void
     */
    public function testExtractFromChunkNoEntities(): void
    {
        $chunk = new Chunk();
        $chunk->setTextContent('This text has no personally identifiable information.');
        $chunk->setSourceType('file');
        $chunk->setSourceId(99999);

        $result = $this->entityHandler->extractFromChunk($chunk, ['method' => 'regex']);

        $this->assertSame(0, $result['entities_found']);
        $this->assertEmpty($result['entities']);
    }

    // =======================================================================
    // EntityRecognitionHandler — processSourceChunks (public)
    // =======================================================================

    /**
     * Test processSourceChunks with nonexistent source returns zero.
     *
     * @return void
     */
    public function testProcessSourceChunksNoChunks(): void
    {
        $result = $this->entityHandler->processSourceChunks('file', 99999999);

        $this->assertSame(0, $result['chunks_processed']);
        $this->assertSame(0, $result['entities_found']);
        $this->assertSame(0, $result['relations_created']);
    }

    // =======================================================================
    // EntityRecognitionHandler — postAnalyzeRequest (null responses)
    // =======================================================================

    /**
     * Test postAnalyzeRequest with invalid URL returns null.
     *
     * @return void
     */
    public function testPostAnalyzeRequestInvalidUrl(): void
    {
        $result = $this->invokePrivate($this->entityHandler, 'postAnalyzeRequest', [
            'http://localhost:99999/nonexistent',
            ['text' => 'test'],
            'TestService',
        ]);

        $this->assertNull($result);
    }

    // =======================================================================
    // EntityRecognitionHandler — constants
    // =======================================================================

    /**
     * Test entity type constants are defined.
     *
     * @return void
     */
    public function testEntityTypeConstants(): void
    {
        $this->assertSame('PERSON', EntityRecognitionHandler::ENTITY_TYPE_PERSON);
        $this->assertSame('ORGANIZATION', EntityRecognitionHandler::ENTITY_TYPE_ORGANIZATION);
        $this->assertSame('LOCATION', EntityRecognitionHandler::ENTITY_TYPE_LOCATION);
        $this->assertSame('EMAIL', EntityRecognitionHandler::ENTITY_TYPE_EMAIL);
        $this->assertSame('PHONE', EntityRecognitionHandler::ENTITY_TYPE_PHONE);
        $this->assertSame('ADDRESS', EntityRecognitionHandler::ENTITY_TYPE_ADDRESS);
        $this->assertSame('DATE', EntityRecognitionHandler::ENTITY_TYPE_DATE);
        $this->assertSame('IBAN', EntityRecognitionHandler::ENTITY_TYPE_IBAN);
        $this->assertSame('SSN', EntityRecognitionHandler::ENTITY_TYPE_SSN);
        $this->assertSame('IP_ADDRESS', EntityRecognitionHandler::ENTITY_TYPE_IP_ADDRESS);
    }

    /**
     * Test method constants are defined.
     *
     * @return void
     */
    public function testMethodConstants(): void
    {
        $this->assertSame('regex', EntityRecognitionHandler::METHOD_REGEX);
        $this->assertSame('presidio', EntityRecognitionHandler::METHOD_PRESIDIO);
        $this->assertSame('openanonymiser', EntityRecognitionHandler::METHOD_OPENANONYMISER);
        $this->assertSame('llm', EntityRecognitionHandler::METHOD_LLM);
        $this->assertSame('hybrid', EntityRecognitionHandler::METHOD_HYBRID);
        $this->assertSame('manual', EntityRecognitionHandler::METHOD_MANUAL);
    }

    /**
     * Test category constants are defined.
     *
     * @return void
     */
    public function testCategoryConstants(): void
    {
        $this->assertSame('personal_data', EntityRecognitionHandler::CATEGORY_PERSONAL_DATA);
        $this->assertSame('sensitive_pii', EntityRecognitionHandler::CATEGORY_SENSITIVE_PII);
        $this->assertSame('business_data', EntityRecognitionHandler::CATEGORY_BUSINESS_DATA);
        $this->assertSame('contextual_data', EntityRecognitionHandler::CATEGORY_CONTEXTUAL_DATA);
        $this->assertSame('temporal_data', EntityRecognitionHandler::CATEGORY_TEMPORAL_DATA);
    }

    // =======================================================================
    // EntityRecognitionHandler — getRegexPatterns via reflection
    // =======================================================================

    /**
     * Test getRegexPatterns returns expected structure.
     *
     * @return void
     */
    public function testGetRegexPatternsStructure(): void
    {
        $patterns = $this->invokePrivate($this->entityHandler, 'getRegexPatterns', []);

        $this->assertIsArray($patterns);
        $this->assertGreaterThanOrEqual(3, count($patterns));

        foreach ($patterns as $pattern) {
            $this->assertArrayHasKey('type', $pattern);
            $this->assertArrayHasKey('pattern', $pattern);
            $this->assertArrayHasKey('category', $pattern);
            $this->assertArrayHasKey('confidence', $pattern);
            $this->assertIsFloat($pattern['confidence']);
        }
    }

    /**
     * Test regex patterns include EMAIL, PHONE, IBAN types.
     *
     * @return void
     */
    public function testGetRegexPatternsIncludesTypes(): void
    {
        $patterns = $this->invokePrivate($this->entityHandler, 'getRegexPatterns', []);
        $types    = array_column($patterns, 'type');

        $this->assertContains('EMAIL', $types);
        $this->assertContains('PHONE', $types);
        $this->assertContains('IBAN', $types);
    }

    // =======================================================================
    // TextExtractionService — hydrateChunkEntity via reflection
    // =======================================================================

    /**
     * Test hydrateChunkEntity creates a valid Chunk entity.
     *
     * @return void
     */
    public function testHydrateChunkEntity(): void
    {
        $chunkData = [
            'chunk_index'         => 5,
            'text_content'        => 'Test chunk content',
            'start_offset'        => 100,
            'end_offset'          => 200,
            'language'            => 'nl',
            'language_level'      => 'B1',
            'language_confidence' => 0.8,
            'detection_method'    => 'heuristic',
            'overlap_size'        => 50,
            'position_reference'  => ['type' => 'text-range', 'start' => 100, 'end' => 200],
            'checksum'            => 'abc123',
        ];

        $chunk = $this->invokePrivate($this->service, 'hydrateChunkEntity', [
            'file', 42, $chunkData, 'admin', 'TestOrg', time(),
        ]);

        $this->assertInstanceOf(Chunk::class, $chunk);
        $this->assertSame('file', $chunk->getSourceType());
        $this->assertSame(42, $chunk->getSourceId());
        $this->assertSame(5, $chunk->getChunkIndex());
        $this->assertSame('Test chunk content', $chunk->getTextContent());
        $this->assertSame(100, $chunk->getStartOffset());
        $this->assertSame(200, $chunk->getEndOffset());
        $this->assertSame('nl', $chunk->getLanguage());
        $this->assertSame(50, $chunk->getOverlapSize());
        $this->assertSame('admin', $chunk->getOwner());
        $this->assertSame('TestOrg', $chunk->getOrganisation());
        $this->assertFalse($chunk->getIndexed());
        $this->assertFalse($chunk->getVectorized());
    }
}
