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

        $this->request        = $this->createMock(IRequest::class);
        $this->container      = $this->createMock(ContainerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

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
}
