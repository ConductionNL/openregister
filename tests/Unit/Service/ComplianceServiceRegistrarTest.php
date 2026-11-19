<?php

/**
 * The Compliance area binds its own service interfaces, each once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\ReadPurposeGuardInterface;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\ComplianceServiceRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\ComplianceServiceRegistrar
 * @covers \OCA\OpenRegister\Service\ComplianceServiceBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
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
	 * The Core read purpose-guard port, which relation reads ask per table, is answered by the doelbinding guard.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function testTheReadPurposeGuardPortIsAnsweredByThePurposeGuard(): void {
		$this->assertSame(expected: PurposeGuard::class, actual: (ComplianceServiceRegistrar::BINDINGS[ReadPurposeGuardInterface::class] ?? null));
	}//end testTheReadPurposeGuardPortIsAnsweredByThePurposeGuard()
}//end class
