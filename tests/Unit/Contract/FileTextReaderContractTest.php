<?php

/**
 * The published read contract for a document's text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace Unit\Contract;

use Exception;
use OCA\OpenRegister\Contract\FileTextReaderInterface;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Service\File\FileTextReader;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A consuming app reads a document's text through the interface.
 */
#[CoversClass(FileTextReader::class)]
class FileTextReaderContractTest extends TestCase {

	/**
	 * The PDF extractor.
	 *
	 * @var PdfExtractor&MockObject
	 */
	private PdfExtractor&MockObject $pdf;

	/**
	 * The Word extractor.
	 *
	 * @var WordExtractor&MockObject
	 */
	private WordExtractor&MockObject $word;

	/**
	 * Fresh extractors per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->pdf  = $this->createMock(originalClassName: PdfExtractor::class);
		$this->word = $this->createMock(originalClassName: WordExtractor::class);
	}//end setUp()

	/**
	 * The reader implements the published interface.
	 *
	 * @return void
	 */
	public function testTheReaderImplementsTheInterface(): void {
		$this->assertTrue(condition: is_a(FileTextReader::class, FileTextReaderInterface::class, true));
	}//end testTheReaderImplementsTheInterface()

	/**
	 * The container answers the interface with the reader, so an app can inject the interface.
	 *
	 * @return void
	 */
	public function testTheContainerBindsTheInterface(): void {
		$source = (string)file_get_contents(filename: __DIR__ . '/../../../lib/Service/ServiceRegistrar.php');

		$this->assertMatchesRegularExpression(
			pattern: '/registerService\(\s*FileTextReaderInterface::class,\s*function \(\$c\) \{\s*return \$c->get\(FileTextReader::class\);/',
			string: $source
		);
	}//end testTheContainerBindsTheInterface()

	/**
	 * A Word or OpenDocument file is read by the Word extractor, and its text comes back.
	 *
	 * @param string $mime The file's mime type.
	 * @param string $name The file's name.
	 *
	 * @return void
	 */
	#[DataProvider('wordFiles')]
	public function testAWordFileIsReadByTheWordExtractor(string $mime, string $name): void {
		$file = $this->file(mime: $mime, name: $name);
		$this->word->expects($this->once())->method('extract')->with($file)->willReturn('Requirements');
		$this->pdf->expects($this->never())->method('extract');

		$this->assertSame(expected: 'Requirements', actual: $this->readThrough(file: $file));
	}//end testAWordFileIsReadByTheWordExtractor()

	/**
	 * Word-family files by mime type, and by extension behind a generic mime type.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function wordFiles(): array {
		return [
			'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'brief.docx'],
			'odt' => ['application/vnd.oasis.opendocument.text', 'brief.odt'],
			'doc' => ['application/msword', 'brief.doc'],
			'docx served as octet-stream' => ['application/octet-stream', 'Brief.DOCX'],
		];
	}//end wordFiles()

	/**
	 * A PDF is read by the PDF extractor.
	 *
	 * @return void
	 */
	public function testAPdfIsReadByThePdfExtractor(): void {
		$file = $this->file(mime: 'application/pdf', name: 'brief.pdf');
		$this->pdf->expects($this->once())->method('extract')->with($file)->willReturn('Scope');
		$this->word->expects($this->never())->method('extract');

		$this->assertSame(expected: 'Scope', actual: $this->readThrough(file: $file));
	}//end testAPdfIsReadByThePdfExtractor()

	/**
	 * A plain text or Markdown file is read as it is, without an extractor.
	 *
	 * @return void
	 */
	public function testATextFileIsReadAsItIs(): void {
		$file = $this->file(mime: 'text/markdown', name: 'brief.md');
		$file->expects($this->once())->method('getContent')->willReturn("# Brief\n");
		$this->pdf->expects($this->never())->method('extract');
		$this->word->expects($this->never())->method('extract');

		$this->assertSame(expected: "# Brief\n", actual: $this->readThrough(file: $file));
	}//end testATextFileIsReadAsItIs()

	/**
	 * A type with no reader answers null and opens nothing; a known extension does not
	 * override a specific mime type.
	 *
	 * @return void
	 */
	public function testAnUnreadTypeAnswersNull(): void {
		$image = $this->file(mime: 'image/png', name: 'scan.pdf');
		$image->expects($this->never())->method('getContent');
		$this->pdf->expects($this->never())->method('extract');
		$this->word->expects($this->never())->method('extract');

		$this->assertNull(actual: $this->readThrough(file: $image));
		$this->assertNull(actual: $this->readThrough(file: $this->file(mime: 'application/octet-stream', name: 'blob.bin')));
	}//end testAnUnreadTypeAnswersNull()

	/**
	 * An extractor's failure reaches the caller rather than reading as "no text".
	 *
	 * @return void
	 */
	public function testAReaderFailureReachesTheCaller(): void {
		$this->pdf->method('extract')->willThrowException(new Exception('PDF extraction failed: broken'));

		$this->expectException(exception: Exception::class);
		$this->expectExceptionMessage(message: 'PDF extraction failed: broken');

		$this->readThrough(file: $this->file(mime: 'application/pdf', name: 'broken.pdf'));
	}//end testAReaderFailureReachesTheCaller()

	/**
	 * The readable types name text, PDF, Word and OpenDocument text.
	 *
	 * @return void
	 */
	public function testTheReadableTypesAreListed(): void {
		$reader = new FileTextReader(pdfExtractor: $this->pdf, wordExtractor: $this->word);

		$this->assertSame(
			expected: [
				'text/plain',
				'text/markdown',
				'text/x-markdown',
				'application/pdf',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/msword',
				'application/vnd.oasis.opendocument.text',
			],
			actual: $reader->readableMimeTypes()
		);
	}//end testTheReadableTypesAreListed()

	/**
	 * A file node with this mime type and name.
	 *
	 * @param string $mime The mime type.
	 * @param string $name The name.
	 *
	 * @return File&MockObject
	 */
	private function file(string $mime, string $name): File&MockObject {
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		return $file;
	}//end file()

	/**
	 * Read as a consumer does: typed on the interface only.
	 *
	 * @param File $file The file.
	 *
	 * @return string|null The text.
	 */
	private function readThrough(File $file): ?string {
		$reader = new FileTextReader(pdfExtractor: $this->pdf, wordExtractor: $this->word);
		return $this->read(reader: $reader, file: $file);
	}//end readThrough()

	/**
	 * Call the interface.
	 *
	 * @param FileTextReaderInterface $reader The reader.
	 * @param File                    $file   The file.
	 *
	 * @return string|null The text.
	 */
	private function read(FileTextReaderInterface $reader, File $file): ?string {
		return $reader->readText(file: $file);
	}//end read()
}//end class
