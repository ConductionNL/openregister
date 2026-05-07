<?php

declare(strict_types=1);

/*
 * FileValidationHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
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
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FileValidationHandler
 *
 * Tests executable file blocking, magic byte detection,
 * ownership checking, and file ownership operations.
 */
class FileValidationHandlerTest extends TestCase
{

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileMapper  = $this->createMock(FileMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

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
    public function testBlockExecutableFileBlocksDangerousExtensions(string $fileName): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable file');

        $this->handler->blockExecutableFile($fileName, '');
    }//end testBlockExecutableFileBlocksDangerousExtensions()

    /**
     * @return array<string, array{string}>
     */
    public static function dangerousExtensionsProvider(): array
    {
        return [
            // Windows executables.
            'exe'      => ['malware.exe'],
            'bat'      => ['script.bat'],
            'cmd'      => ['script.cmd'],
            'com'      => ['prog.com'],
            'msi'      => ['installer.msi'],
            'scr'      => ['screen.scr'],
            'vbs'      => ['macro.vbs'],
            'vbe'      => ['macro.vbe'],
            'js'       => ['script.js'],
            'jse'      => ['script.jse'],
            'wsf'      => ['script.wsf'],
            'wsh'      => ['script.wsh'],
            'ps1'      => ['powershell.ps1'],
            'dll'      => ['library.dll'],
            // Unix/Linux executables.
            'sh'       => ['hack.sh'],
            'bash'     => ['run.bash'],
            'csh'      => ['run.csh'],
            'ksh'      => ['run.ksh'],
            'zsh'      => ['run.zsh'],
            'run'      => ['installer.run'],
            'bin'      => ['binary.bin'],
            'app'      => ['program.app'],
            'deb'      => ['package.deb'],
            'rpm'      => ['package.rpm'],
            // Scripts and code.
            'php'      => ['shell.php'],
            'phtml'    => ['page.phtml'],
            'php3'     => ['old.php3'],
            'php4'     => ['old.php4'],
            'php5'     => ['old.php5'],
            'phps'     => ['source.phps'],
            'phar'     => ['archive.phar'],
            'py'       => ['exploit.py'],
            'pyc'      => ['compiled.pyc'],
            'pyo'      => ['optimized.pyo'],
            'pyw'      => ['window.pyw'],
            'pl'       => ['script.pl'],
            'pm'       => ['module.pm'],
            'cgi'      => ['handler.cgi'],
            'rb'       => ['script.rb'],
            'rbw'      => ['script.rbw'],
            'jar'      => ['app.jar'],
            'war'      => ['webapp.war'],
            'ear'      => ['enterprise.ear'],
            'class'    => ['Main.class'],
            // Containers and packages.
            'appimage' => ['app.appimage'],
            'snap'     => ['app.snap'],
            'flatpak'  => ['app.flatpak'],
            // MacOS.
            'dmg'      => ['installer.dmg'],
            'pkg'      => ['installer.pkg'],
            'command'  => ['script.command'],
            // Android.
            'apk'      => ['app.apk'],
            // Other dangerous.
            'elf'      => ['binary.elf'],
            'out'      => ['a.out'],
            'o'        => ['module.o'],
            'so'       => ['library.so'],
            'dylib'    => ['library.dylib'],
        ];
    }//end dangerousExtensionsProvider()

    /**
     * @dataProvider safeExtensionsProvider
     */
    public function testBlockExecutableFileAllowsSafeExtensions(string $fileName): void
    {
        // Should not throw.
        $this->handler->blockExecutableFile($fileName, 'safe content');

        // If we get here, the assertion is that no exception was thrown.
        $this->assertTrue(true);
    }//end testBlockExecutableFileAllowsSafeExtensions()

    /**
     * @return array<string, array{string}>
     */
    public static function safeExtensionsProvider(): array
    {
        return [
            'pdf'  => ['document.pdf'],
            'jpg'  => ['photo.jpg'],
            'png'  => ['image.png'],
            'docx' => ['report.docx'],
            'xlsx' => ['data.xlsx'],
            'txt'  => ['readme.txt'],
            'csv'  => ['export.csv'],
            'json' => ['config.json'],
            'xml'  => ['data.xml'],
            'zip'  => ['archive.zip'],
        ];
    }//end safeExtensionsProvider()

    public function testBlockExecutableFileIsCaseInsensitive(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable file');

        $this->handler->blockExecutableFile('malware.EXE', '');
    }//end testBlockExecutableFileIsCaseInsensitive()

