<?php

declare(strict_types=1);

/*
 * ExecutableContentDetector Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\ExecutableContentDetector;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExecutableContentDetector — the single source of truth for
 * executable-content detection, shared by FileValidationHandler (the `/files`
 * endpoints) and FilePropertyHandler (the object-save path).
 *
 * The two callers each have their own tests covering their message shapes and
 * logging. These tests cover the detection rules themselves, once.
 */
class ExecutableContentDetectorTest extends TestCase {

	private ExecutableContentDetector $detector;

	protected function setUp(): void {
		parent::setUp();
		$this->detector = new ExecutableContentDetector();
	}//end setUp()

	/**
	 * The reproducer from the Jira attachment migration: a real 1283x926 PNG
	 * screenshot whose first 1024 bytes contain `<?=` at offset 710.
	 *
	 * @return string
	 */
	private function falsePositivePngPath(): string {
		return __DIR__ . '/../../../fixtures/files/php-tag-false-positive.png';
	}//end falsePositivePngPath()

	// =========================================================================
	// matchExecutableSignature — universal, unchanged by the #2776 scoping
	// =========================================================================

	/**
	 * @dataProvider executableSignatureProvider
	 */
	public function testMatchExecutableSignature(string $content, ?string $expected): void {
		$this->assertSame($expected, $this->detector->matchExecutableSignature($content));
	}//end testMatchExecutableSignature()

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function executableSignatureProvider(): array {
		return [
			'pe/exe' => ['MZ' . str_repeat("\x00", 32), 'Windows executable (PE/EXE)'],
			'elf' => ["\x7FELF" . str_repeat("\x00", 32), 'Linux/Unix executable (ELF)'],
			'sh shebang' => ["#!/bin/sh\necho hi", 'Shell script'],
			'bash shebang' => ["#!/bin/bash\necho hi", 'Bash script'],
			'env shebang' => ["#!/usr/bin/env python\n", 'Script with env shebang'],
			'php open tag' => ["<?php echo 1;", 'PHP script'],
			'java class' => ["\xCA\xFE\xBA\xBE" . str_repeat("\x00", 32), 'Java class file'],
			'plain text' => ['Hello, world.', null],
			// Offset 0 only: the same bytes later in the stream are not a match.
			'MZ not at offset 0' => ['some text then MZ more text', null],
			'empty' => ['', null],
		];
	}//end executableSignatureProvider()

	// =========================================================================
	// hasScriptShebang — universal, unchanged by the #2776 scoping
	// =========================================================================

	public function testHasScriptShebangDetectsInterpreterLine(): void {
		$this->assertTrue($this->detector->hasScriptShebang("#!/usr/bin/perl\nprint 1;\n"));
	}//end testHasScriptShebangDetectsInterpreterLine()

	public function testHasScriptShebangIgnoresOrdinaryText(): void {
		$this->assertFalse($this->detector->hasScriptShebang("A readme.\nNothing to see.\n"));
	}//end testHasScriptShebangIgnoresOrdinaryText()

	public function testHasScriptShebangIsNotSuppressedForBinaryContent(): void {
		// The scoping narrows the PHP-tag scan ONLY. A shebang in something
		// named like an image is still detected.
		$this->assertTrue($this->detector->hasScriptShebang("#!/bin/bash\nrm -rf /\n"));
	}//end testHasScriptShebangIsNotSuppressedForBinaryContent()

	// =========================================================================
	// sniffBinaryContentType
	// =========================================================================

	/**
	 * @dataProvider sniffProvider
	 */
	public function testSniffBinaryContentType(string $content, ?string $expected): void {
		$this->assertSame($expected, $this->detector->sniffBinaryContentType($content));
	}//end testSniffBinaryContentType()

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function sniffProvider(): array {
		return [
			'png' => ["\x89PNG\r\n\x1A\n....", 'image/png'],
			'jpeg' => ["\xFF\xD8\xFF\xE0....", 'image/jpeg'],
			'gif87' => ['GIF87a....', 'image/gif'],
			'gif89' => ['GIF89a....', 'image/gif'],
			'tiff le' => ["II*\x00....", 'image/tiff'],
			'tiff be' => ["MM\x00*....", 'image/tiff'],
			'ico' => ["\x00\x00\x01\x00....", 'image/vnd.microsoft.icon'],
			'psd' => ['8BPS....', 'image/vnd.adobe.photoshop'],
			'riff' => ['RIFF....WEBP', 'application/x-riff'],
			'pdf' => ['%PDF-1.7....', 'application/pdf'],
			'ole' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1....", 'application/x-ole-storage'],
			'zip' => ["PK\x03\x04....", 'application/zip'],
			'gzip' => ["\x1F\x8B\x08....", 'application/gzip'],
			'bzip2' => ['BZh9....', 'application/x-bzip2'],
			'xz' => ["\xFD7zXZ\x00....", 'application/x-xz'],
			'7z' => ["7z\xBC\xAF\x27\x1C....", 'application/x-7z-compressed'],
			'rar' => ["Rar!\x1A\x07....", 'application/vnd.rar'],
			'mp3' => ['ID3....', 'audio/mpeg'],
			'ogg' => ['OggS....', 'application/ogg'],
			'flac' => ['fLaC....', 'audio/flac'],
			'matroska' => ["\x1A\x45\xDF\xA3....", 'video/x-matroska'],
			'flv' => ["FLV\x01....", 'video/x-flv'],
			'sqlite' => ["SQLite format 3\x00....", 'application/vnd.sqlite3'],
			'mp4 ftyp at offset 4' => ["\x00\x00\x00\x20ftypisom....", 'video/mp4'],
			// Negative cases — these keep the full scan.
			'plain text' => ['Dear customer,', null],
			'html' => ['<!doctype html>', null],
			'empty' => ['', null],
			'unknown bytes' => ["\x01\x02\x03\x04\x05", null],
			// Too short for the ftyp probe.
			'short ftyp-like' => ["\x00\x00\x00\x20ftyp", null],
		];
	}//end sniffProvider()

