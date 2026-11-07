<?php

/**
 * Lane D13: the classes below are reached from other components only through
 * an interface next to them.
 *
 * MagicTableHandler, which the Service query layer used to name directly.
 *
 * Each class implements its interface, the component's registrar binds the
 * interface to the class, and no lib/ file outside the class's component
 * names the class. A missing binding is a 500 at the first request that no
 * other unit test sees, so the binding is pinned here as well.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class D13InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Hidden class => [the interface outside callers use, the registrar that binds it].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string}>
	 */
	private const HIDDEN = [
		MagicTableHandler::class => [MagicTableHandlerInterface::class, DbRegistrar::class],
	];

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string, class-string, class-string}>
	 */
	public static function hiddenClasses(): array {
		$cases = [];
		foreach (self::HIDDEN as $class => [$contract, $registrar]) {
			$cases[$class] = [$class, $contract, $registrar];
		}

		return $cases;
	}//end hiddenClasses()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string $class     The class.
	 * @param class-string $contract  Its interface.
	 * @param class-string $registrar The registrar that binds it.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testTheClassImplementsItsInterface(string $class, string $contract, string $registrar): void {
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
	}//end testTheClassImplementsItsInterface()

	/**
	 * The registrar binds the interface to the class.
	 *
	 * @param class-string $class     The class.
	 * @param class-string $contract  Its interface.
	 * @param class-string $registrar The registrar that binds it.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testTheRegistrarBindsTheInterface(string $class, string $contract, string $registrar): void {
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey($contract, $bindings, $registrar . ' binds ' . $contract);
		$this->assertSame($class, $bindings[$contract]);
	}//end testTheRegistrarBindsTheInterface()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class     The class.
	 * @param class-string $contract  Its interface.
	 * @param class-string $registrar The registrar that binds it.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOutsideFileNamesTheClass(string $class, string $contract, string $registrar): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming, 'Type against ' . $contract . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside the class's component, keyed by its path under lib/.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(string $class): array {
		$component = explode('\\', $class)[2];
		$lib       = (string) realpath(self::LIB);
		$files     = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, $component . '/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * A bare short name counts when the file imports the class or lives in
	 * the class's own namespace.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short = substr($class, (int) strrpos($class, '\\') + 1);
		$bare  = $this->bareNameResolves(source: $source, class: $class);
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($bare === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

	/**
	 * Whether a bare short name in this source resolves to the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function bareNameResolves(string $source, string $class): bool {
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		if (str_contains($source, 'use ' . $class . ';') === true) {
			return true;
		}

		return preg_match('/^namespace\s+' . preg_quote($namespace, '/') . '\s*;/m', $source) === 1;
	}//end bareNameResolves()

	/**
	 * Whether a qualified-name token is the class.
	 *
	 * @param array{int, string, int} $token The token.
	 * @param class-string            $class The class.
	 *
	 * @return bool
	 */
	private function tokenNames(array $token, string $class): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end tokenNames()
}//end class
