<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\File\ManualEntityService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileTextControllerTest extends TestCase
{

    private FileTextController $controller;

    private IRequest&MockObject $request;

    private TextExtractionService&MockObject $textExtractor;

    private FileService&MockObject $fileService;

    private EntityRelationMapper&MockObject $entityRelationMapper;

    private LoggerInterface&MockObject $logger;

    private IAppConfig&MockObject $config;

    private ManualEntityService&MockObject $manualEntityService;

    private IUserSession&MockObject $userSession;

    private IRootFolder&MockObject $rootFolder;

    private IGroupManager&MockObject $groupManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request              = $this->createMock(IRequest::class);
        $this->textExtractor        = $this->createMock(TextExtractionService::class);
        $this->fileService          = $this->createMock(FileService::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->logger               = $this->createMock(LoggerInterface::class);
        $this->config               = $this->createMock(IAppConfig::class);
        $this->manualEntityService  = $this->createMock(ManualEntityService::class);
        $this->userSession          = $this->createMock(IUserSession::class);
        $this->rootFolder           = $this->createMock(IRootFolder::class);
        $this->groupManager         = $this->createMock(IGroupManager::class);

        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($admin);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$this->createMock(Node::class)]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->controller = new FileTextController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->fileService,
            $this->entityRelationMapper,
            $this->logger,
            $this->config,
            $this->manualEntityService,
            $this->userSession,
            $this->rootFolder,
            $this->groupManager
        );
    }//end setUp()

    // =========================================================================
    // getFileText
    // =========================================================================
    public function testGetFileTextReturnsDeprecated(): void
    {
        $result = $this->controller->getFileText(1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('deprecated', $data['message']);
        $this->assertEquals(1, $data['file_id']);
    }//end testGetFileTextReturnsDeprecated()

    public function testGetFileTextReturnsDeprecatedWithDifferentFileId(): void
    {
        $result = $this->controller->getFileText(42);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals(42, $data['file_id']);
        $this->assertStringContainsString('chunk-based endpoints', $data['message']);
    }//end testGetFileTextReturnsDeprecatedWithDifferentFileId()

    // =========================================================================
    // extractFileText
    // =========================================================================
    public function testExtractFileTextDisabledWhenNoConfig(): void
    {
        $this->config->method('hasKey')->willReturn(false);
        $this->config->method('getValueString')->willReturn('{}');

        $result = $this->controller->extractFileText(1);

        $this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('disabled', $data['message']);
    }//end testExtractFileTextDisabledWhenNoConfig()

    public function testExtractFileTextDisabledWhenScopeIsNone(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn(
            json_encode(
                [
                    'extractionScope' => 'none',
                ]
            )
        );

        $this->logger->expects($this->once())
            ->method('info');

        $result = $this->controller->extractFileText(1);

        $this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('disabled', $data['message']);
    }//end testExtractFileTextDisabledWhenScopeIsNone()

    public function testExtractFileTextSuccess(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn(
            json_encode(
                [
                    'extractionScope' => 'all',
                ]
            )
        );

        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(1, true);

        $result = $this->controller->extractFileText(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('successfully', $data['message']);
    }//end testExtractFileTextSuccess()

    public function testExtractFileTextSuccessWithDifferentFileId(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn(
            json_encode(
                [
                    'extractionScope' => 'all',
                ]
            )
        );

        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(99, true);

        $result = $this->controller->extractFileText(99);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }//end testExtractFileTextSuccessWithDifferentFileId()

    public function testExtractFileTextException(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn(
            json_encode(
                [
                    'extractionScope' => 'all',
                ]
            )
        );
        $this->textExtractor->method('extractFile')
            ->willThrowException(new \Exception('Extract error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->extractFileText(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Extract error', $data['message']);
    }//end testExtractFileTextException()

    public function testExtractFileTextWithNullExtractionScope(): void
    {
        // Config has key but extractionScope is not set (null fallback).
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn(
            json_encode(
                [
                    'someOtherKey' => 'value',
                ]
            )
        );

        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(5, true);

        $result = $this->controller->extractFileText(5);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }//end testExtractFileTextWithNullExtractionScope()

    // =========================================================================
    // bulkExtract
    // =========================================================================
    public function testBulkExtractSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap(
                [
                    ['limit', 100, '50'],
                ]
            );
        $this->textExtractor->method('extractPendingFiles')
            ->with(50)
            ->willReturn(['processed' => 10, 'failed' => 0, 'total' => 10]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(10, $data['processed']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEquals(10, $data['total']);
    }//end testBulkExtractSuccess()

    public function testBulkExtractCapsLimitAt500(): void
    {
        $this->request->method('getParam')
            ->willReturnMap(
                [
                    ['limit', 100, '999'],
                ]
            );
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(500)
            ->willReturn(['processed' => 500, 'failed' => 0, 'total' => 500]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(500, $data['processed']);
    }//end testBulkExtractCapsLimitAt500()

    public function testBulkExtractUsesDefaultLimit(): void
    {
        $this->request->method('getParam')
            ->willReturnMap(
                [
                    ['limit', 100, 100],
                ]
            );
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(100)
            ->willReturn(['processed' => 5, 'failed' => 1, 'total' => 6]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(5, $data['processed']);
        $this->assertEquals(1, $data['failed']);
        $this->assertEquals(6, $data['total']);
    }//end testBulkExtractUsesDefaultLimit()

    public function testBulkExtractException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap(
                [
                    ['limit', 100, '50'],
                ]
            );
        $this->textExtractor->method('extractPendingFiles')
            ->willThrowException(new \Exception('Bulk error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->bulkExtract();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Bulk error', $data['message']);
    }//end testBulkExtractException()

    // =========================================================================
    // getStats
    // =========================================================================
    public function testGetStatsSuccess(): void
    {
        $statsData = ['totalFiles' => 100, 'extracted' => 80, 'pending' => 20];
        $this->textExtractor->method('getStats')->willReturn($statsData);

        $result = $this->controller->getStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($statsData, $data['stats']);
    }//end testGetStatsSuccess()

    public function testGetStatsException(): void
    {
        $this->textExtractor->method('getStats')
            ->willThrowException(new \Exception('Stats error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->getStats();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Stats error', $data['message']);
    }//end testGetStatsException()

    // =========================================================================
    // deleteFileText
    // =========================================================================
    public function testDeleteFileTextNotImplemented(): void
    {
        $result = $this->controller->deleteFileText(1);

        $this->assertEquals(501, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('not yet implemented', strtolower($data['message']));
    }//end testDeleteFileTextNotImplemented()

    public function testDeleteFileTextNotImplementedWithDifferentId(): void
    {
        $result = $this->controller->deleteFileText(999);

        $this->assertEquals(501, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('chunk-based endpoints', $data['message']);
    }//end testDeleteFileTextNotImplementedWithDifferentId()

    // =========================================================================
    // anonymizeFile
    // =========================================================================
    public function testAnonymizeFileNotFound(): void
    {
        $this->fileService->method('getFileById')->willReturn(null);

        $result = $this->controller->anonymizeFile(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('not found', strtolower($data['message']));
    }//end testAnonymizeFileNotFound()

    public function testAnonymizeFileAlreadyAnonymized(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('test_anonymized.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('already anonymized', $data['message']);
    }//end testAnonymizeFileAlreadyAnonymized()

    public function testAnonymizeFileAlreadyAnonymizedMidName(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('report_anonymized_v2.docx');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }//end testAnonymizeFileAlreadyAnonymizedMidName()

    public function testAnonymizeFileNoEntities(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('test.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);
        $this->entityRelationMapper->method('findEntitiesForAnonymization')->willReturn([]);

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('No entities', $data['message']);
    }//end testAnonymizeFileNoEntities()

    public function testAnonymizeFileSuccess(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('contract.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        $entityData = [
            [
                'entity_value' => 'John Doe',
                'entity_type'  => 'PERSON',
            ],
            [
                'entity_value' => '123-45-6789',
                'entity_type'  => 'SSN',
            ],
        ];
        $this->entityRelationMapper->method('findEntitiesForAnonymization')
            ->with(10)
            ->willReturn($entityData);

        $anonymizedFileNode = $this->createMock(\OCP\Files\File::class);
        $anonymizedFileNode->method('getId')->willReturn(20);
        $anonymizedFileNode->method('getPath')->willReturn('/files/admin/contract_anonymized.pdf');

        $this->fileService->expects($this->once())
            ->method('anonymizeDocument')
            ->with(
                $fileNode,
                $this->callback(
                    function ($entities) {
                        return count($entities) === 2
                        && $entities[0]['text'] === 'John Doe'
                        && $entities[0]['entityType'] === 'PERSON'
                        && strlen($entities[0]['key']) === 8
                        && $entities[1]['text'] === '123-45-6789'
                        && $entities[1]['entityType'] === 'SSN';
                    }
                )
            )
            ->willReturn($anonymizedFileNode);

        $this->entityRelationMapper->expects($this->never())
            ->method('markAsAnonymized');

        $result = $this->controller->anonymizeFile(10);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('successfully', $data['message']);
        $this->assertEquals(10, $data['original_file_id']);
        $this->assertEquals(20, $data['anonymized_file_id']);
        $this->assertEquals('/files/admin/contract_anonymized.pdf', $data['anonymized_path']);
        $this->assertEquals(2, $data['entities_replaced']);
    }//end testAnonymizeFileSuccess()

    public function testAnonymizeFileDeduplicatesEntities(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('contract.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        // Same entity value appearing multiple times.
        $entityData = [
            [
                'entity_value' => 'John Doe',
                'entity_type'  => 'PERSON',
            ],
            [
                'entity_value' => 'John Doe',
                'entity_type'  => 'PERSON',
            ],
            [
                'entity_value' => 'Acme Corp',
                'entity_type'  => 'ORGANIZATION',
            ],
        ];
        $this->entityRelationMapper->method('findEntitiesForAnonymization')
            ->willReturn($entityData);

        $anonymizedFileNode = $this->createMock(\OCP\Files\File::class);
        $anonymizedFileNode->method('getId')->willReturn(21);
        $anonymizedFileNode->method('getPath')->willReturn('/files/admin/contract_anonymized.pdf');

        $this->fileService->expects($this->once())
            ->method('anonymizeDocument')
            ->with(
                $fileNode,
                $this->callback(
                    function ($entities) {
                        // Should be deduplicated: 2 unique entities, not 3.
                        return count($entities) === 2;
                    }
                )
            )
            ->willReturn($anonymizedFileNode);

        $this->entityRelationMapper->expects($this->never())
            ->method('markAsAnonymized');

        $result = $this->controller->anonymizeFile(5);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['entities_replaced']);
    }//end testAnonymizeFileDeduplicatesEntities()

    public function testAnonymizeFileException(): void
    {
        $this->fileService->method('getFileById')
            ->willThrowException(new \Exception('File error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('File error', $data['message']);
    }//end testAnonymizeFileException()

    public function testAnonymizeFileExceptionDuringAnonymization(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('contract.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        $entityData = [
            [
                'entity_value' => 'Jane Smith',
                'entity_type'  => 'PERSON',
            ],
        ];
        $this->entityRelationMapper->method('findEntitiesForAnonymization')
            ->willReturn($entityData);

        $this->fileService->method('anonymizeDocument')
            ->willThrowException(new \Exception('Anonymization failed'));

        $result = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Anonymization failed', $data['message']);
    }//end testAnonymizeFileExceptionDuringAnonymization()

    // =========================================================================
    // access guards
    // =========================================================================
    public function testExtractFileTextRejectsInaccessibleFile(): void
    {
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($bob);

        $rootFolder   = $this->createMock(IRootFolder::class);
        $userFolder   = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([]);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);

        $this->textExtractor->expects($this->never())
            ->method('extractFile');

        $controller = new FileTextController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->fileService,
            $this->entityRelationMapper,
            $this->logger,
            $this->config,
            $this->manualEntityService,
            $userSession,
            $rootFolder,
            $groupManager
        );

        $result = $controller->extractFileText(999);

        $this->assertEquals(404, $result->getStatus());
    }//end testExtractFileTextRejectsInaccessibleFile()

    public function testBulkExtractRejectsNonAdmin(): void
    {
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($bob);

        $rootFolder   = $this->createMock(IRootFolder::class);
        $userFolder   = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$this->createMock(Node::class)]);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);

        $this->textExtractor->expects($this->never())
            ->method('extractPendingFiles');

        $controller = new FileTextController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->fileService,
            $this->entityRelationMapper,
            $this->logger,
            $this->config,
            $this->manualEntityService,
            $userSession,
            $rootFolder,
            $groupManager
        );

        $result = $controller->bulkExtract();

        $this->assertEquals(403, $result->getStatus());
    }//end testBulkExtractRejectsNonAdmin()
}//end class
