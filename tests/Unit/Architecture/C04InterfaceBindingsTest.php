<?php

/**
 * The Db mappers lane C04 hid behind a narrow interface stay hidden and bound.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\DbRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A missing binding is a 500 at the first request that resolves the
 * interface, which no other unit test catches. So every lib/Db interface
 * whose docblock says it is guarded here is checked for its DbRegistrar
 * binding, for its bound mapper implementing it, and for no lib/ file
 * outside lib/Db naming that mapper again.
 *
 * The rows are discovered from the interfaces rather than listed, so every
 * change that hides one more mapper carries this same file unchanged.
 *
 * @coversNothing
 */
final class C04InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The sentence an interface's docblock carries to be guarded here.
	 *
	 * @var string
	 */
	private const MARKER = 'Guarded by C04InterfaceBindingsTest.';

	/**
	 * One row per guarded interface: [interface, the class DbRegistrar binds it to].
	 *
	 * @return array<string, array{0: class-string, 1: string|null}>
	 */
	public static function guardedInterfaces(): array {
		$rows = [];
		$paths = glob(pattern: self::LIB . '/Db/*Interface.php');
		if ($paths === false) {
			$paths = [];
		}

		foreach ($paths as $path) {
			if (str_contains((string)file_get_contents($path), self::MARKER) === false) {
				continue;
			}

			$interface        = 'OCA\\OpenRegister\\Db\\' . basename($path, '.php');
			$rows[$interface] = [$interface, DbRegistrar::BINDINGS[$interface] ?? null];
		}

		ksort($rows);
		return $rows;
	}//end guardedInterfaces()

	/**
	 * At least one interface is guarded, so a renamed marker cannot pass silently.
	 *
	 * @return void
	 */
	public function testSomeInterfaceIsGuarded(): void {
		$this->assertNotSame(expected: [], actual: self::guardedInterfaces());
	}//end testSomeInterfaceIsGuarded()

	/**
	 * DbRegistrar binds the interface to a Db class that implements it.
	 *
	 * @param class-string $interface The guarded interface.
	 * @param string|null  $class     The class DbRegistrar binds it to.
	 *
	 * @return void
	 */
	#[DataProvider('guardedInterfaces')]
	public function testDbRegistrarBindsTheInterfaceToItsMapper(string $interface, ?string $class): void {
		$this->assertTrue(condition: interface_exists(interface: $interface), message: $interface . ' exists');
		$this->assertNotNull(actual: $class, message: $interface . ' is bound in DbRegistrar');
		$this->assertStringStartsWith(prefix: 'OCA\\OpenRegister\\Db\\', string: (string)$class);
		$this->assertTrue(condition: is_subclass_of(object_or_class: (string)$class, class: $interface), message: $class . ' implements ' . $interface);
	}//end testDbRegistrarBindsTheInterfaceToItsMapper()

	/**
	 * No lib/ file outside lib/Db names the bound mapper, in code or as a container id string.
	 *
	 * @param class-string $interface The guarded interface.
	 * @param string|null  $class     The class DbRegistrar binds it to.
	 *
	 * @return void
	 */
	#[DataProvider('guardedInterfaces')]
	public function testNoFileOutsideDbNamesTheMapper(string $interface, ?string $class): void {
		$lib    = (string)realpath(self::LIB) . '/';
		$naming = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib)) as $file) {
			$path = (string)$file;
			if (str_ends_with($path, '.php') === false || str_starts_with($path, $lib . 'Db/') === true) {
				continue;
			}

			if (in_array((string)$class, $this->namesIn(path: $path), true) === true) {
				$naming[] = substr($path, strlen($lib));
			}
		}

		sort($naming);
		$this->assertSame(expected: [], actual: $naming, message: $class . ' is named outside lib/Db; type the caller against ' . $interface);
	}//end testNoFileOutsideDbNamesTheMapper()

	/**
	 * The fully qualified class names one file's code mentions, comments left out.
	 *
	 * Counts imports, fully qualified names, names resolved against the
	 * file's namespace, and class names spelled out in a string literal.
	 *
	 * @param string $path The PHP file.
	 *
	 * @return array<int, string>
	 */
	private function namesIn(string $path): array {
		$namespace = '';
		$names     = [];
		foreach (token_get_all((string)file_get_contents($path)) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($namespace === '' && $token[0] === T_NAME_QUALIFIED) {
				$namespace = $token[1];
				continue;
			}

			$names[] = match ($token[0]) {
				T_NAME_FULLY_QUALIFIED => ltrim($token[1], '\\'),
				T_NAME_QUALIFIED => $this->qualified(name: $token[1], namespace: $namespace),
				T_STRING => $namespace . '\\' . $token[1],
				T_CONSTANT_ENCAPSED_STRING => ltrim(str_replace('\\\\', '\\', trim($token[1], '\'"')), '\\'),
				default => null,
			};
		}

		return array_values(array_filter($names));
	}//end namesIn()

	/**
	 * A qualified name as written when it starts at OCA, else resolved against the namespace.
	 *
	 * @param string $name      The qualified name.
	 * @param string $namespace The file's namespace.
	 *
	 * @return string
	 */
	private function qualified(string $name, string $namespace): string {
		if (str_starts_with($name, 'OCA\\') === true) {
			return $name;
		}

		return $namespace . '\\' . $name;
	}//end qualified()
}//end class
