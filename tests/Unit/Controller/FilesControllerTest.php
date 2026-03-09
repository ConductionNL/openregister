<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilesControllerTest extends TestCase
{
    private FilesController $controller;
    private IRequest&MockObject $request;
    private FileService&MockObject $fileService;
    private ObjectService&MockObject $objectService;
    private IRootFolder&MockObject $rootFolder;
    private IUserManager&MockObject $userManager;

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
            $this->userManager
        );
    }

    public function testPage(): void
    {
        $result = $this->controller->page();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

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
    }

    public function testIndexFilesFolderNotFound(): void
    {
        $this->fileService->method('getFiles')
            ->willThrowException(new NotFoundException('Not found'));

        $result = $this->controller->index('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateMissingFileName(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateMissingContent(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
        $this->request->method('getParams')->willReturn([
            'name' => 'test.txt',
        ]);

        $result = $this->controller->create('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);

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

    public function testDeleteSuccess(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($this->createMock(ObjectEntity::class));
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

    public function testDownloadByIdSuccess(): void
    {
        $file = $this->createMock(File::class);
        $this->fileService->method('getFileById')->willReturn($file);

        $streamResponse = $this->createMock(\OCP\AppFramework\Http\StreamResponse::class);
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

    public function testSaveMissingName(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->save('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testPublishSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('publishFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $result = $this->controller->publish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testPublishObjectNull(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn(null);

        $result = $this->controller->publish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDepublishSuccess(): void
    {
        $object = $this->createMock(ObjectEntity::class);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('unpublishFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['id' => 1]);

        $result = $this->controller->depublish('reg1', 'schema1', 'obj1', 1);

        $this->assertEquals(200, $result->getStatus());
    }
}
