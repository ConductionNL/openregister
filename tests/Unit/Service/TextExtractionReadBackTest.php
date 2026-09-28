<?php

/**
 * The text extracted from a file can be read back (#4106).
 *
 * The chunks are produced by the service's own chunker, so the stitching is
 * tested against the overlap the extractor really writes, not a hand-made one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

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
use OCA\OpenRegister\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\TextExtractionService::getExtractedText
 */
final class TextExtractionReadBackTest extends TestCase {

	private ChunkMapper&MockObject $chunks;

	private TextExtractionService $service;

	/**
	 * Build the real service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$logger = $this->createMock(LoggerInterface::class);
		$this->chunks = $this->createMock(ChunkMapper::class);
		$this->service = new TextExtractionService(
			$this->createMock(FileMapper::class),
			$this->chunks,
			$this->createMock(IRootFolder::class),
			$this->createMock(IDBConnection::class),
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(EntityRecognitionHandler::class),
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(SettingsService::class),
			$this->createMock(RiskLevelService::class),
			$this->createMock(EmlParser::class),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);
	}//end setUp()

	/**
	 * A document of numbered paragraphs, long enough for several overlapping chunks.
	 *
	 * @return string
	 */
	private function document(): string {
		$paragraphs = [];
		for ($p = 1; $p <= 9; $p++) {
			$sentences = [];
			for ($s = 1; $s <= 7; $s++) {
				$sentences[] = sprintf('Paragraph %d sentence %d states a distinct fact about case %d.', $p, $s, ($p * 10 + $s));
			}

			$paragraphs[] = implode(' ', $sentences);
		}

		return implode("\n\n", $paragraphs);
	}//end document()

	/**
	 * Chunk the text with the service's own chunker and hydrate the rows the extractor stores.
	 *
	 * @param string $text     The text.
	 * @param string $strategy The chunking strategy.
	 *
	 * @return Chunk[]
	 */
	private function storedChunks(string $text, string $strategy): array {
		$mapped = (new ReflectionMethod(TextExtractionService::class, 'textToChunks'))->invoke(
			$this->service,
			['text' => $text, 'source_type' => 'file'],
			['chunk_size' => 1000, 'chunk_overlap' => 200, 'strategy' => $strategy]
		);
		$this->assertGreaterThan(2, count($mapped), 'The fixture must produce several chunks.');

		// The metadata chunk sorts first (chunk_index -1) and is not text.
		$metadata = new Chunk();
		$metadata->setChunkIndex(-1);
		$metadata->setTextContent('{"source_type":"file"}');
		$metadata->setPositionReference(['type' => 'metadata']);
		$rows = [$metadata];

		foreach ($mapped as $row) {
			$chunk = new Chunk();
			$chunk->setChunkIndex($row['chunk_index']);
			$chunk->setTextContent($row['text_content']);
			$chunk->setStartOffset((int)$row['start_offset']);
			$chunk->setEndOffset((int)$row['end_offset']);
			$chunk->setPositionReference($row['position_reference']);
			$rows[] = $chunk;
		}

		return $rows;
	}//end storedChunks()

	/**
	 * Whitespace-normalised text, since chunk boundaries do not keep the whitespace they were trimmed of.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function words(string $text): string {
		return trim((string)preg_replace('/\s+/', ' ', $text));
	}//end words()

	/**
	 * The recursive chunker the extractor uses reads back as the original text, once.
	 *
	 * @return void
	 */
	public function testRecursiveChunksReadBackAsTheOriginalText(): void {
		$text = $this->document();
		$this->chunks->method('findBySource')->with('file', 5)->willReturn($this->storedChunks($text, 'RECURSIVE_CHARACTER'));

		$this->assertSame($this->words($text), $this->words((string)$this->service->getExtractedText(fileId: 5)));
	}//end testRecursiveChunksReadBackAsTheOriginalText()

	/**
	 * The fixed-size chunker reads back as the original text, once.
	 *
	 * @return void
	 */
	public function testFixedSizeChunksReadBackAsTheOriginalText(): void {
		$text = $this->document();
		$this->chunks->method('findBySource')->willReturn($this->storedChunks($text, 'FIXED_SIZE'));

		$this->assertSame($this->words($text), $this->words((string)$this->service->getExtractedText(fileId: 5)));
	}//end testFixedSizeChunksReadBackAsTheOriginalText()

	/**
	 * A file with no chunks has no text to give.
	 *
	 * @return void
	 */
	public function testAFileWithoutChunksHasNoText(): void {
		$this->chunks->method('findBySource')->willReturn([]);

		$this->assertNull($this->service->getExtractedText(fileId: 5));
	}//end testAFileWithoutChunksHasNoText()
}//end class
