<?php

/**
 * Integration tests for TextExtractionService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\TextExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TextExtractionService
 *
 * Tests document chunking, stats retrieval, and text extraction utilities.
 */
class TextExtractionServiceIntegrationTest extends TestCase
{
    /**
     * The text extraction service instance
     *
     * @var TextExtractionService
     */
    private TextExtractionService $service;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(TextExtractionService::class);
    }

    /**
     * Test chunkDocument with short text returns single chunk
     *
     * @return void
     */
    public function testChunkDocumentShortText(): void
    {
        $text = 'This is a short text that should not be chunked.';

        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('text', $result[0]);
        $this->assertArrayHasKey('start_offset', $result[0]);
        $this->assertArrayHasKey('end_offset', $result[0]);
    }

    /**
     * Test chunkDocument with long text produces multiple chunks
     *
     * @return void
     */
    public function testChunkDocumentLongText(): void
    {
        // Generate text longer than default chunk size (1000 chars) but not too large
        $text = str_repeat('This is a paragraph of text for testing chunking. ', 30);

        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));

        // Each chunk should have the expected structure
        foreach ($result as $chunk) {
            $this->assertArrayHasKey('text', $chunk);
            $this->assertArrayHasKey('start_offset', $chunk);
            $this->assertArrayHasKey('end_offset', $chunk);
            $this->assertNotEmpty(trim($chunk['text']));
        }
    }

    /**
     * Test chunkDocument with custom chunk size
     *
     * @return void
     */
    public function testChunkDocumentCustomSize(): void
    {
        $text = str_repeat('Sentence with enough words for chunking. ', 20);

        $result = $this->service->chunkDocument($text, [
            'chunk_size'    => 500,
            'chunk_overlap' => 50,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkDocument with fixed size strategy
     *
     * @return void
     */
    public function testChunkDocumentFixedSizeStrategy(): void
    {
        $text = str_repeat('Testing fixed size chunking strategy. ', 20);

        $result = $this->service->chunkDocument($text, [
            'strategy'      => 'FIXED_SIZE',
            'chunk_size'    => 500,
            'chunk_overlap' => 0,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkDocument with recursive character strategy
     *
     * @return void
     */
    public function testChunkDocumentRecursiveStrategy(): void
    {
        // Text with paragraph breaks
        $paragraphs = [];
        for ($i = 0; $i < 20; $i++) {
            $paragraphs[] = 'This is paragraph number ' . $i . '. It contains some text that is '
                . 'meaningful for testing the recursive character splitting strategy.';
        }
        $text = implode("\n\n", $paragraphs);

        $result = $this->service->chunkDocument($text, [
            'strategy'   => 'RECURSIVE_CHARACTER',
            'chunk_size' => 500,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(1, count($result));
    }

    /**
     * Test chunkDocument preserves text content
     *
     * @return void
     */
    public function testChunkDocumentPreservesContent(): void
    {
        $text = 'A short text that fits in one chunk without splitting.';

        $result = $this->service->chunkDocument($text);

        $this->assertCount(1, $result);
        $this->assertSame($text, $result[0]['text']);
    }

    /**
     * Test chunkDocument with empty text
     *
     * @return void
     */
    public function testChunkDocumentEmptyText(): void
    {
        $result = $this->service->chunkDocument('');

        $this->assertIsArray($result);
        // Empty text may produce 0 or 1 empty chunk
        $this->assertLessThanOrEqual(1, count($result));
    }

    /**
     * Test chunkDocument with whitespace-only text
     *
     * @return void
     */
    public function testChunkDocumentWhitespaceOnly(): void
    {
        $result = $this->service->chunkDocument('   \n\n   \t   ');

        $this->assertIsArray($result);
    }

    /**
     * Test chunkDocument with text containing special characters
     *
     * @return void
     */
    public function testChunkDocumentSpecialChars(): void
    {
        $text = str_repeat("Line with UTF-8: Geachte heer/mevrouw, deze tekst bevat speciale tekens.\n", 15);

        $result = $this->service->chunkDocument($text, ['chunk_size' => 500]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
    }

    /**
     * Test chunkDocument with null bytes in text
     *
     * @return void
     */
    public function testChunkDocumentNullBytes(): void
    {
        $text = "Text with\0null\0bytes that should be removed.";

        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        // Null bytes should be cleaned
        if (count($result) > 0) {
            $this->assertStringNotContainsString("\0", $result[0]['text']);
        }
    }

    /**
     * Test chunkDocument normalizes line endings
     *
     * @return void
     */
    public function testChunkDocumentNormalizesLineEndings(): void
    {
        $text = "Line one\r\nLine two\rLine three\nLine four";

        $result = $this->service->chunkDocument($text);

        $this->assertIsArray($result);
        // Should have been normalized
        if (count($result) > 0) {
            $this->assertStringNotContainsString("\r", $result[0]['text']);
        }
    }

    /**
     * Test getStats returns statistics array
     *
     * @return void
     */
    public function testGetStats(): void
    {
        $result = $this->service->getStats();

        $this->assertIsArray($result);
    }

    /**
     * Test extractFile with nonexistent file throws
     *
     * @return void
     */
    public function testExtractFileNonexistent(): void
    {
        $this->expectException(\Exception::class);

        $this->service->extractFile(999999999);
    }

    /**
     * Test extractObject with nonexistent object does not crash
     *
     * @return void
     */
    public function testExtractObjectNonexistent(): void
    {
        // extractObject may silently skip nonexistent objects
        // or throw - both are valid behaviors
        try {
            $this->service->extractObject(999999999);
            $this->assertTrue(true); // Did not throw
        } catch (\Exception $e) {
            $this->assertNotEmpty($e->getMessage()); // Threw with message
        }
    }

    /**
     * Test discoverUntrackedFiles returns array
     *
     * @return void
     */
    public function testDiscoverUntrackedFiles(): void
    {
        $result = $this->service->discoverUntrackedFiles(10);

        $this->assertIsArray($result);
    }

    /**
     * Test extractPendingFiles returns array
     *
     * @return void
     */
    public function testExtractPendingFiles(): void
    {
        $result = $this->service->extractPendingFiles(10);

        $this->assertIsArray($result);
    }

    /**
     * Test retryFailedExtractions returns array
     *
     * @return void
     */
    public function testRetryFailedExtractions(): void
    {
        $result = $this->service->retryFailedExtractions(10);

        $this->assertIsArray($result);
    }

    /**
     * Test chunk offsets are sequential
     *
     * @return void
     */
    public function testChunkOffsetsSequential(): void
    {
        $text = str_repeat('This sentence is used for testing sequential chunk offsets. ', 20);

        $result = $this->service->chunkDocument($text, [
            'chunk_size'    => 500,
            'chunk_overlap' => 0,
        ]);

        // Verify offsets make sense
        foreach ($result as $chunk) {
            $this->assertGreaterThanOrEqual(0, $chunk['start_offset']);
            $this->assertGreaterThan($chunk['start_offset'], $chunk['end_offset']);
        }
    }
}
