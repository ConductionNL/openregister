<?php

/**
 * The F07 lib/Service/Object classes are reached through their interfaces.
 *
 * Each class listed here was named directly by a lib file in another
 * component. It now has an interface next to it; outside callers type
 * against and resolve that interface, and the registrar of the class's own
 * component aliases it to the class, so it still resolves to the same shared
 * instance. A missing alias is a runtime 500 that no other unit test sees, so
 * this test pins the binding as well as the implementation and the callers.
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
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\MoveObjectInterface;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugTargetInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandler;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandlerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F07InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [the interface outside callers use, the registrar that binds it, the class's component under lib/].
	 *
	 * @var array<class-string, array{class-string, class-string, string}>
	 */
	private const CLASSES = [
		MoveObject::class => [MoveObjectInterface::class, ContractBindings::class, 'Service/'],
		ObjectSlugHandler::class => [ObjectSlugTargetInterface::class, ContractBindings::class, 'Service/'],
		SchemaTypeConverter::class => [SchemaTypeConverterInterface::class, ContractBindings::class, 'Service/'],
		UpsertOnKeyHandler::class => [UpsertOnKeyHandlerInterface::class, ContractBindings::class, 'Service/'],
	];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string, class-string, class-string, string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (self::CLASSES as $class => [$contract, $registrar, $component]) {
			$cases[$class] = [$class, $contract, $registrar, $component];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements its interface, and its registrar binds the interface to it.
	 *
	 * @param class-string $class     The class.
	 * @param class-string $contract  Its interface.
	 * @param class-string $registrar The registrar whose BINDINGS bind it.
	 * @param string       $component The class's component under lib/.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsImplementedAndBound(string $class, string $contract, string $registrar, string $component): void {
		$this->assertTrue(condition: interface_exists($contract), message: $contract . ' exists');
		$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertSame(expected: $class, actual: ($bindings[$contract] ?? null), message: $contract . ' is bound in ' . $registrar);
	}//end testTheInterfaceIsImplementedAndBound()

	/**
	 * No lib file outside the class's own component names it in code.
	 *
	 * @param class-string $class     The class.
	 * @param class-string $contract  Its interface.
	 * @param class-string $registrar The registrar whose BINDINGS bind it.
	 * @param string       $component The class's component under lib/.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoFileOutsideTheComponentNamesTheClass(string $class, string $contract, string $registrar, string $component): void {
		$naming = [];
		foreach ($this->libFiles() as $relative => $path) {
			if (str_starts_with($relative, $component) === false
				&& $this->namesClass(source: (string) file_get_contents($path), class: $class) === true
			) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Type against ' . $contract . ' instead.');
	}//end testNoFileOutsideTheComponentNamesTheClass()

	/**
	 * Every lib PHP file, keyed by its path under lib/, sorted.
	 *
	 * @return array<string, string>
	 */
	private function libFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() === 'php') {
				$files[str_replace('\\', '/', substr($file->getPathname(), strlen($lib) + 1))] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end libFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$bare      = str_contains($source, 'use ' . $class . ';') || str_contains($source, 'namespace ' . $namespace . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ((in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true && ltrim($token[1], '\\') === $class)
				|| ($bare === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
