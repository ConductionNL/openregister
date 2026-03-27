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

/**
 * Branch coverage tests for FileSettingsController — targets uncovered branches in
 * updateFileSettings, getFileSettings, getFileExtractionStats, getFileIndexStats,
 * getFileCollectionFields, createMissingFileFields, indexFile, reindexFiles.
 */
class FileSettingsControllerBranchTest extends TestCase
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
        $this->settingsService->method('getFileSettingsOnly')
            ->willReturn(['provider' => 'dolphin', 'enabled' => true]);

        $response = $this->controller->getFileSettings();
        $data = $response->getData();

        $this->assertSame('dolphin', $data['provider']);
    }

    public function testGetFileSettingsException(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willThrowException(new \Exception('Settings error'));

        $response = $this->controller->getFileSettings();
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // updateFileSettings
    // =========================================================================

    public function testUpdateFileSettingsSuccess(): void
    {
        $this->request->method('getParams')
            ->willReturn(['enabled' => true]);

        $this->settingsService->method('updateFileSettingsOnly')
            ->willReturn(['enabled' => true]);

        $response = $this->controller->updateFileSettings();
        $data = $response->getData();

        $this->assertTrue($data['success']);
    }

    public function testUpdateFileSettingsExtractsProviderIdFromObject(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'provider' => ['id' => 'dolphin', 'name' => 'Dolphin'],
                'chunkingStrategy' => ['id' => 'fixed', 'name' => 'Fixed'],
            ]);

        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === 'dolphin' && $data['chunkingStrategy'] === 'fixed';
            }))
            ->willReturn(['provider' => 'dolphin']);

        $response = $this->controller->updateFileSettings();
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    public function testUpdateFileSettingsException(): void
    {
        $this->request->method('getParams')
            ->willReturn(['enabled' => true]);

        $this->settingsService->method('updateFileSettingsOnly')
            ->willThrowException(new \Exception('Update failed'));

        $response = $this->controller->updateFileSettings();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // testDolphinConnection
    // =========================================================================

    public function testTestDolphinConnectionEmptyInputs(): void
    {
        $response = $this->controller->testDolphinConnection('', '');
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    // =========================================================================
    // testPresidioConnection
    // =========================================================================

    public function testTestPresidioConnectionEmptyEndpoint(): void
    {
        $response = $this->controller->testPresidioConnection('');
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    // =========================================================================
    // testOpenAnonymiserConnection
    // =========================================================================

    public function testTestOpenAnonymiserConnectionEmptyEndpoint(): void
    {
        $response = $this->controller->testOpenAnonymiserConnection('');
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    // =========================================================================
    // getFileCollectionFields
    // =========================================================================

    public function testGetFileCollectionFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $response = $this->controller->getFileCollectionFields();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // createMissingFileFields
    // =========================================================================

    public function testCreateMissingFileFieldsNoCollection(): void
    {
        $indexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);
        $this->container->method('get')->willReturn($indexService);
        $this->settingsService->method('getSolrSettingsOnly')->willReturn([
            'fileCollection' => '',
        ]);

        $response = $this->controller->createMissingFileFields();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    public function testCreateMissingFileFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $response = $this->controller->createMissingFileFields();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // indexFile
    // =========================================================================

    public function testIndexFileException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Index service unavailable'));

        $response = $this->controller->indexFile(123);
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // getFileIndexStats
    // =========================================================================

    public function testGetFileIndexStatsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Stats unavailable'));

        $response = $this->controller->getFileIndexStats();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // getFileExtractionStats — catch branch (returns zeros)
    // =========================================================================

    public function testGetFileExtractionStatsReturnsZerosOnException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->getFileExtractionStats();
        $data = $response->getData();

        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['totalFiles']);
        $this->assertSame(0, $data['processedFiles']);
    }

    // =========================================================================
    // reindexFiles
    // =========================================================================

    public function testReindexFilesException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $response = $this->controller->reindexFiles();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }
}
