<?php

/**
 * The dependency endpoints: the overview, the reset, its 404, and that both stay administrator-only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OperationsDependenciesController;
use OCA\OpenRegister\Service\Resilience\DependencyConsole;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Controller\OperationsDependenciesController
 */
class OperationsDependenciesControllerTest extends TestCase {

	private function controller(DependencyConsole $console): OperationsDependenciesController {
		return new OperationsDependenciesController(request: $this->createMock(IRequest::class), console: $console);
	}

	public function testIndexAnswersTheOverview(): void {
		$overview = ['dependencies' => [['key' => 'llm', 'state' => 'open']], 'pressure' => ['underPressure' => false], 'shared' => true];
		$console  = $this->createMock(DependencyConsole::class);
		$console->expects($this->once())->method('overview')->willReturn($overview);

		$response = $this->controller($console)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($overview, $response->getData());
	}

	public function testResetAnswersTheClosedState(): void {
		$console = $this->createMock(DependencyConsole::class);
		$console->expects($this->once())->method('reset')->with('source:12')->willReturn(['key' => 'source:12', 'state' => 'closed']);

		$response = $this->controller($console)->reset('source:12');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('closed', $response->getData()['state']);
	}

	public function testResetOfAKeyWithoutABreakerIs404(): void {
		$console = $this->createMock(DependencyConsole::class);
		$console->method('reset')->willReturn(null);

		$response = $this->controller($console)->reset('llm');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No breaker with that key.'], $response->getData());
	}

	public function testBothMethodsAreAdministratorOnlyAndTheResetKeepsCsrf(): void {
		foreach (['index', 'reset'] as $method) {
			$reflection = new ReflectionMethod(OperationsDependenciesController::class, $method);
			$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class), $method . ' must stay administrator-only.');
			$this->assertStringNotContainsString('@NoAdminRequired', (string) $reflection->getDocComment());
		}

		$this->assertSame([], (new ReflectionMethod(OperationsDependenciesController::class, 'reset'))->getAttributes(NoCSRFRequired::class));
	}
}
