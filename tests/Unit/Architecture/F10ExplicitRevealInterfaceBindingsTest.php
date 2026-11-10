<?php

/**
 * ExplicitReveal is reached through its interface, and its static reads through ExplicitRevealMasks, from outside lib/Service.
 *
 * Each class listed here was named directly by a lib file in another component.
 * Outside callers now type against and resolve its interface, and the registrar
 * of the class's own component aliases the interface to the class, so every
 * caller still gets the same shared instance. A missing alias is a runtime 500
 * that no other unit test sees, so this test pins the binding as well as the
 * implementation and the callers.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F10ExplicitRevealInterfaceBindingsTest extends TestCase {

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
		ExplicitReveal::class => [ExplicitRevealInterface::class, ContractBindings::class],
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
	 * The keys keep their values, and ExplicitRevealMasks answers what ExplicitReveal answers.
	 *
	 * @return void
	 */
	public function testTheKeysAndMasksAreUnchanged(): void {
		$this->assertSame(expected: 'reveal', actual: ExplicitReveal::KEY);
		$this->assertSame(expected: 'explicit', actual: ExplicitReveal::EXPLICIT);

		$definition = ['type' => 'string', 'authorization' => [ExplicitRevealInterface::KEY => ExplicitRevealInterface::EXPLICIT]];
		$this->assertTrue(condition: ExplicitRevealMasks::isExplicit(definition: $definition));
		$this->assertSame(expected: ExplicitReveal::isExplicit(definition: ['type' => 'string']), actual: ExplicitRevealMasks::isExplicit(definition: ['type' => 'string']));

		$schema = new Schema();
		$schema->setProperties(['bsn' => $definition, 'name' => ['type' => 'string']]);
		$this->assertSame(expected: ExplicitReveal::explicitProperties(schema: $schema), actual: ExplicitRevealMasks::explicitProperties(schema: $schema));
		$object = ['bsn' => '123456789', 'name' => 'Ada'];
		$this->assertSame(expected: ExplicitReveal::maskAll(schema: $schema, object: $object), actual: ExplicitRevealMasks::maskAll(schema: $schema, object: $object));
	}//end testTheKeysAndMasksAreUnchanged()

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
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'Type against ' . self::CLASSES[$class][0] . ' instead.');
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
