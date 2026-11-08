<?php

/**
 * The principal-reach interface lane E17 added are bound and implemented.
 *
 * Each interface here was added next to a class that a lib file in another
 * component named directly. The outside caller now types against the
 * interface; the component's registrar aliases it to the same class, so every
 * caller still gets the one shared instance. A missing alias is a runtime 500
 * that no other unit test sees, so this test pins the binding and the
 * implementation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Rbac\PrincipalReachListerInterface;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class E17ReachInterfaceBindingsTest extends TestCase {

	/**
	 * Interface => [the class it is bound to, the registrar that binds it].
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const BINDINGS = [
		PrincipalReachListerInterface::class => [PrincipalReachService::class, ContractBindings::class],
	];

	/**
	 * One case per interface.
	 *
	 * @return array<string, array{class-string, class-string, class-string}>
	 */
	public static function bindings(): array {
		$cases = [];
		foreach (self::BINDINGS as $interface => [$class, $registrar]) {
			$cases[$interface] = [$interface, $class, $registrar];
		}

		return $cases;
	}//end bindings()

	/**
	 * The class implements the interface.
	 *
	 * @param class-string $interface The interface.
	 * @param class-string $class     The class it is bound to.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheClassImplementsTheInterface(string $interface, string $class): void {
		$this->assertTrue(interface_exists($interface), $interface . ' exists');
		$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
	}//end testTheClassImplementsTheInterface()

	/**
	 * The registrar binds the interface to the class.
	 *
	 * @param class-string $interface The interface.
	 * @param class-string $class     The class it is bound to.
	 * @param class-string $registrar The registrar of the class's component.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheRegistrarBindsTheInterface(string $interface, string $class, string $registrar): void {
		$this->assertSame($class, constant($registrar . '::BINDINGS')[$interface] ?? null, $interface . ' is bound in ' . $registrar);
	}//end testTheRegistrarBindsTheInterface()
}//end class
