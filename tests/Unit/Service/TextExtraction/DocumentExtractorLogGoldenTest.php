<?php

/**
 * DocumentExtractorLogGoldenTest
 *
 * Characterisation test for the log lines {@see DocumentExtractor::extract()}
 * writes on each of its paths: unsupported format, extracted, no readable
 * content, refused parts and a package that is not a zip. The source line
 * is left out (it moves with any edit); the file is reduced to its base name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\DocumentExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use ZipArchive;

/**
 * Pins the log lines and results of DocumentExtractor::extract().
 */
#[RequiresPhpExtension('zip')]
class DocumentExtractorLogGoldenTest extends TestCase {

	private const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * The captured log lines.
	 *
	 * @var list<string>
	 */
	private array $lines = [];

	/**
	 * The extractor under test.
	 *
	 * @var DocumentExtractor
	 */
	private DocumentExtractor $extractor;

	/**
	 * Build the extractor with a capturing logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$lines = &$this->lines;
		$logger = new class($lines) extends AbstractLogger {
			/**
			 * The capture buffer.
			 *
			 * @var list<string>
			 */
			private array $lines;

			/**
			 * Constructor.
			 *
			 * @param list<string> $lines The capture buffer.
			 */
			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			/**
			 * Capture one line without the source line number.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['line']);
				$context['file'] = basename((string)($context['file'] ?? ''));
				$this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
			}
		};
		$words = $this->createMock(WordExtractor::class);
		$words->method('extract')->willReturn('');
		$this->extractor = new DocumentExtractor(logger: $logger, wordExtractor: $words);
	}//end setUp()

	/**
	 * An unsupported format logs at debug and returns null; a generic MIME type
	 * with a document extension is read.
	 *
	 * @return void
	 */
	public function testUnsupportedAndGenericFormats(): void {
		$this->assertNull($this->extractor->extract(file: $this->file(content: 'x', mime: 'application/pdf', name: 'a.pdf')));
		$result = $this->extractor->extract(file: $this->file(content: $this->docx(body: '<w:p><w:r><w:t>Hallo</w:t></w:r></w:p>'), mime: 'application/zip', name: 'a.DOCX'));

		$this->assertSame('Hallo', $result['text']);
		$this->assertSame(
			[
				'debug [DocumentExtractor] Not a document format this extractor reads {"file":"DocumentExtractor.php","fileId":7,"mimeType":"application\/pdf"}',
				'debug [DocumentExtractor] Document extracted {"file":"DocumentExtractor.php","fileId":7,"sections":1,"truncated":false}',
			],
			$this->lines
		);
	}//end testUnsupportedAndGenericFormats()

	/**
	 * An empty document, refused parts and garbage bytes each log their line.
	 *
	 * @return void
	 */
	public function testNullPaths(): void {
		$this->assertNull($this->extractor->extract(file: $this->file(content: $this->docx(body: '<w:p/>'), mime: self::MIME, name: 'a.docx')));
		$doctype = $this->zip(['word/document.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "BOEM">]><w:document xmlns:w="' . self::W_NS . '"/>']);
		$this->assertNull($this->extractor->extract(file: $this->file(content: $doctype, mime: self::MIME, name: 'a.docx')));
		$this->assertNull($this->extractor->extract(file: $this->file(content: 'not a zip', mime: self::MIME, name: 'a.docx')));

		$this->assertSame(
			[
				'warning [DocumentExtractor] Document holds no readable content {"file":"DocumentExtractor.php","fileId":7,"mimeType":"application\/vnd.openxmlformats-officedocument.wordprocessingml.document"}',
				'warning [DocumentExtractor] Refused parts that were too large, declared a DOCTYPE or were not XML {"file":"DocumentExtractor.php","fileId":7,"parts":["word\/document.xml"]}',
				'warning [DocumentExtractor] Document holds no readable content {"file":"DocumentExtractor.php","fileId":7,"mimeType":"application\/vnd.openxmlformats-officedocument.wordprocessingml.document"}',
				'error [DocumentExtractor] Document extraction failed; returning null {"file":"DocumentExtractor.php","fileId":7,"mimeType":"application\/vnd.openxmlformats-officedocument.wordprocessingml.document","exception":"RuntimeException"}',
			],
			$this->lines
		);
	}//end testNullPaths()

	/**
	 * A mocked file.
	 *
	 * @param string $content The bytes.
	 * @param string $mime The MIME type.
	 * @param string $name The file name.
	 *
	 * @return File
	 */
	private function file(string $content, string $mime, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(7);
		return $file;
	}//end file()

	/**
	 * A minimal document package with the given body.
	 *
	 * @param string $body The body XML.
	 *
	 * @return string The package bytes.
	 */
	private function docx(string $body): string {
		return $this->zip(['word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="' . self::W_NS . '"><w:body>' . $body . '</w:body></w:document>']);
	}//end docx()

	/**
	 * Zip the given parts.
	 *
	 * @param array<string, string> $parts Part path to content.
	 *
	 * @return string The package bytes.
	 */
	private function zip(array $parts): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'docx-golden-');
		$zip = new ZipArchive();
		$zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end zip()
}//end class
