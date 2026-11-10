<?php

/**
 * Forwarding details of {@see \OCA\OpenRegister\Service\Chat\ChatProxyHandler}
 * that ChatProxyHandlerTest leaves open: stream bodies, absent headers,
 * query strings, per-method bodies, the parameter filter, the probe target
 * and what a failure logs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Chat;

use OCA\OpenRegister\Service\Chat\ChatProxyEndpoint;
use OCA\OpenRegister\Service\Chat\ChatProxyHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ChatProxyHandlerForwardingTest.
 */
class ChatProxyHandlerForwardingTest extends TestCase {
	private IClient&MockObject $client;
	private LoggerInterface&MockObject $logger;
	/** @var array<int, array{0: string, 1: string, 2: array}> */
	private array $requests = [];
	private ChatProxyHandler $handler;

	protected function setUp(): void {
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.test' . $path);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new ChatProxyHandler(
			new ChatProxyEndpoint($urlGenerator, $clientService),
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->logger
		);
	}

	private function upstream(int $status, mixed $body, string $contentType): IResponse {
		$upstream = $this->createMock(IResponse::class);
		$upstream->method('getStatusCode')->willReturn($status);
		$upstream->method('getBody')->willReturn($body);
		$upstream->method('getHeader')->with('Content-Type')->willReturn($contentType);
		$this->client->method('request')->willReturnCallback(function (string $method, string $url, array $options) use ($upstream): IResponse {
			$this->requests[] = [$method, $url, $options];
			return $upstream;
		});
		return $upstream;
	}

	private function request(string $method, string $uri, array $params = [], string $cookie = ''): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getRequestUri')->willReturn($uri);
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->with('Cookie')->willReturn($cookie);
		return $request;
	}

	public function testAStreamBodyIsReadIntoTheResponse(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, '{"ok":true}');
		rewind($stream);
		$this->upstream(200, $stream, 'application/json');

		$response = $this->handler->forwardJsonRequest($this->request('get', '/apps/openregister/api/chat/x'), '/apps/hermiq/api/chat/x');

		$this->assertSame('{"ok":true}', $response->render());
	}

	public function testAnUpstreamErrorStatusPassesThroughWithoutInventingAContentType(): void {
		$this->upstream(404, 'missing', '');

		$response = $this->handler->forwardJsonRequest($this->request('GET', '/apps/openregister/api/chat/x'), '/apps/hermiq/api/chat/x');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('Content-Disposition', $response->getHeaders());
		$this->assertArrayNotHasKey('Content-Type', $response->getHeaders());
	}

	public function testTheQueryStringAndUppercasedMethodAreForwardedWithoutCookieOrBodyForDelete(): void {
		$this->upstream(204, '', 'application/json');

		$this->handler->forwardJsonRequest($this->request('delete', '/index.php/apps/openregister/api/chat/c/5?force=1&x=y'), '/apps/hermiq/api/chat/c/5');

		[$method, $url, $options] = $this->requests[0];
		$this->assertSame('DELETE', $method);
		$this->assertSame('https://nc.test/apps/hermiq/api/chat/c/5?force=1&x=y', $url);
		$this->assertSame(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $options['headers']);
		$this->assertFalse($options['http_errors']);
		$this->assertSame(8, $options['timeout']);
		$this->assertArrayNotHasKey('body', $options);
	}

	public function testAPatchBodyKeepsListKeysAndDropsOnlyFrameworkKeys(): void {
		$this->upstream(200, '{}', 'application/json');

		$this->handler->forwardJsonRequest(
			$this->request('PATCH', '/apps/openregister/api/chat/c/5', ['title' => 'T', '_route' => 'r', 'requesttoken' => 't', 0 => 'first', 'under_score' => 1]),
			'/apps/hermiq/api/chat/c/5'
		);

		$this->assertSame('{"title":"T","0":"first","under_score":1}', $this->requests[0][2]['body']);
	}

	public function testAFailedForwardLogsTheUrlAndReason(): void {
		$this->client->method('request')->willThrowException(new RuntimeException('refused'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[ChatProxyHandler] hermiq forward failed — falling back to local serving',
			['url' => 'https://nc.test/apps/hermiq/api/chat/send?a=1', 'error' => 'refused']
		);

		$this->assertNull($this->handler->forwardJsonRequest($this->request('POST', '/apps/openregister/api/chat/send?a=1'), '/apps/hermiq/api/chat/send'));
	}

	public function testTheProbeHitsTheHealthEndpointWithItsShortTimeoutAndTreats499AsReachable(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(499);
		$this->client->expects($this->once())->method('get')
			->with('https://nc.test/apps/hermiq/api/chat/health', ['timeout' => 3, 'http_errors' => false])
			->willReturn($response);

		$this->assertTrue($this->handler->probeReachable());
	}

	public function testTheProbeTreats500AsUnreachable(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$this->client->method('get')->willReturn($response);

		$this->assertFalse($this->handler->probeReachable());
	}

	public function testAFailedProbeLogsTheHealthUrl(): void {
		$this->client->method('get')->willThrowException(new RuntimeException('dns'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[ChatProxyHandler] hermiq reachability probe failed — falling back to local serving',
			['url' => 'https://nc.test/apps/hermiq/api/chat/health', 'error' => 'dns']
		);

		$this->assertFalse($this->handler->probeReachable());
	}

	public function testARedirectWithoutQueryStringPointsAtTheBarePath(): void {
		$response = $this->handler->buildRedirectResponse($this->request('GET', '/apps/openregister/api/chat/stream'), '/apps/hermiq/api/chat/stream');

		$this->assertSame(308, $response->getStatus());
		$this->assertSame('https://nc.test/apps/hermiq/api/chat/stream', $response->getHeaders()['Location']);
	}

	public function testRewriteKeepsTheRemainderAndRefusesAPathMerelyContainingThePrefix(): void {
		$this->assertSame('/apps/hermiq/api/chat/a/b', $this->handler->rewritePathForHermiq('/apps/openregister/api/chat/a/b'));
		$this->assertNull($this->handler->rewritePathForHermiq('/index.php/apps/openregister/api/chat'));
	}
}