	public function testSniffIdentifiesTheRealPngReproducer(): void {
		$content = (string)file_get_contents($this->falsePositivePngPath());

		$this->assertSame('image/png', $this->detector->sniffBinaryContentType($content));
	}//end testSniffIdentifiesTheRealPngReproducer()

	// =========================================================================
	// hasAlwaysScannedExtension
	// =========================================================================

	/**
	 * @dataProvider extensionProvider
	 */
	public function testHasAlwaysScannedExtension(string $fileName, bool $expected): void {
		$this->assertSame($expected, $this->detector->hasAlwaysScannedExtension($fileName));
	}//end testHasAlwaysScannedExtension()

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function extensionProvider(): array {
		return [
			'html' => ['page.html', true],
			'HTML uppercase' => ['PAGE.HTML', true],
			'phtml' => ['x.phtml', true],
			'svg' => ['logo.svg', true],
			'xml' => ['feed.xml', true],
			'txt' => ['notes.txt', true],
			'json' => ['data.json', true],
			'htaccess' => ['.htaccess', true],
			'no extension' => ['attachment', true],
			'png' => ['photo.png', false],
			'pdf' => ['report.pdf', false],
			'docx' => ['contract.docx', false],
			'mp4' => ['clip.mp4', false],
			'unknown binary-ish' => ['blob.dat', false],
		];
	}//end extensionProvider()

	// =========================================================================
	// containsEmbeddedPhpTag — the scoped check, both directions
	// =========================================================================

	public function testRealPngReproducerIsNotFlagged(): void {
		$content = (string)file_get_contents($this->falsePositivePngPath());

		// Guard the guard: the bytes that tripped the old universal rule must
		// still be present, or this test proves nothing.
		$this->assertStringContainsString('<?=', substr($content, 0, 1024));
		$this->assertFalse($this->detector->containsEmbeddedPhpTag($content, 'Gegevens weergeven.PNG'));
	}//end testRealPngReproducerIsNotFlagged()

	/**
	 * @dataProvider embeddedPhpProvider
	 */
	public function testContainsEmbeddedPhpTag(string $content, string $fileName, bool $expected): void {
		$this->assertSame($expected, $this->detector->containsEmbeddedPhpTag($content, $fileName));
	}//end testContainsEmbeddedPhpTag()

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function embeddedPhpProvider(): array {
		$php = "<?php system(\$_GET['c']); ?>";

		return [
			// Skipped: positively-sniffed binary container, non-interpreted name.
			'png' => ["\x89PNG\r\n\x1A\n$php", 'shot.png', false],
			'pdf' => ["%PDF-1.7$php", 'report.pdf', false],
			'docx' => ["PK\x03\x04$php", 'contract.docx', false],
			'mp4' => ["\x00\x00\x00\x20ftypisom$php", 'clip.mp4', false],

			// Scanned: text-ish or unidentified content.
			'plain text' => ["notes\n$php", 'notes.txt', true],
			'html' => ["<html>$php</html>", 'page.html', true],
			'xml short echo' => ["<root><?= 'x' ?></root>", 'feed.xml', true],
			'svg' => ["<svg>$php</svg>", 'logo.svg', true],
			'script language tag' => ['<script language="php">echo 1;</script>', 'page.html', true],
			'no extension' => ["notes\n$php", 'attachment', true],
			'unknown byte stream' => ["\x01\x02\x03\x04$php", 'blob.dat', true],

			// Polyglots: binary magic under an interpreted or markup name.
			'png magic as .phtml' => ["\x89PNG\r\n\x1A\n$php", 'polyglot.phtml', true],
			'png magic as .html' => ["\x89PNG\r\n\x1A\n$php", 'polyglot.html', true],
			'png magic as .svg' => ["\x89PNG\r\n\x1A\n$php", 'polyglot.svg', true],
			'pdf magic as .txt' => ["%PDF-1.7$php", 'polyglot.txt', true],

			// Clean content is clean whatever it is named.
			'clean text' => ['Just a readme.', 'readme.txt', false],
		];
	}//end embeddedPhpProvider()

	public function testTagBeyondTheScannedPrefixIsNotFlagged(): void {
		// Documented, pre-existing bound: only the first 1024 bytes are scanned.
		// Pinned here so a change to SCAN_PREFIX_LENGTH is a deliberate decision.
		$content = str_repeat('a', ExecutableContentDetector::SCAN_PREFIX_LENGTH) . '<?php echo 1;';

		$this->assertFalse($this->detector->containsEmbeddedPhpTag($content, 'notes.txt'));
	}//end testTagBeyondTheScannedPrefixIsNotFlagged()

	public function testTagAtTheEndOfTheScannedPrefixIsFlagged(): void {
		$tag = '<?php';
		$padding = str_repeat('a', (ExecutableContentDetector::SCAN_PREFIX_LENGTH - strlen($tag)));

		$this->assertTrue($this->detector->containsEmbeddedPhpTag($padding . $tag, 'notes.txt'));
	}//end testTagAtTheEndOfTheScannedPrefixIsFlagged()
}//end class
