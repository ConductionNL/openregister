<?php

declare(strict_types=1);

/**
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
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
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
    /** @var FileValidationHandler */
    private FileValidationHandler $handler;

    /** @var FileMapper&MockObject */
    private $fileMapper;

    /** @var IUserSession&MockObject */
    private $userSession;

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileMapper = $this->createMock(FileMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new FileValidationHandler(
            $this->fileMapper,
            $this->userSession,
            $this->logger
        );
    }

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
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dangerousExtensionsProvider(): array
    {
        return [
            // Windows executables.
            'exe'     => ['malware.exe'],
            'bat'     => ['script.bat'],
            'cmd'     => ['script.cmd'],
            'com'     => ['prog.com'],
            'msi'     => ['installer.msi'],
            'scr'     => ['screen.scr'],
            'vbs'     => ['macro.vbs'],
            'vbe'     => ['macro.vbe'],
            'js'      => ['script.js'],
            'jse'     => ['script.jse'],
            'wsf'     => ['script.wsf'],
            'wsh'     => ['script.wsh'],
            'ps1'     => ['powershell.ps1'],
            'dll'     => ['library.dll'],
            // Unix/Linux executables.
            'sh'      => ['hack.sh'],
            'bash'    => ['run.bash'],
            'csh'     => ['run.csh'],
            'ksh'     => ['run.ksh'],
            'zsh'     => ['run.zsh'],
            'run'     => ['installer.run'],
            'bin'     => ['binary.bin'],
            'app'     => ['program.app'],
            'deb'     => ['package.deb'],
            'rpm'     => ['package.rpm'],
            // Scripts and code.
            'php'     => ['shell.php'],
            'phtml'   => ['page.phtml'],
            'php3'    => ['old.php3'],
            'php4'    => ['old.php4'],
            'php5'    => ['old.php5'],
            'phps'    => ['source.phps'],
            'phar'    => ['archive.phar'],
            'py'      => ['exploit.py'],
            'pyc'     => ['compiled.pyc'],
            'pyo'     => ['optimized.pyo'],
            'pyw'     => ['window.pyw'],
            'pl'      => ['script.pl'],
            'pm'      => ['module.pm'],
            'cgi'     => ['handler.cgi'],
            'rb'      => ['script.rb'],
            'rbw'     => ['script.rbw'],
            'jar'     => ['app.jar'],
            'war'     => ['webapp.war'],
            'ear'     => ['enterprise.ear'],
            'class'   => ['Main.class'],
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
    }

    /**
     * @dataProvider safeExtensionsProvider
     */
    public function testBlockExecutableFileAllowsSafeExtensions(string $fileName): void
    {
        // Should not throw.
        $this->handler->blockExecutableFile($fileName, 'safe content');

        // If we get here, the assertion is that no exception was thrown.
        $this->assertTrue(true);
    }

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
    }

    public function testBlockExecutableFileIsCaseInsensitive(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable file');

        $this->handler->blockExecutableFile('malware.EXE', '');
    }

    public function testBlockExecutableFileLogsWarningOnBlock(): void
    {
        $this->logger->expects($this->once())
            ->method('warning');

        try {
            $this->handler->blockExecutableFile('script.sh', '');
        } catch (Exception $e) {
            // Expected.
        }
    }

    // =========================================================================
    // blockExecutableFile - combined extension + magic bytes
    // =========================================================================

    public function testBlockExecutableFileChecksContentAfterExtension(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Windows executable');

        // Safe extension but dangerous content.
        $this->handler->blockExecutableFile('innocent.pdf', 'MZ' . str_repeat("\0", 100));
    }

    public function testBlockExecutableFileEmptyContentAllowed(): void
    {
        // Safe extension with empty content should pass.
        $this->handler->blockExecutableFile('document.pdf', '');

        $this->assertTrue(true);
    }

    public function testBlockExecutableFileSkipsMagicBytesForEmptyContent(): void
    {
        // Empty content should skip magic bytes check entirely.
        $this->handler->blockExecutableFile('file.txt', '');
        $this->assertTrue(true);
    }

    // =========================================================================
    // detectExecutableMagicBytes - magic byte signatures
    // =========================================================================

    public function testDetectExecutableMagicBytesWindowsExe(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Windows executable');

        $this->handler->detectExecutableMagicBytes('MZ' . str_repeat("\0", 100), 'fake.pdf');
    }

    public function testDetectExecutableMagicBytesElfExecutable(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Linux/Unix executable');

        $this->handler->detectExecutableMagicBytes("\x7FELF" . str_repeat("\0", 100), 'fake.txt');
    }

    public function testDetectExecutableMagicBytesShellScript(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Shell script');

        $this->handler->detectExecutableMagicBytes("#!/bin/sh\necho hello", 'script.txt');
    }

    public function testDetectExecutableMagicBytesBashScript(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bash script');

        $this->handler->detectExecutableMagicBytes("#!/bin/bash\necho hello", 'script.txt');
    }

    public function testDetectExecutableMagicBytesEnvShebang(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Script with env shebang');

        $this->handler->detectExecutableMagicBytes("#!/usr/bin/env python\nprint('hi')", 'script.txt');
    }

    public function testDetectExecutableMagicBytesPhpScript(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP script');

        $this->handler->detectExecutableMagicBytes("<?php echo 'test';", 'data.txt');
    }

    public function testDetectExecutableMagicBytesJavaClass(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Java class file');

        $this->handler->detectExecutableMagicBytes("\xCA\xFE\xBA\xBE" . str_repeat("\0", 100), 'App.class');
    }

    public function testDetectExecutableMagicBytesSafeContent(): void
    {
        // Normal content should not trigger detection.
        $this->handler->detectExecutableMagicBytes('Hello, World! This is a text file.', 'readme.txt');

        $this->assertTrue(true);
    }

    public function testDetectExecutableMagicBytesSignatureNotAtStart(): void
    {
        // Magic bytes must be at position 0, not embedded in content.
        $this->handler->detectExecutableMagicBytes('Some text then MZ more text', 'document.txt');

        $this->assertTrue(true);
    }

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
    }

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
    }

    // =========================================================================
    // detectExecutableMagicBytes - embedded PHP detection
    // =========================================================================

    public function testDetectExecutableMagicBytesEmbeddedPhpTag(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = "<html><body><?php system('whoami'); ?></body></html>";
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }

    public function testDetectExecutableMagicBytesPhpShortEchoTag(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = "<html><body><?= 'hello' ?></body></html>";
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }

    public function testDetectExecutableMagicBytesPhpScriptTag(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = '<script language="php">echo "hello";</script>';
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }

    public function testDetectExecutableMagicBytesPhpScriptTagSingleQuotes(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('PHP');

        $content = "<script language='php'>echo 'hello';</script>";
        $this->handler->detectExecutableMagicBytes($content, 'page.html');
    }

    public function testDetectExecutableMagicBytesLogsWarningOnDetection(): void
    {
        $this->logger->expects($this->once())
            ->method('warning');

        try {
            $this->handler->detectExecutableMagicBytes('MZ' . str_repeat("\0", 50), 'test.pdf');
        } catch (Exception $e) {
            // Expected.
        }
    }

    // =========================================================================
    // checkOwnership - File accessible (happy path)
    // =========================================================================

    public function testCheckOwnershipFileAccessible(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')->willReturn('file content');
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $this->logger->expects($this->once())
            ->method('debug');

        $this->handler->checkOwnership($file);

        // No exception means success.
        $this->assertTrue(true);
    }

    public function testCheckOwnershipFolderAccessible(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([]);
        $folder->method('getName')->willReturn('testfolder');
        $folder->method('getId')->willReturn(43);

        $this->handler->checkOwnership($folder);

        $this->assertTrue(true);
    }

    public function testCheckOwnershipGenericNodeAccessible(): void
    {
        // A Node that is neither File nor Folder.
        $node = $this->createMock(Node::class);
        $node->method('getName')->willReturn('generic-node');
        $node->method('getId')->willReturn(44);

        $this->handler->checkOwnership($node);

        $this->assertTrue(true);
    }

    // =========================================================================
    // checkOwnership - NotFoundException path (ownership fix)
    // =========================================================================

    public function testCheckOwnershipNotFoundExceptionFixesOwnership(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotFoundException('not found'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $fileOwner = $this->createMock(IUser::class);
        $fileOwner->method('getUID')->willReturn('wrong-user');
        $file->method('getOwner')->willReturn($fileOwner);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->fileMapper->method('setFileOwnership')
            ->with(42, 'admin')
            ->willReturn(true);

        $this->handler->checkOwnership($file);

        $this->assertTrue(true);
    }

    public function testCheckOwnershipNotFoundExceptionNullOwnerFixesOwnership(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotFoundException('not found'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);
        $file->method('getOwner')->willReturn(null);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->fileMapper->method('setFileOwnership')
            ->willReturn(true);

        $this->handler->checkOwnership($file);

        $this->assertTrue(true);
    }

    public function testCheckOwnershipNotFoundExceptionOwnershipFixFails(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ownership check failed');

        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotFoundException('not found'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $fileOwner = $this->createMock(IUser::class);
        $fileOwner->method('getUID')->willReturn('wrong-user');
        $file->method('getOwner')->willReturn($fileOwner);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->fileMapper->method('setFileOwnership')
            ->willReturn(false);

        $this->handler->checkOwnership($file);
    }

    public function testCheckOwnershipNotFoundExceptionCorrectOwnerButNotAccessible(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotFoundException('not found'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $fileOwner = $this->createMock(IUser::class);
        $fileOwner->method('getUID')->willReturn('admin');
        $file->method('getOwner')->willReturn($fileOwner);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        // Same owner - should log and return without fixing.
        $this->handler->checkOwnership($file);

        $this->assertTrue(true);
    }

    public function testCheckOwnershipNotFoundExceptionOwnershipCheckThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ownership check failed');

        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotFoundException('not found'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);
        $file->method('getOwner')
            ->willThrowException(new Exception('Cannot get owner'));

        $this->handler->checkOwnership($file);
    }

    public function testCheckOwnershipNotFoundExceptionNoUserLoggedIn(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ownership check failed');

        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotFoundException('not found'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $fileOwner = $this->createMock(IUser::class);
        $fileOwner->method('getUID')->willReturn('wrong-user');
        $file->method('getOwner')->willReturn($fileOwner);

        // No user logged in.
        $this->userSession->method('getUser')->willReturn(null);

        $this->handler->checkOwnership($file);
    }

    // =========================================================================
    // checkOwnership - NotPermittedException path
    // =========================================================================

    public function testCheckOwnershipNotPermittedExceptionFixesOwnership(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotPermittedException('permission denied'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->fileMapper->method('setFileOwnership')
            ->willReturn(true);

        $this->handler->checkOwnership($file);

        $this->assertTrue(true);
    }

    public function testCheckOwnershipNotPermittedExceptionFixFails(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ownership fix failed');

        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotPermittedException('permission denied'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->fileMapper->method('setFileOwnership')
            ->willThrowException(new Exception('DB error'));

        $this->handler->checkOwnership($file);
    }

    // =========================================================================
    // checkOwnership - Folder with NotFoundException
    // =========================================================================

    public function testCheckOwnershipFolderNotFoundFixesOwnership(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')
            ->willThrowException(new NotFoundException('not found'));
        $folder->method('getName')->willReturn('testfolder');
        $folder->method('getId')->willReturn(43);

        $fileOwner = $this->createMock(IUser::class);
        $fileOwner->method('getUID')->willReturn('wrong-user');
        $folder->method('getOwner')->willReturn($fileOwner);

        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->fileMapper->method('setFileOwnership')
            ->willReturn(true);

        $this->handler->checkOwnership($folder);

        $this->assertTrue(true);
    }

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
    }

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
    }

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
    }

    public function testOwnFileThrowsWhenNoUserLoggedIn(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to set file ownership');

        $file = $this->createMock(Node::class);
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        $this->userSession->method('getUser')->willReturn(null);

        $this->handler->ownFile($file);
    }

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
    }

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
    }

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
    }

    // =========================================================================
    // checkOwnership - NotPermittedException with no user logged in
    // =========================================================================

    public function testCheckOwnershipNotPermittedNoUserLoggedIn(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ownership fix failed');

        $file = $this->createMock(File::class);
        $file->method('getContent')
            ->willThrowException(new NotPermittedException('denied'));
        $file->method('getName')->willReturn('test.pdf');
        $file->method('getId')->willReturn(42);

        // No user logged in - ownFile will throw.
        $this->userSession->method('getUser')->willReturn(null);

        $this->handler->checkOwnership($file);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testBlockExecutableFileUpperCaseExtension(): void
    {
        $this->expectException(Exception::class);
        $this->handler->blockExecutableFile('VIRUS.PHP', '');
    }

    public function testBlockExecutableFileMixedCaseExtension(): void
    {
        $this->expectException(Exception::class);
        $this->handler->blockExecutableFile('hack.PhP', '');
    }

    public function testBlockExecutableFileDoubleExtension(): void
    {
        $this->expectException(Exception::class);
        $this->handler->blockExecutableFile('document.pdf.exe', '');
    }

    public function testBlockExecutableFileNoExtension(): void
    {
        // File with no extension should pass.
        $this->handler->blockExecutableFile('Makefile', 'content');
        $this->assertTrue(true);
    }

    public function testDetectExecutableMagicBytesEmptyContent(): void
    {
        // Empty content should pass.
        $this->handler->detectExecutableMagicBytes('', 'empty.txt');
        $this->assertTrue(true);
    }

    public function testDetectMagicBytesShebangBeyond1024BytesNotDetected(): void
    {
        // Shebang beyond first 1024 bytes should not be detected.
        $content = str_repeat('a', 1025) . "\n#!/bin/bash\necho hi";
        $this->handler->detectExecutableMagicBytes($content, 'safe.txt');
        $this->assertTrue(true);
    }
}
