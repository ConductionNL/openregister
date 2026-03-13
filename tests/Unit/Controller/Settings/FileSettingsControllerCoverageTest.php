<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\FileSettingsController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Db\FileMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coverage tests for FileSettingsController — targets uncovered branches.
 */
class FileSettingsControllerCoverageTest extends TestCase
{
    private FileSettingsController $controller;
    private IRequest&MockObject $request;
    private ContainerInterface&MockObject $container;
    private SettingsService&MockObject $settingsService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new FileSettingsController(
            'openregister',
            $this->request,
            $this->container,
            $this->settingsService,
            $this->logger
        );
    }

    // =========================================================================
    // getFileSettings
    // =========================================================================

    public function testGetFileSettingsSuccess(): void
    {
        $data = ['extractionEnabled' => true, 'provider' => 'dolphin'];
        $this->settingsService->method('getFileSettingsOnly')->willReturn($data);

        $result = $this->controller->getFileSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    public function testGetFileSettingsException(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willThrowException(new \Exception('Settings unavailable'));

        $result = $this->controller->getFileSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertArrayHasKey('error', $result->getData());
        $this->assertStringContainsString('Settings unavailable', $result->getData()['error']);
    }

    // =========================================================================
    // updateFileSettings
    // =========================================================================

    public function testUpdateFileSettingsSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['extractionEnabled' => true]);
        $this->settingsService->method('updateFileSettingsOnly')
            ->willReturn(['extractionEnabled' => true]);

        $result = $this->controller->updateFileSettings();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('File settings updated successfully', $data['message']);
    }

    public function testUpdateFileSettingsExtractsProviderIdFromArray(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => ['id' => 'dolphin', 'name' => 'Dolphin API'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === 'dolphin';
            }))
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsExtractsChunkingStrategyIdFromArray(): void
    {
        $this->request->method('getParams')->willReturn([
            'chunkingStrategy' => ['id' => 'fixed', 'name' => 'Fixed Size'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['chunkingStrategy'] === 'fixed';
            }))
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsWithStringProviderPassesThrough(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'dolphin',
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === 'dolphin';
            }))
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsWithNullProviderPassesThrough(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => null,
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === null;
            }))
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsArrayProviderMissingId(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => ['name' => 'No ID'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === null;
            }))
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateFileSettingsOnly')
            ->willThrowException(new \Exception('Update failed'));

        $result = $this->controller->updateFileSettings();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Update failed', $data['error']);
    }

    // =========================================================================
    // getFileCollectionFields
    // =========================================================================

    public function testGetFileCollectionFieldsSuccess(): void
    {
        $indexService = $this->getMockBuilder(IndexService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getFileCollectionFieldStatus'])
            ->getMock();
        $indexService->method('getFileCollectionFieldStatus')
            ->willReturn(['field1' => 'exists', 'field2' => 'missing']);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($indexService);

        $result = $this->controller->getFileCollectionFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('files', $data['collection']);
    }

    public function testGetFileCollectionFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->getFileCollectionFields();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to get file collection field status', $data['message']);
    }

    // =========================================================================
    // createMissingFileFields
    // =========================================================================

    public function testCreateMissingFileFieldsNoFileCollection(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($indexService);
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['fileCollection' => '']);

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File collection not configured', $data['message']);
    }

    public function testCreateMissingFileFieldsNullFileCollection(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($indexService);
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['fileCollection' => null]);

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateMissingFileFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Reflection error'));

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to create missing file fields', $data['message']);
    }

    // =========================================================================
    // indexFile
    // =========================================================================

    public function testIndexFileSuccess(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('indexFiles')
            ->willReturn(['indexed' => 1, 'failed' => 0, 'errors' => []]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($indexService);

        $result = $this->controller->indexFile(42);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('File indexed successfully', $data['message']);
        $this->assertEquals(42, $data['file_id']);
    }

    public function testIndexFileFailure(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('indexFiles')
            ->willReturn(['indexed' => 0, 'failed' => 1, 'errors' => ['File not found']]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($indexService);

        $result = $this->controller->indexFile(42);

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File not found', $data['message']);
    }

    public function testIndexFileFailureNoErrors(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('indexFiles')
            ->willReturn(['indexed' => 0, 'failed' => 1, 'errors' => []]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($indexService);

        $result = $this->controller->indexFile(42);

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to index file', $data['message']);
    }

    public function testIndexFileException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->indexFile(42);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('SOLR down', $data['message']);
    }

    // =========================================================================
    // getFileIndexStats
    // =========================================================================

    public function testGetFileIndexStatsSuccess(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('getFileIndexStats')
            ->willReturn(['total_chunks' => 500, 'total_files' => 100]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($indexService);

        $result = $this->controller->getFileIndexStats();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(500, $result->getData()['total_chunks']);
    }

    public function testGetFileIndexStatsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Stats error'));

        $result = $this->controller->getFileIndexStats();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to get statistics', $data['message']);
    }

    // =========================================================================
    // getFileExtractionStats
    // =========================================================================

    public function testGetFileExtractionStatsException(): void
    {
        // Exception branch returns zeros
        $this->container->method('get')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getFileExtractionStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(0, $data['totalFiles']);
        $this->assertEquals(0, $data['processedFiles']);
        $this->assertEquals('0.00', $data['extractedTextStorageMB']);
        $this->assertArrayHasKey('error', $data);
    }

    // =========================================================================
    // reindexFiles
    // =========================================================================

    public function testReindexFilesNoFilesToReindex(): void
    {
        // Use addMethods since findByStatus is a magic/non-existent method
        $textExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findByStatus'])
            ->getMock();
        $textExtractSvc->method('findByStatus')->willReturn([]);

        $indexService = $this->createMock(IndexService::class);

        $this->container->method('get')->willReturnCallback(
            function (string $class) use ($textExtractSvc, $indexService) {
                if ($class === TextExtractionService::class) {
                    return $textExtractSvc;
                }
                return $indexService;
            }
        );

        $this->request->method('getParam')->willReturnMap([
            ['max_files', 1000, 1000],
            ['batch_size', 100, 100],
        ]);

        $result = $this->controller->reindexFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('No files to reindex', $data['message']);
    }

    public function testReindexFilesException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service error'));

        $this->request->method('getParam')->willReturnMap([
            ['max_files', 1000, 1000],
            ['batch_size', 100, 100],
        ]);

        $result = $this->controller->reindexFiles();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Reindex failed', $data['message']);
    }

    // =========================================================================
    // warmupFiles
    // =========================================================================

    public function testWarmupFilesNoFilesToProcess(): void
    {
        // Use addMethods since findNotIndexedInSolr is a magic/non-existent method
        $textExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr'])
            ->getMock();
        $textExtractSvc->method('findNotIndexedInSolr')->willReturn([]);

        $indexService = $this->createMock(IndexService::class);

        $this->container->method('get')->willReturnCallback(
            function (string $class) use ($textExtractSvc, $indexService) {
                if ($class === TextExtractionService::class) {
                    return $textExtractSvc;
                }
                return $indexService;
            }
        );

        $this->request->method('getParam')->willReturnMap([
            ['max_files', 100, 100],
            ['batch_size', 50, 50],
            ['skip_indexed', true, true],
            ['mode', 'parallel', 'parallel'],
        ]);

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('No files to process', $data['message']);
        $this->assertEquals(0, $data['files_processed']);
    }

    public function testWarmupFilesException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Warmup failed'));

        $this->request->method('getParam')->willReturnMap([
            ['max_files', 100, 100],
            ['batch_size', 50, 50],
            ['skip_indexed', true, true],
            ['mode', 'parallel', 'parallel'],
        ]);

        $result = $this->controller->warmupFiles();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('File warmup failed', $data['message']);
    }

    public function testWarmupFilesWithSkipIndexedFalse(): void
    {
        // Use addMethods since findByStatus is a magic/non-existent method
        $textExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findByStatus'])
            ->getMock();
        $textExtractSvc->method('findByStatus')->willReturn([]);

        $indexService = $this->createMock(IndexService::class);

        $this->container->method('get')->willReturnCallback(
            function (string $class) use ($textExtractSvc, $indexService) {
                if ($class === TextExtractionService::class) {
                    return $textExtractSvc;
                }
                return $indexService;
            }
        );

        $this->request->method('getParam')->willReturnMap([
            ['max_files', 100, 100],
            ['batch_size', 50, 50],
            ['skip_indexed', true, false],
            ['mode', 'parallel', 'parallel'],
        ]);

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('No files to process', $data['message']);
    }
}
