<?php

/**
 * AddressAllowlistMiddlewareTest — which surfaces a route reaches, and the blank refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Kernel\Contracts\AddressAllowlistsInterface;
use OCA\OpenRegister\Middleware\AddressAllowlistMiddleware;
use OCA\OpenRegister\Middleware\AddressNotAllowedException;
use OCA\OpenRegister\Middleware\InboundCall;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A controller with one route of each kind.
 */
class AddressAllowlistFixtureController extends Controller {

	/**
	 * An administrator-only route.
	 *
	 * @return void
	 */
	public function adminOnly(): void {
	}

	/**
	 * A route any signed-in user reaches.
	 *
	 * @return void
	 */
	#[NoAdminRequired]
	public function forUsers(): void {
	}

	/**
	 * A public route.
	 *
	 * @return void
	 */
	#[PublicPage]
	public function forEveryone(): void {
	}
}

/**
 * @covers \OCA\OpenRegister\Middleware\AddressAllowlistMiddleware
 * @covers \OCA\OpenRegister\Middleware\AddressNotAllowedException
 * @uses   \OCA\OpenRegister\Middleware\InboundCall
 * @uses   \OCA\OpenRegister\Middleware\DispatchedMethod
 */
class AddressAllowlistMiddlewareTest extends TestCase {

	/**
	 * The surfaces and addresses the policy was asked about.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * The log lines written.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A middleware over a request from an address to a path, and a policy allowing the given surfaces.
	 *
	 * @param string             $address     The remote address.
	 * @param string             $path        The path info.
	 * @param array<int, string> $refusing    The surfaces whose policy refuses this address.
	 * @param array<int, string> $annotations The docblock annotations the route carries.
	 *
	 * @return AddressAllowlistMiddleware The middleware.
	 */
	private function middleware(string $address, string $path, array $refusing = [], array $annotations = []): AddressAllowlistMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn($address);
		$request->method('getPathInfo')->willReturn($path);

		$reflector = $this->createMock(IControllerMethodReflector::class);
		$reflector->method('hasAnnotation')->willReturnCallback(
			static fn (string $name): bool => in_array($name, $annotations, true)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		$policy = $this->createMock(AddressAllowlistsInterface::class);
		$policy->method('allowsAddress')->willReturnCallback(
			function (string $surface, string $asked) use ($refusing): bool {
				$this->asked[] = $surface.'@'.$asked;
				return in_array($surface, $refusing, true) === false;
			}
		);

		return new AddressAllowlistMiddleware(
			new InboundCall($request, $this->createMock(IUserSession::class), $reflector, $logger),
			$policy
		);
	}

	/**
	 * The fixture controller.
	 *
	 * @return AddressAllowlistFixtureController The controller.
	 */
	private function controller(): AddressAllowlistFixtureController {
		return new AddressAllowlistFixtureController('openregister', $this->createMock(IRequest::class));
	}

	/**
	 * An administrator-only API route reaches both surfaces, and both are asked with the request address.
	 *
	 * @return void
	 */
	public function testAnAdminApiRouteAsksBothSurfaces(): void {
		$this->middleware(address: '192.0.2.5', path: '/apps/openregister/api/hardening/controls')
			->beforeController($this->controller(), 'adminOnly');

		self::assertSame(['admin@192.0.2.5', 'api@192.0.2.5'], $this->asked);
	}

	/**
	 * A route open to users or to everyone is not on the administration surface, by attribute or annotation.
	 *
	 * @return void
	 */
	public function testAnOpenRouteIsNotOnTheAdministrationSurface(): void {
		$this->middleware(address: '192.0.2.5', path: '/apps/openregister/api/objects', refusing: ['admin'])
			->beforeController($this->controller(), 'forUsers');
		$this->middleware(address: '192.0.2.5', path: '/apps/openregister/api/objects', refusing: ['admin'])
			->beforeController($this->controller(), 'forEveryone');
		$this->middleware(address: '192.0.2.5', path: '/apps/openregister/', refusing: ['admin'], annotations: ['NoAdminRequired'])
			->beforeController($this->controller(), 'adminOnly');

		self::assertSame(['api@192.0.2.5', 'api@192.0.2.5'], $this->asked, 'only the API was asked, and only for the API paths');
	}

	/**
	 * An administrator-only page outside the API reaches the administration surface only.
	 *
	 * @return void
	 */
	public function testAnAdminPageOutsideTheApiAsksTheAdministrationSurfaceOnly(): void {
		$this->middleware(address: '2001:db8::1', path: '/apps/openregister/settings')
			->beforeController($this->controller(), 'adminOnly');

		self::assertSame(['admin@2001:db8::1'], $this->asked);
	}

	/**
	 * 🔴 A refused address gets a bare 403 naming no address, list or range; the log says which surface.
	 *
	 * @return void
	 */
	public function testARefusalIsBlankAndLogged(): void {
		$middleware = $this->middleware(address: '203.0.113.9', path: '/apps/openregister/api/registers', refusing: ['api']);

		try {
			$middleware->beforeController($this->controller(), 'forUsers');
			self::fail('the request went on');
		} catch (AddressNotAllowedException $refused) {
			self::assertSame('api', $refused->surface);
			$response = $middleware->afterException($this->controller(), 'forUsers', $refused);
		}

		self::assertSame(403, $response->getStatus());
		self::assertSame(['message' => 'Access forbidden'], $response->getData());
		$body = json_encode($response->getData());
		self::assertStringNotContainsString('203.0.113', (string)$body);
		self::assertStringNotContainsString('api', (string)$body);
		self::assertCount(1, $this->logged);
		self::assertSame(['address' => '203.0.113.9', 'surface' => 'api'], $this->logged[0][1]);
	}

	/**
	 * The administration surface refuses first when both would.
	 *
	 * @return void
	 */
	public function testTheAdministrationSurfaceRefusesFirst(): void {
		$this->expectException(AddressNotAllowedException::class);

		try {
			$this->middleware(address: '203.0.113.9', path: '/apps/openregister/api/x', refusing: ['admin', 'api'])
				->beforeController($this->controller(), 'adminOnly');
		} finally {
			self::assertSame(['admin@203.0.113.9'], $this->asked);
		}
	}

	/**
	 * Another middleware's exception is passed on untouched.
	 *
	 * @return void
	 */
	public function testAnotherExceptionIsNotOurs(): void {
		$other = new \RuntimeException('not ours');
		$this->expectExceptionObject($other);

		$this->middleware(address: '192.0.2.1', path: '/')->afterException($this->controller(), 'adminOnly', $other);
	}
}
