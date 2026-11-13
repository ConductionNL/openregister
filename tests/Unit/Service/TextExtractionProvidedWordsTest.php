<?php

/**
 * TextExtractionService: OCR words with positions are stored beside the provided text.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\ProvidedTextRequest;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @covers \OCA\OpenRegister\Files\Service\ProvidedTextRequest
 * @uses   \OCA\OpenRegister\Service\File\OcrWords
 */
final class TextExtractionProvidedWordsTest extends TestCase {

	private FileMapper&MockObject $files;

	private TextExtractionService $service;

	/** @var Chunk[] Every chunk stored. */
	private array $stored = [];

	protected function setUp(): void {
		$logger = new NullLogger();
		$this->files = $this->createMock(FileMapper::class);
		$this->files->method('getFile')->willReturn(['fileid' => 7, 'mtime' => 1790000000, 'owner' => 'alice', 'name' => 'scan.png', 'mimetype' => 'image/png']);
		$chunks = $this->createMock(ChunkMapper::class);
		$chunks->method('insert')->willReturnCallback(
			function (Chunk $chunk): Chunk {
				$this->stored[] = $chunk;
				return $chunk;
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileSettingsOnly')->willReturn(['entityRecognitionEnabled' => false]);

		$this->service = TextExtractionServiceFactory::create(
			$this->files,
			$chunks,
			$this->createMock(IRootFolder::class),
			$this->createMock(IDBConnection::class),
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(EntityRecognitionHandler::class),
			$settings,
			$this->createMock(RiskLevelService::class),
			$this->createMock(EmlParser::class),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);
	}//end setUp()

	private function metadataChunk(): ?Chunk {
		foreach ($this->stored as $chunk) {
			if ($chunk->getChunkIndex() === -1) {
				return $chunk;
			}
		}

		return null;
	}//end metadataChunk()

	public function testWordBoxesAreStoredWithTheText(): void {
		$words = [
			['text' => 'Jan', 'page' => 1, 'box' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.05, 'h' => 0.02]],
			['text' => 'Jansen', 'page' => 1, 'box' => ['x' => 0.16, 'y' => 0.2, 'w' => 0.08, 'h' => 0.02]],
		];

		$this->service->extractFromProvidedText(request: new ProvidedTextRequest(fileId: 7, text: "Jan Jansen\n", words: $words));

		$metadata = $this->metadataChunk();
		$this->assertNotNull($metadata);
		$reference = $metadata->getPositionReference();
		$this->assertSame('metadata', $reference['type']);
		$this->assertSame(
			[
				['text' => 'Jan', 'page' => 1, 'box' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.05, 'h' => 0.02]],
				['text' => 'Jansen', 'page' => 1, 'box' => ['x' => 0.16, 'y' => 0.2, 'w' => 0.08, 'h' => 0.02]],
			],
			$reference['ocrWords']
		);
	}//end testWordBoxesAreStoredWithTheText()

	public function testWithoutWordsTheMetadataChunkCarriesNone(): void {
		$this->service->extractFromProvidedText(request: new ProvidedTextRequest(fileId: 7, text: "Jan Jansen\n"));

		$this->assertSame(['type' => 'metadata'], $this->metadataChunk()?->getPositionReference());
	}//end testWithoutWordsTheMetadataChunkCarriesNone()

	public function testABoxOutsideTheImageIsRefusedBeforeAnythingIsStored(): void {
		$words = [['text' => 'Jan', 'page' => 1, 'box' => ['x' => 0.9, 'y' => 0.2, 'w' => 0.2, 'h' => 0.02]]];

		try {
			$this->service->extractFromProvidedText(request: new ProvidedTextRequest(fileId: 7, text: 'Jan', words: $words));
			$this->fail('A box outside the image must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('words[0].box reaches outside the image.', $e->getMessage());
		}

		$this->assertSame([], $this->stored);
	}//end testABoxOutsideTheImageIsRefusedBeforeAnythingIsStored()
}//end class
