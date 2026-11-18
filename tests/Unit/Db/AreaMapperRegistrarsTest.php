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
use OCA\OpenRegister\Db\ProcessesMapperRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\ProcessesMapperRegistrar
 * @covers \OCA\OpenRegister\Db\ProcessesMapperBindings
 * @uses   \OCA\OpenRegister\Db\DbRegistrar
 */
final class AreaMapperRegistrarsTest extends TestCase {

	/**
	 * One case per area mapper registrar.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function registrars(): array {
		return [
			'Processes' => [ProcessesMapperRegistrar::class],
		];
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
		$this->assertNotSame([], $bindings);
		$this->assertSame($bindings, array_column($context->aliases, 'target', 'alias'));
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
			$this->assertStringStartsWith('OCA\\OpenRegister\\Db\\', $class);
			$this->assertTrue((new ReflectionClass($class))->implementsInterface($interface), $class . ' implements ' . $interface);
		}
	}//end testEveryBindingIsImplementedByItsClass()

	/**
	 * No interface is bound both here and in DbRegistrar or another area's registrar.
	 *
	 * @return void
	 */
	public function testEachInterfaceIsBoundOnce(): void {
		$seen = array_keys(DbRegistrar::BINDINGS);
		foreach (self::registrars() as [$registrar]) {
			$keys = array_keys(constant($registrar . '::BINDINGS'));
			$this->assertSame([], array_values(array_intersect($seen, $keys)), $registrar . ' binds an interface bound elsewhere');
			$seen = array_merge($seen, $keys);
		}
	}//end testEachInterfaceIsBoundOnce()
}//end class
