<?php

/**
 * Lane A11: the RBAC hub classes are reached through the interface next to them.
 *
 * SIG's module coupling counts the files that depend on a module. Each class
 * below had more than ten dependants; its callers now type against its
 * interface, so only construction (`new`), the container binding and the
 * callers listed as left on purpose name the class itself. For each class: it
 * implements its interface, ContractBindings binds that interface to it (a
 * missing binding is a runtime 500 that no other unit test catches), and at
 * most ten lib/ files name it.
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
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class A11InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface its callers type against.
	 *
	 * @var array<class-string, class-string>
	 */
	private const HUBS = [
		DenyResolver::class => DenyResolverInterface::class,
		ObjectScopeResolver::class => ObjectScopeResolverInterface::class,
		PermissionCatalogue::class => PermissionCatalogueInterface::class,
		PropertyRbacHandler::class => PropertyRbacHandlerInterface::class,
	];

	/**
	 * One row per hub class.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function hubs(): array {
		$rows = [];
		foreach (self::HUBS as $class => $interface) {
			$rows[$class] = [$class, $interface];
		}

		return $rows;
	}//end hubs()

	/**
	 * The class implements its interface.
	 *
	 * @param class-string $class     The hub class.
	 * @param class-string $interface Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('hubs')]
	public function testClassImplementsItsInterface(string $class, string $interface): void {
		$this->assertContains($interface, (array) class_implements($class), $class . ' implements ' . $interface);
	}//end testClassImplementsItsInterface()

	/**
	 * ContractBindings binds the interface to the class.
	 *
	 * @param class-string $class     The hub class.
	 * @param class-string $interface Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('hubs')]
	public function testContractBindingsBindsTheInterface(string $class, string $interface): void {
		$this->assertSame($class, (ContractBindings::BINDINGS[$interface] ?? null), 'ContractBindings::BINDINGS binds ' . $interface);
	}//end testContractBindingsBindsTheInterface()

	/**
	 * Every public method of the class is on its interface, so a caller never needs the class.
	 *
	 * @param class-string $class     The hub class.
	 * @param class-string $interface Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('hubs')]
	public function testTheInterfaceCarriesEveryPublicMethod(string $class, string $interface): void {
		$missing = [];
		foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isStatic() === false && $method->isConstructor() === false && method_exists($interface, $method->getName()) === false) {
				$missing[] = $method->getName();
			}
		}

		$this->assertSame([], $missing, $interface . ' lacks public methods of ' . $class);
	}//end testTheInterfaceCarriesEveryPublicMethod()

	/**
	 * At most ten lib/ files name the class itself.
	 *
	 * @param class-string $class     The hub class.
	 * @param class-string $interface Its interface.
	 *
	 * @return void
	 */
	#[DataProvider('hubs')]
	public function testAtMostTenFilesNameTheClassItself(string $class, string $interface): void {
		$referrers = $this->filesNaming(class: $class);
		$this->assertLessThanOrEqual(
			10,
			count($referrers),
			$class . ' is named by ' . implode(', ', $referrers) . '; type the caller against ' . $interface
		);
	}//end testAtMostTenFilesNameTheClassItself()

	/**
	 * The lib/ files, other than the class and its interface, whose code names the class.
	 *
	 * An import, a fully qualified name, or the bare name in the class's own
	 * namespace counts; comments do not.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<int, string> Paths relative to lib/, sorted.
	 */
	private function filesNaming(string $class): array {
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$found     = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS)) as $file) {
			$path = (string) $file;
			if (str_ends_with($path, '.php') === true
				&& in_array(basename($path, '.php'), [$short, $short . 'Interface'], true) === false
				&& $this->codeNames(path: $path, class: $class, namespace: $namespace, short: $short) === true
			) {
				$found[] = substr($path, strlen(self::LIB) + 1);
			}
		}

		sort($found);
		return $found;
	}//end filesNaming()

	/**
	 * Whether one file's code, comments aside, names the class.
	 *
	 * @param string $path      The file.
	 * @param string $class     The fully qualified class name.
	 * @param string $namespace The class's namespace.
	 * @param string $short     The class's short name.
	 *
	 * @return bool
	 */
	private function codeNames(string $path, string $class, string $namespace, string $short): bool {
		$code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
		$body = (string) preg_replace('/^namespace\s+[^;]+;/m', '', $code);
		if (preg_match('/' . preg_quote($class, '/') . '(?![\w\\\\])/', $body) === 1) {
			return true;
		}

		return preg_match('/^namespace\s+' . preg_quote($namespace, '/') . ';/m', $code) === 1
			&& preg_match('/(?<![\w\\\\$])' . $short . '(?!\w)/', $body) === 1;
	}//end codeNames()
}//end class
