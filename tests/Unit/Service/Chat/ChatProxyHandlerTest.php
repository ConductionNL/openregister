<?php

/**
 * ChatProxyHandlerTest — or-chat-proxy-deprecation optional proxy-to-hermiq leg.
 *
 * Covers: config resolution, path rewriting, JSON forwarding (success +
 * transport-failure fallback), the streaming reachability probe, and the
 * 308 redirect builder.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Chat
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/or-chat-proxy-deprecation/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Chat;

use OCA\OpenRegister\Service\Chat\ChatProxyHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Chat\ChatProxyHandler
 */
class ChatProxyHandlerTest extends TestCase
{

    /** @var array<string, mixed>|null Captured client->request() options. */
    private ?array $capturedOptions = null;

    /** @var string|null Captured client->request() url. */
    private ?string $capturedUrl = null;

    /**
     * Build a handler with fully-mocked collaborators.
     *
     * @param string|null $proxyTo         The `chat.proxyTo` appconfig value; null simulates an
     *                                     UNSET key (getValueString returns its default, which is
     *                                     'hermiq' since or-chat-engine-decommission).
     * @param bool        $hermiqInstalled Whether IAppManager::isInstalled('hermiq') returns true.
     * @param IClient|null $client         Optional pre-built client mock (defaults to a never-called mock).
     */
    private function makeHandler(
        ?string $proxyTo=null,
        bool $hermiqInstalled=true,
        ?IClient $client=null
    ): ChatProxyHandler {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->with('openregister', 'chat.proxyTo', 'hermiq')
            ->willReturnCallback(
                fn (string $app, string $key, string $default='') => $proxyTo ?? $default
            );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn($hermiqInstalled);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(fn (string $path): string => 'https://cloud.example.test'.$path);

        if ($client === null) {
            $client = $this->createMock(IClient::class);
            $client->expects($this->never())->method('request');
            $client->expects($this->never())->method('get');
        }

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return new ChatProxyHandler(
            $appConfig,
            $appManager,
            $urlGenerator,
            $clientService,
            $this->createMock(LoggerInterface::class)
        );
    }//end makeHandler()

    /**
     * Build an IRequest mock for the forward/redirect tests.
     *
     * @param array<string, mixed> $params
     */
    private function makeRequest(
        string $method='POST',
        string $requestUri='/index.php/apps/openregister/api/chat/send',
        array $params=[],
        string $cookie=''
    ): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getRequestUri')->willReturn($requestUri);
        $request->method('getParams')->willReturn($params);
        $request->method('getHeader')->willReturnCallback(
            fn (string $name): string => $name === 'Cookie' ? $cookie : ''
        );

