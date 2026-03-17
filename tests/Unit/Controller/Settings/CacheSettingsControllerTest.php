<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OC\Files\AppData\Factory;
use OCA\OpenRegister\Controller\Settings\CacheSettingsController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\Files\GenericFileException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
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

    public function testGetWarmupIntervalWithEmptyLastRun(): void
    {
        // When last_run is empty, the response last_run should be null.
        $this->appConfig->method('getValueString')
            ->willReturnMap([
                ['openregister', 'cache_warmup_interval', '3600', '3600'],
                ['openregister', 'cache_warmup_last_run', '', ''],
            ]);

        $result = $this->controller->getWarmupInterval();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertNull($data['last_run']);
        $this->assertTrue($data['enabled']);
        $this->assertEquals(3600, $data['interval']);
    }

    public function testSetWarmupIntervalDisabledSetsZeroMessage(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 0]);

        $result = $this->controller->setWarmupInterval();

        $data = $result->getData();
        $this->assertEquals('Cache warmup disabled', $data['message']);
        $this->assertFalse($data['enabled']);
        $this->assertEquals(0, $data['interval']);
    }

    public function testSetWarmupIntervalWithExactlyMinimumAllowed(): void
    {
        // 300 seconds is the minimum non-zero value.
        $this->request->method('getParams')->willReturn(['interval' => 300]);

        $result = $this->controller->setWarmupInterval();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(300, $data['interval']);
        $this->assertTrue($data['enabled']);
    }

    public function testSetWarmupIntervalException(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 600]);
        $this->appConfig->method('setValueString')
            ->willThrowException(new \Exception('Config write failed'));

        $result = $this->controller->setWarmupInterval();

        $this->assertEquals(500, $result->getStatus());
        $this->assertArrayHasKey('error', $result->getData());
    }

    public function testSetWarmupIntervalLogsMessage(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 7200]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('7200'));

        $result = $this->controller->setWarmupInterval();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testSetWarmupIntervalDefaultWhenNotProvided(): void
    {
        // When no interval is in params, default 3600 is used.
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->setWarmupInterval();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(3600, $data['interval']);
    }

    public function testClearCacheWithSpecificType(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'object']);
        $this->settingsService->expects($this->once())
            ->method('clearCache')
            ->with('object')
            ->willReturn(['cleared' => true, 'type' => 'object']);

        $result = $this->controller->clearCache();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testClearSpecificCollectionReturnsCollectionName(): void
    {
        $this->indexService->method('clearIndex')->willReturn(['success' => true]);

        $result = $this->controller->clearSpecificCollection('my-index');

        $this->assertEquals('my-index', $result->getData()['collection']);
        $this->assertEquals('Collection cleared successfully', $result->getData()['message']);
    }

    public function testClearSpecificCollectionFailureContainsMessage(): void
    {
        $this->indexService->method('clearIndex')
            ->willReturn(['success' => false, 'message' => 'Index not found']);

        $result = $this->controller->clearSpecificCollection('bad-index');

        $this->assertEquals(422, $result->getStatus());
        $this->assertEquals('Index not found', $result->getData()['message']);
        $this->assertEquals('bad-index', $result->getData()['collection']);
    }

    // ── clearAppStoreCache() ──

    private function buildFileMock(array $json): ISimpleFile&MockObject
    {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn(json_encode($json));
        return $file;
    }

    public function testClearAppStoreCacheSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'apps']);

        $file = $this->buildFileMock(['timestamp' => 9999999, 'data' => []]);
        $file->expects($this->once())->method('putContent');

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->with('apps.json')->willReturn($file);

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);

        $this->appDataFactory->method('get')->with('appstore')->willReturn($appData);

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertContains('apps.json', $data['invalidated']);
    }

    public function testClearAppStoreCacheAllType(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'all']);

        $makeFile = fn(string $name) => $this->buildFileMock(['timestamp' => 1000]);

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willReturnCallback(fn($name) => $makeFile($name));

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);

        $this->appDataFactory->method('get')->willReturn($appData);

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(3, $data['invalidated']);
    }

    public function testClearAppStoreCacheFileNotFound(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'apps']);

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willThrowException(new NotFoundException('apps.json'));

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);

        $this->appDataFactory->method('get')->willReturn($appData);

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEmpty($data['invalidated']);
        $this->assertContains('apps.json (not found)', $data['skipped']);
    }

    public function testClearAppStoreCacheInvalidJsonFormat(): void
    {
        // File exists but has no 'timestamp' key → skipped as invalid format.
        $this->request->method('getParams')->willReturn(['type' => 'categories']);

        $file = $this->buildFileMock(['no_timestamp' => 'here']);
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->with('categories.json')->willReturn($file);

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);

        $this->appDataFactory->method('get')->willReturn($appData);

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEmpty($data['invalidated']);
        $this->assertContains('categories.json (invalid format)', $data['skipped']);
    }

    public function testClearAppStoreCacheAppstoreFolderNotFound(): void
    {
        // Top-level NotFoundException — no appstore cache exists yet.
        $this->request->method('getParams')->willReturn(['type' => 'apps']);

        $this->appDataFactory->method('get')
            ->willThrowException(new NotFoundException('appstore'));

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEmpty($data['invalidated']);
    }

    public function testClearAppStoreCacheGeneralException(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'apps']);

        $this->appDataFactory->method('get')
            ->willThrowException(new \RuntimeException('Disk error'));

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to invalidate', $data['error']);
    }

    public function testClearAppStoreCacheDefaultTypeIsApps(): void
    {
        // When 'type' is not specified, default is 'apps'.
        $this->request->method('getParams')->willReturn([]);

        $file = $this->buildFileMock(['timestamp' => 1]);
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->with('apps.json')->willReturn($file);

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);

        $this->appDataFactory->method('get')->willReturn($appData);

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertContains('apps.json', $data['invalidated']);
    }

    public function testClearAppStoreCacheDiscoverType(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'discover']);

        $file = $this->buildFileMock(['timestamp' => 5000]);
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->with('discover.json')->willReturn($file);

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);

        $this->appDataFactory->method('get')->willReturn($appData);

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $this->assertContains('discover.json', $result->getData()['invalidated']);
    }

    public function testClearAppStoreCacheGenericFileException(): void
    {
        // GenericFileException during getFile should be caught per-file and added to skipped.
        $this->request->method('getParams')->willReturn(['type' => 'apps']);

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')
            ->willThrowException(new GenericFileException('Read error'));

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);

        $this->appDataFactory->method('get')->willReturn($appData);

        $result = $this->controller->clearAppStoreCache();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertContains('apps.json (not found)', $data['skipped']);
    }
}
