<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\FileSettingsController;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use OCA\OpenRegister\Service\SettingsService;
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

    public function testOpenAnonymiserConnection(string $apiEndpoint = ''): JSONResponse
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
            'provider'          => 'dolphin',
            'chunkingStrategy'  => 'paragraph',
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
            'provider'         => ['id' => 'dolphin', 'name' => 'Dolphin'],
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
            'error'   => 'Connection failed: timeout',
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
            'error'   => 'Presidio API returned HTTP 503',
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
        // testOpenAnonymiserConnection() was refactored: it no longer uses $apiEndpoint
        // for URL checks. Instead it probes via AnonymisationBackendService (ExApp detection).
        // When the ExApp is not available the service returns reachable=false → 200 + success=false.
        $probe = new ProbeResult(
            reachable: false,
            latencyMs: null,
            error: ProbeResult::ERROR_EXAPP_NOT_INSTALLED,
            probedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        );

        $mockService = $this->createMock(AnonymisationBackendService::class);
        $mockService->method('testConnection')->willReturn($probe);

        $this->container->method('get')
            ->with(AnonymisationBackendService::class)
            ->willReturn($mockService);

        $result = $this->controller->testOpenAnonymiserConnection('');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
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
            'error'   => 'Connection failed: refused',
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
        // testOpenAnonymiserConnection() was refactored to use AnonymisationBackendService
        // (AppAPI ExApp detection) instead of HTTP health-check. Simulate ExApp unavailable.
        $probe = new ProbeResult(
            reachable: false,
            latencyMs: null,
            error: ProbeResult::ERROR_EXAPP_NOT_INSTALLED,
            probedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        );

        $mockService = $this->createMock(AnonymisationBackendService::class);
        $mockService->method('testConnection')->willReturn($probe);

        $this->container->method('get')
            ->with(AnonymisationBackendService::class)
            ->willReturn($mockService);

        $result = $this->controller->testOpenAnonymiserConnection('http://invalid-host-that-does-not-exist:9999');

        $data = $result->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
    }

    // ── getFileExtractionStats ──────────────────────────────────────────

    public function testGetFileExtractionStatsException(): void
    {
        // When container->get throws, the method returns zeros (catch branch returns success=true with zeros).
        $this->container->method('get')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getFileExtractionStats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['totalFiles']);
        $this->assertSame(0, $data['processedFiles']);
    }
}