        return $request;
    }//end makeRequest()

    public function testIsProxyConfiguredDefaultsOnAndSupportsOptOut(): void
    {
        // Unset key = proxy ON (or-chat-engine-decommission default flip).
        $this->assertTrue($this->makeHandler()->isProxyConfigured());
        $this->assertTrue($this->makeHandler(proxyTo: 'hermiq')->isProxyConfigured());
        // Any explicit non-hermiq value is an operator opt-out.
        $this->assertFalse($this->makeHandler(proxyTo: 'off')->isProxyConfigured());
        $this->assertFalse($this->makeHandler(proxyTo: '')->isProxyConfigured());
        $this->assertFalse($this->makeHandler(proxyTo: 'something-else')->isProxyConfigured());
    }//end testIsProxyConfiguredDefaultsOnAndSupportsOptOut()

    public function testIsHermiqInstalledDelegatesToAppManager(): void
    {
        $this->assertTrue($this->makeHandler(hermiqInstalled: true)->isHermiqInstalled());
        $this->assertFalse($this->makeHandler(hermiqInstalled: false)->isHermiqInstalled());
    }//end testIsHermiqInstalledDelegatesToAppManager()

    public function testRewritePathForHermiqRewritesOrPrefix(): void
    {
        $handler = $this->makeHandler();
        $this->assertSame(
            '/apps/hermiq/api/chat/send',
            $handler->rewritePathForHermiq('/apps/openregister/api/chat/send')
        );
        $this->assertSame(
            '/apps/hermiq/api/agents/stats',
            $handler->rewritePathForHermiq('/apps/openregister/api/agents/stats')
        );
    }//end testRewritePathForHermiqRewritesOrPrefix()

    public function testRewritePathForHermiqReturnsNullForNonMatchingPrefix(): void
    {
        $handler = $this->makeHandler();
        $this->assertNull($handler->rewritePathForHermiq('/apps/someotherapp/api/thing'));
    }//end testRewritePathForHermiqReturnsNullForNonMatchingPrefix()

    public function testForwardJsonRequestRebuildsSuccessfulUpstreamResponse(): void
    {
        $upstream = $this->createMock(IResponse::class);
        $upstream->method('getStatusCode')->willReturn(200);
        $upstream->method('getBody')->willReturn('{"conversation":"abc-123"}');
        $upstream->method('getHeader')->with('Content-Type')->willReturn('application/json; charset=utf-8');

        $client = $this->createMock(IClient::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($upstream) {
                $this->capturedUrl     = $uri;
                $this->capturedOptions = $options;
                return $upstream;
            }
        );

        $handler = $this->makeHandler(proxyTo: 'hermiq', client: $client);
        $request = $this->makeRequest(
            method: 'POST',
            requestUri: '/index.php/apps/openregister/api/chat/send',
            params: ['message' => 'hi', 'requesttoken' => 'tok123', '_route' => 'openregister.chat.sendMessage'],
            cookie: 'oc_sessionid=abc'
        );

        $response = $handler->forwardJsonRequest($request, '/apps/hermiq/api/chat/send');

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('{"conversation":"abc-123"}', $response->render());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaders()['Content-Type']);
        $this->assertArrayNotHasKey('Content-Disposition', $response->getHeaders());

        // The forward URL targets hermiq's mirrored path.
        $this->assertSame('https://cloud.example.test/apps/hermiq/api/chat/send', $this->capturedUrl);

        // NC-internal keys are stripped from the forwarded body.
        $body = json_decode((string) $this->capturedOptions['body'], true);
        $this->assertSame(['message' => 'hi'], $body);

        // The session cookie flows through; nothing else does.
        $this->assertSame('oc_sessionid=abc', $this->capturedOptions['headers']['Cookie']);
    }//end testForwardJsonRequestRebuildsSuccessfulUpstreamResponse()

    public function testForwardJsonRequestSkipsBodyForGetAndDelete(): void
    {
        $upstream = $this->createMock(IResponse::class);
        $upstream->method('getStatusCode')->willReturn(200);
        $upstream->method('getBody')->willReturn('{"messages":[]}');
        $upstream->method('getHeader')->willReturn('application/json');

        $client = $this->createMock(IClient::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($upstream) {
                $this->capturedOptions = $options;
                return $upstream;
            }
        );

        $handler = $this->makeHandler(proxyTo: 'hermiq', client: $client);
        $request = $this->makeRequest(method: 'GET', requestUri: '/apps/openregister/api/chat/history?conversationId=5');

        $handler->forwardJsonRequest($request, '/apps/hermiq/api/chat/history');

        $this->assertArrayNotHasKey('body', $this->capturedOptions);
    }//end testForwardJsonRequestSkipsBodyForGetAndDelete()

    public function testForwardJsonRequestReturnsNullOnTransportFailure(): void
    {
        $client = $this->createMock(IClient::class);
        $client->method('request')->willThrowException(new \RuntimeException('connection refused'));

        $handler = $this->makeHandler(proxyTo: 'hermiq', client: $client);
        $request = $this->makeRequest();

        $response = $handler->forwardJsonRequest($request, '/apps/hermiq/api/chat/send');

        $this->assertNull($response);
    }//end testForwardJsonRequestReturnsNullOnTransportFailure()

    public function testProbeReachableTrueOnNonServerErrorStatus(): void
    {
        $upstream = $this->createMock(IResponse::class);
        $upstream->method('getStatusCode')->willReturn(200);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($upstream);

        $handler = $this->makeHandler(proxyTo: 'hermiq', client: $client);

        $this->assertTrue($handler->probeReachable());
    }//end testProbeReachableTrueOnNonServerErrorStatus()

    public function testProbeReachableFalseOnServerErrorStatus(): void
    {
        $upstream = $this->createMock(IResponse::class);
        $upstream->method('getStatusCode')->willReturn(503);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($upstream);

        $handler = $this->makeHandler(proxyTo: 'hermiq', client: $client);

        $this->assertFalse($handler->probeReachable());
    }//end testProbeReachableFalseOnServerErrorStatus()

    public function testProbeReachableFalseOnTransportFailure(): void
    {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new \RuntimeException('no route to host'));

        $handler = $this->makeHandler(proxyTo: 'hermiq', client: $client);

        $this->assertFalse($handler->probeReachable());
    }//end testProbeReachableFalseOnTransportFailure()

    public function testBuildRedirectResponseIsA308ToTheHermiqPath(): void
    {
        $handler = $this->makeHandler(proxyTo: 'hermiq');
        $request = $this->makeRequest(method: 'POST', requestUri: '/apps/openregister/api/chat/stream?foo=bar');

        $response = $handler->buildRedirectResponse($request, '/apps/hermiq/api/chat/stream');

        $this->assertSame(308, $response->getStatus());
        $this->assertSame(
            'https://cloud.example.test/apps/hermiq/api/chat/stream?foo=bar',
            $response->getHeaders()['Location']
        );
    }//end testBuildRedirectResponseIsA308ToTheHermiqPath()
}//end class
