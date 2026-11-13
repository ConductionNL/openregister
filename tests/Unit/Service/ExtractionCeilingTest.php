<?php

/**
 * A file above the extraction ceiling is never opened and keeps its metadata chunk.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\ExtractionCeiling;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\ExtractionCeiling
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Files\Service\ChunkSizing
 * @uses   \OCA\OpenRegister\Files\Service\ChunkingPlan
 * @uses   \OCA\OpenRegister\Files\Service\SplitLevel
 * @uses   \OCA\OpenRegister\Files\Service\TextSplitPass
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ChunkSource
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ExtractionSource
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\FileExtractionOptions
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SourceStamp
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SourceText
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionAnalysis
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionObjects
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionSources
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage
 */
final class ExtractionCeilingTest extends TestCase {

	/**
	 * The chunks the service stored.
	 *
	 * @var array<int, Chunk>
	 */
	private array $inserted = [];

	/**
	 * The service over one plain-text file of the given size, with a 1000-byte ceiling.
	 *
	 * @param int       $size       The size the file row states.
	 * @param File|null $node       The node the root folder answers, or null when it must never be asked.
	 * @param bool      $unreadable Whether reading the file settings fails.
	 *
	 * @return TextExtractionService
	 */
	private function service(int $size, ?File $node, bool $unreadable = false): TextExtractionService {
		$files = $this->createMock(FileMapper::class);
		$files->method('getFile')->willReturn(
			['fileid' => 7, 'name' => 'raadsdossier-2024.txt', 'path' => 'files/raadsdossier-2024.txt', 'mimetype' => 'text/plain', 'size' => $size, 'mtime' => 1700000000, 'owner' => null]
		);

		$chunks = $this->createMock(ChunkMapper::class);
		$chunks->method('getLatestUpdatedTimestamp')->willReturn(null);
		$chunks->method('insert')->willReturnCallback(
			function (Chunk $chunk): Chunk {
				$this->inserted[] = $chunk;
				return $chunk;
			}
		);

		$root = $this->createMock(IRootFolder::class);
		if ($node === null) {
			$root->expects($this->never())->method('getById');
			$root->expects($this->never())->method('getUserFolder');
		} else {
			$root->method('getById')->willReturn([$node]);
		}

		$settings = $this->createMock(SettingsService::class);
		if ($unreadable === true) {
			$settings->method('getFileSettingsOnly')->willThrowException(new RuntimeException('appconfig gone'));
		} else {
			$settings->method('getFileSettingsOnly')->willReturn(['textExtraction' => ['maxBytes' => 1000], 'entityRecognitionEnabled' => false]);
		}

		$logger = $this->createMock(LoggerInterface::class);

		return TextExtractionServiceFactory::create(
			$files,
			$chunks,
			$root,
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
	}//end service()

	/**
	 * The decoded metadata chunk among the stored chunks.
	 *
	 * @return array<string, mixed>
	 */
	private function metadataChunk(): array {
		foreach ($this->inserted as $chunk) {
			if (($chunk->getPositionReference()['type'] ?? null) === 'metadata') {
				return json_decode((string) $chunk->getTextContent(), true);
			}
		}

		$this->fail(message: 'no metadata chunk was stored');
	}//end metadataChunk()

	/**
	 * A file one byte over the ceiling is not opened and no text chunk is written.
	 *
	 * @return void
	 */
	public function testAFileOverTheCeilingIsNotOpened(): void {
		$this->service(size: 1001, node: null)->extractFile(7);

		$this->assertCount(expectedCount: 1, haystack: $this->inserted, message: 'only the metadata chunk');
	}//end testAFileOverTheCeilingIsNotOpened()

	/**
	 * The file over the ceiling keeps its name, the status and the ceiling that applied.
	 *
	 * @return void
	 */
	public function testAFileOverTheCeilingGetsItsMetadataChunk(): void {
		$this->service(size: 300000000, node: null)->extractFile(7);

		$metadata = $this->metadataChunk();
		$this->assertSame(expected: ExtractionCeiling::SKIPPED_TOO_LARGE, actual: $metadata['extraction_status']);
		$this->assertSame(expected: 1000, actual: $metadata['ceiling_bytes']);
		$this->assertSame(expected: 'raadsdossier-2024.txt', actual: $metadata['file_metadata']['file_name']);
		$this->assertSame(expected: 300000000, actual: $metadata['file_metadata']['file_size']);
	}//end testAFileOverTheCeilingGetsItsMetadataChunk()

	/**
	 * A file exactly at the ceiling is read and chunked as before, without a status.
	 *
	 * @return void
	 */
	public function testAFileAtTheCeilingIsExtracted(): void {
		$node = $this->createMock(File::class);
		$node->expects($this->once())->method('getContent')->willReturn('De gemeenteraad besluit over het dossier.');

		$this->service(size: 1000, node: $node)->extractFile(7);

		$this->assertGreaterThan(expected: 1, actual: count($this->inserted));
		$this->assertArrayNotHasKey(key: 'extraction_status', array: $this->metadataChunk());
	}//end testAFileAtTheCeilingIsExtracted()

	/**
	 * The ceiling reads the new key first, then the legacy megabytes, then the default.
	 *
	 * @return void
	 */
	public function testTheCeilingComesFromTheSettings(): void {
		$this->assertSame(expected: 5000, actual: ExtractionCeiling::fromSettings(fileSettings: ['textExtraction' => ['maxBytes' => 5000], 'maxFileSizeMB' => 50])->maxBytes);
		$this->assertSame(expected: 52428800, actual: ExtractionCeiling::fromSettings(fileSettings: ['maxFileSizeMB' => 50])->maxBytes);
		$this->assertSame(expected: 104857600, actual: ExtractionCeiling::fromSettings(fileSettings: ['maxFileSizeMB' => 100])->maxBytes);
		$this->assertSame(expected: ExtractionCeiling::DEFAULT_BYTES, actual: ExtractionCeiling::fromSettings(fileSettings: ['textExtraction' => ['maxBytes' => 0], 'maxFileSizeMB' => 'abc'])->maxBytes);
		$this->assertSame(expected: ExtractionCeiling::DEFAULT_BYTES, actual: ExtractionCeiling::fromSettings(fileSettings: [])->maxBytes);
	}//end testTheCeilingComesFromTheSettings()

	/**
	 * A size at the ceiling is admitted, one byte more is not, and an unknown size is.
	 *
	 * @return void
	 */
	public function testTheBoundaryIsInclusive(): void {
		$ceiling = new ExtractionCeiling(maxBytes: 1000);

		$this->assertTrue(condition: $ceiling->admits(size: 1000));
		$this->assertTrue(condition: $ceiling->admits(size: '1000'));
		$this->assertFalse(condition: $ceiling->admits(size: 1001));
		$this->assertTrue(condition: $ceiling->admits(size: null));
	}//end testTheBoundaryIsInclusive()

	/**
	 * Unreadable settings fall back to the default ceiling instead of failing the extraction.
	 *
	 * @return void
	 */
	public function testUnreadableSettingsUseTheDefault(): void {
		$this->service(size: ExtractionCeiling::DEFAULT_BYTES + 1, node: null, unreadable: true)->extractFile(7);

		$this->assertSame(expected: ExtractionCeiling::DEFAULT_BYTES, actual: $this->metadataChunk()['ceiling_bytes']);
	}//end testUnreadableSettingsUseTheDefault()
}//end class
