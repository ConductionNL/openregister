<?php

/**
 * PublicApiCorsMiddlewareTest — who may read a public answer from a browser.
 *
 * Three things have to stay true together, and dropping any one of them is
 * silent: an empty allowlist keeps reflecting, as this instance did before the
 * control existed; a configured allowlist refuses everything not on it; and the
 * refusal adds no header and changes no body, so it names neither the allowlist
 * nor anything on it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\PublicApiCorsMiddleware;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\PublicApiCorsMiddleware
 */
class PublicApiCorsMiddlewareTest extends TestCase {

	/**
	 * Run one response through the middleware.
	 *
	 * @param string $origin The Origin header on the request.
	 * @param string $allowlist The stored allowlist.
	 * @param bool $public Whether the controller method is a public page.
	 *
	 * @return Response The response as the middleware left it.
	 */
	private function pass(string $origin, string $allowlist = '', bool $public = true): Response {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Origin' ? $origin : '')
		);

		$reflector = $this->createMock(IControllerMethodReflector::class);
		$reflector->method('hasAnnotation')->willReturn($public);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($allowlist): string {
				if ($key === HardeningPolicy::ORIGINS_KEY) {
					return $allowlist;
				}

				return $default;
			}
		);

		$middleware = new PublicApiCorsMiddleware($request, $reflector, new HardeningPolicy($appConfig));

		return $middleware->afterController(
			$this->createMock(Controller::class),
			'index',
			new Response()
		);
	}

	public function testAnEmptyAllowlistKeepsReflectingWhicheverOriginAsks(): void {
		$response = $this->pass(origin: 'https://anything.example');

		$this->assertSame('https://anything.example', $response->getHeaders()['Access-Control-Allow-Origin']);
	}

	public function testAnAllowlistedOriginIsReflected(): void {
		$response = $this->pass(origin: 'https://example.nl', allowlist: 'https://example.nl,https://other.nl');

		$this->assertSame('https://example.nl', $response->getHeaders()['Access-Control-Allow-Origin']);
	}

	public function testAnOriginOffTheAllowlistGetsNoHeaderAndNoNames(): void {
		$response = $this->pass(origin: 'https://attacker.example', allowlist: 'https://example.nl');
		$headers = $response->getHeaders();

		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
		$this->assertStringNotContainsString('example.nl', implode(' ', array_map('strval', $headers)));
	}

	public function testTheAllowlistIsMatchedWithoutRegardToCaseOrSpacing(): void {
		$response = $this->pass(origin: ' HTTPS://Example.NL ', allowlist: 'https://example.nl');

		$this->assertArrayHasKey('Access-Control-Allow-Origin', $response->getHeaders());
	}

	public function testANonPublicMethodIsNeverGivenAReflectedOrigin(): void {
		$response = $this->pass(origin: 'https://example.nl', allowlist: 'https://example.nl', public: false);

		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->getHeaders());
	}

	public function testARequestWithNoOriginIsLeftAlone(): void {
		$response = $this->pass(origin: '');

		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->getHeaders());
	}
}
