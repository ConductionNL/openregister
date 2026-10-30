<?php

/**
 * Every Db contract binding points at a mapper that implements the contract.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DbContractBindings;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

class DbContractBindingsTest extends TestCase {

	/**
	 * Each bound mapper implements the contract it is bound to.
	 *
	 * The container hands the mapper out wherever the contract is asked
	 * for, so a mapper that does not implement it fails at the first
	 * request instead of at boot.
	 *
	 * @return void
	 */
	public function testEveryMapperImplementsItsContract(): void {
		$wrong = [];
		foreach (DbContractBindings::BINDINGS as $contract => $mapper) {
			if (interface_exists($contract) === false || is_subclass_of($mapper, $contract) === false) {
				$wrong[] = $contract . ' => ' . $mapper;
			}
		}

		$this->assertSame(expected: [], actual: $wrong);
	}//end testEveryMapperImplementsItsContract()

	/**
	 * register() aliases every contract to its mapper, and nothing else.
	 *
	 * @return void
	 */
	public function testRegisterAliasesEveryContract(): void {
		$context = new RecordingRegistrationContext();
		DbContractBindings::register(context: $context);

		$aliases = [];
		foreach ($context->aliases as $alias) {
			$aliases[$alias['alias']] = $alias['target'];
		}

		$this->assertSame(expected: DbContractBindings::BINDINGS, actual: $aliases);
		$this->assertSame(expected: [], actual: $context->services);
	}//end testRegisterAliasesEveryContract()
}//end class
