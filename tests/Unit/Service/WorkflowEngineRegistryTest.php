<?php

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\WorkflowEngine;
use OCA\OpenRegister\Db\WorkflowEngineMapper;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCA\OpenRegister\WorkflowEngine\N8nAdapter;
use OCA\OpenRegister\WorkflowEngine\WindmillAdapter;
use OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface;
use OCP\App\IAppManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class WorkflowEngineRegistryTest extends TestCase
{
    private WorkflowEngineRegistry $registry;
    private WorkflowEngineMapper&MockObject $mapper;
    private N8nAdapter&MockObject $n8nAdapter;
    private WindmillAdapter&MockObject $windmillAdapter;
    private ICrypto&MockObject $crypto;
    private IAppManager&MockObject $appManager;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(WorkflowEngineMapper::class);
        $this->n8nAdapter = $this->createMock(N8nAdapter::class);
        $this->windmillAdapter = $this->createMock(WindmillAdapter::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->registry = new WorkflowEngineRegistry(
            $this->mapper,
            $this->n8nAdapter,
            $this->windmillAdapter,
            $this->crypto,
            $this->appManager,
            $this->logger
        );
    }

    private function createEngine(
        int $id,
        string $type = 'n8n',
        string $baseUrl = 'http://localhost:5678',
        ?string $authConfig = null,
        ?string $authType = 'api_key'
    ): WorkflowEngine {
        $engine = new WorkflowEngine();
        $ref = new ReflectionClass($engine);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($engine, $id);

        $engine->setEngineType($type);
        $engine->setBaseUrl($baseUrl);
        $engine->setAuthConfig($authConfig);
        $engine->setAuthType($authType);
        $engine->setName('Test Engine');

        return $engine;
    }

    // ── resolveAdapter ──

    public function testResolveAdapterReturnsN8nAdapterForN8nType(): void
    {
        $engine = $this->createEngine(1, 'n8n');
        $this->n8nAdapter->expects($this->once())->method('configure');

        $result = $this->registry->resolveAdapter($engine);
        $this->assertSame($this->n8nAdapter, $result);
    }

    public function testResolveAdapterReturnsWindmillAdapterForWindmillType(): void
    {
        $engine = $this->createEngine(1, 'windmill');
        $this->windmillAdapter->expects($this->once())->method('configure');

        $result = $this->registry->resolveAdapter($engine);
        $this->assertSame($this->windmillAdapter, $result);
    }

    public function testResolveAdapterThrowsForUnsupportedType(): void
    {
        $engine = $this->createEngine(1, 'unsupported');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported engine type: 'unsupported'");

        $this->registry->resolveAdapter($engine);
    }

    public function testResolveAdapterDecryptsAuthConfig(): void
    {
        $engine = $this->createEngine(1, 'n8n', 'http://localhost:5678', 'encrypted_data');
        $this->crypto->method('decrypt')->willReturn('{"apiKey":"test-key"}');
        $this->n8nAdapter->expects($this->once())->method('configure');

        $this->registry->resolveAdapter($engine);
    }

    public function testResolveAdapterHandlesDecryptionFailure(): void
    {
        $engine = $this->createEngine(1, 'n8n', 'http://localhost:5678', 'bad_encrypted');
        $this->crypto->method('decrypt')
            ->willThrowException(new \Exception('Decrypt failed'));
        $this->logger->expects($this->once())->method('warning');

        $this->n8nAdapter->expects($this->once())->method('configure');

        $this->registry->resolveAdapter($engine);
    }

    public function testResolveAdapterHandlesNullAuthConfig(): void
    {
        $engine = $this->createEngine(1, 'n8n', 'http://localhost:5678', null, 'none');
        $this->n8nAdapter->expects($this->once())->method('configure');

        $this->registry->resolveAdapter($engine);
    }

    // ── resolveAdapterById ──

    public function testResolveAdapterByIdFindsAndResolves(): void
    {
        $engine = $this->createEngine(1, 'n8n');
        $this->mapper->method('find')->willReturn($engine);
        $this->n8nAdapter->expects($this->once())->method('configure');

        $result = $this->registry->resolveAdapterById(1);
        $this->assertSame($this->n8nAdapter, $result);
    }

    // ── getEngines ──

    public function testGetEnginesReturnsAll(): void
    {
        $engines = [$this->createEngine(1), $this->createEngine(2)];
        $this->mapper->method('findAll')->willReturn($engines);

        $result = $this->registry->getEngines();
        $this->assertCount(2, $result);
    }

    // ── getEnginesByType ──

    public function testGetEnginesByTypeFiltersCorrectly(): void
    {
        $engines = [$this->createEngine(1, 'n8n')];
        $this->mapper->method('findByType')->willReturn($engines);

        $result = $this->registry->getEnginesByType('n8n');
        $this->assertCount(1, $result);
    }

    // ── getEngine ──

    public function testGetEngineReturnsEngine(): void
    {
        $engine = $this->createEngine(1);
        $this->mapper->method('find')->willReturn($engine);

        $result = $this->registry->getEngine(1);
        $this->assertSame($engine, $result);
    }

    // ── createEngine ──

    public function testCreateEngineEncryptsAuthConfig(): void
    {
        $this->crypto->method('encrypt')->willReturn('encrypted');
        $expectedEngine = $this->createEngine(1);
        $this->mapper->method('createFromArray')->willReturn($expectedEngine);

        $result = $this->registry->createEngine([
            'name' => 'Test',
            'engineType' => 'n8n',
            'authConfig' => ['apiKey' => 'secret'],
        ]);

        $this->assertSame($expectedEngine, $result);
    }

    public function testCreateEngineSkipsEncryptionForNonArrayAuthConfig(): void
    {
        $expectedEngine = $this->createEngine(1);
        $this->mapper->method('createFromArray')->willReturn($expectedEngine);
        $this->crypto->expects($this->never())->method('encrypt');

        $this->registry->createEngine([
            'name' => 'Test',
            'engineType' => 'n8n',
            'authConfig' => 'already-encrypted',
        ]);
    }

    // ── updateEngine ──

    public function testUpdateEngineEncryptsAuthConfig(): void
    {
        $this->crypto->method('encrypt')->willReturn('encrypted');
        $expectedEngine = $this->createEngine(1);
        $this->mapper->method('updateFromArray')->willReturn($expectedEngine);

        $result = $this->registry->updateEngine(1, [
            'authConfig' => ['apiKey' => 'new-secret'],
        ]);

        $this->assertSame($expectedEngine, $result);
    }

    // ── deleteEngine ──

    public function testDeleteEngineReturnsDeletedEngine(): void
    {
        $engine = $this->createEngine(1);
        $this->mapper->method('find')->willReturn($engine);
        $this->mapper->expects($this->once())->method('delete');

        $result = $this->registry->deleteEngine(1);
        $this->assertSame($engine, $result);
    }

    // ── healthCheck ──

    public function testHealthCheckReturnsHealthyResult(): void
    {
        $engine = $this->createEngine(1, 'n8n');
        $this->mapper->method('find')->willReturn($engine);
        $this->n8nAdapter->method('healthCheck')->willReturn(true);
        $this->n8nAdapter->method('configure');
        $this->mapper->expects($this->once())->method('update');

        $result = $this->registry->healthCheck(1);

        $this->assertTrue($result['healthy']);
        $this->assertArrayHasKey('responseTime', $result);
    }

    public function testHealthCheckReturnsUnhealthyResult(): void
    {
        $engine = $this->createEngine(1, 'n8n');
        $this->mapper->method('find')->willReturn($engine);
        $this->n8nAdapter->method('healthCheck')->willReturn(false);
        $this->n8nAdapter->method('configure');
        $this->mapper->expects($this->once())->method('update');

        $result = $this->registry->healthCheck(1);

        $this->assertFalse($result['healthy']);
    }

    // ── discoverEngines ──

    public function testDiscoverEnginesReturnsEmptyWhenAppApiNotInstalled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $result = $this->registry->discoverEngines();
        $this->assertSame([], $result);
    }

    public function testDiscoverEnginesReturnsInstalledEngines(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturnCallback(
            function (string $appId): bool {
                return $appId === 'app_api' || $appId === 'n8n';
            }
        );

        $result = $this->registry->discoverEngines();

        $this->assertCount(1, $result);
        $this->assertSame('n8n', $result[0]['engineType']);
        $this->assertTrue($result[0]['installed']);
    }

    public function testDiscoverEnginesReturnsBothEnginesWhenInstalled(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $result = $this->registry->discoverEngines();

        $this->assertCount(2, $result);
    }
}
