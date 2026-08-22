<?php

declare(strict_types=1);

/*
 * FileValidationHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FileValidationHandler
 *
 * Tests executable file blocking, magic byte detection,
 * ownership checking, and file ownership operations.
 */
class FileValidationHandlerTest extends TestCase {

	/**
	 * @var FileValidationHandler
	 */
	private FileValidationHandler $handler;

	/**
	 * @var FileMapper&MockObject
	 */
	private $fileMapper;

	/**
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new FileValidationHandler(
			$this->fileMapper,
			$this->userSession,
			$this->logger
		);
	}//end setUp()

	// =========================================================================
	// blockExecutableFile - extension blocking
	// =========================================================================

	/**
	 * @dataProvider dangerousExtensionsProvider
	 */
	public function testBlockExecutableFileBlocksDangerousExtensions(string $fileName): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFile($fileName, '');
	}//end testBlockExecutableFileBlocksDangerousExtensions()

	/**
	 * @return array<string, array{string}>
	 */
	public static function dangerousExtensionsProvider(): array {
		return [
			// Windows executables.
			'exe' => ['malware.exe'],
			'bat' => ['script.bat'],
			'cmd' => ['script.cmd'],
			'com' => ['prog.com'],
			'msi' => ['installer.msi'],
			'scr' => ['screen.scr'],
			'vbs' => ['macro.vbs'],
			'vbe' => ['macro.vbe'],
			'js' => ['script.js'],
			'jse' => ['script.jse'],
			'wsf' => ['script.wsf'],
			'wsh' => ['script.wsh'],
			'ps1' => ['powershell.ps1'],
			'dll' => ['library.dll'],
			// Unix/Linux executables.
			'sh' => ['hack.sh'],
			'bash' => ['run.bash'],
			'csh' => ['run.csh'],
			'ksh' => ['run.ksh'],
			'zsh' => ['run.zsh'],
			'run' => ['installer.run'],
			'bin' => ['binary.bin'],
			'app' => ['program.app'],
			'deb' => ['package.deb'],
			'rpm' => ['package.rpm'],
			// Scripts and code.
			'php' => ['shell.php'],
			'phtml' => ['page.phtml'],
			'php3' => ['old.php3'],
			'php4' => ['old.php4'],
			'php5' => ['old.php5'],
			'phps' => ['source.phps'],
			'phar' => ['archive.phar'],
			'py' => ['exploit.py'],
			'pyc' => ['compiled.pyc'],
			'pyo' => ['optimized.pyo'],
			'pyw' => ['window.pyw'],
			'pl' => ['script.pl'],
			'pm' => ['module.pm'],
			'cgi' => ['handler.cgi'],
			'rb' => ['script.rb'],
			'rbw' => ['script.rbw'],
			'jar' => ['app.jar'],
			'war' => ['webapp.war'],
			'ear' => ['enterprise.ear'],
			'class' => ['Main.class'],
			// Containers and packages.
			'appimage' => ['app.appimage'],
			'snap' => ['app.snap'],
			'flatpak' => ['app.flatpak'],
			// MacOS.
			'dmg' => ['installer.dmg'],
			'pkg' => ['installer.pkg'],
			'command' => ['script.command'],
			// Android.
			'apk' => ['app.apk'],
			// Other dangerous.
			'elf' => ['binary.elf'],
			'out' => ['a.out'],
			'o' => ['module.o'],
			'so' => ['library.so'],
			'dylib' => ['library.dylib'],
		];
	}//end dangerousExtensionsProvider()

	/**
	 * @dataProvider safeExtensionsProvider
	 */
	public function testBlockExecutableFileAllowsSafeExtensions(string $fileName): void {
		// Should not throw.
		$this->handler->blockExecutableFile($fileName, 'safe content');

		// If we get here, the assertion is that no exception was thrown.
		$this->assertTrue(true);
	}//end testBlockExecutableFileAllowsSafeExtensions()

	/**
	 * @return array<string, array{string}>
	 */
	public static function safeExtensionsProvider(): array {
		return [
			'pdf' => ['document.pdf'],
			'jpg' => ['photo.jpg'],
			'png' => ['image.png'],
			'docx' => ['report.docx'],
			'xlsx' => ['data.xlsx'],
			'txt' => ['readme.txt'],
			'csv' => ['export.csv'],
			'json' => ['config.json'],
			'xml' => ['data.xml'],
			'zip' => ['archive.zip'],
		];
	}//end safeExtensionsProvider()

	public function testBlockExecutableFileIsCaseInsensitive(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFile('malware.EXE', '');
	}//end testBlockExecutableFileIsCaseInsensitive()

	public function testBlockExecutableFileLogsWarningOnBlock(): void {
		$this->logger->expects($this->once())
			->method('warning');

		try {
			$this->handler->blockExecutableFile('script.sh', '');
		} catch (Exception $e) {
			// Expected.
		}
	}//end testBlockExecutableFileLogsWarningOnBlock()

	// =========================================================================
	// blockExecutableFile - combined extension + magic bytes
	// =========================================================================
	public function testBlockExecutableFileChecksContentAfterExtension(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Windows executable');

		// Safe extension but dangerous content.
		$this->handler->blockExecutableFile('innocent.pdf', 'MZ' . str_repeat("\0", 100));
	}//end testBlockExecutableFileChecksContentAfterExtension()

	public function testBlockExecutableFileEmptyContentAllowed(): void {
		// Safe extension with empty content should pass.
		$this->handler->blockExecutableFile('document.pdf', '');

		$this->assertTrue(true);
	}//end testBlockExecutableFileEmptyContentAllowed()

	public function testBlockExecutableFileSkipsMagicBytesForEmptyContent(): void {
		// Empty content should skip magic bytes check entirely.
		$this->handler->blockExecutableFile('file.txt', '');
		$this->assertTrue(true);
	}//end testBlockExecutableFileSkipsMagicBytesForEmptyContent()

	// =========================================================================
	// detectExecutableMagicBytes - magic byte signatures
	// =========================================================================
	public function testDetectExecutableMagicBytesWindowsExe(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Windows executable');

		$this->handler->detectExecutableMagicBytes('MZ' . str_repeat("\0", 100), 'fake.pdf');
	}//end testDetectExecutableMagicBytesWindowsExe()

	public function testDetectExecutableMagicBytesElfExecutable(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Linux/Unix executable');

		$this->handler->detectExecutableMagicBytes("\x7FELF" . str_repeat("\0", 100), 'fake.txt');
	}//end testDetectExecutableMagicBytesElfExecutable()

	public function testDetectExecutableMagicBytesShellScript(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Shell script');

		$this->handler->detectExecutableMagicBytes("#!/bin/sh\necho hello", 'script.txt');
	}//end testDetectExecutableMagicBytesShellScript()

	public function testDetectExecutableMagicBytesBashScript(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Bash script');

		$this->handler->detectExecutableMagicBytes("#!/bin/bash\necho hello", 'script.txt');
	}//end testDetectExecutableMagicBytesBashScript()

	public function testDetectExecutableMagicBytesEnvShebang(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Script with env shebang');

		$this->handler->detectExecutableMagicBytes("#!/usr/bin/env python\nprint('hi')", 'script.txt');
	}//end testDetectExecutableMagicBytesEnvShebang()

	public function testDetectExecutableMagicBytesPhpScript(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP script');

		$this->handler->detectExecutableMagicBytes("<?php echo 'test';", 'data.txt');
	}//end testDetectExecutableMagicBytesPhpScript()

	public function testDetectExecutableMagicBytesJavaClass(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Java class file');

		$this->handler->detectExecutableMagicBytes("\xCA\xFE\xBA\xBE" . str_repeat("\0", 100), 'App.class');
	}//end testDetectExecutableMagicBytesJavaClass()

	public function testDetectExecutableMagicBytesSafeContent(): void {
		// Normal content should not trigger detection.
		$this->handler->detectExecutableMagicBytes('Hello, World! This is a text file.', 'readme.txt');

		$this->assertTrue(true);
	}//end testDetectExecutableMagicBytesSafeContent()

	public function testDetectExecutableMagicBytesSignatureNotAtStart(): void {
		// Magic bytes must be at position 0, not embedded in content.
		$this->handler->detectExecutableMagicBytes('Some text then MZ more text', 'document.txt');

		$this->assertTrue(true);
	}//end testDetectExecutableMagicBytesSignatureNotAtStart()

	// =========================================================================
	// detectExecutableMagicBytes - shebang detection in first lines
	// =========================================================================

	/**
	 * @dataProvider shebangProvider
	 */
	public function testDetectExecutableMagicBytesShebangInFirstLines(string $content): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('shebang');

		$this->handler->detectExecutableMagicBytes($content, 'file.txt');
	}//end testDetectExecutableMagicBytesShebangInFirstLines()

	/**
	 * @return array<string, array{string}>
	 */
	public static function shebangProvider(): array {
		return [
			'python shebang line 2' => ["some header\n#!/usr/bin/python\ncode"],
			'perl shebang' => ["# comment\n#!/usr/bin/perl\ncode"],
			'ruby shebang' => ["text\n#!/usr/bin/ruby\ncode"],
			'node shebang' => ["header\n#!/usr/bin/node\nconsole.log('hi')"],
			'php shebang' => ["header\n#!/usr/bin/php\ncode"],
			'zsh shebang' => ["text\n#!/bin/zsh\ncode"],
			'ksh shebang' => ["text\n#!/bin/ksh\ncode"],
			'csh shebang' => ["text\n#!/bin/csh\ncode"],
		];
	}//end shebangProvider()

	// =========================================================================
	// detectExecutableMagicBytes - embedded PHP detection
	// =========================================================================
	public function testDetectExecutableMagicBytesEmbeddedPhpTag(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP');

		$content = "<html><body><?php system('whoami'); ?></body></html>";
		$this->handler->detectExecutableMagicBytes($content, 'page.html');
	}//end testDetectExecutableMagicBytesEmbeddedPhpTag()

	public function testDetectExecutableMagicBytesPhpShortEchoTag(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP');

		$content = "<html><body><?= 'hello' ?></body></html>";
		$this->handler->detectExecutableMagicBytes($content, 'page.html');
	}//end testDetectExecutableMagicBytesPhpShortEchoTag()

	public function testDetectExecutableMagicBytesPhpScriptTag(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP');

		$content = '<script language="php">echo "hello";</script>';
		$this->handler->detectExecutableMagicBytes($content, 'page.html');
	}//end testDetectExecutableMagicBytesPhpScriptTag()

	public function testDetectExecutableMagicBytesPhpScriptTagSingleQuotes(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP');

		$content = "<script language='php'>echo 'hello';</script>";
		$this->handler->detectExecutableMagicBytes($content, 'page.html');
	}//end testDetectExecutableMagicBytesPhpScriptTagSingleQuotes()

	// =========================================================================
	// detectExecutableMagicBytes - binary MIME scoping (openregister#2776)
	//
	// The embedded-PHP-tag scan used to run over the first kilobyte of EVERY
	// upload. A genuine PNG screenshot whose deflate stream happens to contain
	// `<?=` was rejected as "contains PHP code" (HTTP 400), and because the
	// throw aborted the caller's batch, one false positive took 284 storable
	// files with it.
	//
	// These tests pin BOTH directions: the real-world PNG must pass, and every
	// text-ish payload must still be refused.
	// =========================================================================

	/**
	 * Path to the reproducer from the Jira attachment migration: a real
	 * 1283x926 PNG screenshot whose first 1024 bytes contain the `<?=` byte
	 * sequence at offset 710.
	 *
	 * @return string
	 */
	private function falsePositivePngPath(): string {
		return __DIR__ . '/../../../fixtures/files/php-tag-false-positive.png';
	}//end falsePositivePngPath()

	public function testFixturePngGenuinelyContainsThePhpShortEchoSequence(): void {
		// Guard the guard: if this fixture is ever re-encoded and loses the
		// `<?=` bytes, the regression tests below would pass vacuously.
		$content = (string)file_get_contents($this->falsePositivePngPath());

		$this->assertStringStartsWith("\x89PNG\r\n\x1A\n", $content, 'fixture must be a real PNG');
		$this->assertStringContainsString('<?=', substr($content, 0, 1024), 'fixture must trip the old rule');
	}//end testFixturePngGenuinelyContainsThePhpShortEchoSequence()

	public function testRealPngWithEmbeddedPhpShortEchoSequenceIsAccepted(): void {
		$content = (string)file_get_contents($this->falsePositivePngPath());

		$this->handler->detectExecutableMagicBytes($content, 'Gegevens weergeven.PNG');

		$this->assertTrue(true, 'a genuine PNG must not be rejected as PHP');
	}//end testRealPngWithEmbeddedPhpShortEchoSequenceIsAccepted()

	public function testRealPngPassesTheFullBlockExecutableFileGate(): void {
		$content = (string)file_get_contents($this->falsePositivePngPath());

		$this->handler->blockExecutableFile('Gegevens weergeven.PNG', $content);

		$this->assertTrue(true, 'a genuine PNG must survive the whole upload gate');
	}//end testRealPngPassesTheFullBlockExecutableFileGate()

	/**
	 * @dataProvider binaryContainerProvider
	 */
	public function testBinaryContainersSkipTheEmbeddedPhpScan(string $magic, string $fileName): void {
		$content = $magic . str_repeat("\x00", 32) . "<?php system('whoami'); ?>" . str_repeat("\x00", 32);

		$this->handler->detectExecutableMagicBytes($content, $fileName);

		$this->assertTrue(true, "{$fileName} must not be scanned for PHP tags");
	}//end testBinaryContainersSkipTheEmbeddedPhpScan()

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function binaryContainerProvider(): array {
		return [
			'png' => ["\x89PNG\r\n\x1A\n", 'screenshot.png'],
			'jpeg' => ["\xFF\xD8\xFF\xE0", 'photo.jpg'],
			'gif' => ['GIF89a', 'anim.gif'],
			'pdf' => ['%PDF-1.7', 'report.pdf'],
			'zip/docx' => ["PK\x03\x04", 'contract.docx'],
			'gzip' => ["\x1F\x8B\x08", 'dump.gz'],
			'ole/doc' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'legacy.doc'],
			'matroska' => ["\x1A\x45\xDF\xA3", 'clip.mkv'],
			'ogg' => ['OggS', 'sound.ogg'],
			'sqlite' => ["SQLite format 3\x00", 'data.sqlite'],
		];
	}//end binaryContainerProvider()

	public function testIsoBaseMediaMp4SkipsTheEmbeddedPhpScan(): void {
		// 4-byte box length, then the 'ftyp' brand marker at offset 4.
		$content = "\x00\x00\x00\x20" . 'ftypisom' . str_repeat("\x00", 16) . '<?php echo 1; ?>';

		$this->handler->detectExecutableMagicBytes($content, 'movie.mp4');

		$this->assertTrue(true, 'an mp4 must not be scanned for PHP tags');
	}//end testIsoBaseMediaMp4SkipsTheEmbeddedPhpScan()

	// --- MUST-FAIL side: the protection is unchanged for everything else. ---

	/**
	 * @dataProvider stillRejectedProvider
	 */
	public function testPhpPayloadsAreStillRejected(string $content, string $fileName): void {
		$this->expectException(Exception::class);

		$this->handler->detectExecutableMagicBytes($content, $fileName);
	}//end testPhpPayloadsAreStillRejected()

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function stillRejectedProvider(): array {
		$php = "<?php system(\$_GET['c']); ?>";

		return [
			// Plain text and markup are scanned exactly as before.
			'php source as .php' => [$php, 'shell.php'],
			'php source as .phtml' => [$php, 'shell.phtml'],
			'php source as .txt' => [$php, 'notes.txt'],
			'php source as .html' => ["<html><body>$php</body></html>", 'page.html'],
			'php short echo in xml' => ["<root><?= 'x' ?></root>", 'feed.xml'],
			'php inside svg' => ["<svg xmlns='http://www.w3.org/2000/svg'>$php</svg>", 'logo.svg'],
			'script language=php' => ['<script language="php">echo 1;</script>', 'page.html'],
			// No extension at all is treated as text-ish and still scanned.
			'php source, no extension' => [$php, 'attachment'],
			// An unrecognised byte stream is NOT a known binary container, so the
			// scan still applies — the skip is a whitelist, not a blocklist.
			'unknown binary-looking prefix' => ["\x01\x02\x03\x04" . $php, 'blob.dat'],
			// A polyglot: real PNG magic bytes, but named so a server would
			// hand it to an interpreter or a markup parser.
			'png magic saved as .phtml' => ["\x89PNG\r\n\x1A\n" . $php, 'polyglot.phtml'],
			'png magic saved as .html' => ["\x89PNG\r\n\x1A\n" . $php, 'polyglot.html'],
			'png magic saved as .svg' => ["\x89PNG\r\n\x1A\n" . $php, 'polyglot.svg'],
		];
	}//end stillRejectedProvider()

	public function testUniversalChecksStillApplyToBinaryContainers(): void {
		// The MIME scoping narrows ONLY the embedded-PHP-tag scan. A PNG-named
		// file whose leading bytes are a Windows executable is still refused by
		// the offset-0 magic-byte table.
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable');

		$this->handler->detectExecutableMagicBytes('MZ' . str_repeat("\x00", 64), 'innocent.png');
	}//end testUniversalChecksStillApplyToBinaryContainers()

	public function testShebangStillRejectedRegardlessOfName(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('shebang');

		$this->handler->detectExecutableMagicBytes("#!/usr/bin/env python\nprint(1)\n", 'data.png');
	}//end testShebangStillRejectedRegardlessOfName()

	public function testPhpExtensionStillBlockedBeforeContentIsEvenLookedAt(): void {
		// blockExecutableFile()'s extension blocklist is the primary control and
		// is untouched: a .php upload is refused whatever its bytes look like.
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFile('shell.php', "\x89PNG\r\n\x1A\n" . '<?php echo 1;');
	}//end testPhpExtensionStillBlockedBeforeContentIsEvenLookedAt()

	// =========================================================================
	// sniffBinaryContentType / shouldScanForEmbeddedPhp
	// =========================================================================
	public function testSniffBinaryContentTypeIdentifiesPng(): void {
		$this->assertSame('image/png', $this->handler->sniffBinaryContentType("\x89PNG\r\n\x1A\n1234"));
	}//end testSniffBinaryContentTypeIdentifiesPng()

	public function testSniffBinaryContentTypeReturnsNullForText(): void {
		$this->assertNull($this->handler->sniffBinaryContentType('Hello, world.'));
	}//end testSniffBinaryContentTypeReturnsNullForText()

	public function testSniffBinaryContentTypeReturnsNullForEmptyContent(): void {
		$this->assertNull($this->handler->sniffBinaryContentType(''));
	}//end testSniffBinaryContentTypeReturnsNullForEmptyContent()

	public function testShouldScanForEmbeddedPhpDefaultsToTrue(): void {
		$this->assertTrue($this->handler->shouldScanForEmbeddedPhp('some plain text', 'readme.txt'));
	}//end testShouldScanForEmbeddedPhpDefaultsToTrue()

	public function testShouldScanForEmbeddedPhpIsFalseForRealPng(): void {
		$content = (string)file_get_contents($this->falsePositivePngPath());

		$this->assertFalse($this->handler->shouldScanForEmbeddedPhp($content, 'Gegevens weergeven.PNG'));
	}//end testShouldScanForEmbeddedPhpIsFalseForRealPng()

	public function testDetectExecutableMagicBytesLogsWarningOnDetection(): void {
		$this->logger->expects($this->once())
			->method('warning');

		try {
			$this->handler->detectExecutableMagicBytes('MZ' . str_repeat("\0", 50), 'test.pdf');
		} catch (Exception $e) {
			// Expected.
		}
	}//end testDetectExecutableMagicBytesLogsWarningOnDetection()

	// =========================================================================
	// checkOwnership - Readable/unreadable (isReadable() probe, see design.md Decision 1)
	// =========================================================================
	public function testCheckOwnershipReadableFileWithCorrectOwnerIsNoOp(): void {
		$file = $this->createMock(Node::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$fileOwner = $this->createMock(IUser::class);
		$fileOwner->method('getUID')->willReturn('admin');
		$file->method('getOwner')->willReturn($fileOwner);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		// Owner matches — no repair call should be made.
		$this->fileMapper->expects($this->never())
			->method('setFileOwnership');

		$this->handler->checkOwnership(file: $file);

		$this->assertTrue(true);
	}//end testCheckOwnershipReadableFileWithCorrectOwnerIsNoOp()

	public function testCheckOwnershipUnreadableFileThrowsNotPermitted(): void {
		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('is not readable');

		$file = $this->createMock(Node::class);
		$file->method('isReadable')->willReturn(false);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		// No repair should be attempted on unreadable files.
		$this->fileMapper->expects($this->never())
			->method('setFileOwnership');

		$this->handler->checkOwnership(file: $file);
	}//end testCheckOwnershipUnreadableFileThrowsNotPermitted()

	public function testCheckOwnershipReadableFileWithDifferentOwnerIsAllowed(): void {
		// Access is readability-based and ownership-agnostic: a file the session can
		// read but does not own (e.g. reached via a file share, or owned by the
		// openregister system user) must be allowed. No ownership repair is performed.
		$file = $this->createMock(Node::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$fileOwner = $this->createMock(IUser::class);
		$fileOwner->method('getUID')->willReturn('other-user');
		$file->method('getOwner')->willReturn($fileOwner);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		// Ownership is never mutated on an access check.
		$this->fileMapper->expects($this->never())
			->method('setFileOwnership');

		$this->handler->checkOwnership(file: $file);

		$this->assertTrue(true);
	}//end testCheckOwnershipReadableFileWithDifferentOwnerIsAllowed()

	public function testCheckOwnershipReadableFileWithNullOwnerIsAllowed(): void {
		// A readable file is allowed even when getOwner() returns null: readability is
		// the access gate, and the owner identity is irrelevant.
		$file = $this->createMock(Node::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);
		$file->method('getOwner')->willReturn(null);

		$this->fileMapper->expects($this->never())
			->method('setFileOwnership');

		$this->handler->checkOwnership(file: $file);

		$this->assertTrue(true);
	}//end testCheckOwnershipReadableFileWithNullOwnerIsAllowed()

	// =========================================================================
	// ownFile
	// =========================================================================
	public function testOwnFileSuccess(): void {
		$file = $this->createMock(Node::class);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		$this->fileMapper->expects($this->once())
			->method('setFileOwnership')
			->with(42, 'admin')
			->willReturn(true);

		$result = $this->handler->ownFile($file);

		$this->assertTrue($result);
	}//end testOwnFileSuccess()

	public function testOwnFileReturnsFalse(): void {
		$file = $this->createMock(Node::class);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		$this->fileMapper->method('setFileOwnership')
			->willReturn(false);

		$result = $this->handler->ownFile($file);

		$this->assertFalse($result);
	}//end testOwnFileReturnsFalse()

	public function testOwnFileThrowsOnMapperException(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to set file ownership');

		$file = $this->createMock(Node::class);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		$this->fileMapper->method('setFileOwnership')
			->willThrowException(new Exception('DB connection failed'));

		$this->handler->ownFile($file);
	}//end testOwnFileThrowsOnMapperException()

	public function testOwnFileThrowsWhenNoUserLoggedIn(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to set file ownership');

		$file = $this->createMock(Node::class);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$this->userSession->method('getUser')->willReturn(null);

		$this->handler->ownFile($file);
	}//end testOwnFileThrowsWhenNoUserLoggedIn()

	public function testOwnFileLogsInfoOnSuccess(): void {
		$file = $this->createMock(Node::class);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		$this->fileMapper->method('setFileOwnership')->willReturn(true);

		// Expects two info calls: one before setting, one after.
		$this->logger->expects($this->exactly(2))
			->method('info');

		$this->handler->ownFile($file);
	}//end testOwnFileLogsInfoOnSuccess()

	public function testOwnFileLogsWarningOnFailure(): void {
		$file = $this->createMock(Node::class);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		$this->fileMapper->method('setFileOwnership')->willReturn(false);

		$this->logger->expects($this->once())
			->method('warning');

		$this->handler->ownFile($file);
	}//end testOwnFileLogsWarningOnFailure()

	public function testOwnFileLogsErrorOnException(): void {
		$file = $this->createMock(Node::class);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(42);

		$currentUser = $this->createMock(IUser::class);
		$currentUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($currentUser);

		$this->fileMapper->method('setFileOwnership')
			->willThrowException(new Exception('DB error'));

		$this->logger->expects($this->once())
			->method('error');

		try {
			$this->handler->ownFile($file);
		} catch (Exception $e) {
			// Expected.
		}
	}//end testOwnFileLogsErrorOnException()

	// =========================================================================
	// Edge cases
	// =========================================================================
	public function testBlockExecutableFileUpperCaseExtension(): void {
		$this->expectException(Exception::class);
		$this->handler->blockExecutableFile('VIRUS.PHP', '');
	}//end testBlockExecutableFileUpperCaseExtension()

	public function testBlockExecutableFileMixedCaseExtension(): void {
		$this->expectException(Exception::class);
		$this->handler->blockExecutableFile('hack.PhP', '');
	}//end testBlockExecutableFileMixedCaseExtension()

	public function testBlockExecutableFileDoubleExtension(): void {
		$this->expectException(Exception::class);
		$this->handler->blockExecutableFile('document.pdf.exe', '');
	}//end testBlockExecutableFileDoubleExtension()

	public function testBlockExecutableFileNoExtension(): void {
		// File with no extension should pass.
		$this->handler->blockExecutableFile('Makefile', 'content');
		$this->assertTrue(true);
	}//end testBlockExecutableFileNoExtension()

	public function testDetectExecutableMagicBytesEmptyContent(): void {
		// Empty content should pass.
		$this->handler->detectExecutableMagicBytes('', 'empty.txt');
		$this->assertTrue(true);
	}//end testDetectExecutableMagicBytesEmptyContent()

	public function testDetectMagicBytesShebangBeyond1024BytesNotDetected(): void {
		// Shebang beyond first 1024 bytes should not be detected.
		$content = str_repeat('a', 1025) . "\n#!/bin/bash\necho hi";
		$this->handler->detectExecutableMagicBytes($content, 'safe.txt');
		$this->assertTrue(true);
	}//end testDetectMagicBytesShebangBeyond1024BytesNotDetected()
}//end class
