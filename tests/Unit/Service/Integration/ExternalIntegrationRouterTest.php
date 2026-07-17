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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test method names + arrange/act/assert structure make intent obvious; matches BrpPersoonProviderTest convention.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion + local fixture helpers take positional args by convention; mirroring BrpPersoonProviderTest in this repo.

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
class FakeExternalProvider extends AbstractIntegrationProvider
{
    public function __construct(
        private string $id='xwiki',
        private ?string $source='xwiki',
        private string $storage='external',
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

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
    }//end list()
}//end class

/**
 * Local provider stub.
 */
class FakeLocalProvider extends AbstractIntegrationProvider
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

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
    }//end list()
}//end class

/**
 * Stand-in for OpenConnector's CallLog — only the bits the router reads.
 */
class FakeCallLog
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
class FakeCallService
{
    public function __construct(private FakeCallLog $log)
    {
    }//end __construct()

    public function call($source, string $endpoint='', string $method='GET', array $config=[])
    {
        return $this->log;
    }//end call()
}//end class

/**
 * Stand-in for OpenConnector's SourceMapper — find() returns a marker.
 */
class FakeSourceMapper
{
    public function find($id)
    {
        return (object) ['id' => 1, 'slug' => (string) $id];
    }//end find()
}//end class

/**
 * Stand-in for an OpenConnector source ObjectEntity carrying a mock
 * `configuration` — the router reads `getObject()['configuration']`.
 */
class FakeMockSource
{
    public function __construct(private array $object)
    {
    }//end __construct()

    public function getObject(): array
    {
        return $this->object;
    }//end getObject()
}//end class

/**
 * Stand-in for OpenConnector's SourceMapper that returns a mock-flagged
 * source ObjectEntity for the find() lookup.
 */
class FakeMockSourceMapper
{
    public function __construct(private array $object)
    {
    }//end __construct()

    public function find($id)
    {
        return new FakeMockSource($this->object);
    }//end find()
}//end class

/**
 * A CallService that fails loudly if ever called — proves mock mode never
 * touches the real upstream transport.
 */
