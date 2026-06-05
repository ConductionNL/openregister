<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Db\EntityRelationMapper;
<<<<<<< HEAD
=======
use OCA\OpenRegister\Service\File\ManualEntityService;
>>>>>>> origin/development
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
<<<<<<< HEAD
=======
use OCP\IUserSession;
>>>>>>> origin/development
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

=======

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

>>>>>>> origin/development
    protected function setUp(): void
    {
        parent::setUp();

<<<<<<< HEAD
        $this->request = $this->createMock(IRequest::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IAppConfig::class);
=======
        $this->request       = $this->createMock(IRequest::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->indexService  = $this->createMock(IndexService::class);
        $this->fileService   = $this->createMock(FileService::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->manualEntityService = $this->createMock(ManualEntityService::class);
        $this->userSession         = $this->createMock(IUserSession::class);
>>>>>>> origin/development

        $this->controller = new FileTextController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->indexService,
            $this->fileService,
            $this->entityRelationMapper,
            $this->logger,
<<<<<<< HEAD
            $this->config
        );
    }
=======
            $this->config,
            $this->manualEntityService,
            $this->userSession
        );
    }//end setUp()
>>>>>>> origin/development

    public function testExtractFileTextWhenDisabled(): void
    {
        $this->config->method('hasKey')->willReturn(false);
        $this->config->method('getValueString')->willReturn('{}');

        $response = $this->controller->extractFileText(42);

        $this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
<<<<<<< HEAD
    }
=======
    }//end testExtractFileTextWhenDisabled()
>>>>>>> origin/development

    public function testExtractFileTextWhenScopeNone(): void
    {
        $this->config->method('hasKey')->willReturn(true);
        $this->config->method('getValueString')->willReturn('{"extractionScope":"none"}');

        $response = $this->controller->extractFileText(42);

        $this->assertEquals(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
<<<<<<< HEAD
    }
=======
    }//end testExtractFileTextWhenScopeNone()
>>>>>>> origin/development

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
    }
=======
    }//end testExtractFileTextSuccess()
>>>>>>> origin/development

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
    }
=======
    }//end testExtractFileTextException()
>>>>>>> origin/development

    public function testBulkExtractException(): void
    {
        $this->request->method('getParam')->willReturn(100);
        $this->textExtractor->method('extractPendingFiles')
            ->willThrowException(new \Exception('bulk fail'));

        $response = $this->controller->bulkExtract();

        $this->assertEquals(500, $response->getStatus());
        $this->assertStringContainsString('bulk fail', $response->getData()['message']);
<<<<<<< HEAD
    }
=======
    }//end testBulkExtractException()
>>>>>>> origin/development

    public function testGetStatsException(): void
    {
        $this->textExtractor->method('getStats')
            ->willThrowException(new \Exception('stats error'));

        $response = $this->controller->getStats();

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }
=======
    }//end testGetStatsException()
>>>>>>> origin/development

    public function testProcessAndIndexExtractedException(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willThrowException(new \Exception('index error'));

        $response = $this->controller->processAndIndexExtracted();

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }
=======
    }//end testProcessAndIndexExtractedException()
>>>>>>> origin/development

    public function testProcessAndIndexFileException(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willThrowException(new \Exception('file error'));

        $response = $this->controller->processAndIndexFile(1);

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }
=======
    }//end testProcessAndIndexFileException()
>>>>>>> origin/development

    public function testGetChunkingStatsException(): void
    {
        $this->indexService->method('getChunkingStats')
            ->willThrowException(new \Exception('chunk stats error'));

        $response = $this->controller->getChunkingStats();

        $this->assertEquals(500, $response->getStatus());
<<<<<<< HEAD
    }
=======
    }//end testGetChunkingStatsException()
>>>>>>> origin/development

    public function testAnonymizeFileNotFound(): void
    {
        $this->fileService->method('getFileById')->willReturn(null);

        $response = $this->controller->anonymizeFile(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
<<<<<<< HEAD
    }
=======
    }//end testAnonymizeFileNotFound()
>>>>>>> origin/development

    public function testAnonymizeFileAlreadyAnonymized(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('document_anonymized.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);

        $response = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertEquals('File is already anonymized', $response->getData()['message']);
<<<<<<< HEAD
    }
=======
    }//end testAnonymizeFileAlreadyAnonymized()
>>>>>>> origin/development

    public function testAnonymizeFileNoEntities(): void
    {
        $fileNode = $this->createMock(\OCP\Files\File::class);
        $fileNode->method('getName')->willReturn('document.pdf');
        $this->fileService->method('getFileById')->willReturn($fileNode);
<<<<<<< HEAD
        $this->entityRelationMapper->method('findEntitiesForFile')->willReturn([]);
=======
        $this->entityRelationMapper->method('findEntitiesForAnonymization')->willReturn([]);
>>>>>>> origin/development

        $response = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
<<<<<<< HEAD
    }
=======
    }//end testAnonymizeFileNoEntities()
>>>>>>> origin/development

    public function testAnonymizeFileException(): void
    {
        $this->fileService->method('getFileById')
            ->willThrowException(new \Exception('anon error'));

        $response = $this->controller->anonymizeFile(1);

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
<<<<<<< HEAD
    }
}
=======
    }//end testAnonymizeFileException()
}//end class
>>>>>>> origin/development
