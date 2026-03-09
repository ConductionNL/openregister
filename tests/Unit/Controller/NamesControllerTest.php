<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\NamesController;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NamesController
 *
 * @package Unit\Controller
 */
class NamesControllerTest extends TestCase
{
    private NamesController $controller;
    private IRequest&MockObject $request;
    private CacheHandler&MockObject $cacheHandler;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new NamesController(
            'openregister',
            $this->request,
            $this->cacheHandler,
            $this->logger
        );
    }

    public function testIndexReturnsAllNames(): void
    {
        $names = ['uuid-1' => 'Name 1', 'uuid-2' => 'Name 2'];

        $this->request->method('getParam')->willReturn(null);
        $this->cacheHandler->method('getAllObjectNames')->willReturn($names);
        $this->cacheHandler->method('getStats')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame($names, $data['names']);
        $this->assertSame(2, $data['total']);
        $this->assertTrue($data['cached']);
    }

    public function testIndexWithSpecificIds(): void
    {
        $names = ['uuid-1' => 'Name 1'];

        $this->request->method('getParam')
            ->willReturnMap([
                ['ids', null, 'uuid-1,uuid-2'],
            ]);
        $this->cacheHandler->method('getMultipleObjectNames')->willReturn($names);
        $this->cacheHandler->method('getStats')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('names', $data);
    }

    public function testIndexWithJsonArrayIds(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['ids', null, '["uuid-1","uuid-2"]'],
            ]);
        $this->cacheHandler->method('getMultipleObjectNames')->willReturn([]);
        $this->cacheHandler->method('getStats')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->cacheHandler->method('getAllObjectNames')
            ->willThrowException(new Exception('Cache error'));

        $result = $this->controller->index();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateWithValidIds(): void
    {
        $names = ['uuid-1' => 'Name 1'];

        $this->request->method('getParams')->willReturn([
            'ids' => ['uuid-1', 'uuid-2'],
        ]);
        $this->cacheHandler->method('getMultipleObjectNames')->willReturn($names);
        $this->cacheHandler->method('getStats')->willReturn([]);

        $result = $this->controller->create();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame($names, $data['names']);
        $this->assertSame(2, $data['requested']);
    }

    public function testCreateReturnsBadRequestWhenIdsNotArray(): void
    {
        $this->request->method('getParams')->willReturn([
            'ids' => 'not-an-array',
        ]);

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturnsBadRequestWhenIdsMissing(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturnsBadRequestWhenIdsEmpty(): void
    {
        $this->request->method('getParams')->willReturn([
            'ids' => ['', ' '],
        ]);

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([
            'ids' => ['uuid-1'],
        ]);
        $this->cacheHandler->method('getMultipleObjectNames')
            ->willThrowException(new Exception('Failed'));

        $result = $this->controller->create();

        $this->assertSame(500, $result->getStatus());
    }

    public function testShowReturnsNameForExistingId(): void
    {
        $this->cacheHandler->method('getSingleObjectName')->willReturn('Test Name');

        $result = $this->controller->show('uuid-123');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('uuid-123', $data['id']);
        $this->assertSame('Test Name', $data['name']);
        $this->assertTrue($data['found']);
    }

    public function testShowReturns404WhenNameNotFound(): void
    {
        $this->cacheHandler->method('getSingleObjectName')->willReturn(null);

        $result = $this->controller->show('nonexistent');

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['found']);
    }

    public function testShowReturns500OnException(): void
    {
        $this->cacheHandler->method('getSingleObjectName')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->show('uuid-123');

        $this->assertSame(500, $result->getStatus());
    }

    public function testStatsReturnsStatistics(): void
    {
        $stats = ['hits' => 100, 'misses' => 5];
        $this->cacheHandler->method('getStats')->willReturn($stats);

        $result = $this->controller->stats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame($stats, $data['cache_statistics']);
        $this->assertArrayHasKey('performance_metrics', $data);
    }

    public function testStatsReturns500OnException(): void
    {
        $this->cacheHandler->method('getStats')
            ->willThrowException(new Exception('Stats error'));

        $result = $this->controller->stats();

        $this->assertSame(500, $result->getStatus());
    }

    public function testWarmupReturnsSuccess(): void
    {
        $this->cacheHandler->method('getStats')->willReturn(['name_cache_size' => 50]);
        $this->cacheHandler->method('warmupNameCache')->willReturn(100);

        $result = $this->controller->warmup();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(100, $data['loaded_names']);
    }

    public function testWarmupReturns500OnException(): void
    {
        $this->cacheHandler->method('getStats')
            ->willThrowException(new Exception('Warmup failed'));

        $result = $this->controller->warmup();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }
}