class ExplodingCallService
{
    public function call($source, string $endpoint='', string $method='GET', array $config=[])
    {
        throw new \RuntimeException('CallService::call must NOT be reached in mock mode');
    }//end call()
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
     * @param FakeCallLog $log The CallLog the fake CallService returns.
     *
     * @return ExternalIntegrationRouter
     */
    private function buildRouterWithCallLog(FakeCallLog $log): ExternalIntegrationRouter
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);
        $appManager->method('isEnabledForUser')->willReturn(true);

        $callService = new FakeCallService($log);
        $mapper      = new FakeSourceMapper();

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
        $provider = new FakeLocalProvider();

        $this->expectException(\LogicException::class);
        $router->call($provider, 'GET', '/some/path');
    }//end testCallRejectsNonExternalProvider()

    public function testCallRejectsExternalProviderWithoutSource(): void
    {
        $router   = $this->buildRouter(true);
        $provider = new FakeExternalProvider(source: null);

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
        $provider = new FakeExternalProvider();

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
        $provider = new FakeLocalProvider();

        $report = $router->probe($provider);
        $this->assertSame('ok', $report['status']);
        $this->assertSame('configured', $report['authStatus']);
    }//end testProbeReturnsOkForLocalProvider()

    public function testProbeReportsUnavailableWhenOpenConnectorMissing(): void
    {
        $router   = $this->buildRouter(false);
        $provider = new FakeExternalProvider();

        $report = $router->probe($provider);
        $this->assertSame('unavailable', $report['status']);
        $this->assertSame('missing', $report['authStatus']);
    }//end testProbeReportsUnavailableWhenOpenConnectorMissing()

    public function testCallUnwrapsTheCallLogBody(): void
    {
        // CallService returns a CallLog; the upstream JSON payload is the
        // `body` string inside getResponse() — the router must hand the
        // caller the decoded body, not the CallLog wrapper.
        $body   = '{"pageSummaries":[{"id":"xwiki:Sandbox.Page","name":"Page"}]}';
        $log    = new FakeCallLog(200, ['statusCode' => 200, 'headers' => [], 'body' => $body, 'encoding' => 'UTF-8']);
        $router = $this->buildRouterWithCallLog($log);

        $result = $router->call(new FakeExternalProvider(), 'GET', '');

        $this->assertArrayHasKey('pageSummaries', $result);
        $this->assertSame('xwiki:Sandbox.Page', $result['pageSummaries'][0]['id']);
    }//end testCallUnwrapsTheCallLogBody()

    public function testCallDecodesBase64EncodedBody(): void
    {
        $log    = new FakeCallLog(200, ['body' => base64_encode('{"items":[]}'), 'encoding' => 'base64']);
        $router = $this->buildRouterWithCallLog($log);

        $this->assertSame(['items' => []], $router->call(new FakeExternalProvider(), 'GET', ''));
    }//end testCallDecodesBase64EncodedBody()

    public function testCallTreatsAuthErrorAsProviderAuth(): void
    {
        $router = $this->buildRouterWithCallLog(new FakeCallLog(401, ['body' => 'denied']));

        try {
            $router->call(new FakeExternalProvider(), 'GET', '');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $e->getCause());
        }
    }//end testCallTreatsAuthErrorAsProviderAuth()

    public function testCallTreatsServerErrorAsUpstreamDown(): void
    {
        $router = $this->buildRouterWithCallLog(new FakeCallLog(500, ['body' => 'oops']));

        try {
            $router->call(new FakeExternalProvider(), 'GET', '');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $e->getCause());
        }
    }//end testCallTreatsServerErrorAsUpstreamDown()

    public function testCallWithMetaSurfacesBodyStatusDurationAndCorrelationId(): void
    {
        // The CallLog carries the upstream X-Correlation-ID header + the
        // OpenConnector-measured responseTime (ms) + the upstream status; the
        // router must surface them under `meta` alongside the decoded body so
        // a leaf can relay the Wet-BRP audit fields.
        $log    = new FakeCallLog(
            200,
            [
                'statusCode'   => 200,
                'responseTime' => 137.4,
                'headers'      => [
                    'Content-Type'     => ['application/hal+json'],
                    'X-Correlation-ID' => ['abcd-1234-correlation'],
                ],
                'body'         => '{"personen":[{"burgerservicenummer":"999993653"}]}',
                'encoding'     => 'UTF-8',
            ]
        );
        $router = $this->buildRouterWithCallLog($log);

        $result = $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');

        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertSame('999993653', $result['body']['personen'][0]['burgerservicenummer']);
        $this->assertSame(200, $result['meta']['status']);
        $this->assertSame(137, $result['meta']['durationMs']);
        $this->assertSame('abcd-1234-correlation', $result['meta']['correlationId']);
        $this->assertSame('application/hal+json', $result['meta']['headers']['Content-Type']);
    }//end testCallWithMetaSurfacesBodyStatusDurationAndCorrelationId()

    public function testCallWithMetaFindsCorrelationIdCaseInsensitively(): void
    {
        $log    = new FakeCallLog(
            200,
            [
                'statusCode'   => 200,
                'responseTime' => 12,
                'headers'      => ['x-correlation-id' => ['lower-case-id']],
                'body'         => '{"personen":[]}',
                'encoding'     => 'UTF-8',
            ]
        );
        $router = $this->buildRouterWithCallLog($log);

        $result = $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');
        $this->assertSame('lower-case-id', $result['meta']['correlationId']);
    }//end testCallWithMetaFindsCorrelationIdCaseInsensitively()

    public function testCallWithMetaDefaultsWhenHeadersAndTimingAbsent(): void
    {
        $log    = new FakeCallLog(200, ['statusCode' => 200, 'body' => '{"personen":[]}', 'encoding' => 'UTF-8']);
        $router = $this->buildRouterWithCallLog($log);

        $result = $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');
        $this->assertNull($result['meta']['correlationId']);
        $this->assertSame(0, $result['meta']['durationMs']);
        $this->assertSame(200, $result['meta']['status']);
        $this->assertSame([], $result['meta']['headers']);
    }//end testCallWithMetaDefaultsWhenHeadersAndTimingAbsent()

    public function testCallWithMetaDegradesOnAuthError(): void
    {
        $router = $this->buildRouterWithCallLog(new FakeCallLog(401, ['body' => 'denied']));

        try {
            $router->callWithMeta(new FakeExternalProvider(), 'POST', 'personen');
            $this->fail('Expected ProviderUnavailableException');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $e->getCause());
        }
    }//end testCallWithMetaDegradesOnAuthError()

    /**
     * Build a router whose source is flagged `configuration.mock=true` and
     * whose CallService explodes if reached — so a passing test proves the
     * mock short-circuit fired without any real upstream call.
     *
     * @param array<string,mixed> $configuration The source `configuration` array.
     *
     * @return ExternalIntegrationRouter
     */
    private function buildMockRouter(array $configuration): ExternalIntegrationRouter
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn(true);
        $appManager->method('isEnabledForUser')->willReturn(true);

        $mapper      = new FakeMockSourceMapper(['configuration' => $configuration]);
        $callService = new ExplodingCallService();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($mapper, $callService) {
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
    }//end buildMockRouter()

    public function testCallReturnsCannedMockBodyWithoutRealCall(): void
    {
        // A source flagged mock returns its canned mockResponse shaped exactly
        // like the real KvK upstream — and the ExplodingCallService proves no
        // real HTTP call was made.
        $fixture = [
            'resultaten' => [
                ['kvkNummer' => '69599084', 'naam' => 'Conduction B.V.'],
                ['kvkNummer' => '12345678', 'naam' => 'Acme Holding B.V.'],
            ],
        ];
        $router  = $this->buildMockRouter(['mock' => true, 'mockResponse' => $fixture]);

        $result = $router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');

        $this->assertSame($fixture, $result);
        $this->assertSame('69599084', $result['resultaten'][0]['kvkNummer']);
    }//end testCallReturnsCannedMockBodyWithoutRealCall()

    public function testCallReturnsEmptyBodyWhenMockResponseAbsent(): void
    {
        // Flagged mock but no fixture → empty body (never a 500); the leaf's
        // own extractor then yields an empty result set.
        $router = $this->buildMockRouter(['mock' => true]);

        $this->assertSame([], $router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken'));
    }//end testCallReturnsEmptyBodyWhenMockResponseAbsent()

    public function testCallWithMetaReturnsCannedBodyAndSynthesizedMeta(): void
    {
        // A BRP-style mock returns the canned personen body PLUS a synthesized
        // meta (status 200, non-zero duration, a fresh fake correlationId).
        $fixture = ['personen' => [['burgerservicenummer' => '999990019']]];
        $router  = $this->buildMockRouter(['mock' => true, 'mockResponse' => $fixture]);

        $result = $router->callWithMeta(new FakeExternalProvider(source: 'brp-haalcentraal'), 'POST', 'personen');

        $this->assertSame($fixture, $result['body']);
        $this->assertSame('999990019', $result['body']['personen'][0]['burgerservicenummer']);
        $this->assertSame(200, $result['meta']['status']);
        $this->assertGreaterThan(0, $result['meta']['durationMs']);
        $this->assertNotNull($result['meta']['correlationId']);
        $this->assertSame($result['meta']['correlationId'], $result['meta']['headers']['X-Correlation-ID']);
    }//end testCallWithMetaReturnsCannedBodyAndSynthesizedMeta()

    public function testCallWithMetaHonoursMockMetaOverride(): void
    {
        $router = $this->buildMockRouter(
            [
                'mock'         => true,
                'mockResponse' => ['personen' => []],
                'mockMeta'     => ['status' => 200, 'durationMs' => 42, 'correlationId' => 'fixed-cid'],
            ]
        );

        $result = $router->callWithMeta(new FakeExternalProvider(source: 'brp-haalcentraal'), 'POST', 'personen');

        $this->assertSame(42, $result['meta']['durationMs']);
        $this->assertSame('fixed-cid', $result['meta']['correlationId']);
    }//end testCallWithMetaHonoursMockMetaOverride()

    public function testNonMockSourceStillUsesTheRealCallPath(): void
    {
        // A source WITHOUT the mock flag must still hit the real CallService
        // path unchanged (here the FakeCallService returns a normal CallLog).
        $log    = new FakeCallLog(200, ['statusCode' => 200, 'headers' => [], 'body' => '{"resultaten":[]}', 'encoding' => 'UTF-8']);
        $router = $this->buildRouterWithCallLog($log);

        $result = $router->call(new FakeExternalProvider(source: 'kvk'), 'GET', 'zoeken');

        $this->assertSame(['resultaten' => []], $result);
    }//end testNonMockSourceStillUsesTheRealCallPath()
}//end class
