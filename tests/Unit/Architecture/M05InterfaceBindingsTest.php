<?php

/**
 * The interfaces lane M05 added are bound, and their classes implement them.
 *
 * A missing alias is a 500 at the first call that no other unit test sees, so
 * each binding is pinned here.
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

use OCA\OpenRegister\Service\Bindings\FlowBindings;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKinds;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKindsInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class M05InterfaceBindingsTest extends TestCase {

	/**
	 * One row per interface bound through a registrar map.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function bindings(): array {
		return [
			'principal kinds' => [PrincipalKindsInterface::class, PrincipalKinds::class],
		];
	}//end bindings()

	/**
	 * The class implements the interface.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $service  The class.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheClassImplementsTheInterface(string $contract, string $service): void {
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertTrue(is_subclass_of($service, $contract), $service . ' implements ' . $contract);
	}//end testTheClassImplementsTheInterface()

	/**
	 * The flow bindings map the interface to the class, and the alias is registered.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $service  The class.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheBindingIsPresent(string $contract, string $service): void {
		$this->assertSame($service, FlowBindings::BINDINGS[$contract] ?? null);

		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);
		$aliases = array_column($context->aliases, 'target', 'alias');
		$this->assertSame($service, $aliases[$contract] ?? null);
	}//end testTheBindingIsPresent()
}//end class
