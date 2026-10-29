<?php

declare(strict_types=1);

/**
 * WordExtractor Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for WordExtractor.
 *
 * Garbage bytes under a genuine docx/doc MIME type exercise the degrade-to-null
 * path a malformed real document takes. The MIME/extension to reader mapping
 * is checked through reflection. Real documents are covered by
 * {@see WordExtractorGoldenTest}.
 */
class WordExtractorTest extends TestCase {

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private WordExtractor $extractor;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->extractor = new WordExtractor(logger: $this->logger);
	}

	/**
	 * Build a mock Nextcloud File returning the given bytes/MIME/name.
	 *
	 * @param string $content Raw file content.
	 * @param string $mime MIME type.
	 * @param string $name File name.
	 *
	 * @return File&MockObject
	 */
	private function mockFile(string $content, string $mime, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(303);

		return $file;
	}

	/**
	 * Invoke the private resolveWordReader() method via reflection.
	 *
	 * @param string $mimeType MIME type argument.
	 * @param string $fileName File name argument.
	 *
	 * @return string
	 */
	private function invokeResolveWordReader(string $mimeType, string $fileName): string {
		$ref = new ReflectionMethod(WordExtractor::class, 'resolveWordReader');
		$ref->setAccessible(true);

		return $ref->invoke($this->extractor, $mimeType, $fileName);
	}

	/**
	 * @return void
	 */
	public function testResolveWordReaderDocxMime(): void {
		$result = $this->invokeResolveWordReader(
			mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			fileName: 'anything.bin'
		);

		$this->assertSame('Word2007', $result);
	}

	/**
	 * @return void
	 */
	public function testResolveWordReaderDocMime(): void {
		$result = $this->invokeResolveWordReader(mimeType: 'application/msword', fileName: 'anything.bin');

		$this->assertSame('MsDoc', $result);
	}

	/**
	 * @return void
	 */
	public function testResolveWordReaderOdtMime(): void {
		$result = $this->invokeResolveWordReader(
			mimeType: 'application/vnd.oasis.opendocument.text',
			fileName: 'anything.bin'
		);

		$this->assertSame('ODText', $result);
	}

	/**
	 * @return void
	 */
	public function testResolveWordReaderFallsBackToDocxExtension(): void {
		$result = $this->invokeResolveWordReader(mimeType: 'application/octet-stream', fileName: 'report.docx');

		$this->assertSame('Word2007', $result);
	}

	/**
	 * @return void
	 */
	public function testResolveWordReaderFallsBackToDocExtension(): void {
		$result = $this->invokeResolveWordReader(mimeType: 'application/octet-stream', fileName: 'report.doc');

		$this->assertSame('MsDoc', $result);
	}

	/**
	 * @return void
	 */
	public function testResolveWordReaderFallsBackToOdtExtension(): void {
		$result = $this->invokeResolveWordReader(mimeType: 'application/octet-stream', fileName: 'report.odt');

		$this->assertSame('ODText', $result);
	}

	/**
	 * Unknown MIME and unknown/absent extension must default to Word2007.
	 *
	 * @return void
	 */
	public function testResolveWordReaderDefaultsToWord2007ForUnknown(): void {
		$result = $this->invokeResolveWordReader(mimeType: 'application/octet-stream', fileName: 'mystery-file');

		$this->assertSame('Word2007', $result);
	}

	/**
	 * Legacy binary .doc is not read: null, and a log line without document
	 * content (ADR-005).
	 *
	 * @return void
	 */
	public function testExtractReturnsNullForLegacyDoc(): void {
		$this->logger->expects($this->once())
			->method('info')
			->with($this->stringContains('[WordExtractor] Legacy binary .doc is not supported'), ['fileId' => 303]);

		$result = $this->extractor->extract(
			file: $this->mockFile(content: 'not a real ole/doc binary', mime: 'application/msword', name: 'garbage.doc')
		);

		$this->assertNull($result);
	}

	/**
	 * A docx-typed file that is not a zip package must likewise degrade to
	 * null instead of throwing.
	 *
	 * @return void
	 */
	public function testExtractReturnsNullOnUnreadableDocxContent(): void {
		$result = $this->extractor->extract(
			file: $this->mockFile(
				content: 'not a real docx zip container',
				mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				name: 'garbage.docx'
			)
		);

		$this->assertNull($result);
	}
}//end class
