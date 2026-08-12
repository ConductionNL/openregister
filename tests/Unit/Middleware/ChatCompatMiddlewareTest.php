<?php

/**
 * ChatCompatMiddlewareTest — or-chat-proxy-deprecation orchestration.
 *
 * Covers: deprecation headers on every chat-family response, the proxy
 * short-circuit (JSON forward + streaming redirect), the hermiq-unreachable
 * fallback, and that non-chat controllers / the AgentsController page shell
 * are left untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/or-chat-proxy-deprecation/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Controller\AgentsController;
use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Middleware\ChatCompatMiddleware;
use OCA\OpenRegister\Middleware\Exception\ChatProxiedResponseException;
use OCA\OpenRegister\Service\Chat\ChatProxyHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\ChatCompatMiddleware
 */
class ChatCompatMiddlewareTest extends TestCase {

	/**
	 * @return array{0: ChatCompatMiddleware, 1: ChatProxyHandler&MockObject, 2: IRequest&MockObject}
	 */
	private function build(string $pathInfo = '/apps/openregister/api/chat/send'): array {
		$proxyHandler = $this->createMock(ChatProxyHandler::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($pathInfo);

		$middleware = new ChatCompatMiddleware($request, $proxyHandler);

		return [$middleware, $proxyHandler, $request];
	}//end build()

	// --- Deprecation headers -------------------------------------------------

	public function testAfterControllerAddsDeprecationHeadersOnChatController(): void {
		[$middleware] = $this->build();
		$controller = $this->createMock(ChatController::class);
		$response = new JSONResponse(['ok' => true]);

		$decorated = $middleware->afterController($controller, 'sendMessage', $response);

		$this->assertSame('Mon, 06 Jul 2026 00:00:00 GMT', $decorated->getHeaders()['Deprecation']);
		$this->assertSame('Wed, 06 Jan 2027 00:00:00 GMT', $decorated->getHeaders()['Sunset']);
		$this->assertSame('</apps/hermiq/api/chat>; rel="successor-version"', $decorated->getHeaders()['Link']);
	}//end testAfterControllerAddsDeprecationHeadersOnChatController()

	public function testAfterControllerLeavesNonChatControllerResponseUntouched(): void {
		[$middleware] = $this->build();
		$controller = $this->createMock(Controller::class);
		$response = new JSONResponse(['ok' => true]);

		$decorated = $middleware->afterController($controller, 'index', $response);

		$this->assertArrayNotHasKey('Deprecation', $decorated->getHeaders());
		$this->assertArrayNotHasKey('Sunset', $decorated->getHeaders());
		$this->assertArrayNotHasKey('Link', $decorated->getHeaders());
	}//end testAfterControllerLeavesNonChatControllerResponseUntouched()

	// --- Proxy off / not configured -----------------------------------------

	public function testBeforeControllerDoesNothingWhenProxyNotConfigured(): void {
		[$middleware, $proxyHandler] = $this->build();
		$proxyHandler->method('isProxyConfigured')->willReturn(false);
		$proxyHandler->expects($this->never())->method('isHermiqInstalled');

		$controller = $this->createMock(ChatController::class);
		$middleware->beforeController($controller, 'sendMessage');

		$this->addToAssertionCount(1);
	}//end testBeforeControllerDoesNothingWhenProxyNotConfigured()

	public function testBeforeControllerDoesNothingForNonChatController(): void {
		[$middleware, $proxyHandler] = $this->build();
		$proxyHandler->expects($this->never())->method('isProxyConfigured');

		$controller = $this->createMock(Controller::class);
		$middleware->beforeController($controller, 'index');

		$this->addToAssertionCount(1);
	}//end testBeforeControllerDoesNothingForNonChatController()

	public function testBeforeControllerSkipsTheAgentsPageShellMethod(): void {
		[$middleware, $proxyHandler] = $this->build();
		$proxyHandler->expects($this->never())->method('isProxyConfigured');

		$controller = $this->createMock(AgentsController::class);
		$middleware->beforeController($controller, 'page');

		$this->addToAssertionCount(1);
	}//end testBeforeControllerSkipsTheAgentsPageShellMethod()

	// --- Proxy on: JSON forward ----------------------------------------------

	public function testBeforeControllerThrowsProxiedResponseWhenForwardSucceeds(): void {
		[$middleware, $proxyHandler, $request] = $this->build();
		$proxyHandler->method('isProxyConfigured')->willReturn(true);
		$proxyHandler->method('isHermiqInstalled')->willReturn(true);
		$proxyHandler->method('rewritePathForHermiq')->with('/apps/openregister/api/chat/send')
			->willReturn('/apps/hermiq/api/chat/send');

		$proxiedResponse = new JSONResponse(['conversation' => 'abc']);
		$proxyHandler->expects($this->once())->method('forwardJsonRequest')
			->with($request, '/apps/hermiq/api/chat/send')
			->willReturn($proxiedResponse);

		$controller = $this->createMock(ChatController::class);

		try {
			$middleware->beforeController($controller, 'sendMessage');
			$this->fail('Expected ChatProxiedResponseException to be thrown.');
		} catch (ChatProxiedResponseException $e) {
			$this->assertSame($proxiedResponse, $e->getResponse());
		}
	}//end testBeforeControllerThrowsProxiedResponseWhenForwardSucceeds()

	public function testBeforeControllerFallsBackLocallyWhenForwardFails(): void {
		[$middleware, $proxyHandler] = $this->build();
		$proxyHandler->method('isProxyConfigured')->willReturn(true);
		$proxyHandler->method('isHermiqInstalled')->willReturn(true);
		$proxyHandler->method('rewritePathForHermiq')->willReturn('/apps/hermiq/api/chat/send');
		$proxyHandler->method('forwardJsonRequest')->willReturn(null);

		$controller = $this->createMock(ChatController::class);

		// No exception — falls through to local serving.
		$middleware->beforeController($controller, 'sendMessage');
		$this->addToAssertionCount(1);
	}//end testBeforeControllerFallsBackLocallyWhenForwardFails()

	public function testBeforeControllerFallsBackLocallyWhenHermiqNotInstalled(): void {
		[$middleware, $proxyHandler] = $this->build();
		$proxyHandler->method('isProxyConfigured')->willReturn(true);
		$proxyHandler->method('isHermiqInstalled')->willReturn(false);
		$proxyHandler->expects($this->never())->method('forwardJsonRequest');

		$controller = $this->createMock(ChatController::class);
		$middleware->beforeController($controller, 'sendMessage');

		$this->addToAssertionCount(1);
	}//end testBeforeControllerFallsBackLocallyWhenHermiqNotInstalled()

	// --- Proxy on: streaming redirect ----------------------------------------

	public function testBeforeControllerRedirectsStreamingControllerWhenReachable(): void {
		[$middleware, $proxyHandler, $request] = $this->build(pathInfo: '/apps/openregister/api/chat/stream');
		$proxyHandler->method('isProxyConfigured')->willReturn(true);
		$proxyHandler->method('isHermiqInstalled')->willReturn(true);
		$proxyHandler->method('rewritePathForHermiq')->willReturn('/apps/hermiq/api/chat/stream');
		$proxyHandler->method('probeReachable')->willReturn(true);

		$redirectResponse = new Response(308, ['Location' => 'https://cloud.example.test/apps/hermiq/api/chat/stream']);
		$proxyHandler->expects($this->once())->method('buildRedirectResponse')
			->with($request, '/apps/hermiq/api/chat/stream')
			->willReturn($redirectResponse);
		$proxyHandler->expects($this->never())->method('forwardJsonRequest');

		$controller = $this->createMock(ChatStreamController::class);

		try {
			$middleware->beforeController($controller, 'stream');
			$this->fail('Expected ChatProxiedResponseException to be thrown.');
		} catch (ChatProxiedResponseException $e) {
			$this->assertSame($redirectResponse, $e->getResponse());
		}
	}//end testBeforeControllerRedirectsStreamingControllerWhenReachable()

	public function testBeforeControllerFallsBackLocallyWhenStreamingProbeUnreachable(): void {
		[$middleware, $proxyHandler] = $this->build(pathInfo: '/apps/openregister/api/chat/stream');
		$proxyHandler->method('isProxyConfigured')->willReturn(true);
		$proxyHandler->method('isHermiqInstalled')->willReturn(true);
		$proxyHandler->method('rewritePathForHermiq')->willReturn('/apps/hermiq/api/chat/stream');
		$proxyHandler->method('probeReachable')->willReturn(false);
		$proxyHandler->expects($this->never())->method('buildRedirectResponse');

		$controller = $this->createMock(ChatStreamController::class);
		$middleware->beforeController($controller, 'stream');

		$this->addToAssertionCount(1);
	}//end testBeforeControllerFallsBackLocallyWhenStreamingProbeUnreachable()

	// --- afterException -------------------------------------------------------

	public function testAfterExceptionReturnsCarriedResponse(): void {
		[$middleware] = $this->build();
		$controller = $this->createMock(ChatController::class);
		$response = new JSONResponse(['proxied' => true]);
		$exception = new ChatProxiedResponseException($response);

		$result = $middleware->afterException($controller, 'sendMessage', $exception);

		$this->assertSame($response, $result);
	}//end testAfterExceptionReturnsCarriedResponse()

	public function testAfterExceptionRethrowsOtherExceptions(): void {
		[$middleware] = $this->build();
		$controller = $this->createMock(ChatController::class);
		$exception = new \Exception('something else went wrong');

		$this->expectExceptionMessage('something else went wrong');
		$middleware->afterException($controller, 'sendMessage', $exception);
	}//end testAfterExceptionRethrowsOtherExceptions()
}//end class
