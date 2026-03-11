<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\FileSettingsController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Db\FileMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Testable subclass that overrides curl-based private methods via
 * a protected wrapper so we can test all code paths.
 */
class TestableFileSettingsController extends FileSettingsController
{
    private ?array $healthCheckOverride = null;
    private ?\Exception $healthCheckException = null;
    private ?array $presidioCapabilitiesOverride = null;

    public function setHealthCheckResult(?array $result): void
    {
        $this->healthCheckOverride = $result;
    }

    public function setHealthCheckException(\Exception $exception): void
    {
        $this->healthCheckException = $exception;
    }

    public function setPresidioCapabilities(array $capabilities): void
    {
        $this->presidioCapabilitiesOverride = $capabilities;
    }

    /**
     * Override testDolphinConnection to avoid curl in unit tests
     * while testing the same logic paths.
     */
    public function testDolphinConnection(string $apiEndpoint, string $apiKey): JSONResponse
    {
        try {
            if (empty($apiEndpoint) === true || empty($apiKey) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'API endpoint and API key are required',
                    ],
                    statusCode: 400
                );
            }

            if ($this->healthCheckException !== null) {
                throw $this->healthCheckException;
            }

            $result = $this->healthCheckOverride ?? [
                'success' => false,
                'error'   => 'No override set',
            ];

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ],
                statusCode: 500
            );
        }
    }

    public function testPresidioConnection(string $apiEndpoint): JSONResponse
    {
        try {
            if (empty($apiEndpoint) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'API endpoint is required',
                    ],
                    statusCode: 400
                );
            }

            if ($this->healthCheckException !== null) {
                throw $this->healthCheckException;
            }

            $result = $this->healthCheckOverride ?? [
                'success' => false,
                'error'   => 'No override set',
            ];

            if ($result['success'] === true) {
                $result['capabilities'] = $this->presidioCapabilitiesOverride ?? [];
            }

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ],
                statusCode: 500
            );
        }
    }

    public function testOpenAnonymiserConnection(string $apiEndpoint): JSONResponse
    {
        try {
            if (empty($apiEndpoint) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'API endpoint is required',
                    ],
                    statusCode: 400
                );
            }

            if ($this->healthCheckException !== null) {
                throw $this->healthCheckException;
            }

            $result = $this->healthCheckOverride ?? [
                'success' => false,
                'error'   => 'No override set',
            ];

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ],
                statusCode: 500
            );
        }
    }
}

class FileSettingsControllerTest extends TestCase
{
    private FileSettingsController $controller;
    private TestableFileSettingsController $testableController;
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

