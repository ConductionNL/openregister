<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\SolrOperationsController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stub for IndexService methods used by SolrOperationsController.
 */
class SolrOperationsIndexServiceStub
{
    public function isAvailable(bool $forceRefresh = false): bool { return false; }
    public function testConnectivityOnly(): array { return []; }
    public function inspectIndex(string $query = '*:*', int $start = 0, int $rows = 20, string $fields = ''): array { return []; }
    public function commit(): bool { return false; }
    public function optimize(): bool { return false; }
    public function clearIndex(?string $collectionName = null): array { return []; }
    public function getMemoryPrediction(int $maxObjects = 0): array { return []; }
}

class SolrOperationsControllerTest extends TestCase
{
    private SolrOperationsController $controller;
    private IRequest&MockObject $request;
    private IDBConnection&MockObject $db;
    private ContainerInterface&MockObject $container;
    private SettingsService&MockObject $settingsService;
    private IndexService&MockObject $indexService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SolrOperationsController(
            'openregister',
            $this->request,
            $this->db,
            $this->container,
            $this->settingsService,
            $this->indexService,
            $this->logger
        );
    }

    private function mockIndexService(): MockObject
    {
        $mockService = $this->createMock(SolrOperationsIndexServiceStub::class);
        $this->container->method('get')
            ->willReturn($mockService);
        return $mockService;
    }

    public function testTestSolrConnectionSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('testConnectivityOnly')
            ->willReturn(['success' => true, 'message' => 'Connected']);

        $result = $this->controller->testSolrConnection();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testTestSolrConnectionException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Connection failed'));

        $result = $this->controller->testSolrConnection();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testInspectSolrIndexSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 0],
                ['rows', 20, 10],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [['id' => '1']],
                'total' => 1,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals(1, $result->getData()['total']);
    }

    public function testInspectSolrIndexFailure(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 0],
                ['rows', 20, 20],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn(['success' => false, 'error' => 'Bad query']);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testGetSolrMemoryPredictionSolrUnavailable(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 1000],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testGetSolrMemoryPredictionException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 1000],
            ]);

        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testManageSolrCommitSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('commit')->willReturn(true);

        $result = $this->controller->manageSolr('commit');

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('commit', $result->getData()['operation']);
    }

    public function testManageSolrCommitFailure(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('commit')->willReturn(false);

        $result = $this->controller->manageSolr('commit');

        $this->assertEquals(200, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testManageSolrOptimizeSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('optimize')->willReturn(true);

        $result = $this->controller->manageSolr('optimize');

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('optimize', $result->getData()['operation']);
    }

    public function testManageSolrClearSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn(['success' => true]);

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('clear', $result->getData()['operation']);
    }

    public function testManageSolrClearFailure(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn(['success' => false, 'error' => 'Permission denied']);

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(200, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testManageSolrUnknownOperation(): void
    {
        $this->mockIndexService();

        $result = $this->controller->manageSolr('unknown');

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testManageSolrException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->manageSolr('commit');

        $this->assertEquals(500, $result->getStatus());
    }
}
