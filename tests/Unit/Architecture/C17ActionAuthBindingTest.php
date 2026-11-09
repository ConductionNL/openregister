<?php

/**
 * C17ActionAuthBindingTest: the action-auth matrix is reached through its Kernel contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCA\OpenRegister\Kernel\Contracts\ActionAuthInterface;
use OCA\OpenRegister\Middleware\MiddlewareRegistrar;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Both action-auth services implement the Kernel contract, the Platform
 * registrar binds it to OpenRegister's own matrix, and the services that
 * check an action type against the contract (ADR-013 Rule 1 and 3).
 */
class C17ActionAuthBindingTest extends TestCase {

	/**
	 * The services that check an action and must not name the AppHost or Platform class.
	 *
	 * @var list<string>
	 */
	private const CALLERS = [
		'Service/Flow/FlowAccess.php',
		'Service/Object/CorrectionService.php',
	];

	/**
	 * Both implementations satisfy the contract.
	 *
	 * @return void
	 */
	public function testBothServicesImplementTheContract(): void {
		$this->assertContains(ActionAuthInterface::class, class_implements(GenericActionAuthService::class));
		$this->assertContains(ActionAuthInterface::class, class_implements(OpenRegisterActionAuthService::class));
	}//end testBothServicesImplementTheContract()

	/**
	 * The Platform registrar binds the contract to OpenRegister's own matrix.
	 *
	 * @return void
	 */
	public function testThePlatformRegistrarBindsTheContract(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		MiddlewareRegistrar::register(context: $context);

		$this->assertSame(OpenRegisterActionAuthService::class, $aliases[ActionAuthInterface::class] ?? null);
	}//end testThePlatformRegistrarBindsTheContract()

	/**
	 * The checking services type against the contract only.
	 *
	 * @return void
	 */
	public function testTheCallersNameOnlyTheContract(): void {
		$naming = [];
		foreach (self::CALLERS as $relative) {
			$source = (string) file_get_contents(__DIR__ . '/../../../lib/' . $relative);
			if (preg_match('/\b(OpenRegisterActionAuthService|GenericActionAuthService)\b/', $source) === 1) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming);
	}//end testTheCallersNameOnlyTheContract()
}//end class
