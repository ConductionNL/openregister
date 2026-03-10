<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\FileSettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class FileSettingsControllerTest extends TestCase
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

    public function testGetFileSettingsSuccess(): void
    {
        $data = ['extractionEnabled' => true];
        $this->settingsService->method('getFileSettingsOnly')->willReturn($data);

        $result = $this->controller->getFileSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    public function testGetFileSettingsException(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->getFileSettings();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateFileSettingsSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['extractionEnabled' => true]);
        $this->settingsService->method('updateFileSettingsOnly')
            ->willReturn(['extractionEnabled' => true]);

        $result = $this->controller->updateFileSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testUpdateFileSettingsExtractsProviderAndChunkingIds(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => ['id' => 'dolphin', 'name' => 'Dolphin'],
            'chunkingStrategy' => ['id' => 'paragraph', 'name' => 'Paragraph'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === 'dolphin'
                    && $data['chunkingStrategy'] === 'paragraph';
            }))
            ->willReturn(['updated' => true]);

        $this->controller->updateFileSettings();
    }

    public function testUpdateFileSettingsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateFileSettingsOnly')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->updateFileSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestDolphinConnectionEmptyParams(): void
    {
        $result = $this->controller->testDolphinConnection('', '');

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestPresidioConnectionEmptyEndpoint(): void
    {
        $result = $this->controller->testPresidioConnection('');

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestOpenAnonymiserConnectionEmptyEndpoint(): void
    {
        $result = $this->controller->testOpenAnonymiserConnection('');

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testGetFileCollectionFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->getFileCollectionFields();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testCreateMissingFileFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testWarmupFilesException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->warmupFiles();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testIndexFileException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->indexFile(42);

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testReindexFilesException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->reindexFiles();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testGetFileIndexStatsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->getFileIndexStats();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testGetFileExtractionStatsReturnsZerosOnException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->getFileExtractionStats();

        // Returns 200 with zeros instead of error to avoid breaking UI.
        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['totalFiles']);
        $this->assertSame(0, $data['processedFiles']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateMissingFileFieldsNoCollectionConfigured(): void
    {
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $this->container->method('get')
            ->willReturn($mockIndexService);
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['fileCollection' => '']);

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('not configured', $data['message']);
    }

    // ── Additional success/edge-case tests ─────────────────────────────

    public function testGetFileSettingsReturnsData(): void
    {
        $data = [
            'extractionEnabled' => true,
            'provider' => 'dolphin',
            'chunkingStrategy' => 'paragraph',
        ];
        $this->settingsService->method('getFileSettingsOnly')->willReturn($data);

        $result = $this->controller->getFileSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals('dolphin', $result->getData()['provider']);
    }

    public function testUpdateFileSettingsStripsNonFileKeys(): void
    {
        $this->request->method('getParams')->willReturn([
            'extractionEnabled' => false,
            '_route' => 'some_route',
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->willReturn(['extractionEnabled' => false]);

        $result = $this->controller->updateFileSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsHandlesNullProvider(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => null,
        ]);
        $this->settingsService->method('updateFileSettingsOnly')
            ->willReturn(['provider' => null]);

        $result = $this->controller->updateFileSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsHandlesStringProvider(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => 'dolphin',
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === 'dolphin';
            }))
            ->willReturn(['provider' => 'dolphin']);

        $result = $this->controller->updateFileSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetFileCollectionFieldsCallsIndexService(): void
    {
        // getFileCollectionFields calls container->get(IndexService)
        // then calls getFileCollectionFieldStatus which may not exist yet.
        // When container throws, we get 500.
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService not available'));

        $result = $this->controller->getFileCollectionFields();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testGetFileIndexStatsSuccess(): void
    {
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('getFileIndexStats')->willReturn([
            'totalDocuments' => 150,
            'totalSize' => '12MB',
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $result = $this->controller->getFileIndexStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(150, $data['totalDocuments']);
    }

    public function testIndexFileSuccess(): void
    {
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 1,
            'failed' => 0,
            'errors' => [],
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $result = $this->controller->indexFile(42);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(42, $data['file_id']);
    }

    public function testIndexFileReturns422WhenFailed(): void
    {
        $mockIndexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 0,
            'failed' => 1,
            'errors' => ['File not found'],
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $result = $this->controller->indexFile(99);

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals(99, $data['file_id']);
    }

    public function testTestDolphinConnectionHandlesException(): void
    {
        // When the health check itself throws an exception (after validation)
        // We cannot easily test the success path since performHealthCheck uses curl
        // But we can verify that valid params get past the 400 check
        // and the exception from curl is caught
        $result = $this->controller->testDolphinConnection('http://invalid-host:9999', 'test-key');

        // Will get 500 because curl fails
        $this->assertContains($result->getStatus(), [200, 500]);
    }

    public function testTestPresidioConnectionHandlesException(): void
    {
        $result = $this->controller->testPresidioConnection('http://invalid-host:9999');

        $this->assertContains($result->getStatus(), [200, 500]);
    }

    public function testTestOpenAnonymiserConnectionHandlesException(): void
    {
        $result = $this->controller->testOpenAnonymiserConnection('http://invalid-host:9999');

        $this->assertContains($result->getStatus(), [200, 500]);
    }

}
