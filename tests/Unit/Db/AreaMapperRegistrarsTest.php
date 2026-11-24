<?php

/**
 * The mapper registrars of the functional areas bind each interface once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\ProcessesMapperRegistrar
 * @covers \OCA\OpenRegister\Db\ProcessesMapperBindings
 * @covers \OCA\OpenRegister\Db\IntegrationsMapperRegistrar
 * @covers \OCA\OpenRegister\Db\IntegrationsMapperBindings
 * @covers \OCA\OpenRegister\Db\ComplianceMapperRegistrar
 * @covers \OCA\OpenRegister\Db\ComplianceMapperBindings
 * @covers \OCA\OpenRegister\Db\FilesMapperRegistrar
 * @covers \OCA\OpenRegister\Db\FilesMapperBindings
 * @covers \OCA\OpenRegister\Db\ConfigurationMapperRegistrar
 * @covers \OCA\OpenRegister\Db\ConfigurationMapperBindings
 * @covers \OCA\OpenRegister\Db\PlatformMapperRegistrar
 * @covers \OCA\OpenRegister\Db\PlatformMapperBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 */
final class AreaMapperRegistrarsTest extends TestCase {

	/**
	 * One case per area mapper registrar, DbRegistrar left out.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function registrars(): array {
		$cases = [];
		foreach (array_diff(DbBindingMaps::REGISTRARS, [DbRegistrar::class]) as $registrar) {
			$cases[$registrar] = [$registrar];
		}

		return $cases;
	}//end registrars()

	/**
	 * The registrar aliases exactly the bindings of its map.
	 *
	 * @param class-string $registrar The area's mapper registrar.
	 *
	 * @return void
	 */
	#[DataProvider('registrars')]
	public function testTheRegistrarAliasesEveryBindingOfItsMap(string $registrar): void {
		$context = new RecordingRegistrationContext();
		$registrar::register(context: $context);

		$bindings = constant($registrar . '::BINDINGS');
		$this->assertNotSame(expected: [], actual: $bindings);
		$this->assertSame(expected: $bindings, actual: array_column($context->aliases, 'target', 'alias'));
	}//end testTheRegistrarAliasesEveryBindingOfItsMap()

	/**
	 * Every bound class is a Db class that implements its interface.
	 *
	 * @param class-string $registrar The area's mapper registrar.
	 *
	 * @return void
	 */
	#[DataProvider('registrars')]
	public function testEveryBindingIsImplementedByItsClass(string $registrar): void {
		foreach (constant($registrar . '::BINDINGS') as $interface => $class) {
			$this->assertStringStartsWith(prefix: 'OCA\\OpenRegister\\Db\\', string: $class);
			$this->assertTrue(condition: (new ReflectionClass($class))->implementsInterface($interface), message: $class . ' implements ' . $interface);
		}
	}//end testEveryBindingIsImplementedByItsClass()

	/**
	 * No interface is bound by two of the registrars.
	 *
	 * @return void
	 */
	public function testEachInterfaceIsBoundOnce(): void {
		$seen = [];
		foreach (DbBindingMaps::REGISTRARS as $registrar) {
			$keys = array_keys(constant($registrar . '::BINDINGS'));
			$this->assertSame(expected: [], actual: array_values(array_intersect($seen, $keys)), message: $registrar . ' binds an interface bound elsewhere');
			$seen = array_merge($seen, $keys);
		}
	}//end testEachInterfaceIsBoundOnce()
}//end class
