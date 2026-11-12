<?php

/**
 * The K21 classes are reached through an interface, or named only inside their component.
 *
 * Each class listed here was named directly by a lib file in another
 * component. Classes with an interface are aliased to it by the registrar
 * named beside them, so they still resolve to the same shared instance; a
 * missing alias is a runtime 500 that no other unit test sees, so this test
 * pins the binding as well as the implementation and the callers. Classes
 * without an interface are value objects or Twig parts that callers outside
 * their component no longer need to name at all.
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

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\AppHostRegistrar;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleManifest;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use OCA\OpenRegister\Twig\MappingTwigInstaller;
use OCA\OpenRegister\Twig\MappingTwigInstallerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class K21InterfaceBindingsTest extends TestCase {

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
		AppContainerLocator::class => [AppContainerFinderInterface::class, AppHostRegistrar::class, 'AppHost/'],
		MappingTwigInstaller::class => [MappingTwigInstallerInterface::class, AppHostRegistrar::class, 'Twig/'],
	];

	/**
	 * Class => its component under lib/; no lib file outside that component names it.
	 *
	 * @var array<class-string, string>
	 */
	private const HIDDEN = [
		AppContainerLocator::class => 'AppHost/',
		ScheduleManifest::class => 'AppHost/',
		MappingExtension::class => 'Twig/',
		MappingRuntimeLoader::class => 'Twig/',
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
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function hiddenClasses(): array {
		$cases = [];
		foreach (self::HIDDEN as $class => $component) {
			$cases[$class] = [$class, $component];
		}

		return $cases;
	}//end hiddenClasses()

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
	 * @param string       $component The class's component under lib/.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoFileOutsideTheComponentNamesTheClass(string $class, string $component): void {
		$naming = [];
		foreach ($this->libFiles() as $relative => $path) {
			if (str_starts_with($relative, $component) === false
				&& $this->namesClass(source: (string) file_get_contents($path), class: $class) === true
			) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: $class . ' is named only inside lib/' . $component);
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