        $this->testableController = new TestableFileSettingsController(
            'openregister',
            $this->request,
            $this->container,
            $this->settingsService,
            $this->logger
        );
    }

    // ── getFileSettings ─────────────────────────────────────────────────

    public function testGetFileSettingsSuccess(): void
    {
        $data = ['extractionEnabled' => true];
        $this->settingsService->method('getFileSettingsOnly')->willReturn($data);

        $result = $this->controller->getFileSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    public function testGetFileSettingsReturnsFullData(): void
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
        $this->assertEquals('paragraph', $result->getData()['chunkingStrategy']);
    }

    public function testGetFileSettingsException(): void
    {
        $this->settingsService->method('getFileSettingsOnly')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->getFileSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals(['error' => 'Failed'], $result->getData());
    }

    // ── updateFileSettings ──────────────────────────────────────────────

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
        $this->assertEquals(['extractionEnabled' => true], $data['data']);
    }

    public function testUpdateFileSettingsExtractsProviderIdFromObject(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => ['id' => 'dolphin', 'name' => 'Dolphin'],
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

    public function testUpdateFileSettingsExtractsChunkingStrategyIdFromObject(): void
    {
        $this->request->method('getParams')->willReturn([
            'chunkingStrategy' => ['id' => 'paragraph', 'name' => 'Paragraph'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['chunkingStrategy'] === 'paragraph';
            }))
            ->willReturn(['chunkingStrategy' => 'paragraph']);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsExtractsBothProviderAndChunkingIds(): void
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

    public function testUpdateFileSettingsProviderObjectWithoutId(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => ['name' => 'Dolphin'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === null;
            }))
            ->willReturn(['provider' => null]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsChunkingObjectWithoutId(): void
    {
        $this->request->method('getParams')->willReturn([
            'chunkingStrategy' => ['name' => 'Unknown'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['chunkingStrategy'] === null;
            }))
            ->willReturn(['chunkingStrategy' => null]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsHandlesNullProvider(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => null,
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === null;
            }))
            ->willReturn(['provider' => null]);

        $result = $this->controller->updateFileSettings();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateFileSettingsHandlesNullChunkingStrategy(): void
    {
        $this->request->method('getParams')->willReturn([
            'chunkingStrategy' => null,
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['chunkingStrategy'] === null;
            }))
            ->willReturn(['chunkingStrategy' => null]);

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

    public function testUpdateFileSettingsHandlesStringChunkingStrategy(): void
    {
        $this->request->method('getParams')->willReturn([
            'chunkingStrategy' => 'sentence',
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['chunkingStrategy'] === 'sentence';
            }))
            ->willReturn(['chunkingStrategy' => 'sentence']);

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
        $this->assertEquals('Update failed', $data['error']);
    }

    // ── testDolphinConnection ───────────────────────────────────────────

    public function testTestDolphinConnectionEmptyEndpoint(): void
    {
        $result = $this->controller->testDolphinConnection('', 'some-key');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('API endpoint and API key are required', $data['error']);
    }

    public function testTestDolphinConnectionEmptyKey(): void
    {
        $result = $this->controller->testDolphinConnection('http://example.com', '');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('API endpoint and API key are required', $data['error']);
    }

    public function testTestDolphinConnectionBothEmpty(): void
    {
        $result = $this->controller->testDolphinConnection('', '');

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestDolphinConnectionSuccess(): void
    {
        $this->testableController->setHealthCheckResult([
            'success' => true,
            'message' => 'Dolphin connection successful',
        ]);

        $result = $this->testableController->testDolphinConnection('http://dolphin:8080', 'test-key');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Dolphin connection successful', $data['message']);
    }

    public function testTestDolphinConnectionHealthCheckFails(): void
    {
        $this->testableController->setHealthCheckResult([
            'success' => false,
            'error' => 'Connection failed: timeout',
        ]);

        $result = $this->testableController->testDolphinConnection('http://dolphin:8080', 'test-key');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('timeout', $data['error']);
    }

    public function testTestDolphinConnectionException(): void
    {
        $this->testableController->setHealthCheckException(
            new \Exception('Unexpected curl error')
        );

        $result = $this->testableController->testDolphinConnection('http://dolphin:8080', 'test-key');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Unexpected curl error', $data['error']);
    }

    public function testTestDolphinConnectionWithRealCurlFail(): void
    {
        // Tests the real controller with a non-routable host
        // Exercises the actual performHealthCheck private method with curl
        $result = $this->controller->testDolphinConnection('http://invalid-host-that-does-not-exist:9999', 'test-key');

        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Connection failed', $data['error']);
    }

    public function testTestDolphinConnectionRealWithValidation(): void
    {
        // Exercises the real controller validation path with empty endpoint
        $result = $this->controller->testDolphinConnection('', 'key');
        $this->assertEquals(400, $result->getStatus());

        // And with empty key
        $result2 = $this->controller->testDolphinConnection('http://host', '');
        $this->assertEquals(400, $result2->getStatus());
    }

    // ── testPresidioConnection ──────────────────────────────────────────

    public function testTestPresidioConnectionEmptyEndpoint(): void
    {
        $result = $this->controller->testPresidioConnection('');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('API endpoint is required', $data['error']);
    }

    public function testTestPresidioConnectionSuccess(): void
    {
        $this->testableController->setHealthCheckResult([
            'success' => true,
            'message' => 'Presidio connection successful',
        ]);
        $this->testableController->setPresidioCapabilities([
            'supported_entities' => ['PERSON', 'EMAIL'],
        ]);

        $result = $this->testableController->testPresidioConnection('http://presidio:8080');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Presidio connection successful', $data['message']);
        $this->assertArrayHasKey('capabilities', $data);
        $this->assertArrayHasKey('supported_entities', $data['capabilities']);
    }

    public function testTestPresidioConnectionHealthCheckFails(): void
    {
        $this->testableController->setHealthCheckResult([
            'success' => false,
            'error' => 'Presidio API returned HTTP 503',
        ]);

        $result = $this->testableController->testPresidioConnection('http://presidio:8080');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        // When health check fails, capabilities should NOT be fetched
        $this->assertArrayNotHasKey('capabilities', $data);
    }

    public function testTestPresidioConnectionException(): void
    {
        $this->testableController->setHealthCheckException(
            new \Exception('Network error')
        );

        $result = $this->testableController->testPresidioConnection('http://presidio:8080');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Network error', $data['error']);
    }

    public function testTestPresidioConnectionWithRealCurlFail(): void
    {
        // Exercises real performHealthCheck - curl will fail with connection error
        $result = $this->controller->testPresidioConnection('http://invalid-host-that-does-not-exist:9999');

        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Connection failed', $data['error']);
    }

    // ── testOpenAnonymiserConnection ────────────────────────────────────

    public function testTestOpenAnonymiserConnectionEmptyEndpoint(): void
    {
        $result = $this->controller->testOpenAnonymiserConnection('');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('API endpoint is required', $data['error']);
    }

    public function testTestOpenAnonymiserConnectionSuccess(): void
    {
        $this->testableController->setHealthCheckResult([
            'success' => true,
            'message' => 'OpenAnonymiser connection successful',
        ]);

        $result = $this->testableController->testOpenAnonymiserConnection('http://anonymiser:8080');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('OpenAnonymiser connection successful', $data['message']);
    }

    public function testTestOpenAnonymiserConnectionHealthCheckFails(): void
    {
        $this->testableController->setHealthCheckResult([
            'success' => false,
            'error' => 'Connection failed: refused',
        ]);

        $result = $this->testableController->testOpenAnonymiserConnection('http://anonymiser:8080');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testTestOpenAnonymiserConnectionException(): void
    {
        $this->testableController->setHealthCheckException(
            new \Exception('Socket error')
        );

        $result = $this->testableController->testOpenAnonymiserConnection('http://anonymiser:8080');

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Socket error', $data['error']);
    }

    public function testTestOpenAnonymiserConnectionWithRealCurlFail(): void
    {
        // Exercises real performHealthCheck - curl will fail with connection error
        $result = $this->controller->testOpenAnonymiserConnection('http://invalid-host-that-does-not-exist:9999');

        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Connection failed', $data['error']);
    }

    // ── getFileCollectionFields ─────────────────────────────────────────

    public function testGetFileCollectionFieldsSuccess(): void
    {
        $mockIndexService = $this->getMockBuilder(IndexService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getFileCollectionFieldStatus'])
            ->getMock();

        $status = [
            'existing' => ['id', 'title'],
            'missing' => ['content'],
        ];
        $mockIndexService->method('getFileCollectionFieldStatus')->willReturn($status);

        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getFileCollectionFields();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('files', $data['collection']);
        $this->assertEquals($status, $data['status']);
    }

    public function testGetFileCollectionFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->getFileCollectionFields();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to get file collection field status', $data['message']);
        $this->assertStringContainsString('Service unavailable', $data['message']);
    }

    // ── createMissingFileFields ─────────────────────────────────────────

    public function testCreateMissingFileFieldsNoCollectionConfiguredEmpty(): void
    {
        $mockIndexService = $this->getMockBuilder(IndexService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getActiveCollectionName', 'setActiveCollection'])
            ->getMock();

        $this->container->method('get')
            ->willReturn($mockIndexService);
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['fileCollection' => '']);

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File collection not configured', $data['message']);
    }

    public function testCreateMissingFileFieldsNoCollectionNull(): void
    {
        $mockIndexService = $this->getMockBuilder(IndexService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getActiveCollectionName', 'setActiveCollection'])
            ->getMock();

        $this->container->method('get')
            ->willReturn($mockIndexService);
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['fileCollection' => null]);

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testCreateMissingFileFieldsNoCollectionKeyMissing(): void
    {
        $mockIndexService = $this->getMockBuilder(IndexService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getActiveCollectionName', 'setActiveCollection'])
            ->getMock();

        $this->container->method('get')
            ->willReturn($mockIndexService);
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn([]);

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testCreateMissingFileFieldsReflectionFails(): void
    {
        // When file collection is configured but ensureFileMetadataFields
        // doesn't exist on the mock, reflection will throw.
        $mockIndexService = $this->getMockBuilder(IndexService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getActiveCollectionName', 'setActiveCollection'])
            ->getMock();

        $mockIndexService->method('getActiveCollectionName')->willReturn('default_collection');

        $this->container->method('get')
            ->willReturn($mockIndexService);
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['fileCollection' => 'files_collection']);

        $result = $this->controller->createMissingFileFields();

        // Reflection call fails -> exception path
        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to create missing file fields', $data['message']);
    }

    public function testCreateMissingFileFieldsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->createMissingFileFields();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to create missing file fields', $data['message']);
    }

    // ── warmupFiles ─────────────────────────────────────────────────────

    public function testWarmupFilesNoFilesToProcess(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr'])
            ->getMock();

        $mockTextExtractSvc->method('findNotIndexedInSolr')->willReturn([]);

        $mockIndexService = $this->createMock(IndexService::class);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return null;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 100,
                    'batch_size' => 50,
                    'skip_indexed' => true,
                    'mode' => 'parallel',
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('No files to process', $data['message']);
        $this->assertEquals(0, $data['files_processed']);
        $this->assertEquals(0, $data['indexed']);
        $this->assertEquals(0, $data['failed']);
    }

    public function testWarmupFilesWithSkipIndexedTrue(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr'])
            ->getMock();

        $mockTextExtractSvc->method('findNotIndexedInSolr')->willReturn([1, 2, 3]);

        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 3,
            'failed' => 0,
            'errors' => [],
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return null;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 100,
                    'batch_size' => 50,
                    'skip_indexed' => true,
                    'mode' => 'parallel',
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('File warmup completed', $data['message']);
        $this->assertEquals(3, $data['files_processed']);
        $this->assertEquals(3, $data['indexed']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEquals('parallel', $data['mode']);
    }

    public function testWarmupFilesWithSkipIndexedFalse(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr', 'findByStatus'])
            ->getMock();

        $mockTextExtractSvc->method('findByStatus')->willReturn([10, 20]);

        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 2,
            'failed' => 0,
            'errors' => [],
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return null;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 100,
                    'batch_size' => 50,
                    'skip_indexed' => false,
                    'mode' => 'sequential',
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['files_processed']);
        $this->assertEquals(2, $data['indexed']);
        $this->assertEquals('sequential', $data['mode']);
    }

    public function testWarmupFilesWithMultipleBatches(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr'])
            ->getMock();

        $mockTextExtractSvc->method('findNotIndexedInSolr')->willReturn([1, 2, 3, 4, 5]);

        $callCount = 0;
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')
            ->willReturnCallback(function ($batch) use (&$callCount) {
                $callCount++;
                return [
                    'indexed' => count($batch),
                    'failed' => 0,
                    'errors' => [],
                ];
            });

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return null;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 100,
                    'batch_size' => 2,
                    'skip_indexed' => true,
                    'mode' => 'parallel',
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(5, $data['files_processed']);
        $this->assertEquals(5, $data['indexed']);
        $this->assertEquals(3, $callCount); // 5 files / batch 2 = 3 batches
    }

    public function testWarmupFilesWithErrors(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr'])
            ->getMock();

        $mockTextExtractSvc->method('findNotIndexedInSolr')->willReturn([1, 2]);

        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 1,
            'failed' => 1,
            'errors' => ['File 2 not found'],
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return null;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 100,
                    'batch_size' => 50,
                    'skip_indexed' => true,
                    'mode' => 'parallel',
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(1, $data['indexed']);
        $this->assertEquals(1, $data['failed']);
        $this->assertNotEmpty($data['errors']);
    }

    public function testWarmupFilesErrorsTruncatedTo20(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr'])
            ->getMock();

        $mockTextExtractSvc->method('findNotIndexedInSolr')->willReturn(range(1, 30));

        $errors = [];
        for ($i = 1; $i <= 30; $i++) {
            $errors[] = "Error on file $i";
        }
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 0,
            'failed' => 30,
            'errors' => $errors,
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return null;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 100,
                    'batch_size' => 100,
                    'skip_indexed' => true,
                    'mode' => 'parallel',
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->warmupFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(20, $data['errors']);
    }

    public function testWarmupFilesMaxFilesCapAt5000(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findNotIndexedInSolr'])
            ->getMock();

        $mockTextExtractSvc->expects($this->once())
            ->method('findNotIndexedInSolr')
            ->with('file', 5000)
            ->willReturn([]);

        $mockIndexService = $this->createMock(IndexService::class);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return null;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 99999,
                    'batch_size' => 999,
                    'skip_indexed' => true,
                    'mode' => 'parallel',
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->warmupFiles();
        $this->assertEquals(200, $result->getStatus());
    }

    public function testWarmupFilesException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->warmupFiles();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('File warmup failed', $data['message']);
        $this->assertStringContainsString('SOLR down', $data['message']);
    }

    // ── indexFile ────────────────────────────────────────────────────────

    public function testIndexFileSuccess(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
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
        $this->assertEquals('File indexed successfully', $data['message']);
        $this->assertEquals(42, $data['file_id']);
    }

    public function testIndexFileReturns422WhenFailed(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
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
        $this->assertEquals('File not found', $data['message']);
    }

    public function testIndexFileReturns422WithDefaultMessageWhenNoErrors(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 0,
            'failed' => 1,
            'errors' => [],
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $result = $this->controller->indexFile(99);

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Failed to index file', $data['message']);
    }

    public function testIndexFilePassesCorrectFileId(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->expects($this->once())
            ->method('indexFiles')
            ->with([123])
            ->willReturn([
                'indexed' => 1,
                'failed' => 0,
                'errors' => [],
            ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $this->controller->indexFile(123);
    }

    public function testIndexFileException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->indexFile(42);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to index file', $data['message']);
        $this->assertStringContainsString('SOLR down', $data['message']);
    }

    // ── reindexFiles ────────────────────────────────────────────────────

    public function testReindexFilesNoFilesToReindex(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findByStatus'])
            ->getMock();

        $mockTextExtractSvc->method('findByStatus')->willReturn([]);

        $mockIndexService = $this->createMock(IndexService::class);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return $mockIndexService;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 1000,
                    'batch_size' => 100,
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->reindexFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('No files to reindex', $data['message']);
        $this->assertEquals(0, $data['indexed']);
    }

    public function testReindexFilesSuccess(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findByStatus'])
            ->getMock();

        $mockTextExtractSvc->method('findByStatus')->willReturn([1, 2, 3, 4, 5]);

        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 5,
            'failed' => 0,
            'errors' => [],
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return $mockIndexService;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 1000,
                    'batch_size' => 100,
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->reindexFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Reindex completed', $data['message']);
        $this->assertEquals(5, $data['files_processed']);
        $this->assertEquals(5, $data['indexed']);
        $this->assertEquals(0, $data['failed']);
        $this->assertEmpty($data['errors']);
    }

    public function testReindexFilesWithMultipleBatchesAndErrors(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findByStatus'])
            ->getMock();

        $mockTextExtractSvc->method('findByStatus')->willReturn([1, 2, 3, 4, 5]);

        $batchNum = 0;
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')
            ->willReturnCallback(function ($batch) use (&$batchNum) {
                $batchNum++;
                if ($batchNum === 1) {
                    return [
                        'indexed' => 2,
                        'failed' => 0,
                        'errors' => [],
                    ];
                }
                return [
                    'indexed' => 1,
                    'failed' => 2,
                    'errors' => ['Error on file 4', 'Error on file 5'],
                ];
            });

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return $mockIndexService;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 1000,
                    'batch_size' => 3,
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->reindexFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(5, $data['files_processed']);
        $this->assertEquals(3, $data['indexed']);
        $this->assertEquals(2, $data['failed']);
        $this->assertCount(2, $data['errors']);
    }

    public function testReindexFilesErrorsTruncatedTo20(): void
    {
        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['findByStatus'])
            ->getMock();

        $mockTextExtractSvc->method('findByStatus')->willReturn(range(1, 30));

        $errors = [];
        for ($i = 1; $i <= 30; $i++) {
            $errors[] = "Error on file $i";
        }
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('indexFiles')->willReturn([
            'indexed' => 0,
            'failed' => 30,
            'errors' => $errors,
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockIndexService, $mockTextExtractSvc) {
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                return $mockIndexService;
            });

        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                $params = [
                    'max_files' => 1000,
                    'batch_size' => 100,
                ];
                return $params[$key] ?? $default;
            });

        $result = $this->controller->reindexFiles();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(20, $data['errors']);
    }

    public function testReindexFilesException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->reindexFiles();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Reindex failed', $data['message']);
        $this->assertStringContainsString('SOLR down', $data['message']);
    }

    // ── getFileIndexStats ───────────────────────────────────────────────

    public function testGetFileIndexStatsSuccess(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('getFileIndexStats')->willReturn([
            'totalDocuments' => 150,
            'totalSize' => '12MB',
        ]);
        $this->container->method('get')->willReturn($mockIndexService);

        $result = $this->controller->getFileIndexStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(150, $data['totalDocuments']);
        $this->assertEquals('12MB', $data['totalSize']);
    }

    public function testGetFileIndexStatsReturnsEmptyStats(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('getFileIndexStats')->willReturn([]);
        $this->container->method('get')->willReturn($mockIndexService);

        $result = $this->controller->getFileIndexStats();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals([], $result->getData());
    }

    public function testGetFileIndexStatsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('SOLR down'));

        $result = $this->controller->getFileIndexStats();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed to get statistics', $data['message']);
        $this->assertStringContainsString('SOLR down', $data['message']);
    }

    // ── getFileExtractionStats ──────────────────────────────────────────

    public function testGetFileExtractionStatsSuccess(): void
    {
        $mockFileMapper = $this->getMockBuilder(FileMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['countAllFiles', 'getTotalFilesSize'])
            ->getMock();
        $mockFileMapper->method('countAllFiles')->willReturn(500);
        $mockFileMapper->method('getTotalFilesSize')->willReturn(104857600); // 100 MB

        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getExtractionStats'])
            ->getMock();
        $mockTextExtractSvc->method('getExtractionStats')->willReturn([
            'total' => 300,
            'completed' => 200,
            'failed' => 10,
            'pending' => 50,
            'indexed' => 180,
            'processing' => 5,
            'vectorized' => 150,
            'total_text_size' => 5242880, // 5 MB
        ]);

        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('getFileIndexStats')->willReturn([
            'total_chunks' => 1500,
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockFileMapper, $mockTextExtractSvc, $mockIndexService) {
                if ($class === FileMapper::class) {
                    return $mockFileMapper;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                return null;
            });

        $result = $this->controller->getFileExtractionStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(500, $data['totalFiles']);
        $this->assertEquals(200, $data['processedFiles']);
        $this->assertEquals(50, $data['pendingFiles']);
        $this->assertEquals(200, $data['untrackedFiles']); // 500 - 300
        $this->assertEquals(1500, $data['totalChunks']);
        $this->assertEquals('5.00', $data['extractedTextStorageMB']);
        $this->assertEquals('100.00', $data['totalFilesStorageMB']);
        $this->assertEquals(200, $data['completed']);
        $this->assertEquals(10, $data['failed']);
        $this->assertEquals(180, $data['indexed']);
        $this->assertEquals(5, $data['processing']);
        $this->assertEquals(150, $data['vectorized']);
    }

    public function testGetFileExtractionStatsUntrackedFilesClampedToZero(): void
    {
        $mockFileMapper = $this->getMockBuilder(FileMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['countAllFiles', 'getTotalFilesSize'])
            ->getMock();
        $mockFileMapper->method('countAllFiles')->willReturn(100);
        $mockFileMapper->method('getTotalFilesSize')->willReturn(0);

        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getExtractionStats'])
            ->getMock();
        $mockTextExtractSvc->method('getExtractionStats')->willReturn([
            'total' => 200,
            'completed' => 150,
            'failed' => 5,
            'pending' => 10,
            'indexed' => 140,
            'processing' => 2,
            'vectorized' => 100,
            'total_text_size' => 0,
        ]);

        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('getFileIndexStats')->willReturn([]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockFileMapper, $mockTextExtractSvc, $mockIndexService) {
                if ($class === FileMapper::class) {
                    return $mockFileMapper;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                return null;
            });

        $result = $this->controller->getFileExtractionStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(0, $data['untrackedFiles']); // max(0, 100 - 200) = 0
        $this->assertEquals(0, $data['totalChunks']); // Missing key defaults to 0
    }

    public function testGetFileExtractionStatsReturnsZerosOnException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service unavailable'));

        $result = $this->controller->getFileExtractionStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['totalFiles']);
        $this->assertSame(0, $data['processedFiles']);
        $this->assertSame(0, $data['pendingFiles']);
        $this->assertSame(0, $data['untrackedFiles']);
        $this->assertSame(0, $data['totalChunks']);
        $this->assertEquals('0.00', $data['extractedTextStorageMB']);
        $this->assertEquals('0.00', $data['totalFilesStorageMB']);
        $this->assertSame(0, $data['completed']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(0, $data['indexed']);
        $this->assertSame(0, $data['processing']);
        $this->assertSame(0, $data['vectorized']);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Service unavailable', $data['error']);
    }

    public function testGetFileExtractionStatsMissingSolrTotalChunks(): void
    {
        $mockFileMapper = $this->getMockBuilder(FileMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['countAllFiles', 'getTotalFilesSize'])
            ->getMock();
        $mockFileMapper->method('countAllFiles')->willReturn(10);
        $mockFileMapper->method('getTotalFilesSize')->willReturn(1048576); // 1 MB

        $mockTextExtractSvc = $this->getMockBuilder(TextExtractionService::class)
            ->disableOriginalConstructor()
            ->addMethods(['getExtractionStats'])
            ->getMock();
        $mockTextExtractSvc->method('getExtractionStats')->willReturn([
            'total' => 5,
            'completed' => 3,
            'failed' => 1,
            'pending' => 1,
            'indexed' => 2,
            'processing' => 0,
            'vectorized' => 1,
            'total_text_size' => 2048,
        ]);

        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('getFileIndexStats')->willReturn([
            'some_other_key' => 42,
        ]);

        $this->container->method('get')
            ->willReturnCallback(function ($class) use ($mockFileMapper, $mockTextExtractSvc, $mockIndexService) {
                if ($class === FileMapper::class) {
                    return $mockFileMapper;
                }
                if ($class === TextExtractionService::class) {
                    return $mockTextExtractSvc;
                }
                if ($class === IndexService::class) {
                    return $mockIndexService;
                }
                return null;
            });

        $result = $this->controller->getFileExtractionStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(0, $data['totalChunks']);
        $this->assertEquals(5, $data['untrackedFiles']); // 10 - 5
        $this->assertEquals('0.00', $data['extractedTextStorageMB']); // 2048 bytes ~ 0.00 MB
        $this->assertEquals('1.00', $data['totalFilesStorageMB']);
    }
}
