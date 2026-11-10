<?php

/**
 * ChatCompatMiddlewareBehaviourTest — the edges of proxy eligibility.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Middleware\ChatCompatMiddleware;
use OCA\OpenRegister\Service\Chat\ChatProxyHandlerInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Middleware\ChatCompatMiddleware
 */
class ChatCompatMiddlewareBehaviourTest extends TestCase {

	public function testAnUnresolvablePathFallsBackLocallyWithoutRewriting(): void {
		$proxy = $this->createMock(ChatProxyHandlerInterface::class);
		$proxy->method('isProxyConfigured')->willReturn(true);
		$proxy->method('isHermiqInstalled')->willReturn(true);
		$proxy->expects($this->never())->method('rewritePathForHermiq');
		$proxy->expects($this->never())->method('forwardJsonRequest');
		$request = $this->createMock(IRequest::class);
		$request->expects($this->once())->method('getPathInfo')->willReturn(false);

		(new ChatCompatMiddleware($request, $proxy))->beforeController($this->createMock(ChatController::class), 'sendMessage');
	}

	public function testAResolvablePathIsRewrittenAndForwarded(): void {
		$proxy = $this->createMock(ChatProxyHandlerInterface::class);
		$proxy->method('isProxyConfigured')->willReturn(true);
		$proxy->method('isHermiqInstalled')->willReturn(true);
		$proxy->expects($this->once())->method('rewritePathForHermiq')->with('/api/chat/send')->willReturn(null);
		$proxy->expects($this->never())->method('forwardJsonRequest');
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/api/chat/send');

		(new ChatCompatMiddleware($request, $proxy))->beforeController($this->createMock(ChatController::class), 'sendMessage');
	}

	public function testANonControllerObjectIsNeitherProxiedNorDecorated(): void {
		$proxy = $this->createMock(ChatProxyHandlerInterface::class);
		$proxy->expects($this->never())->method('isProxyConfigured');
		$middleware = new ChatCompatMiddleware($this->createMock(IRequest::class), $proxy);

		$middleware->beforeController(new stdClass(), 'sendMessage');
		$response = $middleware->afterController(new stdClass(), 'sendMessage', new JSONResponse([]));

		$this->assertArrayNotHasKey('Deprecation', $response->getHeaders());
	}
}