    public function testBlockExecutableFileLogsWarningOnBlock(): void
    {
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
    public function testBlockExecutableFileChecksContentAfterExtension(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Windows executable');

        // Safe extension but dangerous content.
        $this->handler->blockExecutableFile('innocent.pdf', 'MZ'.str_repeat("\0", 100));
    }//end testBlockExecutableFileChecksContentAfterExtension()

    public function testBlockExecutableFileEmptyContentAllowed(): void
    {
        // Safe extension with empty content should pass.
        $this->handler->blockExecutableFile('document.pdf', '');

        $this->assertTrue(true);
    }//end testBlockExecutableFileEmptyContentAllowed()

    public function testBlockExecutableFileSkipsMagicBytesForEmptyContent(): void
    {
        // Empty content should skip magic bytes check entirely.
        $this->handler->blockExecutableFile('file.txt', '');
        $this->assertTrue(true);
    }//end testBlockExecutableFileSkipsMagicBytesForEmptyContent()

    // =========================================================================
    // detectExecutableMagicBytes - magic byte signatures
    // =========================================================================
    public function testDetectExecutableMagicBytesWindowsExe(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Windows executable');

        $this->handler->detectExecutableMagicBytes('MZ'.str_repeat("\0", 100), 'fake.pdf');
    }//end testDetectExecutableMagicBytesWindowsExe()

    public function testDetectExecutableMagicBytesElfExecutable(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Linux/Unix executable');

        $this->handler->detectExecutableMagicBytes("\x7FELF".str_repeat("\0", 100), 'fake.txt');
    }//end testDetectExecutableMagicBytesElfExecutable()

    public function testDetectExecutableMagicBytesShellScript(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Shell script');

        $this->handler->detectExecutableMagicBytes("#!/bin/sh\necho hello", 'script.txt');
    }//end testDetectExecutableMagicBytesShellScript()

    public function testDetectExecutableMagicBytesBashScript(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bash script');

        $this->handler->detectExecutableMagicBytes("#!/bin/bash\necho hello", 'script.txt');
    }//end testDetectExecutableMagicBytesBashScript()

    public function testDetectExecutableMagicBytesEnvShebang(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Script with env shebang');

        $this->handler->detectExecutableMagicBytes("#!/usr/bin/env python\nprint('hi')", 'script.txt');
    }//end testDetectExecutableMagicBytesEnvShebang()

    public function testDetectExecutableMagicBytesPhpScript(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP script');

        $this->handler->detectExecutableMagicBytes("<?php echo 'test';", 'data.txt');
    }//end testDetectExecutableMagicBytesPhpScript()

    public function testDetectExecutableMagicBytesJavaClass(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Java class file');

        $this->handler->detectExecutableMagicBytes("\xCA\xFE\xBA\xBE".str_repeat("\0", 100), 'App.class');
    }//end testDetectExecutableMagicBytesJavaClass()

    public function testDetectExecutableMagicBytesSafeContent(): void
    {
        // Normal content should not trigger detection.
        $this->handler->detectExecutableMagicBytes('Hello, World! This is a text file.', 'readme.txt');

        $this->assertTrue(true);
    }//end testDetectExecutableMagicBytesSafeContent()

    public function testDetectExecutableMagicBytesSignatureNotAtStart(): void
    {
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
    public function testDetectExecutableMagicBytesShebangInFirstLines(string $content): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('shebang');

        $this->handler->detectExecutableMagicBytes($content, 'file.txt');
    }//end testDetectExecutableMagicBytesShebangInFirstLines()

    /**
     * @return array<string, array{string}>
     */
    public static function shebangProvider(): array
    {
        return [
            'python shebang line 2' => ["some header\n#!/usr/bin/python\ncode"],
            'perl shebang'          => ["# comment\n#!/usr/bin/perl\ncode"],
            'ruby shebang'          => ["text\n#!/usr/bin/ruby\ncode"],
            'node shebang'          => ["header\n#!/usr/bin/node\nconsole.log('hi')"],
            'php shebang'           => ["header\n#!/usr/bin/php\ncode"],
            'zsh shebang'           => ["text\n#!/bin/zsh\ncode"],
            'ksh shebang'           => ["text\n#!/bin/ksh\ncode"],
            'csh shebang'           => ["text\n#!/bin/csh\ncode"],
        ];
    }//end shebangProvider()

    // =========================================================================
    // detectExecutableMagicBytes - embedded PHP detection
    // =========================================================================
    public function testDetectExecutableMagicBytesEmbeddedPhpTag(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = "<html><body><?php system('whoami'); ?></body></html>";
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }//end testDetectExecutableMagicBytesEmbeddedPhpTag()

    public function testDetectExecutableMagicBytesPhpShortEchoTag(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = "<html><body><?= 'hello' ?></body></html>";
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }//end testDetectExecutableMagicBytesPhpShortEchoTag()

    public function testDetectExecutableMagicBytesPhpScriptTag(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = '<script language="php">echo "hello";</script>';
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }//end testDetectExecutableMagicBytesPhpScriptTag()

    public function testDetectExecutableMagicBytesPhpScriptTagSingleQuotes(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = "<script language='php'>echo 'hello';</script>";
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }//end testDetectExecutableMagicBytesPhpScriptTagSingleQuotes()

    public function testDetectExecutableMagicBytesLogsWarningOnDetection(): void
    {
        $this->logger->expects($this->once())
            ->method('warning');

        try {
            $this->handler->detectExecutableMagicBytes('MZ'.str_repeat("\0", 50), 'test.pdf');
        } catch (Exception $e) {
            // Expected.
        }
    }//end testDetectExecutableMagicBytesLogsWarningOnDetection()

    // =========================================================================
    // checkOwnership - Readable/unreadable (isReadable() probe, see design.md Decision 1)
    // =========================================================================
    public function testCheckOwnershipReadableFileWithCorrectOwnerIsNoOp(): void
    {
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

    public function testCheckOwnershipUnreadableFileThrowsNotPermitted(): void
    {
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

    public function testCheckOwnershipReadableFileWithDriftedOwnerTriggersRepair(): void
    {
        $file = $this->createMock(Node::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $fileOwner = $this->createMock(IUser::class);
        $fileOwner->method('getUID')->willReturn('old-owner');
        $file->method('getOwner')->willReturn($fileOwner);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        // Readable but drifted owner — repair must be attempted.
        $this->fileMapper->expects($this->once())
            ->method('setFileOwnership')
            ->with(42, 'admin')
            ->willReturn(true);

        $this->handler->checkOwnership(file: $file);

        $this->assertTrue(true);
    }//end testCheckOwnershipReadableFileWithDriftedOwnerTriggersRepair()

    public function testCheckOwnershipReadableFileWithNullOwnerTriggersRepair(): void
    {
        $file = $this->createMock(Node::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);
        $file->method('getOwner')->willReturn(null);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->fileMapper->expects($this->once())
            ->method('setFileOwnership')
            ->with(42, 'admin')
            ->willReturn(true);

        $this->handler->checkOwnership(file: $file);

        $this->assertTrue(true);
    }//end testCheckOwnershipReadableFileWithNullOwnerTriggersRepair()

    public function testCheckOwnershipRepairFailureIsSwallowed(): void
    {
        $file = $this->createMock(Node::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $fileOwner = $this->createMock(IUser::class);
        $fileOwner->method('getUID')->willReturn('old-owner');
        $file->method('getOwner')->willReturn($fileOwner);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        // Repair fails — checkOwnership must NOT propagate the failure (best-effort).
        $this->fileMapper->method('setFileOwnership')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // No exception thrown despite repair failure.
        $this->handler->checkOwnership(file: $file);

        $this->assertTrue(true);
    }//end testCheckOwnershipRepairFailureIsSwallowed()

    // =========================================================================
    // ownFile
    // =========================================================================
    public function testOwnFileSuccess(): void
    {
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

    public function testOwnFileReturnsFalse(): void
    {
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

    public function testOwnFileThrowsOnMapperException(): void
    {
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

    public function testOwnFileThrowsWhenNoUserLoggedIn(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to set file ownership');

        $file = $this->createMock(Node::class);
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $this->userSession->method('getUser')->willReturn(null);

        $this->handler->ownFile($file);
    }//end testOwnFileThrowsWhenNoUserLoggedIn()

    public function testOwnFileLogsInfoOnSuccess(): void
    {
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

    public function testOwnFileLogsWarningOnFailure(): void
    {
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

    public function testOwnFileLogsErrorOnException(): void
    {
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
    public function testBlockExecutableFileUpperCaseExtension(): void
    {
        $this->expectException(Exception::class);
        $this->handler->blockExecutableFile('VIRUS.PHP', '');
    }//end testBlockExecutableFileUpperCaseExtension()

    public function testBlockExecutableFileMixedCaseExtension(): void
    {
        $this->expectException(Exception::class);
        $this->handler->blockExecutableFile('hack.PhP', '');
    }//end testBlockExecutableFileMixedCaseExtension()

    public function testBlockExecutableFileDoubleExtension(): void
    {
        $this->expectException(Exception::class);
        $this->handler->blockExecutableFile('document.pdf.exe', '');
    }//end testBlockExecutableFileDoubleExtension()

    public function testBlockExecutableFileNoExtension(): void
    {
        // File with no extension should pass.
        $this->handler->blockExecutableFile('Makefile', 'content');
        $this->assertTrue(true);
    }//end testBlockExecutableFileNoExtension()

    public function testDetectExecutableMagicBytesEmptyContent(): void
    {
        // Empty content should pass.
        $this->handler->detectExecutableMagicBytes('', 'empty.txt');
        $this->assertTrue(true);
    }//end testDetectExecutableMagicBytesEmptyContent()

    public function testDetectMagicBytesShebangBeyond1024BytesNotDetected(): void
    {
        // Shebang beyond first 1024 bytes should not be detected.
        $content = str_repeat('a', 1025)."\n#!/bin/bash\necho hi";
        $this->handler->detectExecutableMagicBytes($content, 'safe.txt');
        $this->assertTrue(true);
    }//end testDetectMagicBytesShebangBeyond1024BytesNotDetected()
}//end class
