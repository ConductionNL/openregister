<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FilesControllerTest extends TestCase
{
    private FilesController $controller;
    private IRequest&MockObject $request;
    private FileService&MockObject $fileService;
    private ObjectService&MockObject $objectService;
    private IRootFolder&MockObject $rootFolder;
    private IUserManager&MockObject $userManager;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->userManager = $this->createMock(IUserManager::class);

        $this->controller = new FilesController(
            'openregister',
            $this->request,
            $this->fileService,
            $this->objectService,
            $this->rootFolder,
            $this->userManager,
            $this->createMock(IEventDispatcher::class)
        );

        $this->reflection = new ReflectionClass(FilesController::class);
    }

    /**
     * Helper to invoke private methods via reflection.
     */
    private function invokePrivateMethod(string $methodName, array $parameters = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->controller, $parameters);
    }

    /**
     * Helper to set up object service mocks for common patterns.
     */
    private function setupObjectServiceMocks(?ObjectEntity $object = null): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
    }

    // ==================== page() Tests ====================

    public function testPage(): void
    {
        $result = $this->controller->page();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    // ==================== index() Tests ====================

    public function testIndexSuccess(): void
    {
        $files = [];
        $this->fileService->method('getFiles')->willReturn($files);
        $this->fileService->method('formatFiles')->willReturn(['results' => [], 'total' => 0]);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->index('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexObjectNotFound(): void
    {
        $this->fileService->method('getFiles')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->index('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $result->getData());
    }

    public function testIndexFilesFolderNotFound(): void
    {
        $this->fileService->method('getFiles')
            ->willThrowException(new NotFoundException('Not found'));

        $result = $this->controller->index('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(['error' => 'Files folder not found'], $result->getData());
    }

    public function testIndexGeneralException(): void
    {
        $this->fileService->method('getFiles')
            ->willThrowException(new Exception('General error'));

        $result = $this->controller->index('reg1', 'schema1', 'obj1');

        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals(['error' => 'General error'], $result->getData());
    }

    public function testIndexWithFilesReturnsFormattedData(): void
    {
        $file1 = $this->createMock(File::class);
        $file2 = $this->createMock(File::class);
        $this->fileService->method('getFiles')->willReturn([$file1, $file2]);
        $this->fileService->method('formatFiles')->willReturn([
            'results' => [['id' => 1], ['id' => 2]],
            'total' => 2,
        ]);
        $this->request->method('getParams')->willReturn(['page' => 1, 'limit' => 10]);

        $result = $this->controller->index('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(2, $data['results']);
        $this->assertEquals(2, $data['total']);
    }

    // ==================== show() Tests ====================

    public function testShowSuccessReturnsStreamResponse(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $resource = fopen('php://memory', 'r');
        $file->method('fopen')->willReturn($resource);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getName')->willReturn('test.txt');
        $file->method('getSize')->willReturn(42);

        $this->fileService->method('getFile')->willReturn($file);

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 1);

        $this->assertInstanceOf(StreamResponse::class, $result);
        fclose($resource);
    }

    public function testShowObjectNotFound(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $result->getData());
    }

    public function testShowFileNotFoundReturns404(): void
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getOwner'])
            ->getMock();
        $object->method('getOwner')->willReturn(null);
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('getFile')->willReturn(null);
        // No users found via getFileViaKnownUsers either
        $this->userManager->method('get')->willReturn(null);

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 999);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(['error' => 'File not found'], $result->getData());
    }

    public function testShowFileNotFoundFallbackViaOwner(): void
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getOwner'])
            ->getMock();
        $object->method('getOwner')->willReturn('testuser');
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('getFile')->willReturn(null);

        // Set up user lookup for owner
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->userManager->method('get')
            ->willReturnCallback(function ($userId) use ($user) {
                if ($userId === 'testuser') {
                    return $user;
                }
                return null;
            });

        $file = $this->createMock(File::class);
        $resource = fopen('php://memory', 'r');
        $file->method('fopen')->willReturn($resource);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getName')->willReturn('logo.png');
        $file->method('getSize')->willReturn(1024);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$file]);

        $this->rootFolder->method('getUserFolder')
            ->with('testuser')
            ->willReturn($userFolder);

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 42);

        $this->assertInstanceOf(StreamResponse::class, $result);
        fclose($resource);
    }

    public function testShowFileNotFoundFallbackViaSystemUser(): void
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getOwner'])
            ->getMock();
        $object->method('getOwner')->willReturn(null);
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('getFile')->willReturn(null);

        // Owner is null, so try OpenRegister, then admin
        $sysUser = $this->createMock(IUser::class);
        $sysUser->method('getUID')->willReturn('OpenRegister');

        $this->userManager->method('get')
            ->willReturnCallback(function ($userId) use ($sysUser) {
                if ($userId === 'OpenRegister') {
                    return $sysUser;
                }
                return null;
            });

        $file = $this->createMock(File::class);
        $resource = fopen('php://memory', 'r');
        $file->method('fopen')->willReturn($resource);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getSize')->willReturn(2048);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$file]);

        $this->rootFolder->method('getUserFolder')
            ->with('OpenRegister')
            ->willReturn($userFolder);

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 42);

        $this->assertInstanceOf(StreamResponse::class, $result);
        fclose($resource);
    }

    public function testShowFallbackUserFolderThrowsException(): void
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getOwner'])
            ->getMock();
        $object->method('getOwner')->willReturn('baduser');
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('getFile')->willReturn(null);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('baduser');

        // All users throw exceptions
        $this->userManager->method('get')
            ->willReturnCallback(function ($userId) use ($user) {
                if ($userId === 'baduser') {
                    return $user;
                }
                return null;
            });

        $this->rootFolder->method('getUserFolder')
            ->willThrowException(new Exception('No folder'));

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 42);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
    }

    public function testShowFallbackEmptyNodesReturnsNotFound(): void
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getOwner'])
            ->getMock();
        $object->method('getOwner')->willReturn('testuser');
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('getFile')->willReturn(null);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userManager->method('get')
            ->willReturnCallback(function ($userId) use ($user) {
                if ($userId === 'testuser') {
                    return $user;
                }
                return null;
            });

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 42);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
    }

    public function testShowGeneralException(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new Exception('Something broke'));

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Something broke'], $result->getData());
    }

    public function testShowFallbackNodeNotFileInstance(): void
    {
        $object = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getOwner'])
            ->getMock();
        $object->method('getOwner')->willReturn('testuser');
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('getFile')->willReturn(null);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userManager->method('get')
            ->willReturnCallback(function ($userId) use ($user) {
                if ($userId === 'testuser') {
                    return $user;
                }
                return null;
            });

        // Return a folder instead of a file
        $folder = $this->createMock(Folder::class);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$folder]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $result = $this->controller->show('reg1', 'schema1', 'obj1', 42);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
    }

    // ==================== create() Tests ====================

    public function testCreateSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'Hello World',
            'share' => false,
            'tags' => [],
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(['id' => 1], $result->getData());
    }

    public function testCreateWithFilenameKey(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 2]);

        $this->request->method('getParams')->willReturn([
            'filename' => 'test2.txt',
            'content' => 'Hello',
            'share' => false,
            'tags' => [],
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateMissingFileName(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'File name is required (use "name" or "filename")'], $result->getData());
    }

    public function testCreateMissingContent(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);
        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'File content is required'], $result->getData());
    }

    public function testCreateObjectNotFound(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateGeneralException(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('addFile')
            ->willThrowException(new Exception('Disk full'));

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => false,
            'tags' => [],
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Disk full'], $result->getData());
    }

    public function testCreateWithStringShareTrue(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => 'true',
            'tags' => 'tag1, tag2',
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateWithStringShareYes(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => 'yes',
            'tags' => [],
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateWithNumericShare(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => 1,
            'tags' => [],
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateWithNullShare(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'tags' => [],
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateWithNullTags(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => false,
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCreateObjectNull(): void
    {
        $this->setupObjectServiceMocks(null);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        // create() checks if object is null and returns 404 before checking params
        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $result->getData());
    }

    // ==================== save() Tests ====================

    public function testSaveSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('saveFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1, 'name' => 'saved.txt']);

        $this->request->method('getParams')->willReturn([
            'name' => 'saved.txt',
            'content' => 'file content',
            'share' => true,
            'tags' => ['tag1'],
        ]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSaveMissingName(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'File name is required'], $result->getData());
    }

    public function testSaveMissingContent(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);
        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
        ]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'File content is required'], $result->getData());
    }

    public function testSaveEmptyContent(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);
        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => '',
        ]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'File content is required'], $result->getData());
    }

    public function testSaveObjectNull(): void
    {
        $this->setupObjectServiceMocks(null);
        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
        ]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $result->getData());
    }

    public function testSaveObjectNotFoundViaException(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testSaveGeneralException(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('saveFile')
            ->willThrowException(new Exception('Save failed'));

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => false,
            'tags' => [],
        ]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Save failed'], $result->getData());
    }

    public function testSaveWithStringTags(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('saveFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => false,
            'tags' => 'tag1, tag2, tag3',
        ]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSaveWithShareTrue(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('saveFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
            'content' => 'data',
            'share' => true,
            'tags' => [],
        ]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
    }

    // ==================== createMultipart() Tests ====================

    public function testCreateMultipartMissingFile(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);
        $this->request->method('getUploadedFile')->willReturn(null);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->createMultipart('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateMultipartObjectNull(): void
    {
        $this->setupObjectServiceMocks(null);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->createMultipart('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
        $this->assertEquals(['error' => 'Object not found'], $result->getData());
    }

    public function testCreateMultipartSingleFileUpload(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        // Create a real temp file for validation
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test content');

        // The 'file' key upload is appended as-is, so it needs share/tags
        $uploadedFile = [
            'name' => 'upload.txt',
            'type' => 'text/plain',
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => 12,
            'share' => false,
            'tags' => [],
        ];

        $this->request->method('getUploadedFile')
            ->willReturnCallback(function ($key) use ($uploadedFile) {
                if ($key === 'file') {
                    return $uploadedFile;
                }
                return null;
            });
        $this->request->method('getParams')->willReturn([]);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFiles')->willReturn([
            'results' => [['id' => 1, 'name' => 'upload.txt']],
        ]);

        $result = $this->controller->createMultipart('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());

        @unlink($tmpFile);
    }

    public function testCreateMultipartMultipleFilesUpload(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $tmpFile1 = tempnam(sys_get_temp_dir(), 'test1_');
        $tmpFile2 = tempnam(sys_get_temp_dir(), 'test2_');
        file_put_contents($tmpFile1, 'content1');
        file_put_contents($tmpFile2, 'content2');

        $multiFiles = [
            'name' => ['file1.txt', 'file2.txt'],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => [$tmpFile1, $tmpFile2],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [8, 8],
        ];

        $this->request->method('getUploadedFile')
            ->willReturnCallback(function ($key) use ($multiFiles) {
                if ($key === 'files') {
                    return $multiFiles;
                }
                return null;
            });
        $this->request->method('getParams')->willReturn([
            'share' => 'true',
            'tags' => ['tag1', 'tag2'],
        ]);

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);
        $this->fileService->method('formatFiles')->willReturn([
            'results' => [['id' => 1], ['id' => 2]],
        ]);

        $result = $this->controller->createMultipart('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());

        @unlink($tmpFile1);
        @unlink($tmpFile2);
    }

    public function testCreateMultipartGeneralException(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new Exception('Server error'));

        $result = $this->controller->createMultipart('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Server error'], $result->getData());
    }

    // ==================== update() Tests ====================

    public function testUpdateSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('updateFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1, 'name' => 'updated.txt']);

        $this->request->method('getParams')->willReturn([
            'content' => 'updated content',
            'tags' => ['tag1'],
        ]);

        $result = $this->controller->update('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateMetadataOnly(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('updateFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            'tags' => ['newtag'],
        ]);

        $result = $this->controller->update('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateObjectNotFound(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->update('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testUpdateGeneralException(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('updateFile')
            ->willThrowException(new Exception('Update failed'));

        $this->request->method('getParams')->willReturn([
            'content' => 'data',
            'tags' => [],
        ]);

        $result = $this->controller->update('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Update failed'], $result->getData());
    }

    public function testUpdateNoTagsProvided(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('updateFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->update('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    // ==================== delete() Tests ====================

    public function testDeleteSuccess(): void
    {
        $this->setupObjectServiceMocks($this->createMock(ObjectEntity::class));
        $this->fileService->method('deleteFile')->willReturn(true);

        $result = $this->controller->delete('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDeleteObjectNotFound(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->delete('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDeleteGeneralException(): void
    {
        $this->setupObjectServiceMocks($this->createMock(ObjectEntity::class));
        $this->fileService->method('deleteFile')
            ->willThrowException(new Exception('Delete error'));

        $result = $this->controller->delete('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Delete error'], $result->getData());
    }

    public function testDeleteReturnsFalse(): void
    {
        $this->setupObjectServiceMocks($this->createMock(ObjectEntity::class));
        $this->fileService->method('deleteFile')->willReturn(false);

        $result = $this->controller->delete('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    // ==================== publish() Tests ====================

    public function testPublishSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('publishFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $result = $this->controller->publish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testPublishObjectNull(): void
    {
        $this->setupObjectServiceMocks(null);

        $result = $this->controller->publish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testPublishObjectNotFound(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->publish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testPublishGeneralException(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('publishFile')
            ->willThrowException(new Exception('Publish failed'));

        $result = $this->controller->publish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Publish failed'], $result->getData());
    }

    // ==================== depublish() Tests ====================

    public function testDepublishSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('unpublishFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $result = $this->controller->depublish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDepublishObjectNull(): void
    {
        $this->setupObjectServiceMocks(null);

        $result = $this->controller->depublish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDepublishObjectNotFound(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->depublish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDepublishGeneralException(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $this->fileService->method('unpublishFile')
            ->willThrowException(new Exception('Depublish failed'));

        $result = $this->controller->depublish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals(['error' => 'Depublish failed'], $result->getData());
    }

    // ==================== downloadById() Tests ====================

    public function testDownloadByIdSuccess(): void
    {
        $file = $this->createMock(File::class);
        $this->fileService->method('getFileById')->willReturn($file);

        $streamResponse = $this->createMock(StreamResponse::class);
        $this->fileService->method('streamFile')->willReturn($streamResponse);

        $result = $this->controller->downloadById(1);

        $this->assertNotInstanceOf(JSONResponse::class, $result);
    }

    public function testDownloadByIdNotFound(): void
    {
        $this->fileService->method('getFileById')->willReturn(null);

        $result = $this->controller->downloadById(999);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
    }

    public function testDownloadByIdNotFoundException(): void
    {
        $this->fileService->method('getFileById')
            ->willThrowException(new NotFoundException('Not found'));

        $result = $this->controller->downloadById(999);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
    }

    public function testDownloadByIdGeneralException(): void
    {
        $this->fileService->method('getFileById')
            ->willThrowException(new Exception('Server error'));

        $result = $this->controller->downloadById(999);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals(['error' => 'Server error'], $result->getData());
    }

    // ==================== Private method tests via reflection ====================

    // -- parseBool --

    public function testParseBoolWithBoolTrue(): void
    {
        $result = $this->invokePrivateMethod('parseBool', [true]);
        $this->assertTrue($result);
    }

    public function testParseBoolWithBoolFalse(): void
    {
        $result = $this->invokePrivateMethod('parseBool', [false]);
        $this->assertFalse($result);
    }

    public function testParseBoolWithStringTrue(): void
    {
        $this->assertTrue($this->invokePrivateMethod('parseBool', ['true']));
        $this->assertTrue($this->invokePrivateMethod('parseBool', ['TRUE']));
        $this->assertTrue($this->invokePrivateMethod('parseBool', [' True ']));
    }

    public function testParseBoolWithString1(): void
    {
        $this->assertTrue($this->invokePrivateMethod('parseBool', ['1']));
    }

    public function testParseBoolWithStringOn(): void
    {
        $this->assertTrue($this->invokePrivateMethod('parseBool', ['on']));
    }

    public function testParseBoolWithStringYes(): void
    {
        $this->assertTrue($this->invokePrivateMethod('parseBool', ['yes']));
    }

    public function testParseBoolWithStringFalse(): void
    {
        $this->assertFalse($this->invokePrivateMethod('parseBool', ['false']));
    }

    public function testParseBoolWithStringNo(): void
    {
        $this->assertFalse($this->invokePrivateMethod('parseBool', ['no']));
    }

    public function testParseBoolWithString0(): void
    {
        $this->assertFalse($this->invokePrivateMethod('parseBool', ['0']));
    }

    public function testParseBoolWithNumeric1(): void
    {
        $this->assertTrue($this->invokePrivateMethod('parseBool', [1]));
    }

    public function testParseBoolWithNumeric0(): void
    {
        $this->assertFalse($this->invokePrivateMethod('parseBool', [0]));
    }

    public function testParseBoolWithNull(): void
    {
        $this->assertFalse($this->invokePrivateMethod('parseBool', [null]));
    }

    public function testParseBoolWithArray(): void
    {
        $this->assertFalse($this->invokePrivateMethod('parseBool', [[]]));
    }

    // -- normalizeTags --

    public function testNormalizeTagsWithArray(): void
    {
        $result = $this->invokePrivateMethod('normalizeTags', [['tag1', ' tag2 ', 'tag3']]);
        $this->assertEquals(['tag1', 'tag2', 'tag3'], $result);
    }

    public function testNormalizeTagsWithString(): void
    {
        $result = $this->invokePrivateMethod('normalizeTags', ['tag1, tag2, tag3']);
        $this->assertEquals(['tag1', 'tag2', 'tag3'], $result);
    }

    public function testNormalizeTagsWithEmptyString(): void
    {
        $result = $this->invokePrivateMethod('normalizeTags', ['']);
        $this->assertEquals([''], $result);
    }

    public function testNormalizeTagsWithNull(): void
    {
        $result = $this->invokePrivateMethod('normalizeTags', [null]);
        $this->assertEquals([], $result);
    }

    public function testNormalizeTagsWithInteger(): void
    {
        $result = $this->invokePrivateMethod('normalizeTags', [42]);
        $this->assertEquals([], $result);
    }

    // -- getUploadErrorMessage --

    public function testGetUploadErrorMessageIniSize(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [UPLOAD_ERR_INI_SIZE]);
        $this->assertStringContainsString('upload_max_filesize', $result);
    }

    public function testGetUploadErrorMessageFormSize(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [UPLOAD_ERR_FORM_SIZE]);
        $this->assertStringContainsString('MAX_FILE_SIZE', $result);
    }

    public function testGetUploadErrorMessagePartial(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [UPLOAD_ERR_PARTIAL]);
        $this->assertStringContainsString('partially', $result);
    }

    public function testGetUploadErrorMessageNoFile(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [UPLOAD_ERR_NO_FILE]);
        $this->assertStringContainsString('No file', $result);
    }

    public function testGetUploadErrorMessageNoTmpDir(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [UPLOAD_ERR_NO_TMP_DIR]);
        $this->assertStringContainsString('temporary folder', $result);
    }

    public function testGetUploadErrorMessageCantWrite(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [UPLOAD_ERR_CANT_WRITE]);
        $this->assertStringContainsString('write file', $result);
    }

    public function testGetUploadErrorMessageExtension(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [UPLOAD_ERR_EXTENSION]);
        $this->assertStringContainsString('PHP extension', $result);
    }

    public function testGetUploadErrorMessageUnknown(): void
    {
        $result = $this->invokePrivateMethod('getUploadErrorMessage', [999]);
        $this->assertStringContainsString('Unknown upload error', $result);
        $this->assertStringContainsString('999', $result);
    }

    // -- getFileViaKnownUsers --

    public function testGetFileViaKnownUsersWithOwner(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('owner1');
        $this->userManager->method('get')
            ->willReturnCallback(function ($userId) use ($user) {
                if ($userId === 'owner1') {
                    return $user;
                }
                return null;
            });

        $file = $this->createMock(File::class);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$file]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $result = $this->invokePrivateMethod('getFileViaKnownUsers', [42, 'owner1']);
        $this->assertSame($file, $result);
    }

    public function testGetFileViaKnownUsersNoUserFound(): void
    {
        $this->userManager->method('get')->willReturn(null);

        $result = $this->invokePrivateMethod('getFileViaKnownUsers', [42, null]);
        $this->assertNull($result);
    }

    public function testGetFileViaKnownUsersAllExceptions(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userManager->method('get')
            ->willReturnCallback(function ($userId) use ($user) {
                if ($userId === 'admin') {
                    return $user;
                }
                return null;
            });

        $this->rootFolder->method('getUserFolder')
            ->willThrowException(new Exception('No folder'));

        $result = $this->invokePrivateMethod('getFileViaKnownUsers', [42, null]);
        $this->assertNull($result);
    }

    // -- validateUploadedFile --

    public function testValidateUploadedFileSuccess(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test content');

        $file = [
            'name' => 'test.jpg',
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
        ];

        // Should not throw
        $this->invokePrivateMethod('validateUploadedFile', [$file]);
        $this->assertTrue(true);

        @unlink($tmpFile);
    }

    public function testValidateUploadedFileWithError(): void
    {
        $file = [
            'name' => 'test.jpg',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/File upload error/');
        $this->invokePrivateMethod('validateUploadedFile', [$file]);
    }

    public function testValidateUploadedFileNonReadable(): void
    {
        $file = [
            'name' => 'test.jpg',
            'tmp_name' => '/tmp/nonexistent_file_' . uniqid(),
            'error' => UPLOAD_ERR_OK,
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/not found or not readable/');
        $this->invokePrivateMethod('validateUploadedFile', [$file]);
    }

    public function testValidateUploadedFileNullError(): void
    {
        // When error key is missing, error should be null and no upload error thrown,
        // but the file should still fail readability check
        $file = [
            'name' => 'test.jpg',
            'tmp_name' => '/tmp/nonexistent_' . uniqid(),
        ];

        $this->expectException(Exception::class);
        $this->invokePrivateMethod('validateUploadedFile', [$file]);
    }

    // -- normalizeMultipartFiles --

    public function testNormalizeMultipartFilesEmpty(): void
    {
        $result = $this->invokePrivateMethod('normalizeMultipartFiles', [[], []]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testNormalizeMultipartFilesSingle(): void
    {
        $files = [
            'name' => 'doc.pdf',
            'type' => 'application/pdf',
            'tmp_name' => '/tmp/phpXYZ',
            'error' => 0,
            'size' => 5000,
        ];
        $data = ['share' => 'true', 'tags' => 'tag1'];

        $result = $this->invokePrivateMethod('normalizeMultipartFiles', [$files, $data]);

        $this->assertCount(1, $result);
        $this->assertEquals('doc.pdf', $result[0]['name']);
        $this->assertTrue($result[0]['share']);
    }

    public function testNormalizeMultipartFilesMultiple(): void
    {
        $files = [
            'name' => ['a.txt', 'b.txt'],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => ['/tmp/a', '/tmp/b'],
            'error' => [0, 0],
            'size' => [10, 20],
        ];
        $data = ['share' => 'true', 'tags' => ['t1', 't2']];

        $result = $this->invokePrivateMethod('normalizeMultipartFiles', [$files, $data]);

        $this->assertCount(2, $result);
    }

    // -- normalizeMultipleFiles edge cases --

    public function testNormalizeMultipleFilesWithScalarErrorAndSize(): void
    {
        // Test the branch where error and size are scalar (not arrays)
        $files = [
            'name' => ['file.txt'],
            'type' => 'text/plain',      // not an array
            'tmp_name' => '/tmp/test',    // not an array
            'error' => 0,                 // scalar int
            'size' => 100,                // scalar int
        ];
        $data = ['share' => 'false', 'tags' => 'sometag'];
        $fileNames = ['file.txt'];

        $result = $this->invokePrivateMethod('normalizeMultipleFiles', [$files, $data, $fileNames]);

        $this->assertCount(1, $result);
        $this->assertEquals('file.txt', $result[0]['name']);
        $this->assertEquals(0, $result[0]['error']);
        $this->assertEquals(100, $result[0]['size']);
    }

    public function testNormalizeMultipleFilesWithMissingFields(): void
    {
        // Test with minimal fields - no type/tmp_name/error/size arrays
        $files = [
            'name' => ['file.txt'],
        ];
        $data = ['share' => 'false', 'tags' => ''];
        $fileNames = ['file.txt'];

        $result = $this->invokePrivateMethod('normalizeMultipleFiles', [$files, $data, $fileNames]);

        $this->assertCount(1, $result);
        $this->assertEquals('', $result[0]['type']);
        $this->assertEquals('', $result[0]['tmp_name']);
        $this->assertEquals(UPLOAD_ERR_NO_FILE, $result[0]['error']);
        $this->assertEquals(0, $result[0]['size']);
    }

    // -- normalizeSingleFile --

    public function testNormalizeSingleFileWithArrayTags(): void
    {
        $files = [
            'name' => 'test.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '/tmp/phpXYZ',
            'error' => 0,
            'size' => 12345,
        ];
        $data = [
            'share' => 'true',
            'tags' => ['tag1', 'tag2'],
        ];

        $result = $this->invokePrivateMethod('normalizeSingleFile', [$files, $data]);

        $this->assertEquals(['tag1', 'tag2'], $result['tags']);
        $this->assertTrue($result['share']);
    }

    public function testNormalizeSingleFileWithMissingFields(): void
    {
        $files = [];
        $data = ['share' => 'false', 'tags' => ''];

        $result = $this->invokePrivateMethod('normalizeSingleFile', [$files, $data]);

        $this->assertEquals('', $result['name']);
        $this->assertEquals('', $result['type']);
        $this->assertEquals('', $result['tmp_name']);
        $this->assertEquals(UPLOAD_ERR_NO_FILE, $result['error']);
        $this->assertEquals(0, $result['size']);
    }

    // -- extractUploadedFiles --

    public function testExtractUploadedFilesThrowsWhenNoFiles(): void
    {
        $this->request->method('getUploadedFile')->willReturn(null);
        $this->request->method('getParams')->willReturn([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No files uploaded');
        $this->invokePrivateMethod('extractUploadedFiles', []);
    }

    public function testExtractUploadedFilesSingleFile(): void
    {
        $uploadedFile = [
            'name' => 'single.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/phpSingle',
            'error' => 0,
            'size' => 100,
        ];

        $this->request->method('getUploadedFile')
            ->willReturnCallback(function ($key) use ($uploadedFile) {
                if ($key === 'file') {
                    return $uploadedFile;
                }
                return null;
            });
        $this->request->method('getParams')->willReturn([]);

        $result = $this->invokePrivateMethod('extractUploadedFiles', []);
        $this->assertCount(1, $result);
    }

    public function testExtractUploadedFilesMultipart(): void
    {
        $multiFiles = [
            'name' => 'doc.pdf',
            'type' => 'application/pdf',
            'tmp_name' => '/tmp/phpMulti',
            'error' => 0,
            'size' => 500,
        ];

        $this->request->method('getUploadedFile')
            ->willReturnCallback(function ($key) use ($multiFiles) {
                if ($key === 'files') {
                    return $multiFiles;
                }
                return null;
            });
        $this->request->method('getParams')->willReturn([
            'share' => 'false',
            'tags' => '',
        ]);

        $result = $this->invokePrivateMethod('extractUploadedFiles', []);
        $this->assertCount(1, $result);
        $this->assertEquals('doc.pdf', $result[0]['name']);
    }

    // -- validateAndGetObject --

    public function testValidateAndGetObjectReturnsObject(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->setupObjectServiceMocks($object);

        $result = $this->invokePrivateMethod('validateAndGetObject', ['reg1', 'schema1', 'obj1']);
        $this->assertSame($object, $result);
    }

    public function testValidateAndGetObjectReturnsNull(): void
    {
        $this->setupObjectServiceMocks(null);

        $result = $this->invokePrivateMethod('validateAndGetObject', ['reg1', 'schema1', 'obj1']);
        $this->assertNull($result);
    }

    // -- processUploadedFiles --

    public function testProcessUploadedFilesEmpty(): void
    {
        $object = $this->createMock(ObjectEntity::class);

        $result = $this->invokePrivateMethod('processUploadedFiles', [$object, []]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testProcessUploadedFilesSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'file content');

        $uploadedFiles = [
            [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 12,
                'share' => false,
                'tags' => [],
            ],
        ];

        $file = $this->createMock(File::class);
        $this->fileService->method('addFile')->willReturn($file);

        $result = $this->invokePrivateMethod('processUploadedFiles', [$object, $uploadedFiles]);
        $this->assertCount(1, $result);

        @unlink($tmpFile);
    }

    public function testProcessUploadedFilesFailedRead(): void
    {
        $object = $this->createMock(ObjectEntity::class);

        // Create a file then delete it so file_exists passes but file_get_contents would fail
        // Actually, validateUploadedFile checks first, so we need a file that passes validation
        // but fails on file_get_contents. This is hard to simulate, so let's test the upload error path.
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'content');

        $uploadedFiles = [
            [
                'name' => 'test.txt',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 12,
                'share' => false,
                'tags' => [],
            ],
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/File upload error/');
        $this->invokePrivateMethod('processUploadedFiles', [$object, $uploadedFiles]);

        @unlink($tmpFile);
    }
}
