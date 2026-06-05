<?php

declare(strict_types=1);

namespace Unit\Controller;

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

/**
 * Coverage tests for FileTextController — targets remaining uncovered branches.
 */
class FileTextControllerCoverageTest extends TestCase
{
<<<<<<< HEAD

    private FileTextController $controller;

    private IRequest&MockObject $request;

    private TextExtractionService&MockObject $textExtractor;

    private IndexService&MockObject $indexService;

    private FileService&MockObject $fileService;

    private EntityRelationMapper&MockObject $entityRelationMapper;

    private LoggerInterface&MockObject $logger;

    private IAppConfig&MockObject $config;

    private ManualEntityService&MockObject $manualEntityService;

    private IUserSession&MockObject $userSession;

=======
    private FileTextController $controller;
    private IRequest&MockObject $request;
    private TextExtractionService&MockObject $textExtractor;
    private IndexService&MockObject $indexService;
    private FileService&MockObject $fileService;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private LoggerInterface&MockObject $logger;
    private IAppConfig&MockObject $config;

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

    // =========================================================================
    // extractFileText — enabled with valid scope
    // =========================================================================
<<<<<<< HEAD
=======

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testExtractFileTextEnabledWithValidScope(): void
    {
        $this->config->method('hasKey')
            ->with('openregister', 'fileManagement')
            ->willReturn(true);
        $this->config->method('getValueString')
            ->with('openregister', 'fileManagement')
            ->willReturn(json_encode(['extractionScope' => 'all']));

        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(123, true);

        $result = $this->controller->extractFileText(123);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Text extracted successfully', $data['message']);
<<<<<<< HEAD
    }//end testExtractFileTextEnabledWithValidScope()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testExtractFileTextEnabledWithNullScope(): void
    {
        $this->config->method('hasKey')
            ->with('openregister', 'fileManagement')
            ->willReturn(true);
        $this->config->method('getValueString')
            ->with('openregister', 'fileManagement')
<<<<<<< HEAD
            ->willReturn(json_encode([]));
        // no extractionScope key
=======
            ->willReturn(json_encode([])); // no extractionScope key

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(42, true);

        $result = $this->controller->extractFileText(42);

        $this->assertEquals(200, $result->getStatus());
<<<<<<< HEAD
    }//end testExtractFileTextEnabledWithNullScope()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    // =========================================================================
    // processAndIndexExtracted — options passed through
    // =========================================================================
<<<<<<< HEAD
=======

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testProcessAndIndexExtractedWithBothOptions(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['processed' => 10, 'failed' => 0]);

        $result = $this->controller->processAndIndexExtracted(50, 1000, 100);

        $this->assertEquals(200, $result->getStatus());
<<<<<<< HEAD
    }//end testProcessAndIndexExtractedWithBothOptions()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testProcessAndIndexExtractedWithNullLimit(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['processed' => 5, 'failed' => 1]);

        $result = $this->controller->processAndIndexExtracted(null, null, null);

        $this->assertEquals(200, $result->getStatus());
<<<<<<< HEAD
    }//end testProcessAndIndexExtractedWithNullLimit()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    // =========================================================================
    // processAndIndexFile — with chunk size
    // =========================================================================
<<<<<<< HEAD
=======

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testProcessAndIndexFileWithOptions(): void
    {
        $this->indexService->method('processUnindexedChunks')
            ->willReturn(['success' => true]);

        $result = $this->controller->processAndIndexFile(42, 500, 50);

        $this->assertEquals(200, $result->getStatus());
<<<<<<< HEAD
    }//end testProcessAndIndexFileWithOptions()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    // =========================================================================
    // bulkExtract — with limit at boundary
    // =========================================================================
<<<<<<< HEAD
    public function testBulkExtractWithExactMaxLimit(): void
    {
        $this->request->method('getParam')
            ->willReturnMap(
                    [
                        ['limit', 100, 500],
                    ]
                    );
=======

    public function testBulkExtractWithExactMaxLimit(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 100, 500],
            ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(500)
            ->willReturn(['processed' => 500, 'failed' => 0, 'total' => 500]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(500, $data['processed']);
<<<<<<< HEAD
    }//end testBulkExtractWithExactMaxLimit()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testBulkExtractWithOverMaxLimit(): void
    {
        $this->request->method('getParam')
<<<<<<< HEAD
            ->willReturnMap(
                    [
                        ['limit', 100, 1000],
                    ]
                    );
=======
            ->willReturnMap([
                ['limit', 100, 1000],
            ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        // Should be capped to 500
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(500)
            ->willReturn(['processed' => 200, 'failed' => 0, 'total' => 200]);

        $result = $this->controller->bulkExtract();

        $this->assertEquals(200, $result->getStatus());
<<<<<<< HEAD
    }//end testBulkExtractWithOverMaxLimit()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    // =========================================================================
    // anonymizeFile — already anonymized in middle of name
    // =========================================================================
<<<<<<< HEAD
=======

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testAnonymizeFileNotFoundReturns404(): void
    {
        $this->fileService->method('getFileById')
            ->with(999)
            ->willReturn(null);

        $result = $this->controller->anonymizeFile(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File not found', $data['message']);
<<<<<<< HEAD
    }//end testAnonymizeFileNotFoundReturns404()
}//end class
=======
    }
}
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
