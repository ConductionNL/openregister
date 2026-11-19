<?php

/**
 * The Compliance area binds its own service interfaces, each once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ComplianceServiceRegistrar;
use OCA\OpenRegister\Service\Gdpr\GdprRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\ComplianceServiceRegistrar
 * @covers \OCA\OpenRegister\Service\ComplianceServiceBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 * @uses   \OCA\OpenRegister\Service\Gdpr\GdprRegistrar
 */
final class ComplianceServiceRegistrarTest extends TestCase {

	/**
	 * The registrar aliases exactly the bindings of its map.
	 *
	 * @return void
	 */
	public function testTheRegistrarAliasesEveryBindingOfItsMap(): void {
		$context = new RecordingRegistrationContext();
		ComplianceServiceRegistrar::register(context: $context);

		$this->assertNotSame(expected: [], actual: ComplianceServiceRegistrar::BINDINGS);
		$this->assertSame(expected: ComplianceServiceRegistrar::BINDINGS, actual: array_column($context->aliases, 'target', 'alias'));
	}//end testTheRegistrarAliasesEveryBindingOfItsMap()

	/**
	 * Every bound class implements its interface.
	 *
	 * @return void
	 */
	public function testEveryBindingIsImplementedByItsClass(): void {
		foreach (ComplianceServiceRegistrar::BINDINGS as $interface => $class) {
			$this->assertTrue(condition: (new ReflectionClass($class))->implementsInterface($interface), message: $class . ' implements ' . $interface);
		}
	}//end testEveryBindingIsImplementedByItsClass()

	/**
	 * ContractBindings no longer binds any of these interfaces.
	 *
	 * @return void
	 */
	public function testContractBindingsDoesNotBindThemAgain(): void {
		$twice = array_intersect_key(ComplianceServiceRegistrar::BINDINGS, ContractBindings::BINDINGS);
		$this->assertSame(expected: [], actual: $twice);
	}//end testContractBindingsDoesNotBindThemAgain()

	/**
	 * registerGdpr() registers exactly what the GDPR registrar registers, in its order.
	 *
	 * @return void
	 */
	public function testRegisterGdprRegistersTheGdprServices(): void {
		$entry = new RecordingRegistrationContext();
		ComplianceServiceRegistrar::registerGdpr(context: $entry);
		$direct = new RecordingRegistrationContext();
		GdprRegistrar::register(context: $direct);

		$this->assertNotSame(expected: [], actual: $entry->services);
		$this->assertSame(expected: $direct->services, actual: $entry->services);
	}//end testRegisterGdprRegistersTheGdprServices()
}//end class
