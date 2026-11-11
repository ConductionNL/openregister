<?php

/**
 * The objects-core contracts lane G10 moved outside callers onto are bound.
 *
 * The deleted-object access bundle reaches RenderObject through
 * RenderObjectInterface, and the destruction check and destruction run reach
 * SaveObject through SaveObjectWriterInterface. A missing alias is a 500 (or a
 * failed background job) that no other unit test sees, so each contract is
 * pinned here: the class implements it, the registrar binds it to that class,
 * and the binding is registered as an alias so the contract resolves to the
 * same shared instance as the class.
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
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RenderObjectInterface;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class G10InterfaceBindingsTest extends TestCase {

	/**
	 * One row per contract: the contract and the class it resolves to.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function contracts(): array {
		return [
			'render object' => [RenderObjectInterface::class, RenderObject::class],
			'save writer'   => [SaveObjectWriterInterface::class, SaveObject::class],
		];
	}//end contracts()

	/**
	 * The class implements the contract.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $class    The class it resolves to.
	 *
	 * @return void
	 */
	#[DataProvider('contracts')]
	public function testTheClassImplementsTheContract(string $contract, string $class): void {
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertTrue(is_subclass_of($class, $contract), $class . ' implements ' . $contract);
	}//end testTheClassImplementsTheContract()

	/**
	 * The registrar binds the contract to the class and registers the alias.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $class    The class it resolves to.
	 *
	 * @return void
	 */
	#[DataProvider('contracts')]
	public function testTheContractIsBoundToTheClass(string $contract, string $class): void {
		$this->assertSame($class, ContractBindings::BINDINGS[$contract] ?? null);

		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);
		$aliases = array_column($context->aliases, 'target', 'alias');
		$this->assertSame($class, $aliases[$contract] ?? null);
	}//end testTheContractIsBoundToTheClass()
}//end class
