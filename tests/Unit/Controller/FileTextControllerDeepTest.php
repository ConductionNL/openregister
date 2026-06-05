<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\EntityRelationMapper;
<<<<<<< HEAD
use OCA\OpenRegister\Service\File\ManualEntityService;
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
<<<<<<< HEAD
use OCP\IUserSession;
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileTextControllerDeepTest extends TestCase
{
<<<<<<< HEAD

    private FileTextController $controller;

    private IRequest|MockObject $request;

    private TextExtractionService|MockObject $textExtractor;

    private IndexService|MockObject $indexService;

    private FileService|MockObject $fileService;

    private EntityRelationMapper|MockObject $entityRelationMapper;

    private LoggerInterface|MockObject $logger;

    private IAppConfig|MockObject $config;

    private ManualEntityService|MockObject $manualEntityService;

    private IUserSession|MockObject $userSession;

=======
    private FileTextController $controller;
    private IRequest|MockObject $request;
    private TextExtractionService|MockObject $textExtractor;
    private IndexService|MockObject $indexService;
    private FileService|MockObject $fileService;
    private EntityRelationMapper|MockObject $entityRelationMapper;
    private LoggerInterface|MockObject $logger;
    private IAppConfig|MockObject $config;

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    protected function setUp(): void
    {
        parent::setUp();

<<<<<<< HEAD
        $this->request       = $this->createMock(IRequest::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->indexService  = $this->createMock(IndexService::class);
        $this->fileService   = $this->createMock(FileService::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->manualEntityService = $this->createMock(ManualEntityService::class);
        $this->userSession         = $this->createMock(IUserSession::class);
=======
        $this->request = $this->createMock(IRequest::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IAppConfig::class);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $this->controller = new FileTextController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->indexService,
            $this->fileService,
            $this->entityRelationMapper,
            $this->logger,
<<<<<<< HEAD
            $this->config,
            $this->manualEntityService,
            $this->userSession
        );
    }//end setUp()
=======
            $this->config
        );
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testExtractFileTextWhenDisabled(): void
    {
        $this->config->method('hasKey')->willReturn(false);
        $this->config->method('getValueString')->willReturn('{}');

        $response = $this->controller->extractFileText(42);

        $this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
<<<<<<< HEAD
    }//end testExtractFileTextWhenDisabled()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testExtractFileTextWhenScopeNone(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn('{"extractionScope":"none"}');

        $response = $this->controller->extractFileText(42);

        $this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
<<<<<<< HEAD
    }//end testExtractFileTextWhenScopeNone()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testExtractFileTextSuccess(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn('{"extractionScope":"all"}');
        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(42, true);

        $response = $this->controller->extractFileText(42);

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
<<<<<<< HEAD
    }//end testExtractFileTextSuccess()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testExtractFileTextException(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn('{"extractionScope":"all"}');
        $this->textExtractor->method('extractFile')
            ->willThrowException(new \Exception('extract error'));

        $response = $this->controller->extractFileText(42);

        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContainsString('extract error', $response->getData()['message']);
<<<<<<< HEAD
    }//end testExtractFileTextException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testBulkExtractException(): void
    {
        $this->request->method('getParam')->willReturn(100);
        $this->textExtractor->method('extractPendingFiles')
            ->willThrowException(new \Exception('bulk fail'));

        $response = $this->controller->bulkExtract();

        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContainsString('bulk fail', $response->getData()['message']);
<<<<<<< HEAD
    }//end testBulkExtractException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testGetStatsException(): void
    {
        $this->textExtractor->method('getStats')
            ->willThrowException(new \Exception('stats error'));

        $response = $this->controller->getStats();

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }//end testGetStatsException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testProcessAndIndexExtractedException(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willThrowException(new \Exception('index error'));

        $response = $this->controller->processAndIndexExtracted();

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }//end testProcessAndIndexExtractedException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testProcessAndIndexFileException(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willThrowException(new \Exception('file error'));

        $response = $this->controller->processAndIndexFile(1);

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }//end testProcessAndIndexFileException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testGetChunkingStatsException(): void
    {
        $this->indexService->method('getChunkingStats')
            ->willThrowException(new \Exception('chunk stats error'));

        $response = $this->controller->getChunkingStats();

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }//end testGetChunkingStatsException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testAnonymizeFileNotFound(): void
    {
        $this->fileService->method('getFileById')->willReturn(null);

        $response = $this->controller->anonymizeFile(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
<<<<<<< HEAD
    }//end testAnonymizeFileNotFound()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testAnonymizeFileAlreadyAnonymized(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('document_anonymized.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        $response = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertEquals('File is already anonymized', $response->getData()['message']);
<<<<<<< HEAD
    }//end testAnonymizeFileAlreadyAnonymized()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testAnonymizeFileNoEntities(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('document.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);
<<<<<<< HEAD
        $this->entityRelationMapper->method('findEntitiesForAnonymization')->willReturn([]);
=======
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $response = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
<<<<<<< HEAD
    }//end testAnonymizeFileNoEntities()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testAnonymizeFileException(): void
    {
        $this->fileService->method('getFileById')
            ->willThrowException(new \Exception('anon error'));

        $response = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
<<<<<<< HEAD
    }//end testAnonymizeFileException()
}//end class
=======
    }
}
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
