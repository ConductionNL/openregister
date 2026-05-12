<?php

/**
 * Unit tests for ExternalIntegrationRouter.
 *
 * Covers:
 *  - rejects non-external providers (LogicException)
 *  - rejects external providers without OpenConnector source
 *  - throws CAUSE_OPENCONNECTOR_DOWN when openconnector app missing
 *  - probe() reports the right descriptor in each failure mode
 *
 * The actual upstream call path is exercised in integration tests
 * (which spin up the OpenConnector container); here we only assert
 * the failure-mode classification per AD-23.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * External provider stub.
 */
class _ExternalProvider extends AbstractIntegrationProvider
{

    public function __construct(
        private string $id = 'xwiki',
        private ?string $source = 'xwiki',
        private string $storage = 'external',
    ) {
    }//end __construct()

    public function getId(): string
    {
        return $this->id;
    }//end getId()

    public function getLabel(): string
    {
        return 'XWiki';
    }//end getLabel()

    public function getIcon(): string
    {
        return 'FileDocumentMultiple';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return $this->storage;
    }//end getStorageStrategy()

    public function getOpenConnectorSource(): ?string
    {
        return $this->source;
    }//end getOpenConnectorSource()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()

}//end class

/**
 * Local provider stub.
 */
class _LocalProvider extends AbstractIntegrationProvider
{

    public function getId(): string
    {
        return 'files';
    }//end getId()

    public function getLabel(): string
    {
        return 'Files';
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Paperclip';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'magic-column';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()

}//end class

/**
 * Stand-in for OpenConnector's CallLog — only the bits the router reads.
 */
class _FakeCallLog
{

    public function __construct(
        private int $status,
        private ?array $response,
    ) {
    }//end __construct()

    public function getStatusCode(): int
    {
        return $this->status;
    }//end getStatusCode()

    public function getResponse(): ?array
    {
        return $this->response;
    }//end getResponse()

}//end class

/**
 * Stand-in for OpenConnector's CallService — returns a preset CallLog.
 */
class _FakeCallService
{

    public function __construct(private _FakeCallLog $log)
    {
    }//end __construct()

    public function call($source, string $endpoint = '', string $method = 'GET', array $config = [])
    {
        return $this->log;
    }//end call()

}//end class

/**
 * Stand-in for OpenConnector's SourceMapper — find() returns a marker.
 */
class _FakeSourceMapper
{

    public function find($id)
    {
        return (object) ['id' => 1, 'slug' => (string) $id];
    }//end find()

}//end class

/**
 * Unit tests for ExternalIntegrationRouter.
 */
class ExternalIntegrationRouterTest extends TestCase
{

    private function buildRouter(bool $openConnectorInstalled): ExternalIntegrationRouter
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')
            ->with('openconnector')
            ->willReturn($openConnectorInstalled);
        $appManager->method('isEnabledForUser')
            ->with('openconnector')
            ->willReturn($openConnectorInstalled);

        $container = $this->createMock(ContainerInterface::class);

        return new ExternalIntegrationRouter($appManager, $container, new NullLogger());
    }//end buildRouter()

