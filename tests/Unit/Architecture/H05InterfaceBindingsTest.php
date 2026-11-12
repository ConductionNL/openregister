<?php

/**
 * The interfaces lane H05 put in front of a Db class stay bound and implemented.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Db\TenantUsageMapperInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A missing binding is a 500 at the first request that resolves the
 * interface, which no other unit test catches.
 *
 * @coversNothing
 */
final class H05InterfaceBindingsTest extends TestCase {

	/**
	 * One row per interface: [interface, the class it must resolve to].
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function bindings(): array {
		return [
			'tenant usage' => [TenantUsageMapperInterface::class, TenantUsageMapper::class],
		];
	}//end bindings()

	/**
	 * DbRegistrar binds the interface to its class, and the class implements it.
	 *
	 * @param class-string $interface The interface.
	 * @param class-string $class     The class it must resolve to.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheInterfaceIsBoundToAClassThatImplementsIt(string $interface, string $class): void {
		$this->assertSame($class, DbRegistrar::BINDINGS[$interface] ?? null, $interface . ' is bound in DbRegistrar');
		$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
	}//end testTheInterfaceIsBoundToAClassThatImplementsIt()
}//end class
