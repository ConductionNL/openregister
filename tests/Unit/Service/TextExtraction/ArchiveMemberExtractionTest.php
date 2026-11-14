<?php

/**
 * ArchiveMemberExtractionTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\FileSidebarEntityStore;
use OCA\OpenRegister\Files\Service\FileSidebarSchemaLookup;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtraction\ArchiveTextExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Service\File\ArchiveBounds;
use OCA\OpenRegister\Service\File\ArchiveMemberEnumerator;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Service\Support\TextExtractionServiceFactory;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * Archive members extracted and reported (REQ-AMS-002, REQ-AMS-003), through
 * TextExtractionService::extractFile(), the path the background job takes.
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\ArchiveTextExtractor
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\ArchiveExtraction
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\ArchiveContainers
 * @covers \OCA\OpenRegister\Files\Service\TextExtractionService
 * @covers \OCA\OpenRegister\Files\Service\FileSidebarService
 * @uses   \OCA\OpenRegister\Service\File\ArchiveMemberEnumerator
 * @uses   \OCA\OpenRegister\Service\File\ArchiveListing
 * @uses   \OCA\OpenRegister\Service\File\ArchiveWalk
 * @uses   \OCA\OpenRegister\Service\File\ArchiveBounds
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\EmlParser
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\WordTextReader
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionSources
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionStorage
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionObjects
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionAnalysis
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\SourceText
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ChunkSource
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ExtractionSource
 * @uses   \OCA\OpenRegister\Files\Db\Chunk
 */
class ArchiveMemberExtractionTest extends TestCase {

