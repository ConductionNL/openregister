<?php

/**
 * The Db mappers lane C04 hid behind a narrow interface stay hidden and bound.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Db\CaseItemMapperInterface;
use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCA\OpenRegister\Db\ObjectReadStateMapperInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A missing binding is a 500 at the first request that resolves the
 * interface, which no other unit test catches, so every class hidden here is
 * checked for its binding, for implementing its interface, and for no lib/
 * file outside lib/Db naming it again.
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
	 * Hidden class => the interface its callers outside lib/Db type against.
	 *
	 * @var array<class-string, class-string>
	 */
	private const HIDDEN = [
		CaseItemMapper::class => CaseItemMapperInterface::class,
		ObjectReadStateMapper::class => ObjectReadStateMapperInterface::class,
	];

	/**
	 * One row per hidden class.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function hiddenClasses(): array {
		$rows = [];
		foreach (self::HIDDEN as $class => $interface) {
			$rows[$class] = [$class, $interface];
		}

		return $rows;
	}//end hiddenClasses()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string $class     The hidden class.
	 * @param class-string $interface The interface callers type against.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testTheClassImplementsItsInterface(string $class, string $interface): void {
		$this->assertTrue(interface_exists($interface), $interface . ' exists');
		$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
	}//end testTheClassImplementsItsInterface()

	/**
	 * DbRegistrar binds the interface to the class, so it resolves to the same shared mapper.
	 *
	 * @param class-string $class     The hidden class.
	 * @param class-string $interface The interface callers type against.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testDbRegistrarBindsTheInterface(string $class, string $interface): void {
		$this->assertSame($class, DbRegistrar::BINDINGS[$interface] ?? null, $interface . ' is bound in DbRegistrar');
	}//end testDbRegistrarBindsTheInterface()

	/**
	 * No lib/ file outside lib/Db names the class, in code or as a container id string.
	 *
	 * @param class-string $class     The hidden class.
	 * @param class-string $interface The interface callers type against.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoFileOutsideDbNamesTheClass(string $class, string $interface): void {
		$lib    = (string)realpath(self::LIB) . '/';
		$naming = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib)) as $file) {
			$path = (string)$file;
			if (str_ends_with($path, '.php') === false || str_starts_with($path, $lib . 'Db/') === true) {
				continue;
			}

			if (in_array($class, $this->namesIn(path: $path), true) === true) {
				$naming[] = substr($path, strlen($lib));
			}
		}

		sort($naming);
		$this->assertSame([], $naming, $class . ' is named outside lib/Db; type the caller against ' . $interface);
	}//end testNoFileOutsideDbNamesTheClass()

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
				T_NAME_QUALIFIED => str_starts_with($token[1], 'OCA\\') === true ? $token[1] : $namespace . '\\' . $token[1],
				T_STRING => $namespace . '\\' . $token[1],
				T_CONSTANT_ENCAPSED_STRING => ltrim(str_replace('\\\\', '\\', trim($token[1], '\'"')), '\\'),
				default => null,
			};
		}

		return array_values(array_filter($names));
	}//end namesIn()
}//end class
