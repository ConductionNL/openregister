<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OC\Files\AppData\Factory;
use OCA\OpenRegister\Controller\Settings\CacheSettingsController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CacheSettingsControllerTest extends TestCase
{
    private CacheSettingsController $controller;
    private IRequest&MockObject $request;
    private SettingsService&MockObject $settingsService;
    private IndexService&MockObject $indexService;
    private LoggerInterface&MockObject $logger;
    private Factory&MockObject $appDataFactory;
    private IAppConfig&MockObject $appConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appDataFactory = $this->createMock(Factory::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        $this->controller = new CacheSettingsController(
            'openregister',
            $this->request,
            $this->settingsService,
            $this->indexService,
            $this->logger,
            $this->appDataFactory,
            $this->appConfig
        );
    }

    public function testGetCacheStatsSuccess(): void
    {
        $stats = ['hits' => 100, 'misses' => 10];
        $this->settingsService->method('getCacheStats')->willReturn($stats);

        $result = $this->controller->getCacheStats();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($stats, $result->getData());
    }

    public function testGetCacheStatsException(): void
    {
        $this->settingsService->method('getCacheStats')
            ->willThrowException(new \Exception('Cache error'));

        $result = $this->controller->getCacheStats();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testClearCacheSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'all']);
        $this->settingsService->method('clearCache')->willReturn(['cleared' => true]);

        $result = $this->controller->clearCache();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testClearCacheDefaultType(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->expects($this->once())
            ->method('clearCache')
            ->with('all')
            ->willReturn(['cleared' => true]);

        $result = $this->controller->clearCache();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testClearCacheException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('clearCache')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->clearCache();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testWarmupNamesCacheSuccess(): void
    {
        $this->settingsService->method('warmupNamesCache')
            ->willReturn(['warmed' => 50]);

        $result = $this->controller->warmupNamesCache();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testWarmupNamesCacheException(): void
    {
        $this->settingsService->method('warmupNamesCache')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->warmupNamesCache();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testGetWarmupIntervalSuccess(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnMap([
                ['openregister', 'cache_warmup_interval', '3600', '1800'],
                ['openregister', 'cache_warmup_last_run', '', '2024-01-01T00:00:00'],
            ]);

        $result = $this->controller->getWarmupInterval();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(1800, $data['interval']);
        $this->assertTrue($data['enabled']);
    }

    public function testGetWarmupIntervalException(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('Config error'));

        $result = $this->controller->getWarmupInterval();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testSetWarmupIntervalSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 600]);

        $result = $this->controller->setWarmupInterval();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(600, $data['interval']);
        $this->assertTrue($data['enabled']);
    }

    public function testSetWarmupIntervalDisabled(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 0]);

        $result = $this->controller->setWarmupInterval();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['enabled']);
    }

    public function testSetWarmupIntervalTooLow(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 100]);

        $result = $this->controller->setWarmupInterval();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testClearSpecificCollectionSuccess(): void
    {
        $this->indexService->method('clearIndex')
            ->willReturn(['success' => true]);

        $result = $this->controller->clearSpecificCollection('test_collection');

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('test_collection', $result->getData()['collection']);
    }

    public function testClearSpecificCollectionFailure(): void
    {
        $this->indexService->method('clearIndex')
            ->willReturn(['success' => false, 'message' => 'Not found']);

        $result = $this->controller->clearSpecificCollection('test_collection');

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testClearSpecificCollectionException(): void
    {
        $this->indexService->method('clearIndex')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->clearSpecificCollection('test_collection');

        $this->assertEquals(422, $result->getStatus());
    }
}
