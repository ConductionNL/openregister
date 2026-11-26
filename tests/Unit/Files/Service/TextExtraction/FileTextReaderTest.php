<?php

/**
 * Unit tests for FileTextReader: the published text read of one file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/a-file-text-read-for-consuming-apps/specs/text-extraction/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use OCA\OpenRegister\Contract\FileTextReaderInterface;
use OCA\OpenRegister\Files\Service\TextExtraction\FileTextReader;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each type goes to the reader it needs, and nothing else is read.
 */
class FileTextReaderTest extends TestCase {

	/**
	 * A file node with a MIME type, a name and, optionally, content or a local path.
	 *
	 * @param string      $mimeType The MIME type.
	 * @param string      $name     The file name.
	 * @param string      $content  The content getContent() answers.
	 * @param string|null $path     A local file fopen() opens, for the real Word reader.
	 *
	 * @return File
	 */
	private function file(string $mimeType, string $name, string $content='', ?string $path=null): File {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(42);
		$file->method('getContent')->willReturnCallback(static fn (): string => $path === null ? $content : (string)file_get_contents($path));
		$file->method('fopen')->willReturnCallback(static fn (string $mode) => $path === null ? false : fopen($path, $mode));
		$file->method('getSize')->willReturnCallback(static fn (): int => $path === null ? strlen($content) : (int)filesize($path));

		return $file;
	}//end file()

	/**
	 * The reader is the published contract.
	 *
	 * @return void
	 */
	public function testTheReaderIsThePublishedContract(): void {
		$reader = new FileTextReader(pdfExtractor: $this->createMock(PdfExtractor::class), wordExtractor: $this->createMock(WordExtractor::class));

		$this->assertInstanceOf(FileTextReaderInterface::class, $reader);
	}//end testTheReaderIsThePublishedContract()

	/**
	 * A PDF goes to the PDF reader only.
	 *
	 * @return void
	 */
	public function testAPdfIsReadByThePdfReader(): void {
		$file = $this->file(mimeType: 'application/pdf', name: 'brief.pdf');
		$pdf  = $this->createMock(PdfExtractor::class);
		$pdf->expects($this->once())->method('extract')->with($file)->willReturn('Vergunning aanvragen');
		$word = $this->createMock(WordExtractor::class);
		$word->expects($this->never())->method('extract');

		$this->assertSame('Vergunning aanvragen', (new FileTextReader(pdfExtractor: $pdf, wordExtractor: $word))->readFileText(file: $file));
	}//end testAPdfIsReadByThePdfReader()

	/**
	 * Word and OpenDocument text go to the Word reader, also by extension under a generic MIME type.
	 *
	 * @return void
	 */
	public function testWordAndOpenDocumentAreReadByTheWordReader(): void {
		$pdf = $this->createMock(PdfExtractor::class);
		$pdf->expects($this->never())->method('extract');
		$word = $this->createMock(WordExtractor::class);
		$word->expects($this->exactly(3))->method('extract')->willReturn('text');
		$reader = new FileTextReader(pdfExtractor: $pdf, wordExtractor: $word);

		$this->assertSame('text', $reader->readFileText(file: $this->file(mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', name: 'a.docx')));
		$this->assertSame('text', $reader->readFileText(file: $this->file(mimeType: 'application/vnd.oasis.opendocument.text', name: 'a.odt')));
		$this->assertSame('text', $reader->readFileText(file: $this->file(mimeType: 'application/octet-stream', name: 'a.DOCX')));
	}//end testWordAndOpenDocumentAreReadByTheWordReader()

	/**
	 * A real .docx is read through the Word reader.
	 *
	 * @return void
	 */
	#[RequiresPhpExtension('zip')]
	public function testARealDocxIsRead(): void {
		$path   = __DIR__ . '/../../../../Data/Word/word-structures.docx';
		$reader = new FileTextReader(pdfExtractor: $this->createMock(PdfExtractor::class), wordExtractor: new WordExtractor(logger: $this->createMock(LoggerInterface::class)));

		$text = $reader->readFileText(file: $this->file(mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', name: 'word-structures.docx', path: $path));

		$this->assertIsString($text);
		$this->assertNotSame('', trim($text));
	}//end testARealDocxIsRead()

	/**
	 * Plain text and Markdown are their own content; white space only is no text.
	 *
	 * @return void
	 */
	public function testPlainTextIsItsContent(): void {
		$reader = new FileTextReader(pdfExtractor: $this->createMock(PdfExtractor::class), wordExtractor: $this->createMock(WordExtractor::class));

		$this->assertSame("# Brief\n", $reader->readFileText(file: $this->file(mimeType: 'text/markdown', name: 'brief.md', content: "# Brief\n")));
		$this->assertSame('notes', $reader->readFileText(file: $this->file(mimeType: 'application/octet-stream', name: 'notes.txt', content: 'notes')));
		$this->assertNull($reader->readFileText(file: $this->file(mimeType: 'text/plain', name: 'empty.txt', content: "  \n")));
	}//end testPlainTextIsItsContent()

	/**
	 * A type without a reader answers null and reads nothing.
	 *
	 * @return void
	 */
	public function testATypeWithoutAReaderIsNull(): void {
		$pdf = $this->createMock(PdfExtractor::class);
		$pdf->expects($this->never())->method('extract');
		$word = $this->createMock(WordExtractor::class);
		$word->expects($this->never())->method('extract');

		$this->assertNull((new FileTextReader(pdfExtractor: $pdf, wordExtractor: $word))->readFileText(file: $this->file(mimeType: 'image/png', name: 'logo.png')));
	}//end testATypeWithoutAReaderIsNull()
}//end class
