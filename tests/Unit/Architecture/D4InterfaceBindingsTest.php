<?php

/**
 * Lane D4: classes reached from other components only through their interface.
 *
 * Each class below sits in a first-level component under lib/ and has an
 * interface next to it. The test checks that the class implements it, that
 * the component's registrar binds it (a missing binding is a 500 at the first
 * request, which no other unit test sees), and that no lib/ file outside the
 * class's own component names the concrete class.
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
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Service\LinkedEntityServiceInterface;
use OCA\OpenRegister\Service\MigrationService;
use OCA\OpenRegister\Service\MigrationServiceInterface;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\OasServiceInterface;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\ObjectRelationCleanupServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guards the interfaces, bindings and outside callers of the D4 classes.
 */
final class D4InterfaceBindingsTest extends TestCase {

	/**
	 * The lib/ directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [its interface, the registrar whose BINDINGS bind it].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string}>
	 */
	private const CLASSES = [
		LinkedEntityService::class => [LinkedEntityServiceInterface::class, ContractBindings::class],
		MigrationService::class => [MigrationServiceInterface::class, ContractBindings::class],
		OasService::class => [OasServiceInterface::class, ContractBindings::class],
		ObjectRelationCleanupService::class => [ObjectRelationCleanupServiceInterface::class, ContractBindings::class],
	];

	/**
	 * Every class implements its interface.
	 *
	 * @return void
	 */
	public function testEveryClassImplementsItsInterface(): void {
		foreach (self::CLASSES as $class => [$interface]) {
			$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
		}
	}//end testEveryClassImplementsItsInterface()

	/**
	 * Every interface is bound to its class in the registrar's BINDINGS.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsBound(): void {
		foreach (self::CLASSES as $class => [$interface, $registrar]) {
			$bindings = constant($registrar . '::BINDINGS');
			$this->assertSame($class, $bindings[$interface] ?? null, $interface . ' is bound in ' . $registrar);
		}
	}//end testEveryInterfaceIsBound()

	/**
	 * No lib/ file outside the class's component names the concrete class.
	 *
	 * @return void
	 */
	public function testNoOtherComponentNamesTheClass(): void {
		foreach (array_keys(self::CLASSES) as $class) {
			$this->assertSame([], $this->outsideFilesNaming(class: $class), $class . ' is named outside its component');
		}
	}//end testNoOtherComponentNamesTheClass()

	/**
	 * The lib/ files outside the class's component that name the class.
	 *
	 * @param string $class The fully qualified class name
	 *
	 * @return string[] Paths relative to lib/
	 */
	private function outsideFilesNaming(string $class): array {
		$component = $this->componentOf(class: $class);
		$found     = [];
		$files     = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = substr((string) $file, strlen(self::LIB) + 1);
			if (str_ends_with($path, '.php') === true
				&& str_starts_with($path, $component . '/') === false
				&& $this->names(source: (string) file_get_contents((string) $file), class: $class) === true
			) {
				$found[] = $path;
			}
		}

		sort($found);
		return $found;
	}//end outsideFilesNaming()

	/**
	 * Whether the code names the class, outside comments.
	 *
	 * @param string $source The PHP source
	 * @param string $class  The fully qualified class name
	 *
	 * @return bool True when the class is named
	 */
	private function names(string $source, string $class): bool {
		$code      = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$body      = (string) preg_replace('/^namespace\s+[^;]+;/m', '', $code);
		if (preg_match('/' . preg_quote($class, '/') . '(?![\w\\\\])/', $body) === 1) {
			return true;
		}

		$sameNamespace = preg_match('/^namespace\s+' . preg_quote($namespace, '/') . ';/m', $code) === 1;
		return $sameNamespace === true && preg_match('/(?<![\w\\\\$])' . $short . '(?!\w)/', $body) === 1;
	}//end names()

	/**
	 * The first-level lib/ directory holding the class.
	 *
	 * @param string $class The fully qualified class name
	 *
	 * @return string The component directory name
	 */
	private function componentOf(string $class): string {
		$file = (string) (new \ReflectionClass($class))->getFileName();
		$path = substr($file, strlen((string) realpath(self::LIB)) + 1);
		return explode('/', $path)[0];
	}//end componentOf()
}//end class
