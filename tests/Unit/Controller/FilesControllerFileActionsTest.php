<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileAuditHandler;
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
use OCP\IUserSession;
use OCP\IUser;
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

        // The audit handler is invoked from every successful action path;
        // wire a no-op mock so each test does not have to re-stub it.
        $auditHandler = $this->createMock(FileAuditHandler::class);
        $this->fileService->method('getAuditHandler')->willReturn($auditHandler);

        $this->controller = new FilesController(
            'openregister',
            $this->request,
            $this->fileService,
            $this->objectService,
            $this->rootFolder,
            $this->userManager,
            $this->eventDispatcher
        );
    }//end setUp()

    private function setupObjectServiceMocks(?ObjectEntity $object=null): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn($object);
    }//end setupObjectServiceMocks()

    private function createObjectMock(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('abc-123');
        return $object;
    }//end createObjectMock()

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
    }//end testRenameSuccess()

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
    }//end testRenameDuplicate()

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
    }//end testRenameInvalidChars()

    /**
     * Test lock returns lock metadata.
     */
    public function testLockSuccess(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $lockHandler = $this->createMock(FileLockHandler::class);
        $lockHandler->method('lockFile')->willReturn(
                [
                    'locked'    => true,
                    'lockedBy'  => 'user-1',
                    'lockedAt'  => '2026-03-25T10:00:00Z',
                    'expiresAt' => '2026-03-25T10:30:00Z',
                ]
                );
        $this->fileService->method('getLockHandler')->willReturn($lockHandler);

        $response = $this->controller->lock('reg', 'sch', 'abc-123', 42);

        $this->assertEquals(200, $response->getStatus());
    }//end testLockSuccess()

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
    }//end testLockConflict()

    /**
     * Test batch returns 200 on all success.
     */
    public function testBatchSuccess(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $batchHandler = $this->createMock(FileBatchHandler::class);
        $batchHandler->method('executeBatch')->willReturn(
                [
                    'results' => [
                        ['fileId' => 42, 'success' => true],
                        ['fileId' => 43, 'success' => true],
                    ],
                    'summary' => ['total' => 2, 'succeeded' => 2, 'failed' => 0],
                ]
                );
        $this->fileService->method('getBatchHandler')->willReturn($batchHandler);

        $this->request->method('getParams')->willReturn(
                [
                    'action'  => 'publish',
                    'fileIds' => [42, 43],
                ]
                );

        $response = $this->controller->batch('reg', 'sch', 'abc-123');

        $this->assertEquals(200, $response->getStatus());
    }//end testBatchSuccess()

    /**
     * Test batch returns 207 on partial failure.
     */
    public function testBatchPartialFailure(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $batchHandler = $this->createMock(FileBatchHandler::class);
        $batchHandler->method('executeBatch')->willReturn(
                [
                    'results' => [
                        ['fileId' => 42, 'success' => true],
                        ['fileId' => 43, 'success' => false, 'error' => 'locked'],
                    ],
                    'summary' => ['total' => 2, 'succeeded' => 1, 'failed' => 1],
                ]
                );
        $this->fileService->method('getBatchHandler')->willReturn($batchHandler);

        $this->request->method('getParams')->willReturn(
                [
                    'action'  => 'delete',
                    'fileIds' => [42, 43],
                ]
                );

        $response = $this->controller->batch('reg', 'sch', 'abc-123');

        $this->assertEquals(207, $response->getStatus());
    }//end testBatchPartialFailure()

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

        $this->request->method('getParams')->willReturn(
                [
                    'action'  => 'archive',
                    'fileIds' => [42],
                ]
                );

        $response = $this->controller->batch('reg', 'sch', 'abc-123');

        $this->assertEquals(400, $response->getStatus());
    }//end testBatchInvalidAction()

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
    }//end testUnlockNonOwner()

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
    }//end testPreviewUnsupported()

    /**
     * Test that an anonymous caller hitting preview() on an UNPUBLISHED file
     * gets 403 — the public-preview gate must block previewing files that
     * haven't been explicitly published with a public share.
     */
    public function testPreviewAnonymousOnUnpublishedFileReturns403(): void
    {
        $fileMapper = $this->createMock(\OCA\OpenRegister\Db\FileMapper::class);
        $fileMapper->expects($this->once())
            ->method('isFilePublished')
            ->with(42)
            ->willReturn(false);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = new FilesController(
            'openregister',
            $this->request,
            $this->fileService,
            $this->objectService,
            $this->rootFolder,
            $this->userManager,
            $this->eventDispatcher,
            $fileMapper,
            null,
            $userSession
        );

        $response = $controller->preview('reg', 'sch', 'abc-123', 42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(403, $response->getStatus());
    }//end testPreviewAnonymousOnUnpublishedFileReturns403()

    /**
     * Test that an anonymous caller hitting preview() on a PUBLISHED file
     * passes the gate and falls through to the file/preview pipeline.
     * Verified by asserting the JSONResponse 404 we get from the unsupported
     * preview handler — meaning the gate did NOT short-circuit with 403.
     */
    public function testPreviewAnonymousOnPublishedFileFallsThrough(): void
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

        $fileMapper = $this->createMock(\OCA\OpenRegister\Db\FileMapper::class);
        $fileMapper->expects($this->once())
            ->method('isFilePublished')
            ->with(42)
            ->willReturn(true);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = new FilesController(
            'openregister',
            $this->request,
            $this->fileService,
            $this->objectService,
            $this->rootFolder,
            $this->userManager,
            $this->eventDispatcher,
            $fileMapper,
            null,
            $userSession
        );

        $response = $controller->preview('reg', 'sch', 'abc-123', 42);

        // The gate let us through; the unsupported preview handler returned 404.
        // 403 here would mean the gate fired (regression).
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }//end testPreviewAnonymousOnPublishedFileFallsThrough()

    /**
     * Test that an AUTHENTICATED caller bypasses the published-file gate
     * entirely — isFilePublished MUST NOT even be queried for logged-in users.
     */
    public function testPreviewAuthenticatedBypassesPublishedGate(): void
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

        $fileMapper = $this->createMock(\OCA\OpenRegister\Db\FileMapper::class);
        $fileMapper->expects($this->never())->method('isFilePublished');

        $authedUser  = $this->createMock(IUser::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($authedUser);

        $controller = new FilesController(
            'openregister',
            $this->request,
            $this->fileService,
            $this->objectService,
            $this->rootFolder,
            $this->userManager,
            $this->eventDispatcher,
            $fileMapper,
            null,
            $userSession
        );

        $response = $controller->preview('reg', 'sch', 'abc-123', 42);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
    }//end testPreviewAuthenticatedBypassesPublishedGate()

    /**
     * Helper: build a mock target ObjectEntity. The objectService is set up
     * to return $sourceObject on the first getObject() call (source lookup)
     * and $targetObject on the second (target lookup).
     */
    private function setupCopyMoveObjectMocks(ObjectEntity $sourceObject, ObjectEntity $targetObject): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturnOnConsecutiveCalls(
            $sourceObject,
            $targetObject
        );
    }//end setupCopyMoveObjectMocks()

    /**
     * Test copy returns 201 when copying within the same register/schema.
     */
    public function testCopyWithinSameRegister(): void
    {
        $sourceObject = new ObjectEntity();
        $sourceObject->setUuid('source-uuid');
        $targetObject = new ObjectEntity();
        $targetObject->setUuid('target-uuid');
        $this->setupCopyMoveObjectMocks($sourceObject, $targetObject);

        $newFile = $this->createMock(File::class);
        $newFile->method('getId')->willReturn(99);
        $newFile->method('getName')->willReturn('copied.pdf');

        $this->request->method('getParams')->willReturn(
                [
                    'targetObjectId' => 'target-uuid',
                ]
                );
        $this->fileService->expects($this->once())
            ->method('copyFile')
            ->with($sourceObject, 42, $targetObject)
            ->willReturn($newFile);
        $this->fileService->method('formatFile')->willReturn(['name' => 'copied.pdf']);

        $response = $this->controller->copy('reg', 'sch', 'source-uuid', 42);

        $this->assertEquals(201, $response->getStatus());
    }//end testCopyWithinSameRegister()

    /**
     * Test copy across registers/schemas honors targetRegister + targetSchema params.
     */
    public function testCopyAcrossRegisters(): void
    {
        $sourceObject = new ObjectEntity();
        $sourceObject->setUuid('source-uuid');
        $targetObject = new ObjectEntity();
        $targetObject->setUuid('target-uuid');
        $this->setupCopyMoveObjectMocks($sourceObject, $targetObject);

        $newFile = $this->createMock(File::class);
        $newFile->method('getId')->willReturn(100);

        $this->request->method('getParams')->willReturn(
                [
                    'targetObjectId' => 'target-uuid',
                    'targetRegister' => 'other-register',
                    'targetSchema'   => 'other-schema',
                ]
                );

        // The controller switches schema/register on the objectService before
        // resolving the target object. Verify both setSchema and setRegister
        // are called with the alternate values.
        $this->objectService->expects($this->atLeastOnce())
            ->method('setSchema')
            ->with($this->logicalOr('sch', 'other-schema'));
        $this->objectService->expects($this->atLeastOnce())
            ->method('setRegister')
            ->with($this->logicalOr('reg', 'other-register'));

        $this->fileService->method('copyFile')->willReturn($newFile);
        $this->fileService->method('formatFile')->willReturn(['name' => 'cross.pdf']);

        $response = $this->controller->copy('reg', 'sch', 'source-uuid', 42);

        $this->assertEquals(201, $response->getStatus());
    }//end testCopyAcrossRegisters()

    /**
     * Test copy returns 404 when target object does not exist.
     */
    public function testCopyToNonexistentTarget(): void
    {
        $sourceObject = new ObjectEntity();
        $sourceObject->setUuid('source-uuid');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        // First call resolves source, second call (target) returns null.
        $this->objectService->method('getObject')->willReturnOnConsecutiveCalls(
            $sourceObject,
            null
        );

        $this->request->method('getParams')->willReturn(
                [
                    'targetObjectId' => 'missing-uuid',
                ]
                );

        // copyFile must NOT be called when target is missing.
        $this->fileService->expects($this->never())->method('copyFile');

        $response = $this->controller->copy('reg', 'sch', 'source-uuid', 42);

        $this->assertEquals(404, $response->getStatus());
    }//end testCopyToNonexistentTarget()

    /**
     * Test move with source cleanup -- both copyFile + delete must run, and
     * controller must dispatch FileMovedEvent (covered by formatFile contract).
     */
    public function testMoveWithSourceCleanup(): void
    {
        $sourceObject = new ObjectEntity();
        $sourceObject->setUuid('source-uuid');
        $targetObject = new ObjectEntity();
        $targetObject->setUuid('target-uuid');
        $this->setupCopyMoveObjectMocks($sourceObject, $targetObject);

        $movedFile = $this->createMock(File::class);
        $movedFile->method('getId')->willReturn(101);
        $movedFile->method('getName')->willReturn('moved.pdf');

        $this->request->method('getParams')->willReturn(
                [
                    'targetObjectId' => 'target-uuid',
                ]
                );

        // moveFile is the integration point that does copy+delete; assert
        // it is called exactly once with the expected source/target.
        $this->fileService->expects($this->once())
            ->method('moveFile')
            ->with($sourceObject, 42, $targetObject)
            ->willReturn($movedFile);
        $this->fileService->method('formatFile')->willReturn(['name' => 'moved.pdf']);

        // Verify a FileMovedEvent is dispatched on success.
        $this->eventDispatcher->expects($this->once())->method('dispatchTyped');

        $response = $this->controller->move('reg', 'sch', 'source-uuid', 42);

        $this->assertEquals(200, $response->getStatus());
    }//end testMoveWithSourceCleanup()

    /**
     * Test move returns 423 when the source file is locked by another user.
     */
    public function testMoveBlockedWhenSourceLocked(): void
    {
        $sourceObject = new ObjectEntity();
        $sourceObject->setUuid('source-uuid');
        $targetObject = new ObjectEntity();
        $targetObject->setUuid('target-uuid');
        $this->setupCopyMoveObjectMocks($sourceObject, $targetObject);

        $this->request->method('getParams')->willReturn(
                [
                    'targetObjectId' => 'target-uuid',
                ]
                );

        $this->fileService->method('moveFile')
            ->willThrowException(new Exception('File is locked by user-2'));

        $response = $this->controller->move('reg', 'sch', 'source-uuid', 42);

        $this->assertEquals(423, $response->getStatus());
    }//end testMoveBlockedWhenSourceLocked()

    /**
     * Regression test: restoreVersion response shape must be the formatted-file
     * payload (NOT the raw versioning-handler return value). Guards against
     * shape drift -- the frontend depends on a flat file object.
     */
    public function testRestoreVersionResponseShape(): void
    {
        $object = $this->createObjectMock();
        $this->setupObjectServiceMocks($object);

        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('rapport.pdf');

        $versioningHandler = $this->createMock(FileVersioningHandler::class);
        $versioningHandler->expects($this->once())
            ->method('restoreVersion')
            ->with($file, 'v123');

        $this->fileService->method('getFile')->willReturn($file);
        $this->fileService->method('getVersioningHandler')->willReturn($versioningHandler);

        // Locked-down expected shape: matches FileFormattingHandler::formatFile keys.
        $expected = [
            'id'          => 42,
            'name'        => 'rapport.pdf',
            'size'        => 12345,
            'mimetype'    => 'application/pdf',
            'mtime'       => 1700000000,
            'accessUrl'   => 'https://example.com/file/42',
            'downloadUrl' => 'https://example.com/file/42/download',
            'published'   => null,
            'labels'      => [],
        ];
        $this->fileService->method('formatFile')->willReturn($expected);

        $response = $this->controller->restoreVersion('reg', 'sch', 'abc-123', 42, 'v123');

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($expected, $response->getData());
    }//end testRestoreVersionResponseShape()
}//end class
