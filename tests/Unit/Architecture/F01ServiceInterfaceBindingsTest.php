<?php

/**
 * The F01 Service classes are reached through interfaces, a factory or a small entry class.
 *
 * Each class listed here was named directly by a lib file in another component.
 * It now has an interface next to it; outside callers type against and resolve
 * that interface, and the registrar of the class's own component aliases it to
 * the class. A missing alias is a runtime 500 that no other unit test sees, so
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
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogueInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyMarksInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates;
use OCA\OpenRegister\Service\Gdpr\GdprRegistrar;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use OCA\OpenRegister\Service\ServiceRegistrar;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetService;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F01ServiceInterfaceBindingsTest extends TestCase {

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
		ExampleSetCatalogue::class => [ExampleSetCatalogueInterface::class, ContractBindings::class],
		ExampleSetDailyRefresh::class => [ExampleSetDailyMarksInterface::class, ContractBindings::class],
		RegisterScopedSchemaResolver::class => [RegisterScopedSchemaResolverInterface::class, ContractBindings::class],
		ShippedBaselineResetService::class => [ShippedBaselineResetServiceInterface::class, ContractBindings::class],
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * None.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [];

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
	 * The class implements its interface, and its registrar is in its own component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertSame(explode('\\', $class)[2], explode('\\', $registrar)[2], 'Bound in its own component');
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
		$this->assertArrayHasKey($contract, $bindings);
		$this->assertSame($class, $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_CALLERS[$class] ?? [], $naming, 'Type against ' . self::CLASSES[$class][0] . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Classes outside callers now reach through a small entry class or a forwarding facade.
	 *
	 * Application registers the hand-wired services through ServiceBootstrap; RulesController reads its
	 * run window through ExampleSetDateValue; outside callers build a scoped schema resolver through
	 * RegisterScopedSchemaResolvers.
	 *
	 * @var list<class-string>
	 */
	private const HIDDEN = [
		ExampleSetTaskDates::class,
		GdprRegistrar::class,
		ServiceRegistrar::class,
	];

	/**
	 * One case per hidden class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function hiddenClasses(): array {
		$cases = [];
		foreach (self::HIDDEN as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end hiddenClasses()

	/**
	 * No lib file outside the component of a hidden class names it.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOutsideFileNamesTheHiddenClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame([], $naming, 'Call the entry class of ' . $class . ' instead.');
	}//end testNoOutsideFileNamesTheHiddenClass()

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
		$short    = substr($class, (int) strrpos($class, '\\') + 1);
		$imported = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($imported === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

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
