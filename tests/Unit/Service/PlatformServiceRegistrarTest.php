<?php

/**
 * The Platform area binds its own service interfaces, each once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\PlatformServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\PlatformServiceRegistrar
 * @covers \OCA\OpenRegister\Service\PlatformServiceBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 */
final class PlatformServiceRegistrarTest extends TestCase {

	/**
	 * The registrar aliases exactly the bindings of its map.
	 *
	 * @return void
	 */
	public function testTheRegistrarAliasesEveryBindingOfItsMap(): void {
		$context = new RecordingRegistrationContext();
		PlatformServiceRegistrar::register(context: $context);

		$this->assertNotSame(expected: [], actual: PlatformServiceRegistrar::BINDINGS);
		$this->assertSame(expected: PlatformServiceRegistrar::BINDINGS + PlatformServiceRegistrar::LISTENER_BINDINGS, actual: array_column($context->aliases, 'target', 'alias'));
	}//end testTheRegistrarAliasesEveryBindingOfItsMap()

	/**
	 * Every bound class implements its interface.
	 *
	 * @return void
	 */
	public function testEveryBindingIsImplementedByItsClass(): void {
		foreach (PlatformServiceRegistrar::BINDINGS + PlatformServiceRegistrar::LISTENER_BINDINGS as $interface => $class) {
			$this->assertTrue(condition: (new ReflectionClass($class))->implementsInterface($interface), message: $class . ' implements ' . $interface);
		}
	}//end testEveryBindingIsImplementedByItsClass()

	/**
	 * ContractBindings no longer binds any of these interfaces.
	 *
	 * @return void
	 */
	public function testContractBindingsDoesNotBindThemAgain(): void {
		$twice = array_intersect_key(PlatformServiceRegistrar::BINDINGS + PlatformServiceRegistrar::LISTENER_BINDINGS, ContractBindings::BINDINGS);
		$this->assertSame(expected: [], actual: $twice);
	}//end testContractBindingsDoesNotBindThemAgain()
}//end class
