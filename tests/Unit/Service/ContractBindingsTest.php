<?php

/**
 * Every contract binding points at a service that implements the contract.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

class ContractBindingsTest extends TestCase {

	/**
	 * Each bound service implements the contract it is bound to.
	 *
	 * The container hands the service out wherever the contract is asked
	 * for, so a service that does not implement it fails at the first
	 * request instead of at boot.
	 *
	 * @return void
	 */
	public function testEveryServiceImplementsItsContract(): void {
		$wrong = [];
		foreach (ContractBindings::BINDINGS as $contract => $service) {
			if (interface_exists($contract) === false || is_subclass_of($service, $contract) === false) {
				$wrong[] = $contract . ' => ' . $service;
			}
		}

		$this->assertSame(expected: [], actual: $wrong);
	}//end testEveryServiceImplementsItsContract()

	/**
	 * register() aliases every contract to its service, and nothing else.
	 *
	 * @return void
	 */
	public function testRegisterAliasesEveryContract(): void {
		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);

		$aliases = [];
		foreach ($context->aliases as $alias) {
			$aliases[$alias['alias']] = $alias['target'];
		}

		$this->assertSame(expected: ContractBindings::BINDINGS, actual: $aliases);
		$this->assertSame(expected: [], actual: $context->services);
	}//end testRegisterAliasesEveryContract()
}//end class
