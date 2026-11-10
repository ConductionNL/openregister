<?php

/**
 * ClassAliasMap aliases an eager entry only after the lazy autoloader can serve the old names it uses.
 *
 * An eager entry is aliased with class_alias(), which loads its new class. When
 * that class still implements or extends a moved type by its old name (callers
 * keep old names until they are rewritten, ADR-012 Rule 4), loading it asks the
 * autoloader for that old name. If the lazy autoloader is not registered yet the
 * load fails with "Interface ... not found" and the app does not boot.
 *
 * Runs in a fresh process with the autoloader taken off the stack, so the first
 * aliasClasses() call is the one under test, as it is at boot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\ClassAliasMap;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The type a moved interface now lives under.
 */
interface ClassAliasMapEagerOrderContract {
}//end interface

/**
 * Eager entries are aliased after the lazy names are available.
 */
class ClassAliasMapEagerOrderTest extends TestCase {

	private const OLD_INTERFACE = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Retired\\E1OldContractName';

	private const OLD_CLASS = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Retired\\E1OldImplementationName';

	private const NEW_CLASS = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Moved\\E1Implementation';

	/**
	 * A moved class aliased eagerly loads although it implements an old name that is aliased lazily.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAnEagerClassThatImplementsALazyOldNameLoads(): void {
		spl_autoload_unregister([ClassAliasMap::class, 'autoload']);
		(new ReflectionProperty(ClassAliasMap::class, 'autoloaderRegistered'))->setValue(null, false);

		// The moved implementation, declared on first autoload, still names the interface by its old name.
		$declare = static function (string $class): void {
			if ($class === self::NEW_CLASS) {
				eval('namespace OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Moved; final class E1Implementation implements \\' . self::OLD_INTERFACE . ' {}');
			}
		};
		spl_autoload_register($declare);

		try {
			(new ClassAliasMap(
				entries: [
					self::OLD_CLASS     => ['class' => self::NEW_CLASS, 'eager' => true],
					self::OLD_INTERFACE => ['class' => ClassAliasMapEagerOrderContract::class, 'eager' => false],
				]
			))->aliasClasses();
		} finally {
			spl_autoload_unregister($declare);
		}

		$this->assertTrue(class_exists(self::OLD_CLASS, false));
		$this->assertInstanceOf(ClassAliasMapEagerOrderContract::class, new (self::OLD_CLASS)());
	}//end testAnEagerClassThatImplementsALazyOldNameLoads()

	/**
	 * A moved class aliased eagerly loads although it implements an old name that is aliased eagerly further down.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAnEagerClassThatImplementsALaterEagerOldNameLoads(): void {
		spl_autoload_unregister([ClassAliasMap::class, 'autoload']);
		(new ReflectionProperty(ClassAliasMap::class, 'autoloaderRegistered'))->setValue(null, false);
		(new ReflectionProperty(ClassAliasMap::class, 'lazy'))->setValue(null, []);

		$declare = static function (string $class): void {
			if ($class === self::NEW_CLASS) {
				eval('namespace OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Moved; final class E1Implementation implements \\' . self::OLD_INTERFACE . ' {}');
			}
		};
		spl_autoload_register($declare);

		try {
			(new ClassAliasMap(
				entries: [
					self::OLD_CLASS     => ['class' => self::NEW_CLASS, 'eager' => true],
					self::OLD_INTERFACE => ['class' => ClassAliasMapEagerOrderContract::class, 'eager' => true],
				]
			))->aliasClasses();
		} finally {
			spl_autoload_unregister($declare);
		}

		$this->assertTrue(interface_exists(self::OLD_INTERFACE, false));
		$this->assertInstanceOf(ClassAliasMapEagerOrderContract::class, new (self::OLD_CLASS)());
		$this->assertNotContains([ClassAliasMap::class, 'autoload'], spl_autoload_functions());
	}//end testAnEagerClassThatImplementsALaterEagerOldNameLoads()
}//end class
