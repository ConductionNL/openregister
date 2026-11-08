<?php

/**
 * The F10 Integration, Mcp and Relation classes are reached through interfaces from outside their components.
 *
 * Each class listed here was named directly by a lib file in another component.
 * Outside callers now type against and resolve its interface, and the registrar
 * of the class's own component aliases the interface to the class, so every
 * caller still gets the same shared instance. A missing alias is a runtime 500
 * that no other unit test sees, so this test pins the binding as well as the
 * implementation and the callers. A class the callers built with `new` is
 * built by a small factory in its own component instead: a fresh instance with
 * the same arguments, typed as the interface.
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

use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator;
use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidatorInterface;
use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidators;
use OCA\OpenRegister\Service\Integration\IntegrationIdIndexInterface;
use OCA\OpenRegister\Service\Integration\IntegrationRegistrar;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Service\Integration\LeafRegistryInterface;
use OCA\OpenRegister\Service\Relation\AffectedSet;
use OCA\OpenRegister\Service\Relation\AffectedSetInterface;
use OCA\OpenRegister\Service\Relation\AffectedSets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F10IntegrationMcpInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against, and the registrar that binds it.
	 *
	 * @var array<class-string, array{class-string, class-string}>
	 */
	private const CLASSES = [
		LeafRegistry::class => [LeafRegistryInterface::class, IntegrationRegistrar::class],
		IntegrationRegistry::class => [IntegrationIdIndexInterface::class, IntegrationRegistrar::class],
	];

	/**
	 * Class => the interface its factory returns it as.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BUILT = [
		McpAnnotationValidator::class => McpAnnotationValidatorInterface::class,
		AffectedSet::class => AffectedSetInterface::class,
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
	 * Every class, bound or built.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function allClasses(): array {
		$cases = self::classes();
		foreach (array_keys(self::BUILT) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end allClasses()

	/**
	 * Each factory builds a fresh instance of the class the callers built.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsWhatTheCallersBuilt(): void {
		$built = [
			McpAnnotationValidator::class => static fn (): object => McpAnnotationValidators::validator(),
			AffectedSet::class => static fn (): object => AffectedSets::deriver(),
		];
		foreach ($built as $class => $build) {
			$first = $build();
			$this->assertSame(expected: $class, actual: get_class($first));
			$this->assertInstanceOf(expected: self::BUILT[$class], actual: $first);
			$this->assertNotSame(expected: $first, actual: $build());
		}
	}//end testTheFactoryBuildsWhatTheCallersBuilt()

	/**
	 * The class implements its interface, and its registrar is in its own component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$this->assertTrue(condition: interface_exists($contract), message: $contract . ' exists');
		$this->assertContains(needle: $contract, haystack: class_implements($class), message: $class . ' implements ' . $contract);
		$this->assertSame(expected: explode('\\', $class)[2], actual: explode('\\', $registrar)[2], message: 'Bound in its own component');
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in the registrar of the class's component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey(key: $contract, array: $bindings);
		$this->assertSame(expected: $class, actual: $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('allClasses')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Type against ' . (self::CLASSES[$class][0] ?? self::BUILT[$class]) . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Every lib PHP file outside the component of the class, keyed by its path under lib/.
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
