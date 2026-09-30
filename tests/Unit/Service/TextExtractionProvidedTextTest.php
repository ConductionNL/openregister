<?php

/**
 * Text another app extracted, such as OCR of a scan, is indexed for its file (#2033).
 *
 * Filinq's local OCR fallback hands openregister the text of a scanned file so
 * entity detection and search can see it. The seam must store the text as the
 * file's chunks, run entity recognition, and never read the file's own content.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md
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
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TextExtractionProvidedTextTest extends TestCase {

	private FileMapper&MockObject $files;

	private ChunkMapper&MockObject $chunks;

	private EntityRecognitionHandler&MockObject $entities;

	private IRootFolder&MockObject $root;

	private TextExtractionService $service;

	/** @var string[] The text of every chunk stored. */
	private array $stored = [];

	protected function setUp(): void {
		$logger = new NullLogger();
		$this->files = $this->createMock(FileMapper::class);
		$this->chunks = $this->createMock(ChunkMapper::class);
		$this->chunks->method('insert')->willReturnCallback(
			function (Chunk $chunk): Chunk {
				$this->stored[] = (string) $chunk->getTextContent();
				return $chunk;
			}
		);
		$this->entities = $this->createMock(EntityRecognitionHandler::class);
		$this->root = $this->createMock(IRootFolder::class);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileSettingsOnly')->willReturn(['entityRecognitionEnabled' => true, 'entityRecognitionMethod' => 'regex']);

		$this->service = new TextExtractionService(
			$this->files,
			$this->chunks,
			$this->root,
			$this->createMock(IDBConnection::class),
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->entities,
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$settings,
			$this->createMock(RiskLevelService::class),
			$this->createMock(EmlParser::class),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);
	}//end setUp()

	/**
	 * OCR text is stored as the file's chunks and entity recognition runs over it.
	 */
	public function testProvidedTextIsIndexedForTheFileWithoutReadingIt(): void {
		$this->files->method('getFile')->with(42)->willReturn(['fileid' => 42, 'mtime' => 1790000000, 'owner' => 'alice', 'name' => 'scan.pdf', 'mimetype' => 'application/pdf']);
		$this->root->expects($this->never())->method($this->anything());
		$this->entities->expects($this->once())
			->method('processSourceChunks')
			->with('file', 42, $this->callback(static fn (array $options): bool => $options['entity_types'] === ['bsn']))
			->willReturn(['entities_found' => 1, 'relations_created' => 0]);

		$this->service->extractFromProvidedText(fileId: 42, text: "De aanvrager met BSN 123456782 woont in Utrecht.\n", entityTypes: ['bsn']);

		$this->assertStringContainsString('BSN 123456782', implode("\n", $this->stored));
		$this->assertStringContainsString('"extraction_method": "ocr"', implode("\n", $this->stored), 'The metadata chunk says the text came from OCR.');
	}//end testProvidedTextIsIndexedForTheFileWithoutReadingIt()

	/**
	 * Text for a file that does not exist is refused.
	 */
	public function testTextForAMissingFileIsRefused(): void {
		$this->files->method('getFile')->willReturn(null);
		$this->chunks->expects($this->never())->method('insert');

		$this->expectException(NotFoundException::class);

		$this->service->extractFromProvidedText(fileId: 404, text: 'anything');
	}//end testTextForAMissingFileIsRefused()
}//end class