	/**
	 * Temp files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Chunks the service inserted.
	 *
	 * @var array<int, Chunk>
	 */
	private array $inserted = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (file_exists($file) === true) {
				unlink($file);
			}
		}

		parent::tearDown();
	}//end tearDown()

	private function tempFile(): string {
		$file          = (string) tempnam(sys_get_temp_dir(), 'or-ams-');
		$this->files[] = $file;
		return $file;
	}//end tempFile()

	/**
	 * A zip with these members, built on disk.
	 *
	 * @param array<string, string> $members Member name => contents.
	 * @param array<int, string>    $encrypt Members to encrypt.
	 */
	private function zip(array $members, array $encrypt=[]): string {
		$path = $this->tempFile();
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($members as $name => $contents) {
			$zip->addFromString($name, $contents);
			if (in_array($name, $encrypt, true) === true) {
				$zip->setEncryptionName($name, ZipArchive::EM_AES_256, 'secret');
			}
		}

		$zip->close();
		return $path;
	}//end zip()

	private function extractor(): ArchiveTextExtractor {
		$mimes = $this->createMock(IMimeTypeDetector::class);
		$mimes->method('detectPath')->willReturnCallback(
			static fn (string $path): string => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
				'zip' => 'application/zip',
				'txt' => 'text/plain',
				'csv' => 'text/csv',
				'pdf' => 'application/pdf',
				'eml' => 'message/rfc822',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				default => 'application/octet-stream',
			}
		);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (): string => $this->tempFile());
		$settings = $this->createMock(FileSettingsHandler::class);
		$settings->method('getFileSettingsOnly')->willReturn([]);

		$logger = new NullLogger();
		return new ArchiveTextExtractor(
			new ArchiveMemberEnumerator($mimes, $temp, $settings, $logger),
			new PdfExtractor($logger),
			new SpreadsheetExtractor($logger),
			new EmlParser($logger),
			$logger
		);
	}//end extractor()

	/**
	 * The service over a file 77 with this mime type and these bytes.
	 *
	 * @param string $mimeType The file's mime type.
	 * @param string $bytes    The file's bytes.
	 */
	private function extract(string $mimeType, string $bytes): void {
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFile')->willReturn(
			['fileid' => 77, 'mimetype' => $mimeType, 'name' => 'dossier.zip', 'path' => '/admin/files/dossier.zip', 'size' => strlen($bytes), 'mtime' => 1700000000]
		);

		$path = $this->tempFile();
		file_put_contents($path, $bytes);
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('getName')->willReturn('dossier.zip');
		$file->method('fopen')->willReturnCallback(static fn () => fopen($path, 'r'));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getById')->with(77)->willReturn([$file]);

		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('insert')->willReturnCallback(
			function (Chunk $chunk): Chunk {
				$this->inserted[] = $chunk;
				return $chunk;
			}
		);

		$logger = new NullLogger();
		$args   = TextExtractionServiceFactory::args(
			$fileMapper,
			$chunkMapper,
			$rootFolder,
			$this->createMock(IDBConnection::class),
			$logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(EntityRecognitionHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(RiskLevelService::class),
			new EmlParser($logger),
			new SpreadsheetExtractor($logger),
			new PdfExtractor($logger),
			new WordExtractor($logger)
		);
		$readers = $args[3];
		$args[3] = new TextExtractionReaders($readers->emlParser, $readers->spreadsheetExtractor, $readers->pdfExtractor, $readers->wordExtractor, $this->extractor());

		(new TextExtractionService(...$args))->extractFile(77);
	}//end extract()

	/**
	 * The inserted content chunks as member => joined text.
	 *
	 * @return array<string, string>
	 */
	private function memberTexts(): array {
		$texts = [];
		foreach ($this->inserted as $chunk) {
			if ($chunk->getChunkIndex() === -1) {
				continue;
			}

			$member         = (string) ($chunk->getPositionReference()['member'] ?? '');
			$texts[$member] = ($texts[$member] ?? '').$chunk->getTextContent();
		}

		return $texts;
	}//end memberTexts()

	private function metadataChunk(): Chunk {
		foreach ($this->inserted as $chunk) {
			if ($chunk->getChunkIndex() === -1) {
				return $chunk;
			}
		}

		$this->fail('no metadata chunk was stored');
	}//end metadataChunk()

	/**
	 * The extraction status the sidebar returns over the stored chunks.
	 *
	 * @return array<string, mixed>
	 */
	private function extractionStatus(): array {
		$chunkMapper = $this->createMock(ChunkMapper::class);
		$chunkMapper->method('findBySource')->with('file', 77)->willReturn($this->inserted);
		$relations = $this->createMock(EntityRelationMapper::class);
		$relations->method('findByFileId')->willReturn([]);

		return (new FileSidebarService(
			new FileSidebarSchemaLookup($this->createMock(RegisterMapper::class), $this->createMock(SchemaMapper::class)),
			new FileSidebarEntityStore($this->createMock(IDBConnection::class), $chunkMapper, $relations, $this->createMock(GdprEntityMapper::class)),
			$this->createMock(RiskLevelService::class),
			new NullLogger()
		))->getExtractionStatus(77);
	}//end status()

	public function testEachMemberGetsItsOwnChunks(): void {
		$inner = (string) file_get_contents($this->zip(['diep.txt' => 'Een notitie in een geneste map.']));
		$this->extract(
			mimeType: 'application/zip',
			bytes: (string) file_get_contents(
				$this->zip(
					[
						'brieven/bezwaar-2024.pdf' => self::pdf(text: 'Bezwaar tegen de kapvergunning Lindelaan.'),
						'notities.txt'             => 'De hoorzitting is op dinsdag.',
						'bijlagen.zip'             => $inner,
					]
				)
			)
		);

		$texts = $this->memberTexts();
		$this->assertSame(['brieven/bezwaar-2024.pdf', 'notities.txt', 'bijlagen.zip/diep.txt'], array_keys($texts));
		$this->assertStringContainsString('kapvergunning Lindelaan', $texts['brieven/bezwaar-2024.pdf']);
		$this->assertSame('De hoorzitting is op dinsdag.', $texts['notities.txt']);
		$this->assertSame('Een notitie in een geneste map.', $texts['bijlagen.zip/diep.txt']);

		$indexes = [];
		foreach ($this->inserted as $chunk) {
			$indexes[] = $chunk->getChunkIndex();
			$this->assertSame('file', $chunk->getSourceType());
			$this->assertSame(77, $chunk->getSourceId());
		}

		$this->assertSame([0, 1, 2, -1], $indexes, 'one run of indexes across members, the metadata chunk last');
		$this->assertSame('text-range', $this->inserted[1]->getPositionReference()['type']);
		$this->assertSame(0, $this->inserted[1]->getPositionReference()['start'], 'offsets are within the member');

		$report = $this->metadataChunk()->getPositionReference()['archive'];
		$this->assertSame(['type' => 'application/zip', 'status' => 'complete', 'members' => 3, 'unread' => []], $report);
		$this->assertSame('completed', $this->extractionStatus()['extractionStatus']);
	}//end testEachMemberGetsItsOwnChunks()

	public function testAnEncryptedMemberMakesThePartialStatus(): void {
		$this->extract(
			mimeType: 'application/zip',
			bytes: (string) file_get_contents($this->zip(['open.txt' => 'Leesbare tekst.', 'geheim.txt' => 'Versleutelde tekst.'], encrypt: ['geheim.txt']))
		);

		$this->assertSame(['open.txt' => 'Leesbare tekst.'], $this->memberTexts());
		$status = $this->extractionStatus();
		$this->assertSame('partial', $status['extractionStatus']);
		$this->assertSame('partial', $status['archive']['status']);
		$this->assertSame([['path' => 'geheim.txt', 'reason' => 'encrypted']], $status['archive']['unread']);
	}//end testAnEncryptedMemberMakesThePartialStatus()

	public function testATarIsReportedUnsupportedAndKeepsItsMetadata(): void {
		$this->extract(mimeType: 'application/x-tar', bytes: 'not opened');

		$this->assertCount(1, $this->inserted, 'only the metadata chunk');
		$metadata = $this->metadataChunk();
		$this->assertStringContainsString('dossier.zip', $metadata->getTextContent(), 'findable by its name');
		$this->assertStringContainsString('"status": "unsupported"', $metadata->getTextContent());

		$status = $this->extractionStatus();
		$this->assertSame('partial', $status['extractionStatus']);
		$this->assertSame(['type' => 'application/x-tar', 'status' => 'unsupported', 'members' => 0, 'unread' => []], $status['archive']);
	}//end testATarIsReportedUnsupportedAndKeepsItsMetadata()

	public function testRefusedUnsupportedAndOverBoundMembersAreListed(): void {
		$path = $this->zip(['../../config/config.php' => '<?php', 'programma.bin' => "\x00\x01", 'a.txt' => 'A.', 'b.txt' => 'B.']);

		$extraction = $this->extractor()->extractPath(path: $path, bounds: new ArchiveBounds(maxMembers: 3));

		$this->assertSame([['path' => 'a.txt', 'text' => 'A.']], $extraction->texts());
		$this->assertSame(
			[
				['path' => '../../config/config.php', 'reason' => 'refused'],
				['path' => 'programma.bin', 'reason' => 'unsupported'],
				['path' => 'b.txt', 'reason' => 'over-bound:maxMembers'],
			],
			$extraction->unread()
		);
		$this->assertSame('partial', $extraction->status());
	}//end testRefusedUnsupportedAndOverBoundMembersAreListed()

	public function testOfficeSpreadsheetAndMailMembersAreRead(): void {
		$extractor = $this->extractor();
		$docx      = (string) file_get_contents(dirname(__DIR__, 3).'/Data/Word/word-structures.docx');

		$this->assertStringContainsString('Besluit op aanvraag', (string) $extractor->memberText(bytes: $docx, mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		$this->assertStringContainsString("naam\tplaats", (string) $extractor->memberText(bytes: "naam,plaats\nJan,Utrecht\n", mimeType: 'text/csv'));
		$mail = "Subject: Bezwaar\r\nContent-Type: text/plain\r\n\r\nGraag een reactie op het bezwaar.\r\n";
		$this->assertStringContainsString('Graag een reactie op het bezwaar.', (string) $extractor->memberText(bytes: $mail, mimeType: 'message/rfc822'));
		$this->assertNull($extractor->memberText(bytes: 'x', mimeType: 'application/msword'), 'legacy .doc needs the office server: unsupported here');
		$this->assertTrue(ArchiveTextExtractor::isZip('application/x-zip-compressed'));
		$this->assertTrue(ArchiveTextExtractor::isUnsupportedArchive('application/x-7z-compressed'));
		$this->assertFalse(ArchiveTextExtractor::isUnsupportedArchive('application/zip'));
	}//end testOfficeSpreadsheetAndMailMembersAreRead()

	/**
	 * A one-page PDF with a text layer.
	 *
	 * @param string $text The page text.
	 */
	private static function pdf(string $text): string {
		$stream  = (string) gzcompress("BT\n/F1 12 Tf\n72 720 Td\n(".$text.") Tj\nET\n", 6);
		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			4 => '<< /Length '.strlen($stream)." /Filter /FlateDecode >>\nstream\n".$stream."\nendstream",
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		];

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf          .= $oid." 0 obj\n".$body."\nendobj\n";
		}

		$xrefStart = strlen($pdf);
		$pdf      .= "xref\n0 6\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xrefStart."\n%%EOF\n";
	}//end pdf()
}//end class
