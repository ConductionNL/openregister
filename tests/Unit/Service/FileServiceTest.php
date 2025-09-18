<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Share\IManager;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCA\Files_Versions\Versions\VersionManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test class for FileService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class FileServiceTest extends TestCase
{
    private FileService $fileService;
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private LoggerInterface&MockObject $logger;
    private IRootFolder&MockObject $rootFolder;
    private IManager&MockObject $shareManager;
    private IURLGenerator&MockObject $urlGenerator;
    private IConfig&MockObject $config;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IGroupManager&MockObject $groupManager;
    private ISystemTagManager&MockObject $systemTagManager;
    private ISystemTagObjectMapper&MockObject $systemTagObjectMapper;
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private VersionManager&MockObject $versionManager;
    private FileMapper&MockObject $fileMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->shareManager = $this->createMock(IManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->config = $this->createMock(IConfig::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->systemTagManager = $this->createMock(ISystemTagManager::class);
        $this->systemTagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->versionManager = $this->createMock(VersionManager::class);
        $this->fileMapper = $this->createMock(FileMapper::class);

        // Create FileService instance
        $this->fileService = new FileService(
            $this->userSession,
            $this->userManager,
            $this->logger,
            $this->rootFolder,
            $this->shareManager,
            $this->urlGenerator,
            $this->config,
            $this->registerMapper,
            $this->schemaMapper,
            $this->groupManager,
            $this->systemTagManager,
            $this->systemTagObjectMapper,
            $this->objectEntityMapper,
            $this->versionManager,
            $this->fileMapper
        );
    }

    /**
     * Test cleanFilename method with simple filename
     *
     * @return void
     */
    public function testCleanFilenameWithSimpleFilename(): void
    {
        $filePath = 'testfile.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('testfile.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with folder ID prefix
     *
     * @return void
     */
    public function testCleanFilenameWithFolderIdPrefix(): void
    {
        $filePath = '8010/testfile.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('testfile.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with full path
     *
     * @return void
     */
    public function testCleanFilenameWithFullPath(): void
    {
        $filePath = '/path/to/testfile.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('testfile.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with complex path
     *
     * @return void
     */
    public function testCleanFilenameWithComplexPath(): void
    {
        $filePath = '12345/folder/subfolder/testfile.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('testfile.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with empty string
     *
     * @return void
     */
    public function testCleanFilenameWithEmptyString(): void
    {
        $filePath = '';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('', $result['fileName']);
    }

    /**
     * Test cleanFilename method with filename only (no extension)
     *
     * @return void
     */
    public function testCleanFilenameWithFilenameOnly(): void
    {
        $filePath = 'testfile';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('testfile', $result['fileName']);
    }

    /**
     * Test cleanFilename method with multiple dots in filename
     *
     * @return void
     */
    public function testCleanFilenameWithMultipleDots(): void
    {
        $filePath = 'test.file.name.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('test.file.name.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with special characters
     *
     * @return void
     */
    public function testCleanFilenameWithSpecialCharacters(): void
    {
        $filePath = 'test-file_name@123.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('test-file_name@123.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with unicode characters
     *
     * @return void
     */
    public function testCleanFilenameWithUnicodeCharacters(): void
    {
        $filePath = 'tëst-file_ñame.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('tëst-file_ñame.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with Windows-style path
     *
     * @return void
     */
    public function testCleanFilenameWithWindowsStylePath(): void
    {
        $filePath = 'C:\\path\\to\\testfile.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('C:\path\to\testfile.txt', $result['fileName']);
    }

    /**
     * Test cleanFilename method with multiple slashes
     *
     * @return void
     */
    public function testCleanFilenameWithMultipleSlashes(): void
    {
        $filePath = '//path///to////testfile.txt';
        
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->fileService);
        $method = $reflection->getMethod('extractFileNameFromPath');
        $method->setAccessible(true);
        $result = $method->invoke($this->fileService, $filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('cleanPath', $result);
        $this->assertArrayHasKey('fileName', $result);
        $this->assertEquals('testfile.txt', $result['fileName']);
    }

    /**
     * Test deleteFile with valid file
     *
     * @return void
     */
    public function testDeleteFileWithValidFile(): void
    {
        $mockFile = $this->createMock(File::class);
        $mockFile->method('delete')->willReturn(true);

        $result = $this->fileService->deleteFile($mockFile);

        $this->assertTrue($result);
    }

}
