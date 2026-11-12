<?php

/**
 * C12: Service classes reached from other components through an interface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\ContractBindings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pins that a lib/Service class hidden from the other components stays hidden.
 *
 * The cases live in tests/Unit/Architecture/C12/*.php, one file per change, so
 * changes that hide different classes do not edit the same list. Each file
 * returns:
 *
 *  - `classes`: class => the interface outside callers type against, or null
 *    when outside callers reach the class only through a builder inside
 *    lib/Service and never need its type;
 *  - `kept`: class => outside files still allowed to name it (with the reason
 *    in the data file).
 *
 * For every class: it implements its interface, the interface is bound to it
 * in ContractBindings::BINDINGS (so the interface resolves to the same shared
 * instance the class does), and no file outside lib/Service names the class.
 */
final class C12InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Every case of every data file.
	 *
	 * @return array{classes: array<class-string, class-string|null>, kept: array<class-string, list<string>>}
	 */
	private static function cases(): array {
		$classes = [];
		$kept = [];
		$files = glob(__DIR__ . '/C12/*.php');
		if ($files === false) {
			$files = [];
		}

		foreach ($files as $file) {
			$data = require $file;
			$classes = array_merge($classes, $data['classes']);
			$kept = array_merge($kept, ($data['kept'] ?? []));
		}

		ksort($classes);
		return ['classes' => $classes, 'kept' => $kept];
	}//end cases()

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::cases()['classes']) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * The class lives in lib/Service and implements its interface.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		$contract = self::cases()['classes'][$class];
		$this->assertTrue(class_exists($class), $class . ' exists');
		$this->assertSame('Service', explode('\\', $class)[2], $class . ' is in lib/Service');
		if ($contract === null) {
			return;
		}

		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertSame('Service', explode('\\', $contract)[2], $contract . ' is in lib/Service');
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in ContractBindings.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class): void {
		$contract = self::cases()['classes'][$class];
		if ($contract === null) {
			$this->assertNotContains($class, ContractBindings::BINDINGS, $class . ' is reached through a builder, not bound');
			return;
		}

		$this->assertArrayHasKey($contract, ContractBindings::BINDINGS);
		$this->assertSame($class, ContractBindings::BINDINGS[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No file outside lib/Service names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles() as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$contract = self::cases()['classes'][$class];
		$this->assertSame(self::cases()['kept'][$class] ?? [], $naming, 'Type against ' . ($contract ?? 'its builder') . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every PHP file under lib/ outside lib/Service, keyed by its path below lib/.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, 'Service/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether a source names the class: fully qualified, or by its short name after importing it.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short    = substr($class, (int) strrpos($class, '\\') + 1);
		$imported = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ((in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class)
				|| ($imported === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
