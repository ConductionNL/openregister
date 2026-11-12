<?php

/**
 * lib/Db reaches the quality, dedup and merge annotation validators only through interfaces.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\Merge\MergeAnnotationValidator;
use OCA\OpenRegister\Service\Merge\MergeAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Quality\DedupAnnotationValidator;
use OCA\OpenRegister\Service\Quality\DedupAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Quality\MasterDataAnnotationValidators;
use OCA\OpenRegister\Service\Quality\QualityAnnotationValidator;
use OCA\OpenRegister\Service\Quality\QualityAnnotationValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each class here is reached from outside its lib/ component only through
 * an interface. A class the container resolves is checked for its binding,
 * since a missing one is a 500 at the first request no other unit test sees.
 *
 * @coversNothing
 */
final class F04MasterDataValidatorInterfacesTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Hidden class => [the interface outside callers use, its binding registrar (null: never container-resolved), its component].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string|null, 2: string}>
	 */
	private const HIDDEN = [
		QualityAnnotationValidator::class => [QualityAnnotationValidatorInterface::class, null, 'Service'],
		DedupAnnotationValidator::class => [DedupAnnotationValidatorInterface::class, null, 'Service'],
		MergeAnnotationValidator::class => [MergeAnnotationValidatorInterface::class, null, 'Service'],
	];

	/**
	 * Class => the outside callers deliberately left naming it, relative to lib/.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const LEFT = [];

	/**
	 * One row per hidden class.
	 *
	 * @return array<string, array{0: class-string, 1: class-string, 2: class-string|null, 3: string}>
	 */
	public static function hiddenClasses(): array {
		$rows = [];
		foreach (self::HIDDEN as $class => [$interface, $registrar, $component]) {
			$rows[$class] = [$class, $interface, $registrar, $component];
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
		$this->assertTrue(condition: interface_exists($interface), message: $interface . ' exists');
		$this->assertTrue(condition: is_subclass_of($class, $interface), message: $class . ' implements ' . $interface);
	}//end testTheClassImplementsItsInterface()

	/**
	 * The component's registrar binds the interface to the class.
	 *
	 * @param class-string      $class     The hidden class.
	 * @param class-string      $interface The interface callers type against.
	 * @param class-string|null $registrar The registrar of the class's component.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testTheRegistrarBindsTheInterface(string $class, string $interface, ?string $registrar): void {
		if ($registrar === null) {
			$this->assertFalse(condition: class_exists($interface), message: $interface . ' is an interface; its component builds the class');
			return;
		}

		$bound = (constant($registrar . '::BINDINGS')[$interface] ?? null);
		$this->assertSame(expected: $class, actual: $bound, message: $interface . ' is bound in ' . $registrar);
	}//end testTheRegistrarBindsTheInterface()

	/**
	 * No lib/ file outside the class's component names the class.
	 *
	 * @param class-string      $class     The hidden class.
	 * @param class-string      $interface The interface callers type against.
	 * @param class-string|null $registrar The registrar of the class's component.
	 * @param string            $component The first-level lib/ directory of the class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOtherComponentNamesTheClass(string $class, string $interface, ?string $registrar, string $component): void {
		$lib    = realpath(self::LIB) . '/';
		$naming = array_filter(
			$this->filesOutside(component: $component),
			fn (string $path): bool => in_array($class, $this->namesIn(path: $path), true)
		);
		$naming = array_map(static fn (string $path): string => substr($path, strlen($lib)), array_values($naming));
		sort($naming);

		$this->assertSame(
			expected: self::LEFT[$class] ?? [],
			actual: $naming,
			message: $class . ' is named outside lib/' . $component . '; type the caller against ' . $interface
		);
	}//end testNoOtherComponentNamesTheClass()

	/**
	 * The factory builds the same validators the save path used to build itself.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsTheHiddenClasses(): void {
		$this->assertInstanceOf(expected: QualityAnnotationValidator::class, actual: MasterDataAnnotationValidators::quality());
		$this->assertInstanceOf(expected: DedupAnnotationValidator::class, actual: MasterDataAnnotationValidators::dedup());
		$this->assertInstanceOf(expected: MergeAnnotationValidator::class, actual: MasterDataAnnotationValidators::merge());
	}//end testTheFactoryBuildsTheHiddenClasses()

	/**
	 * The PHP files of lib/ outside one component.
	 *
	 * @param string $component The first-level lib/ directory to leave out.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function filesOutside(string $component): array {
		$inside = realpath(self::LIB) . '/' . $component . '/';
		$paths  = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(realpath(self::LIB))) as $file) {
			$path = (string)$file;
			if (str_ends_with($path, '.php') === true && str_starts_with($path, $inside) === false) {
				$paths[] = $path;
			}
		}

		return $paths;
	}//end filesOutside()

	/**
	 * The fully qualified class names one file's code mentions, comments left out.
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
			}

			$names[] = $this->resolve(token: $token, namespace: $namespace);
		}

		return array_values(array_filter($names));
	}//end namesIn()

	/**
	 * The class name a token names, or null.
	 *
	 * @param array{0: int, 1: string, 2: int} $token     The token.
	 * @param string                           $namespace The file's namespace.
	 *
	 * @return string|null
	 */
	private function resolve(array $token, string $namespace): ?string {
		if ($token[0] === T_NAME_FULLY_QUALIFIED) {
			return ltrim($token[1], '\\');
		}

		if ($token[0] === T_NAME_QUALIFIED && str_starts_with($token[1], 'OCA\\') === true) {
			return $token[1];
		}

		if ($token[0] === T_NAME_QUALIFIED || $token[0] === T_STRING) {
			return $namespace . '\\' . $token[1];
		}

		return null;
	}//end resolve()
}//end class
