<?php

/**
 * The interfaces lane M25 added are implemented and bound.
 *
 * Callers outside lib/Service type against these interfaces instead of the
 * concrete class, so a missing alias is a runtime 500 that no other unit test
 * sees. This test pins each binding.
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
use OCA\OpenRegister\Service\Flow\FlowConsentParking;
use OCA\OpenRegister\Service\Flow\FlowConsentSweepInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class M25InterfaceBindingsTest extends TestCase {

	/**
	 * Interface => the class it is bound to.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BOUND = [
		FlowConsentSweepInterface::class => FlowConsentParking::class,
	];

	/**
	 * Each binding as a data set.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function bindings(): array {
		$sets = [];
		foreach (self::BOUND as $interface => $class) {
			$sets[$interface] = [$interface, $class];
		}

		return $sets;
	}

	#[DataProvider('bindings')]
	public function testTheInterfaceIsBoundToTheClass(string $interface, string $class): void {
		$this->assertSame($class, (ContractBindings::BINDINGS[$interface] ?? null));
	}

	#[DataProvider('bindings')]
	public function testTheClassImplementsTheInterface(string $interface, string $class): void {
		$this->assertTrue(is_subclass_of($class, $interface));
	}
}
