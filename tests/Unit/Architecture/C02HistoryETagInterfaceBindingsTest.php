<?php

/**
 * The object-action history and the OAS ETag computer are reached through their interfaces.
 *
 * Each class listed here was named directly by a controller, which lives in
 * another component. It now has a narrow interface next to it; the controllers
 * type against that interface, and ContractBindings aliases it to the same class, so
 * the container still hands out the one shared instance. A missing alias is a
 * runtime 500 that no other unit test sees, so this test pins the binding as
 * well as the implementation and the callers.
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
use OCA\OpenRegister\Service\Oas\OasETagComputer;
use OCA\OpenRegister\Service\Oas\OasETagComputerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class C02HistoryETagInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interfaces the controllers type against.
	 *
	 * @var array<class-string, list<class-string>>
	 */
	private const CLASSES = [
		OasETagComputer::class => [OasETagComputerInterface::class],
		ObjectActionHistory::class => [ObjectActionHistoryInterface::class],
	];

	/**
	 * Lib files outside the class's own top-level component still allowed to name it.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [
		OasETagComputer::class => [],
		ObjectActionHistory::class => [],
	];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::CLASSES) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * The class implements each interface, and ContractBindings binds each to it.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testEachInterfaceIsImplementedAndBound(string $class): void {
		foreach (self::CLASSES[$class] as $contract) {
			$this->assertTrue(condition: interface_exists($contract), message: $contract . ' exists');
			$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
			$this->assertSame(expected: $class, actual: ContractBindings::BINDINGS[$contract] ?? null, message: $contract . ' is bound');
		}
	}//end testEachInterfaceIsImplementedAndBound()

	/**
	 * Outside its own component, only the kept callers name the class in code.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testOnlyTheKeptCallersOutsideTheComponentNameTheClass(string $class): void {
		$own       = str_replace('\\', '/', substr($class, strlen('OCA\\OpenRegister\\')));
		$component = substr($own, 0, (int) strpos($own, '/') + 1);
		$naming    = [];
		foreach ($this->libFiles() as $relative => $path) {
			if (str_starts_with($relative, $component) === false
				&& $this->namesClass(source: (string) file_get_contents($path), class: $class) === true
			) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(
			expected: self::KEPT_CALLERS[$class],
			actual: $naming,
			message: 'Type against ' . implode(' or ', self::CLASSES[$class]) . ' instead.'
		);
	}//end testOnlyTheKeptCallersOutsideTheComponentNameTheClass()

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
				$files[substr($file->getPathname(), strlen($lib) + 1)] = $file->getPathname();
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
