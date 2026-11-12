<?php

/**
 * The A16 classes in lib/Service are reached through their interfaces.
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

use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilderInterface;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogue;
use OCA\OpenRegister\Service\Calculation\OperatorCatalogueInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistryInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolverInterface;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcerInterface;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCA\OpenRegister\Service\Rules\DependentValueTableInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class A16InterfaceBindingsTest extends TestCase {

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
		AdministeredValidationEnforcer::class => [AdministeredValidationEnforcerInterface::class, ContractBindings::class],
		CalculationPayloadBuilder::class => [CalculationPayloadBuilderInterface::class, ContractBindings::class],
		DependentValueTable::class => [DependentValueTableInterface::class, ContractBindings::class],
		LifecycleGuardRegistry::class => [LifecycleGuardRegistryInterface::class, ContractBindings::class],
		LifecycleTransitionResolver::class => [LifecycleTransitionResolverInterface::class, ContractBindings::class],
		OperatorCatalogue::class => [OperatorCatalogueInterface::class, ContractBindings::class],
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

		$this->assertSame([], $naming, 'Type against ' . self::CLASSES[$class][0] . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * The refusal code moved to the interface still reads the same through the class.
	 *
	 * @return void
	 */
	public function testTheDependentValueRefusalCodeIsUnchanged(): void {
		$this->assertSame('dependent-value-not-allowed', DependentValueTable::CODE_VALUE_NOT_ALLOWED);
		$this->assertSame(DependentValueTable::CODE_VALUE_NOT_ALLOWED, DependentValueTableInterface::CODE_VALUE_NOT_ALLOWED);
	}//end testTheDependentValueRefusalCodeIsUnchanged()

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
