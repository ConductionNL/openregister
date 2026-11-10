<?php

/**
 * PublicApiCorsMiddlewareBehaviourTest — a failure while deciding never breaks the response.
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

use OCA\OpenRegister\Middleware\InboundCall;
use OCA\OpenRegister\Middleware\PublicApiCorsMiddleware;
use OCA\OpenRegister\Service\Hardening\AllowedOriginsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\PublicApiCorsMiddleware
 */
class PublicApiCorsMiddlewareBehaviourTest extends TestCase {

	private function middleware(AllowedOriginsInterface $policy, IControllerMethodReflector $reflector): PublicApiCorsMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('https://a.example');

		return new PublicApiCorsMiddleware(new InboundCall($request, $this->createMock(IUserSession::class), $reflector, $this->createMock(LoggerInterface::class)), $policy);
	}

	public function testAPolicyFailureReturnsTheSameResponseWithoutCorsHeaders(): void {
		$policy = $this->createMock(AllowedOriginsInterface::class);
		$policy->method('allowedOrigins')->willThrowException(new RuntimeException('config down'));
		$reflector = $this->createMock(IControllerMethodReflector::class);
		$reflector->method('hasAnnotation')->willReturn(true);
		$response = new Response();

		$out = $this->middleware($policy, $reflector)->afterController($this->createMock(Controller::class), 'index', $response);

		$this->assertSame($response, $out);
		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $out->getHeaders());
	}

	public function testTheMethodIsReflectedBeforeTheAnnotationIsRead(): void {
		$policy = $this->createMock(AllowedOriginsInterface::class);
		$policy->method('allowedOrigins')->willReturn(['https://a.example']);
		$controller = $this->createMock(Controller::class);
		$reflector = $this->createMock(PublicApiCorsProbeReflector::class);
		$reflector->expects($this->once())->method('reflect')->with($controller, 'show');
		$reflector->expects($this->once())->method('hasAnnotation')->with('PublicPage')->willReturn(true);

		$out = $this->middleware($policy, $reflector)->afterController($controller, 'show', new Response());

		$this->assertSame('https://a.example', $out->getHeaders()['Access-Control-Allow-Origin']);
		$this->assertSame('GET, POST, OPTIONS', $out->getHeaders()['Access-Control-Allow-Methods']);
		$this->assertSame('Content-Type', $out->getHeaders()['Access-Control-Allow-Headers']);
	}
}

/**
 * The reflector as Nextcloud implements it: the interface plus reflect().
 */
abstract class PublicApiCorsProbeReflector implements IControllerMethodReflector {

	/**
	 * Reflect a controller method.
	 *
	 * @param object $object Controller.
	 * @param string $method Method name.
	 *
	 * @return void
	 */
	abstract public function reflect($object, string $method);
}
