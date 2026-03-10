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
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FileValidationHandler
 *
 * Tests executable file blocking and magic byte detection.
 */
class FileValidationHandlerTest extends TestCase
{
    /** @var FileValidationHandler */
    private FileValidationHandler $handler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var FileMapper&MockObject $fileMapper */
        $fileMapper = $this->createMock(FileMapper::class);
        /** @var IUserSession&MockObject $userSession */
        $userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new FileValidationHandler($fileMapper, $userSession, $this->logger);
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
            'exe'   => ['malware.exe'],
            'bat'   => ['script.bat'],
            'sh'    => ['hack.sh'],
            'php'   => ['shell.php'],
            'py'    => ['exploit.py'],
            'jar'   => ['app.jar'],
            'dll'   => ['library.dll'],
            'ps1'   => ['powershell.ps1'],
            'bash'  => ['run.bash'],
            'msi'   => ['installer.msi'],
            'apk'   => ['app.apk'],
            'deb'   => ['package.deb'],
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

    // =========================================================================
    // detectExecutableMagicBytes
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
}