    /**
     * Build a router whose container hands back a fake SourceMapper + a
     * fake CallService that returns the given CallLog.
     *
     * @param _FakeCallLog $log The CallLog the fake CallService returns.
     *
     * @return ExternalIntegrationRouter
     */
    private function buildRouterWithCallLog(_FakeCallLog $log): ExternalIntegrationRouter
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);
        $appManager->method('isEnabledForUser')->willReturn(true);

        $callService = new _FakeCallService($log);
        $mapper      = new _FakeSourceMapper();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($callService, $mapper) {
                if (str_ends_with($id, 'SourceMapper') === true) {
                    return $mapper;
                }

                if (str_ends_with($id, 'CallService') === true) {
                    return $callService;
                }

                return null;
            }
        );

        return new ExternalIntegrationRouter($appManager, $container, new NullLogger());
    }//end buildRouterWithCallLog()

    public function testCallRejectsNonExternalProvider(): void
    {
        $router   = $this->buildRouter(true);
        $provider = new _LocalProvider();

        $this->expectException(\LogicException::class);
        $router->call($provider, 'GET', '/some/path');
    }//end testCallRejectsNonExternalProvider()

    public function testCallRejectsExternalProviderWithoutSource(): void
    {
        $router   = $this->buildRouter(true);
        $provider = new _ExternalProvider(source: null);

        try {
            $router->call($provider, 'GET', '/some/path');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(
                ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING,
                $e->getCause()
            );
            $this->assertSame(
                ['cause' => ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING],
                $e->getDetails()
            );
        }
    }//end testCallRejectsExternalProviderWithoutSource()

    public function testCallReportsOpenConnectorDownWhenAppMissing(): void
    {
        $router   = $this->buildRouter(false);
        $provider = new _ExternalProvider();

        try {
            $router->call($provider, 'GET', '/some/path');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(
                ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN,
                $e->getCause()
            );
        }
    }//end testCallReportsOpenConnectorDownWhenAppMissing()

    public function testProbeReturnsOkForLocalProvider(): void
    {
        $router   = $this->buildRouter(false);
        $provider = new _LocalProvider();

        $report = $router->probe($provider);
        $this->assertSame('ok', $report['status']);
        $this->assertSame('configured', $report['authStatus']);
    }//end testProbeReturnsOkForLocalProvider()

    public function testProbeReportsUnavailableWhenOpenConnectorMissing(): void
    {
        $router   = $this->buildRouter(false);
        $provider = new _ExternalProvider();

        $report = $router->probe($provider);
        $this->assertSame('unavailable', $report['status']);
        $this->assertSame('missing', $report['authStatus']);
    }//end testProbeReportsUnavailableWhenOpenConnectorMissing()

    public function testCallUnwrapsTheCallLogBody(): void
    {
        // CallService returns a CallLog; the upstream JSON payload is the
        // `body` string inside getResponse() — the router must hand the
        // caller the decoded body, not the CallLog wrapper.
        $log    = new _FakeCallLog(200, ['statusCode' => 200, 'headers' => [], 'body' => '{"pageSummaries":[{"id":"xwiki:Sandbox.Page","name":"Page"}]}', 'encoding' => 'UTF-8']);
        $router = $this->buildRouterWithCallLog($log);

        $result = $router->call(new _ExternalProvider(), 'GET', '');

        $this->assertArrayHasKey('pageSummaries', $result);
        $this->assertSame('xwiki:Sandbox.Page', $result['pageSummaries'][0]['id']);
    }//end testCallUnwrapsTheCallLogBody()

    public function testCallDecodesBase64EncodedBody(): void
    {
        $log    = new _FakeCallLog(200, ['body' => base64_encode('{"items":[]}'), 'encoding' => 'base64']);
        $router = $this->buildRouterWithCallLog($log);

        $this->assertSame(['items' => []], $router->call(new _ExternalProvider(), 'GET', ''));
    }//end testCallDecodesBase64EncodedBody()

    public function testCallTreatsAuthErrorAsProviderAuth(): void
    {
        $router = $this->buildRouterWithCallLog(new _FakeCallLog(401, ['body' => 'denied']));

        try {
            $router->call(new _ExternalProvider(), 'GET', '');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $e->getCause());
        }
    }//end testCallTreatsAuthErrorAsProviderAuth()

    public function testCallTreatsServerErrorAsUpstreamDown(): void
    {
        $router = $this->buildRouterWithCallLog(new _FakeCallLog(500, ['body' => 'oops']));

        try {
            $router->call(new _ExternalProvider(), 'GET', '');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $e->getCause());
        }
    }//end testCallTreatsServerErrorAsUpstreamDown()

}//end class
