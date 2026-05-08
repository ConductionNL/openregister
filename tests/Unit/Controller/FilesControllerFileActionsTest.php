<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileBatchHandler;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FilePreviewHandler;
use OCA\OpenRegister\Service\File\FileVersioningHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilesControllerFileActionsTest extends TestCase
{
    private FilesController $controller;
    private IRequest&MockObject $request;
    private FileService&MockObject $fileService;
    private ObjectService&MockObject $objectService;
    private IRootFolder&MockObject $rootFolder;
    private IUserManager&MockObject $userManager;
    private IEventDispatcher&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->fileService     = $this->createMock(FileService::class);
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->rootFolder      = $this->createMock(IRootFolder::class);
        $this->userManager     = $this->createMock(IUserManager::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $this->controller = new FilesController(
            'openregister',
            $this->request,
            $this->fileService,
            $this->objectService,
            $this->rootFolder,
            $this->userManager,
            $this->eventDispatcher
        );
    }

    private function setupObjectServiceMocks(?ObjectEntity $object = null): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
    }

    private function createObjectMock(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('abc-123');
        return $object;
    }

    /**
     * Test rename returns 200 on success.
     */
    public function testRenameSuccess(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('new-name.pdf');

        $this->request->method('getParams')->willReturn(['name' => 'new-name.pdf']);
        $this->fileService->method('renameFile')->willReturn($file);
        $this->fileService->method('formatFile')->willReturn(['name' => 'new-name.pdf']);

        $response = $this->controller->rename('reg', 'sch', 'abc-123', 42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test rename returns 409 on duplicate name.
     */
    public function testRenameDuplicate(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $this->request->method('getParams')->willReturn(['name' => 'existing.pdf']);
        $this->fileService->method('renameFile')
            ->willThrowException(new Exception('A file with name "existing.pdf" already exists for this object'));

        $response = $this->controller->rename('reg', 'sch', 'abc-123', 42);

        $this->assertEquals(409, $response->getStatus());
    }

    /**
     * Test rename returns 400 on invalid characters.
     */
    public function testRenameInvalidChars(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $this->request->method('getParams')->willReturn(['name' => 'file<>.pdf']);
        $this->fileService->method('renameFile')
            ->willThrowException(new Exception('File name contains invalid characters'));

        $response = $this->controller->rename('reg', 'sch', 'abc-123', 42);

        $this->assertEquals(400, $response->getStatus());
    }

    /**
     * Test lock returns lock metadata.
     */
    public function testLockSuccess(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $lockHandler = $this->createMock(FileLockHandler::class);
        $lockHandler->method('lockFile')->willReturn([
            'locked'   => true,
            'lockedBy' => 'user-1',
            'lockedAt' => '2026-03-25T10:00:00Z',
            'expiresAt' => '2026-03-25T10:30:00Z',
        ]);
        $this->fileService->method('getLockHandler')->willReturn($lockHandler);

        $response = $this->controller->lock('reg', 'sch', 'abc-123', 42);

        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test lock returns 423 when already locked.
     */
    public function testLockConflict(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $lockHandler = $this->createMock(FileLockHandler::class);
        $lockHandler->method('lockFile')
            ->willThrowException(new Exception('File is locked by user-1'));
        $this->fileService->method('getLockHandler')->willReturn($lockHandler);

        $response = $this->controller->lock('reg', 'sch', 'abc-123', 42);

        $this->assertEquals(423, $response->getStatus());
    }

    /**
     * Test batch returns 200 on all success.
     */
    public function testBatchSuccess(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $batchHandler = $this->createMock(FileBatchHandler::class);
        $batchHandler->method('executeBatch')->willReturn([
            'results' => [
                ['fileId' => 42, 'success' => true],
                ['fileId' => 43, 'success' => true],
            ],
            'summary' => ['total' => 2, 'succeeded' => 2, 'failed' => 0],
        ]);
        $this->fileService->method('getBatchHandler')->willReturn($batchHandler);

        $this->request->method('getParams')->willReturn([
            'action'  => 'publish',
            'fileIds' => [42, 43],
        ]);

        $response = $this->controller->batch('reg', 'sch', 'abc-123');

        $this->assertEquals(200, $response->getStatus());
    }

    /**
     * Test batch returns 207 on partial failure.
     */
    public function testBatchPartialFailure(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $batchHandler = $this->createMock(FileBatchHandler::class);
        $batchHandler->method('executeBatch')->willReturn([
            'results' => [
                ['fileId' => 42, 'success' => true],
                ['fileId' => 43, 'success' => false, 'error' => 'locked'],
            ],
            'summary' => ['total' => 2, 'succeeded' => 1, 'failed' => 1],
        ]);
        $this->fileService->method('getBatchHandler')->willReturn($batchHandler);

        $this->request->method('getParams')->willReturn([
            'action'  => 'delete',
            'fileIds' => [42, 43],
        ]);

        $response = $this->controller->batch('reg', 'sch', 'abc-123');

        $this->assertEquals(207, $response->getStatus());
    }

    /**
     * Test batch returns 400 on invalid action.
     */
    public function testBatchInvalidAction(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $batchHandler = $this->createMock(FileBatchHandler::class);
        $batchHandler->method('executeBatch')
            ->willThrowException(new Exception('Invalid batch action. Allowed: publish, depublish, delete, label'));
        $this->fileService->method('getBatchHandler')->willReturn($batchHandler);

        $this->request->method('getParams')->willReturn([
            'action'  => 'archive',
            'fileIds' => [42],
        ]);

        $response = $this->controller->batch('reg', 'sch', 'abc-123');

        $this->assertEquals(400, $response->getStatus());
    }

    /**
     * Test unlock returns 403 for non-owner.
     */
    public function testUnlockNonOwner(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $lockHandler = $this->createMock(FileLockHandler::class);
        $lockHandler->method('unlockFile')
            ->willThrowException(new Exception('Only the lock owner or an admin can unlock this file'));
        $this->fileService->method('getLockHandler')->willReturn($lockHandler);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->unlock('reg', 'sch', 'abc-123', 42);

        $this->assertEquals(403, $response->getStatus());
    }

    /**
     * Test preview returns 404 for unsupported type.
     */
    public function testPreviewUnsupported(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $this->fileService->method('getFile')->willReturn($file);

        $previewHandler = $this->createMock(FilePreviewHandler::class);
        $previewHandler->method('getPreview')
            ->willThrowException(new Exception('Preview not available for this file type'));
        $this->fileService->method('getPreviewHandler')->willReturn($previewHandler);

        $this->request->method('getParam')->willReturn(null);

        $response = $this->controller->preview('reg', 'sch', 'abc-123', 42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }
}
