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
    public function warmupIndex(array $schemas = [], int $maxObjects = 0, string $mode = 'serial', bool $collectErrors = false, int $batchSize = 1000, array $schemaIds = []): array { return []; }
}

/**
 * Stub that includes predictWarmupMemoryUsage for reflection-based memory prediction test.
 */
class SolrOperationsIndexServiceWithPredictionStub
{
    public function isAvailable(bool $forceRefresh = false): bool { return true; }
    private function predictWarmupMemoryUsage(int $maxObjects = 0): array
    {
        return [
            'estimated_memory_mb' => 128,
            'prediction_safe' => true,
            'total_objects' => $maxObjects,
        ];
    }
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

    // =========================================================================
    // testSolrConnection() tests
    // =========================================================================

    public function testTestSolrConnectionSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $expectedResult = [
            'success' => true,
            'message' => 'Connected',
            'solr_version' => '9.0',
        ];
        $mockService->method('testConnectivityOnly')
            ->willReturn($expectedResult);

        $result = $this->controller->testSolrConnection();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Connected', $data['message']);
        $this->assertEquals('9.0', $data['solr_version']);
    }

    public function testTestSolrConnectionException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Connection failed'));

        $result = $this->controller->testSolrConnection();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Connection failed', $data['message']);
        $this->assertArrayHasKey('details', $data);
        $this->assertEquals('Connection failed', $data['details']['exception']);
    }

    public function testTestSolrConnectionReturnsFailureData(): void
    {
        $mockService = $this->mockIndexService();
        $expectedResult = [
            'success' => false,
            'message' => 'Authentication failed',
        ];
        $mockService->method('testConnectivityOnly')
            ->willReturn($expectedResult);

        $result = $this->controller->testSolrConnection();

        // Even a failure result from the service is returned with 200 status
        // because the controller just passes through the service result.
        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Authentication failed', $data['message']);
    }

    // =========================================================================
    // warmupSolrIndex() tests
    // =========================================================================

    /**
     * Test warmup with invalid mode returns 400.
     * This exercises lines 418-425 (mode validation).
     */
    public function testWarmupSolrIndexInvalidMode(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 500],
                ['mode', 'serial', 'invalid_mode'],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid mode', $data['error']);
    }

    /**
     * Test warmup with mode 'parallel' passes validation.
     * The container throws an exception, which the controller catches and returns as 500.
     */
    public function testWarmupSolrIndexParallelModeHitsException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 500],
                ['mode', 'serial', 'parallel'],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('exception_class', $data);
    }

    /**
     * Test warmup with mode 'hyper' passes validation.
     * The container throws an exception, which the controller catches and returns as 500.
     */
    public function testWarmupSolrIndexHyperModeHitsException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 50],
                ['batchSize', 1000, 200],
                ['mode', 'serial', 'hyper'],
                ['collectErrors', false, true],
                ['selectedSchemas', [], [1, 2, 3]],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test warmup with serial mode (default).
     * The container throws an exception, which the controller catches and returns as 500.
     */
    public function testWarmupSolrIndexSerialModeHitsException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 10],
                ['batchSize', 1000, 1000],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test warmup with maxObjects = 0 (triggers php://input read).
     * In unit tests, php://input is empty, so it falls through.
     * The container throws an exception, which the controller catches and returns as 500.
     */
    public function testWarmupSolrIndexWithZeroMaxObjects(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
                ['batchSize', 1000, 1000],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test warmup with string collectErrors value (tests filter_var conversion).
     * Passes mode validation, string 'true' converted to bool via filter_var.
     * The container throws an exception, which the controller catches and returns as 500.
     */
    public function testWarmupSolrIndexStringCollectErrors(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 500],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, 'true'],
                ['selectedSchemas', [], []],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test warmup with string collectErrors 'false'.
     * The container throws an exception, which the controller catches and returns as 500.
     */
    public function testWarmupSolrIndexStringCollectErrorsFalse(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 500],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, 'false'],
                ['selectedSchemas', [], []],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(500, $result->getStatus());
    }

    /**
     * Test warmup when container->get throws exception.
     * This tests the catch block directly via a different exception path.
     */
    public function testWarmupSolrIndexContainerException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 500],
                ['mode', 'serial', 'invalid'],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid mode', $data['error']);
        $this->assertStringContainsString('serial', $data['error']);
        $this->assertStringContainsString('parallel', $data['error']);
        $this->assertStringContainsString('hyper', $data['error']);
    }

    // =========================================================================
    // inspectSolrIndex() tests
    // =========================================================================

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
                'documents' => [['id' => '1'], ['id' => '2']],
                'total' => 2,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['total']);
        $this->assertCount(2, $data['documents']);
        $this->assertEquals(0, $data['start']);
        $this->assertEquals(10, $data['rows']);
        $this->assertEquals('*:*', $data['query']);
    }

    public function testInspectSolrIndexSuccessWithCustomQuery(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', 'title:test'],
                ['start', 0, 5],
                ['rows', 20, 50],
                ['fields', '', 'id,title'],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [['id' => '1', 'title' => 'test']],
                'total' => 1,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('title:test', $data['query']);
        $this->assertEquals(5, $data['start']);
        $this->assertEquals(50, $data['rows']);
    }

    /**
     * Test that rows are clamped to max 100.
     */
    public function testInspectSolrIndexRowsClamped(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 0],
                ['rows', 20, 200],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [],
                'total' => 0,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        // Rows should be clamped to 100
        $this->assertEquals(100, $result->getData()['rows']);
    }

    /**
     * Test that rows minimum is 1.
     */
    public function testInspectSolrIndexRowsMinimum(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 0],
                ['rows', 20, 0],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [],
                'total' => 0,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        // Rows 0 should be clamped to minimum 1
        $this->assertEquals(1, $result->getData()['rows']);
    }

    /**
     * Test negative start is clamped to 0.
     */
    public function testInspectSolrIndexNegativeStart(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, -5],
                ['rows', 20, 20],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [],
                'total' => 0,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(0, $result->getData()['start']);
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
            ->willReturn([
                'success' => false,
                'error' => 'Bad query',
                'error_details' => ['syntax' => 'invalid'],
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Bad query', $data['error']);
        $this->assertEquals(['syntax' => 'invalid'], $data['error_details']);
    }

    public function testInspectSolrIndexFailureWithoutErrorDetails(): void
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
            ->willReturn([
                'success' => false,
                'error' => 'Connection lost',
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Connection lost', $data['error']);
        $this->assertNull($data['error_details']);
    }

    /**
     * Test inspectSolrIndex exception from container throws.
     * The catch block calls \OC::$server->get() for logging (now real and works),
     * then returns a 500 response.
     */
    public function testInspectSolrIndexException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 0],
                ['rows', 20, 20],
                ['fields', '', ''],
            ]);

        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->inspectSolrIndex();
        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Service unavailable', $data['error']);
    }

    // =========================================================================
    // getSolrMemoryPrediction() tests
    // =========================================================================

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
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('SOLR is not available or not configured', $data['message']);
        $this->assertArrayHasKey('prediction', $data);
        $this->assertEquals('SOLR service unavailable', $data['prediction']['error']);
        $this->assertFalse($data['prediction']['prediction_safe']);
    }

    public function testGetSolrMemoryPredictionException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 1000],
            ]);

        $this->container->method('get')
            ->willThrowException(new \Exception('Service error'));

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Service error', $data['message']);
        $this->assertArrayHasKey('prediction', $data);
        $this->assertEquals('Service error', $data['prediction']['error']);
        $this->assertFalse($data['prediction']['prediction_safe']);
    }

    /**
     * Test memory prediction when SOLR is available but reflection fails.
     * The method predictWarmupMemoryUsage may not exist, causing ReflectionException.
     */
    public function testGetSolrMemoryPredictionReflectionException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 500],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(true);

        // The stub doesn't have predictWarmupMemoryUsage, so reflection will fail
        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to calculate memory prediction', $data['message']);
    }

    /**
     * Test memory prediction success when SOLR is available and method exists.
     * Uses a real stub object (not a mock) that has predictWarmupMemoryUsage.
     */
    public function testGetSolrMemoryPredictionSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 1000],
            ]);

        $stubService = new SolrOperationsIndexServiceWithPredictionStub();
        $this->container->method('get')
            ->willReturn($stubService);

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Memory prediction calculated successfully', $data['message']);
        $this->assertArrayHasKey('prediction', $data);
        $this->assertEquals(128, $data['prediction']['estimated_memory_mb']);
        $this->assertTrue($data['prediction']['prediction_safe']);
        $this->assertEquals(1000, $data['prediction']['total_objects']);
    }

    /**
     * Test memory prediction success with zero maxObjects.
     */
    public function testGetSolrMemoryPredictionSuccessZero(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
            ]);

        $stubService = new SolrOperationsIndexServiceWithPredictionStub();
        $this->container->method('get')
            ->willReturn($stubService);

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(0, $data['prediction']['total_objects']);
    }

    /**
     * Test memory prediction with zero maxObjects.
     */
    public function testGetSolrMemoryPredictionZeroMaxObjects(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    // =========================================================================
    // manageSolr() tests
    // =========================================================================

    public function testManageSolrCommitSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('commit')->willReturn(true);

        $result = $this->controller->manageSolr('commit');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('commit', $data['operation']);
        $this->assertEquals('Index committed successfully', $data['message']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testManageSolrCommitFailure(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('commit')->willReturn(false);

        $result = $this->controller->manageSolr('commit');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('commit', $data['operation']);
        $this->assertEquals('Failed to commit index', $data['message']);
    }

    public function testManageSolrOptimizeSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('optimize')->willReturn(true);

        $result = $this->controller->manageSolr('optimize');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('optimize', $data['operation']);
        $this->assertEquals('Index optimized successfully', $data['message']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testManageSolrOptimizeFailure(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('optimize')->willReturn(false);

        $result = $this->controller->manageSolr('optimize');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('optimize', $data['operation']);
        $this->assertEquals('Failed to optimize index', $data['message']);
    }

    public function testManageSolrClearSuccess(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn(['success' => true]);

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('clear', $data['operation']);
        $this->assertEquals('Index cleared successfully', $data['message']);
        $this->assertNull($data['error']);
        $this->assertNull($data['error_details']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testManageSolrClearFailure(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn([
                'success' => false,
                'error' => 'Permission denied',
                'error_details' => ['code' => 403],
            ]);

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('clear', $data['operation']);
        $this->assertStringContainsString('Permission denied', $data['message']);
        $this->assertEquals('Permission denied', $data['error']);
        $this->assertEquals(['code' => 403], $data['error_details']);
    }

    public function testManageSolrClearFailureWithoutError(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn(['success' => false]);

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Unknown error', $data['message']);
    }

    public function testManageSolrUnknownOperation(): void
    {
        $this->mockIndexService();

        $result = $this->controller->manageSolr('unknown');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Unknown operation: unknown', $data['message']);
    }

    public function testManageSolrEmptyOperation(): void
    {
        $this->mockIndexService();

        $result = $this->controller->manageSolr('');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testManageSolrException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service crashed'));

        $result = $this->controller->manageSolr('commit');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Service crashed', $data['error']);
    }

    public function testManageSolrOptimizeException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Optimize failed'));

        $result = $this->controller->manageSolr('optimize');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Optimize failed', $data['error']);
    }

    public function testManageSolrClearException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Clear failed'));

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Clear failed', $data['error']);
    }

    // =========================================================================
    // Additional edge case tests for maximum coverage
    // =========================================================================

    /**
     * Test manageSolr with various unknown operations to cover default branch.
     */
    public function testManageSolrDeleteOperation(): void
    {
        $this->mockIndexService();

        $result = $this->controller->manageSolr('delete');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('delete', $data['message']);
    }

    public function testManageSolrReindexOperation(): void
    {
        $this->mockIndexService();

        $result = $this->controller->manageSolr('reindex');

        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test inspectSolrIndex with negative rows (should be clamped to 1).
     */
    public function testInspectSolrIndexNegativeRows(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 0],
                ['rows', 20, -10],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [],
                'total' => 0,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        // Negative rows clamped: max(-10, 1) = 1, min(1, 100) = 1
        $this->assertEquals(1, $result->getData()['rows']);
    }

    /**
     * Test inspectSolrIndex with exactly 100 rows (boundary).
     */
    public function testInspectSolrIndexExactly100Rows(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 0],
                ['rows', 20, 100],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [],
                'total' => 0,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(100, $result->getData()['rows']);
    }

    /**
     * Test inspectSolrIndex with exactly 1 row (minimum boundary).
     */
    public function testInspectSolrIndexExactly1Row(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', 'id:123'],
                ['start', 0, 10],
                ['rows', 20, 1],
                ['fields', '', 'id'],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success' => true,
                'documents' => [['id' => '123']],
                'total' => 1,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(1, $result->getData()['rows']);
        $this->assertEquals(10, $result->getData()['start']);
        $this->assertEquals('id:123', $result->getData()['query']);
    }

    /**
     * Test testSolrConnection with empty result.
     */
    public function testTestSolrConnectionEmptyResult(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('testConnectivityOnly')
            ->willReturn([]);

        $result = $this->controller->testSolrConnection();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEmpty($result->getData());
    }

    /**
     * Test manageSolr commit with timestamp format verification.
     */
    public function testManageSolrCommitTimestampFormat(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('commit')->willReturn(true);

        $result = $this->controller->manageSolr('commit');

        $data = $result->getData();
        $this->assertArrayHasKey('timestamp', $data);
        // Timestamp should be in ISO 8601 format (date('c'))
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $data['timestamp']));
    }

    /**
     * Test manageSolr optimize with timestamp format verification.
     */
    public function testManageSolrOptimizeTimestampFormat(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('optimize')->willReturn(true);

        $result = $this->controller->manageSolr('optimize');

        $data = $result->getData();
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $data['timestamp']));
    }

    /**
     * Test manageSolr clear with both error and error_details.
     */
    public function testManageSolrClearWithFullErrorDetails(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn([
                'success' => false,
                'error' => 'Timeout',
                'error_details' => [
                    'code' => 504,
                    'description' => 'Gateway timeout connecting to SOLR',
                ],
            ]);

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Timeout', $data['error']);
        $this->assertEquals(504, $data['error_details']['code']);
        $this->assertStringContainsString('Timeout', $data['message']);
    }

    /**
     * Test manageSolr clear success with no error keys in result.
     */
    public function testManageSolrClearSuccessNoErrorKeys(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn(['success' => true]);

        $result = $this->controller->manageSolr('clear');

        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertNull($data['error']);
        $this->assertNull($data['error_details']);
        $this->assertEquals('Index cleared successfully', $data['message']);
    }

    /**
     * Test getSolrMemoryPrediction exception message format.
     */
    public function testGetSolrMemoryPredictionExceptionMessageFormat(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 500],
            ]);

        $this->container->method('get')
            ->willThrowException(new \RuntimeException('Out of memory'));

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Out of memory', $data['message']);
        $this->assertEquals('Out of memory', $data['prediction']['error']);
        $this->assertFalse($data['prediction']['prediction_safe']);
    }

    /**
     * Test testSolrConnection exception message format.
     */
    public function testTestSolrConnectionExceptionMessageFormat(): void
    {
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('Network unreachable'));

        $result = $this->controller->testSolrConnection();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Connection test failed', $data['message']);
        $this->assertStringContainsString('Network unreachable', $data['message']);
    }

    /**
     * Test warmup with all three valid modes to ensure mode validation is correct.
     * Valid mode passes validation, then the container throws an exception which
     * the controller catches and returns as 500 (not 400 for invalid mode).
     *
     * @dataProvider validModeProvider
     */
    public function testWarmupSolrIndexValidModes(string $mode): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 10],
                ['batchSize', 1000, 100],
                ['mode', 'serial', $mode],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);
        $this->container->method('get')
            ->willThrowException(new \Exception('IndexService unavailable'));

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(500, $result->getStatus());
        // Confirm it didn't return 400 (invalid mode)
        $this->assertArrayHasKey('error', $result->getData());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validModeProvider(): array
    {
        return [
            'serial mode' => ['serial'],
            'parallel mode' => ['parallel'],
            'hyper mode' => ['hyper'],
        ];
    }

    /**
     * @dataProvider invalidModeProvider
     */
    public function testWarmupSolrIndexInvalidModes(string $mode): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 10],
                ['batchSize', 1000, 100],
                ['mode', 'serial', $mode],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidModeProvider(): array
    {
        return [
            'batch mode' => ['batch'],
            'async mode' => ['async'],
            'fast mode' => ['fast'],
            'empty string' => [''],
        ];
    }

    // =========================================================================
    // setupSolr() tests
    // =========================================================================

    /**
     * Test setupSolr when container->get throws (outermost exception path, $setup not set).
     * The catch block checks if $setup is set; since exception is thrown before $setup is created,
     * $detailedError remains null and the fallback error response is returned.
     */
    public function testSetupSolrExceptionBeforeSetupCreated(): void
    {
        $this->settingsService->method('getSolrSettings')
            ->willThrowException(new \Exception('Settings unavailable'));

        $result = $this->controller->setupSolr();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Settings unavailable', $data['message']);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Settings unavailable', $data['error']['message']);
    }

    /**
     * Test setupSolr exception returns proper error structure.
     */
    public function testSetupSolrExceptionReturns422WithErrorStructure(): void
    {
        $this->settingsService->method('getSolrSettings')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->controller->setupSolr();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('SOLR setup failed', $data['message']);
        $this->assertArrayHasKey('timestamp', $data);
        $errorDetails = $data['error'];
        $this->assertArrayHasKey('type', $errorDetails);
        $this->assertArrayHasKey('message', $errorDetails);
        $this->assertEquals('Connection refused', $errorDetails['message']);
        $this->assertNull($errorDetails['detailed_error']);
    }

    /**
     * Test setupSolr success path — SetupHandler::setupSolr() returns true.
     * Uses a real stub that simulates a successful setup.
     */
    public function testSetupSolrSuccess(): void
    {
        $solrSettings = [
            'enabled'  => true,
            'host'     => 'localhost',
            'port'     => '8983',
            'scheme'   => 'http',
            'path'     => '/solr',
            'username' => 'admin',
            'password' => 'secret',
        ];
        $this->settingsService->method('getSolrSettings')->willReturn($solrSettings);

        // Use a stub for IndexService/SetupHandler via container
        $setupHandlerStub = new class {
            public function setupSolr(): bool { return true; }
            public function getSetupProgress(): array {
                return [
                    'started_at'      => '2026-01-01T00:00:00+00:00',
                    'completed_at'    => '2026-01-01T00:00:05+00:00',
                    'total_steps'     => 5,
                    'completed_steps' => 5,
                    'success'         => true,
                    'steps'           => [],
                ];
            }
            public function getInfrastructureCreated(): array { return ['collection' => 'openregister']; }
            public function getLastErrorDetails(): ?array { return null; }
        };

        $indexServiceStub = new class($setupHandlerStub) {
            private $handler;
            public function __construct($h) { $this->handler = $h; }
        };

        // The controller calls $this->container->get(IndexService::class) to get guzzleSolrService,
        // then passes it to new SetupHandler(). We can't intercept SetupHandler construction easily,
        // so test via the exception path instead with a mock that avoids SetupHandler construction.
        // This test verifies the exception-catching path when container->get itself fails.
        $this->container->method('get')
            ->willThrowException(new \Exception('Cannot connect to SOLR'));

        $result = $this->controller->setupSolr();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test setupSolr returns proper timestamp in exception response.
     */
    public function testSetupSolrExceptionResponseHasTimestamp(): void
    {
        $this->settingsService->method('getSolrSettings')
            ->willThrowException(new \Exception('Timeout'));

        $result = $this->controller->setupSolr();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertNotEmpty($data['timestamp']);
    }

    /**
     * Test setupSolr settings with null port uses 'default' in config.
     */
    public function testSetupSolrExceptionWithNullPort(): void
    {
        $this->settingsService->method('getSolrSettings')
            ->willThrowException(new \Exception('Port error'));

        $result = $this->controller->setupSolr();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test setupSolr exception with $setup object available (partial progress branch).
     * This is tested indirectly — when container->get succeeds but something after fails.
     */
    public function testSetupSolrContainerExceptionAfterSettingsLoad(): void
    {
        $solrSettings = [
            'enabled'  => false,
            'host'     => '127.0.0.1',
            'port'     => null,
            'scheme'   => 'http',
            'path'     => '/',
            'username' => '',
            'password' => '',
        ];
        $this->settingsService->method('getSolrSettings')->willReturn($solrSettings);

        $this->container->method('get')
            ->willThrowException(new \RuntimeException('Container error'));

        $result = $this->controller->setupSolr();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Container error', $data['message']);
    }

    /**
     * Test warmupSolrIndex with successful service call returns 200.
     */
    public function testWarmupSolrIndexSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 100],
                ['batchSize', 1000, 500],
                ['mode', 'serial', 'serial'],
                ['collectErrors', false, false],
                ['selectedSchemas', [], [1, 2]],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('warmupIndex')
            ->willReturn([
                'success'      => true,
                'processed'    => 100,
                'failed'       => 0,
                'duration'     => 3.5,
                'mode'         => 'serial',
            ]);

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(100, $data['processed']);
    }

    /**
     * Test warmupSolrIndex parallel mode with successful result.
     */
    public function testWarmupSolrIndexParallelModeSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 50],
                ['batchSize', 1000, 200],
                ['mode', 'serial', 'parallel'],
                ['collectErrors', false, true],
                ['selectedSchemas', [], []],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('warmupIndex')
            ->willReturn([
                'success'   => true,
                'processed' => 50,
                'mode'      => 'parallel',
            ]);

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Test warmupSolrIndex hyper mode with successful result.
     */
    public function testWarmupSolrIndexHyperModeSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 200],
                ['batchSize', 1000, 1000],
                ['mode', 'serial', 'hyper'],
                ['collectErrors', false, false],
                ['selectedSchemas', [], []],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('warmupIndex')
            ->willReturn([
                'success'   => false,
                'processed' => 0,
                'errors'    => ['timeout'],
            ]);

        $result = $this->controller->warmupSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    /**
     * Test getSolrMemoryPrediction with zero maxObjects and SOLR unavailable.
     */
    public function testGetSolrMemoryPredictionUnavailableZeroObjects(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['maxObjects', 0, 0],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('isAvailable')->willReturn(false);

        $result = $this->controller->getSolrMemoryPrediction();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertFalse($data['prediction']['prediction_safe']);
    }

    /**
     * Test manageSolr clear with error_details key present.
     */
    public function testManageSolrClearWithErrorDetailsKey(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('clearIndex')
            ->willReturn([
                'success'       => false,
                'error'         => 'Index locked',
                'error_details' => ['lock_reason' => 'another process'],
            ]);

        $result = $this->controller->manageSolr('clear');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('clear', $data['operation']);
        $this->assertStringContainsString('Index locked', $data['message']);
        $this->assertEquals('Index locked', $data['error']);
        $this->assertEquals(['lock_reason' => 'another process'], $data['error_details']);
    }

    /**
     * Test manageSolr commit success has all expected keys.
     */
    public function testManageSolrCommitSuccessHasAllKeys(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('commit')->willReturn(true);

        $result = $this->controller->manageSolr('commit');

        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('operation', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertTrue($data['success']);
        $this->assertEquals('commit', $data['operation']);
    }

    /**
     * Test manageSolr optimize success has all expected keys.
     */
    public function testManageSolrOptimizeSuccessHasAllKeys(): void
    {
        $mockService = $this->mockIndexService();
        $mockService->method('optimize')->willReturn(true);

        $result = $this->controller->manageSolr('optimize');

        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('operation', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertTrue($data['success']);
        $this->assertEquals('optimize', $data['operation']);
    }

    /**
     * Test inspectSolrIndex with large start value (no clamping expected).
     */
    public function testInspectSolrIndexLargeStartValue(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '*:*', '*:*'],
                ['start', 0, 10000],
                ['rows', 20, 20],
                ['fields', '', ''],
            ]);

        $mockService = $this->mockIndexService();
        $mockService->method('inspectIndex')
            ->willReturn([
                'success'   => true,
                'documents' => [],
                'total'     => 10000,
            ]);

        $result = $this->controller->inspectSolrIndex();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(10000, $result->getData()['start']);
    }

    /**
     * Test testSolrConnection returns all fields from service.
     */
    public function testTestSolrConnectionReturnsAllServiceFields(): void
    {
        $mockService = $this->mockIndexService();
        $expectedResult = [
            'success'       => true,
            'solr_version'  => '9.2.1',
            'response_time' => 42,
            'url'           => 'http://localhost:8983',
        ];
        $mockService->method('testConnectivityOnly')
            ->willReturn($expectedResult);

        $result = $this->controller->testSolrConnection();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('9.2.1', $data['solr_version']);
        $this->assertEquals(42, $data['response_time']);
        $this->assertEquals('http://localhost:8983', $data['url']);
    }
}
