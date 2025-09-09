<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\UploadService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Test class for UploadService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class UploadServiceTest extends TestCase
{
    private UploadService $uploadService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create UploadService instance
        $this->uploadService = new UploadService(
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->rootFolder,
            $this->userSession,
            $this->logger
        );
    }

    /**
     * Test uploadFile method with valid file
     */
    public function testUploadFileWithValidFile(): void
    {
        $registerId = 'test-register';
        $schemaId = 'test-schema';
        $objectId = 'test-object';
        $fileContent = 'Test file content';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        // Create mock folder structure
        $userFolder = $this->createMock(Folder::class);
        $registerFolder = $this->createMock(Folder::class);
        $schemaFolder = $this->createMock(Folder::class);
        $objectFolder = $this->createMock(Folder::class);

        // Create mock file
        $uploadedFile = $this->createMock(File::class);
        $uploadedFile->method('getName')->willReturn('test.txt');
        $uploadedFile->method('getContent')->willReturn($fileContent);
        $uploadedFile->method('getMimeType')->willReturn('text/plain');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock root folder
        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('testuser')
            ->willReturn($userFolder);

        // Mock folder hierarchy
        $userFolder->expects($this->once())
            ->method('nodeExists')
            ->with('Open Registers')
            ->willReturn(true);

        $userFolder->expects($this->once())
            ->method('get')
            ->with('Open Registers')
            ->willReturn($registerFolder);

        $registerFolder->expects($this->once())
            ->method('nodeExists')
            ->with($registerId)
            ->willReturn(true);

        $registerFolder->expects($this->once())
            ->method('get')
            ->with($registerId)
            ->willReturn($schemaFolder);

        $schemaFolder->expects($this->once())
            ->method('nodeExists')
            ->with($schemaId)
            ->willReturn(true);

        $schemaFolder->expects($this->once())
            ->method('get')
            ->with($schemaId)
            ->willReturn($objectFolder);

        $objectFolder->expects($this->once())
            ->method('nodeExists')
            ->with($objectId)
            ->willReturn(true);

        $objectFolder->expects($this->once())
            ->method('get')
            ->with($objectId)
            ->willReturn($objectFolder);

        // Mock file creation
        $objectFolder->expects($this->once())
            ->method('newFile')
            ->with('test.txt')
            ->willReturn($uploadedFile);

        $result = $this->uploadService->uploadFile($uploadedFile, $registerId, $schemaId, $objectId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('file', $result);
        $this->assertTrue($result['success']);
        $this->assertEquals($uploadedFile, $result['file']);
    }

    /**
     * Test uploadFile method with non-existent folder structure
     */
    public function testUploadFileWithNonExistentFolders(): void
    {
        $registerId = 'test-register';
        $schemaId = 'test-schema';
        $objectId = 'test-object';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        // Create mock folder structure
        $userFolder = $this->createMock(Folder::class);
        $registerFolder = $this->createMock(Folder::class);
        $schemaFolder = $this->createMock(Folder::class);
        $objectFolder = $this->createMock(Folder::class);

        // Create mock file
        $uploadedFile = $this->createMock(File::class);
        $uploadedFile->method('getName')->willReturn('test.txt');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock root folder
        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('testuser')
            ->willReturn($userFolder);

        // Mock folder hierarchy - create missing folders
        $userFolder->expects($this->once())
            ->method('nodeExists')
            ->with('Open Registers')
            ->willReturn(false);

        $userFolder->expects($this->once())
            ->method('newFolder')
            ->with('Open Registers')
            ->willReturn($registerFolder);

        $registerFolder->expects($this->once())
            ->method('nodeExists')
            ->with($registerId)
            ->willReturn(false);

        $registerFolder->expects($this->once())
            ->method('newFolder')
            ->with($registerId)
            ->willReturn($schemaFolder);

        $schemaFolder->expects($this->once())
            ->method('nodeExists')
            ->with($schemaId)
            ->willReturn(false);

        $schemaFolder->expects($this->once())
            ->method('newFolder')
            ->with($schemaId)
            ->willReturn($objectFolder);

        $objectFolder->expects($this->once())
            ->method('nodeExists')
            ->with($objectId)
            ->willReturn(false);

        $objectFolder->expects($this->once())
            ->method('newFolder')
            ->with($objectId)
            ->willReturn($objectFolder);

        // Mock file creation
        $objectFolder->expects($this->once())
            ->method('newFile')
            ->with('test.txt')
            ->willReturn($uploadedFile);

        $result = $this->uploadService->uploadFile($uploadedFile, $registerId, $schemaId, $objectId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test uploadFile method with no user session
     */
    public function testUploadFileWithNoUserSession(): void
    {
        $registerId = 'test-register';
        $schemaId = 'test-schema';
        $objectId = 'test-object';

        // Create mock file
        $uploadedFile = $this->createMock(File::class);

        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->uploadService->uploadFile($uploadedFile, $registerId, $schemaId, $objectId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['success']);
        $this->assertEquals('No user session found', $result['error']);
    }

    /**
     * Test uploadFile method with file upload error
     */
    public function testUploadFileWithUploadError(): void
    {
        $registerId = 'test-register';
        $schemaId = 'test-schema';
        $objectId = 'test-object';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        // Create mock folder structure
        $userFolder = $this->createMock(Folder::class);
        $registerFolder = $this->createMock(Folder::class);
        $schemaFolder = $this->createMock(Folder::class);
        $objectFolder = $this->createMock(Folder::class);

        // Create mock file
        $uploadedFile = $this->createMock(File::class);
        $uploadedFile->method('getName')->willReturn('test.txt');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock root folder
        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('testuser')
            ->willReturn($userFolder);

        // Mock folder hierarchy
        $userFolder->expects($this->once())
            ->method('nodeExists')
            ->with('Open Registers')
            ->willReturn(true);

        $userFolder->expects($this->once())
            ->method('get')
            ->with('Open Registers')
            ->willReturn($registerFolder);

        $registerFolder->expects($this->once())
            ->method('nodeExists')
            ->with($registerId)
            ->willReturn(true);

        $registerFolder->expects($this->once())
            ->method('get')
            ->with($registerId)
            ->willReturn($schemaFolder);

        $schemaFolder->expects($this->once())
            ->method('nodeExists')
            ->with($schemaId)
            ->willReturn(true);

        $schemaFolder->expects($this->once())
            ->method('get')
            ->with($schemaId)
            ->willReturn($objectFolder);

        $objectFolder->expects($this->once())
            ->method('nodeExists')
            ->with($objectId)
            ->willReturn(true);

        $objectFolder->expects($this->once())
            ->method('get')
            ->with($objectId)
            ->willReturn($objectFolder);

        // Mock file creation to throw exception
        $objectFolder->expects($this->once())
            ->method('newFile')
            ->with('test.txt')
            ->willThrowException(new \Exception('File upload failed'));

        $result = $this->uploadService->uploadFile($uploadedFile, $registerId, $schemaId, $objectId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['success']);
        $this->assertEquals('File upload failed', $result['error']);
    }

    /**
     * Test validateFile method with valid file
     */
    public function testValidateFileWithValidFile(): void
    {
        // Create mock file
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('test.txt');
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getSize')->willReturn(1024);

        $result = $this->uploadService->validateFile($file);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test validateFile method with invalid file type
     */
    public function testValidateFileWithInvalidFileType(): void
    {
        // Create mock file with invalid type
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('test.exe');
        $file->method('getMimeType')->willReturn('application/x-executable');
        $file->method('getSize')->willReturn(1024);

        $result = $this->uploadService->validateFile($file);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('File type not allowed', $result['error']);
    }

    /**
     * Test validateFile method with file too large
     */
    public function testValidateFileWithFileTooLarge(): void
    {
        // Create mock file that's too large
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('test.txt');
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getSize')->willReturn(100 * 1024 * 1024); // 100MB

        $result = $this->uploadService->validateFile($file);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('File too large', $result['error']);
    }

    /**
     * Test getUploadedFiles method
     */
    public function testGetUploadedFiles(): void
    {
        $registerId = 'test-register';
        $schemaId = 'test-schema';
        $objectId = 'test-object';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        // Create mock folder structure
        $userFolder = $this->createMock(Folder::class);
        $registerFolder = $this->createMock(Folder::class);
        $schemaFolder = $this->createMock(Folder::class);
        $objectFolder = $this->createMock(Folder::class);

        // Create mock files
        $file1 = $this->createMock(File::class);
        $file1->method('getName')->willReturn('file1.txt');
        $file1->method('getMimeType')->willReturn('text/plain');
        $file1->method('getSize')->willReturn(1024);

        $file2 = $this->createMock(File::class);
        $file2->method('getName')->willReturn('file2.pdf');
        $file2->method('getMimeType')->willReturn('application/pdf');
        $file2->method('getSize')->willReturn(2048);

        $files = [$file1, $file2];

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock root folder
        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('testuser')
            ->willReturn($userFolder);

        // Mock folder hierarchy
        $userFolder->expects($this->once())
            ->method('nodeExists')
            ->with('Open Registers')
            ->willReturn(true);

        $userFolder->expects($this->once())
            ->method('get')
            ->with('Open Registers')
            ->willReturn($registerFolder);

        $registerFolder->expects($this->once())
            ->method('nodeExists')
            ->with($registerId)
            ->willReturn(true);

        $registerFolder->expects($this->once())
            ->method('get')
            ->with($registerId)
            ->willReturn($schemaFolder);

        $schemaFolder->expects($this->once())
            ->method('nodeExists')
            ->with($schemaId)
            ->willReturn(true);

        $schemaFolder->expects($this->once())
            ->method('get')
            ->with($schemaId)
            ->willReturn($objectFolder);

        $objectFolder->expects($this->once())
            ->method('nodeExists')
            ->with($objectId)
            ->willReturn(true);

        $objectFolder->expects($this->once())
            ->method('get')
            ->with($objectId)
            ->willReturn($objectFolder);

        // Mock getting files
        $objectFolder->expects($this->once())
            ->method('getDirectoryListing')
            ->willReturn($files);

        $result = $this->uploadService->getUploadedFiles($registerId, $schemaId, $objectId);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals($files, $result);
    }

    /**
     * Test getUploadedFiles method with non-existent folder
     */
    public function testGetUploadedFilesWithNonExistentFolder(): void
    {
        $registerId = 'test-register';
        $schemaId = 'test-schema';
        $objectId = 'test-object';

        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        // Create mock folder structure
        $userFolder = $this->createMock(Folder::class);

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock root folder
        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('testuser')
            ->willReturn($userFolder);

        // Mock folder hierarchy - folder doesn't exist
        $userFolder->expects($this->once())
            ->method('nodeExists')
            ->with('Open Registers')
            ->willReturn(false);

        $result = $this->uploadService->getUploadedFiles($registerId, $schemaId, $objectId);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}
