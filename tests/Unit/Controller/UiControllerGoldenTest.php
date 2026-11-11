<?php

/**
 * Golden tests for UiController.
 *
 * Every public route method serves the same SPA shell: the `index` template
 * of this app, no params, status 200, and a CSP whose connect-src allows
 * every domain. Pins that so a refactor can be proven behaviour-preserving.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\UiController;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden tests for UiController.
 */
class UiControllerGoldenTest extends TestCase {
	/**
	 * The public route methods UiController declares itself.
	 *
	 * @return list<string>
	 */
	private function routeMethods(): array {
		$names = [];
		foreach ((new ReflectionClass(UiController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === UiController::class && $method->isConstructor() === false) {
				$names[] = $method->getName();
			}
		}

		return $names;
	}

	public function testThereAreRouteMethodsToPin(): void {
		$this->assertCount(27, $this->routeMethods());
	}

	public function testEveryRouteServesTheSpaShell(): void {
		$expectedCsp = new ContentSecurityPolicy();
		$expectedCsp->addAllowedConnectDomain('*');
		$controller = new UiController('openregister', $this->createMock(IRequest::class));

		foreach ($this->routeMethods() as $name) {
			$response = $controller->$name();
			$this->assertInstanceOf(TemplateResponse::class, $response, $name);
			$this->assertSame('openregister', $response->getApp(), $name);
			$this->assertSame('index', $response->getTemplateName(), $name);
			$this->assertSame([], $response->getParams(), $name);
			$this->assertSame(200, $response->getStatus(), $name);
			$this->assertSame($expectedCsp->buildPolicy(), $response->getContentSecurityPolicy()->buildPolicy(), $name);
		}
	}

	public function testTheShellUsesTheInjectedAppName(): void {
		$response = (new UiController('otherapp', $this->createMock(IRequest::class)))->registers();

		$this->assertSame('otherapp', $response->getApp());
	}
}
