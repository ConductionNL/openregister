<?php

/**
 * Word-family text extraction tests for TextExtractionService.
 *
 * Covers the `text-extraction-word-completeness` change: full recursive
 * extraction (body + tables incl. nested + headers/footers + footnotes/
 * endnotes), MIME/extension → reader selection (DOCX/DOC/ODT), and graceful
 * null fallback on unparseable / empty input. Fixtures live in tests/Data/Word.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.openregister.app
 */

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @group DB
 */
class TextExtractionWordExtractionTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var TextExtractionService
	 */
	private TextExtractionService $service;

	/**
	 * The handler that owns the Word reader selection and the element walk.
	 *
	 * @var \OCA\OpenRegister\Service\TextExtraction\WordExtractor
	 */
	private \OCA\OpenRegister\Service\TextExtraction\WordExtractor $wordExtractor;

	/**
	 * Temp fixture paths to clean up.
	 *
	 * @var string[]
	 */
	private array $tmpFiles = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = \OC::$server->get(TextExtractionService::class);
		// TextExtractionService::extractWord() is a one-line delegation to WordExtractor.
		$this->wordExtractor = \OC::$server->get(\OCA\OpenRegister\Service\TextExtraction\WordExtractor::class);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tmpFiles as $path) {
			if (is_file($path) === true) {
				@unlink($path);
			}
		}

		parent::tearDown();
	}

	/**
	 * Invoke a private/protected method via reflection.
	 *
	 * @param object $object Target instance.
	 * @param string $methodName Method name.
	 * @param array $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function invokePrivate(object $object, string $methodName, array $args = []): mixed {
		$ref = new ReflectionMethod($object, $methodName);
		$ref->setAccessible(true);
		return $ref->invoke($object, ...$args);
	}

	/**
	 * Build a mock Nextcloud File returning the given bytes / MIME / name.
	 *
	 * @param string $bytes Raw file content.
	 * @param string $mime MIME type.
	 * @param string $name File name.
	 *
	 * @return File
	 */
	private function mockFile(string $bytes, string $mime, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($bytes);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(424242);
		return $file;
	}

	/**
	 * DOCX → reader Word2007, DOC → MsDoc, ODT → ODText, unknown → Word2007.
	 *
	 * @return void
	 */
	public function testResolveWordReaderMapsMimeAndExtension(): void {
		$docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$odt = 'application/vnd.oasis.opendocument.text';

		$this->assertSame('Word2007', $this->invokePrivate($this->wordExtractor, 'resolveWordReader', [$docx, 'a.docx']));
		$this->assertSame('MsDoc', $this->invokePrivate($this->wordExtractor, 'resolveWordReader', ['application/msword', 'a.doc']));
		$this->assertSame('ODText', $this->invokePrivate($this->wordExtractor, 'resolveWordReader', [$odt, 'a.odt']));
		// Generic MIME → fall back to extension.
		$this->assertSame('MsDoc', $this->invokePrivate($this->wordExtractor, 'resolveWordReader', ['application/octet-stream', 'legacy.doc']));
		$this->assertSame('ODText', $this->invokePrivate($this->wordExtractor, 'resolveWordReader', ['application/octet-stream', 'open.odt']));
		// Unknown MIME and extension → safe default.
		$this->assertSame('Word2007', $this->invokePrivate($this->wordExtractor, 'resolveWordReader', ['application/octet-stream', 'mystery.bin']));
	}

	/**
	 * A DOCX yields body, table cells (nested too), list items, header, footer and footnote.
	 *
	 * @return void
	 */
	public function testDocxExtractionCapturesAllNiches(): void {
		$file = $this->mockFile(
			(string)file_get_contents(__DIR__ . '/../Data/Word/word-structures.docx'),
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'rich.docx'
		);

		$text = $this->invokePrivate($this->service, 'extractWord', [$file]);

		$this->assertIsString($text);
		$this->assertStringContainsString('Besluit op aanvraag', $text, 'body text (non-regression)');
		$this->assertStringContainsString('Gemachtigde', $text, 'table cell text');
		$this->assertStringContainsString('Sanne Bakker', $text, 'nested table cell text');
		$this->assertStringContainsString('Eerste punt voor Jan Jansen', $text, 'list item text');
		$this->assertStringContainsString('Gemeente Demostad kenmerk ZK-2026-0042', $text, 'header text');
		$this->assertStringContainsString('Behandelaar Jan Jansen', $text, 'footer text');
		$this->assertStringContainsString('Voetnoot over Maria Bakker.', $text, 'footnote text');
	}

	/**
	 * An ODT input is extracted through the same Word branch.
	 *
	 * @return void
	 */
	public function testOdtExtractionProducesText(): void {
		$file = $this->mockFile(
			(string)file_get_contents(__DIR__ . '/../Data/Word/word-structures.odt'),
			'application/vnd.oasis.opendocument.text',
			'doc.odt'
		);
		$text = $this->invokePrivate($this->service, 'extractWord', [$file]);

		$this->assertIsString($text);
		$this->assertStringContainsString('Besluit op aanvraag', $text);
	}

	/**
	 * Unparseable content degrades to null instead of throwing.
	 *
	 * @return void
	 */
	public function testExtractWordReturnsNullOnUnparseableContent(): void {
		$file = $this->mockFile(
			'this is not a real office document at all',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'broken.docx'
		);

		$this->assertNull($this->invokePrivate($this->service, 'extractWord', [$file]));
	}

	/**
	 * A valid DOCX without text returns null.
	 *
	 * @return void
	 */
	public function testExtractWordReturnsNullOnEmptyDocument(): void {
		$file = $this->mockFile(
			$this->docx(body: '<w:p/>'),
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'empty.docx'
		);

		$this->assertNull($this->invokePrivate($this->service, 'extractWord', [$file]));
	}

	/**
	 * Nesting beyond the XML parser's depth limit does not crash extraction; the part is unreadable.
	 *
	 * @return void
	 */
	public function testPathologicalNestingReturnsNull(): void {
		$body = str_repeat('<w:tbl><w:tr><w:tc>', 120) . '<w:p><w:r><w:t>DEEP</w:t></w:r></w:p>' . str_repeat('</w:tc></w:tr></w:tbl>', 120);
		$file = $this->mockFile(
			$this->docx(body: $body),
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'deep.docx'
		);

		$this->assertNull($this->invokePrivate($this->service, 'extractWord', [$file]));
	}

	/**
	 * A minimal DOCX package with the given body XML.
	 *
	 * @param string $body Content of `w:body`.
	 *
	 * @return string The package bytes.
	 */
	private function docx(string $body): string {
		$path = tempnam(sys_get_temp_dir(), 'ortest_');
		$this->tmpFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
		$zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
		$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
			. $body . '</w:body></w:document>');
		$zip->close();

		return (string)file_get_contents($path);
	}

	/**
	 * The parse-failure log path is PII-safe: its context carries only
	 * structural fields (file ID / MIME / reader / exception class) and never
	 * the document content or extracted text (ADR-005).
	 *
	 * @return void
	 */
	public function testParseFailureLogsNoDocumentContent(): void {
		// The failure-path log moved with the code: extractWord() delegates to
		// WordExtractor::extract(), which is where the catch block lives now.
		$src = (string)file_get_contents(__DIR__ . '/../../lib/Service/TextExtraction/WordExtractor.php');

		// Isolate the failure-path log call inside extractWord().
		$start = strpos($src, 'Word extraction failed; returning null');
		$this->assertNotFalse($start, 'failure-path log message must exist');
		$block = substr($src, $start, 400);

		// Structural fields only.
		$this->assertStringContainsString("'fileId'", $block);
		$this->assertStringContainsString("'mimeType'", $block);
		$this->assertStringContainsString("'reader'", $block);
		$this->assertStringContainsString("'exception' => get_class(\$e)", $block);

		// Must NOT leak document body / extracted text into the failure context.
		$this->assertStringNotContainsString('$content', $block, 'raw file content must not be logged');
		$this->assertStringNotContainsString('getContent()', $block, 'raw file content must not be logged');
		$this->assertStringNotContainsString("'text'", $block, 'extracted text must not be logged');
		$this->assertStringNotContainsString('$e->getMessage()', $block, 'exception message (may echo content) must not be logged in this path');
	}
}//end class
