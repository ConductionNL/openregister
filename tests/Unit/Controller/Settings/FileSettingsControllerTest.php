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
}
