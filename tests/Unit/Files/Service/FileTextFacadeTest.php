<?php

/**
 * FileTextFacade reads a file's text in the person's own folder and writes nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/file-text-facade/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service;

use OCA\OpenRegister\Files\Service\FileTextFacade;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlBody;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlStructure;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/file-text-facade/specs/text-extraction/spec.md
 */
class FileTextFacadeTest extends TestCase {

	/** @var IRootFolder&MockObject */
	private IRootFolder $rootFolder;

	/** @var Folder&MockObject */
	private Folder $userFolder;

	/** @var PdfExtractor&MockObject */
	private PdfExtractor $pdf;

	/** @var WordExtractor&MockObject */
	private WordExtractor $word;

	/** @var EmlParser&MockObject */
	private EmlParser $eml;

	private FileTextFacade $facade;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);
		$this->pdf = $this->createMock(PdfExtractor::class);
		$this->word = $this->createMock(WordExtractor::class);
		$this->eml = $this->createMock(EmlParser::class);
		$readers = new TextExtractionReaders(
			emlParser: $this->eml,
			spreadsheetExtractor: $this->createMock(SpreadsheetExtractor::class),
			pdfExtractor: $this->pdf,
			wordExtractor: $this->word,
		);
		$this->facade = new FileTextFacade(
			rootFolder: $this->rootFolder,
			readers: $readers,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A readable file in the user's folder with this mime type.
	 *
	 * @param string $mimeType The mime type.
	 *
	 * @return File&MockObject
	 */
	private function fileOf(string $mimeType): File {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('isReadable')->willReturn(true);
		$this->userFolder->method('getFirstNodeById')->with(42)->willReturn($file);
		return $file;
	}//end fileOf()

	public function testItIsAvailable(): void {
		$this->assertTrue($this->facade->isAvailable());
	}//end testItIsAvailable()

	public function testAPdfIsReadThroughThePdfReaderAndSanitised(): void {
		$file = $this->fileOf('application/pdf');
		$this->pdf->expects($this->once())->method('extract')->with($file)->willReturn("  Offerte\0 2026  ");

		$this->assertSame('Offerte 2026', $this->facade->extractText(42, 'alice'));
	}//end testAPdfIsReadThroughThePdfReaderAndSanitised()

	public function testAPlainTextFileIsReadAsItsContent(): void {
		$file = $this->fileOf('text/markdown');
		$file->method('getContent')->willReturn('# Notes');
		$this->pdf->expects($this->never())->method('extract');

		$this->assertSame('# Notes', $this->facade->extractText(42, 'alice'));
	}//end testAPlainTextFileIsReadAsItsContent()

	public function testAWordFileIsReadThroughTheWordReader(): void {
		$this->fileOf('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		$this->word->method('extract')->willReturn('Besluit');

		$this->assertSame('Besluit', $this->facade->extractText(42, 'alice'));
	}//end testAWordFileIsReadThroughTheWordReader()

	public function testAMailIsFlattenedWithoutStoringItsHeaders(): void {
		$file = $this->fileOf('message/rfc822');
		$structure = new EmlStructure(headers: ['subject' => 'Hi'], body: new EmlBody(plainText: 'Hello', html: null), attachments: []);
		$this->eml->expects($this->once())->method('parse')->with($file)->willReturn($structure);
		$this->eml->expects($this->once())->method('flatten')->with($structure)->willReturn('Subject: Hi Hello');

		$this->assertSame('Subject: Hi Hello', $this->facade->extractText(42, 'alice'));
	}//end testAMailIsFlattenedWithoutStoringItsHeaders()

	public function testAFileOutsideTheUsersFolderGivesNull(): void {
		$this->userFolder->method('getFirstNodeById')->willReturn(null);
		$this->pdf->expects($this->never())->method('extract');

		$this->assertNull($this->facade->extractText(42, 'alice'));
	}//end testAFileOutsideTheUsersFolderGivesNull()

	public function testAFolderNodeGivesNull(): void {
		$this->userFolder->method('getFirstNodeById')->willReturn($this->createMock(Folder::class));

		$this->assertNull($this->facade->extractText(42, 'alice'));
	}//end testAFolderNodeGivesNull()

	public function testAnUnreadableFileGivesNull(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('isReadable')->willReturn(false);
		$this->userFolder->method('getFirstNodeById')->willReturn($file);
		$this->pdf->expects($this->never())->method('extract');

		$this->assertNull($this->facade->extractText(42, 'alice'));
	}//end testAnUnreadableFileGivesNull()

	public function testATypeWithoutAReaderGivesNull(): void {
		$this->fileOf('image/png');

		$this->assertNull($this->facade->extractText(42, 'alice'));
	}//end testATypeWithoutAReaderGivesNull()

	public function testAReaderThatThrowsGivesNull(): void {
		$this->fileOf('application/pdf');
		$this->pdf->method('extract')->willThrowException(new RuntimeException('broken'));

		$this->assertNull($this->facade->extractText(42, 'alice'));
	}//end testAReaderThatThrowsGivesNull()

	public function testEmptyTextGivesNull(): void {
		$this->fileOf('application/pdf');
		$this->pdf->method('extract')->willReturn(" \0 ");

		$this->assertNull($this->facade->extractText(42, 'alice'));
	}//end testEmptyTextGivesNull()

	public function testNoUserOrNoFileIdGivesNullWithoutALookup(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertNull($this->facade->extractText(0, 'alice'));
		$this->assertNull($this->facade->extractText(42, ''));
	}//end testNoUserOrNoFileIdGivesNullWithoutALookup()
}//end class
