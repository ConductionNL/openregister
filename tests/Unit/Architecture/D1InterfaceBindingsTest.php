<?php

/**
 * The Db classes other components reach only through an interface stay hidden.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A missing binding is a 500 at the first request, which no other unit
 * test catches, so every hidden class is checked for its binding here.
 *
 * @coversNothing
 */
final class D1InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Hidden class => [the interface its outside callers use, the registrar that binds it, its component].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string, 2: string}>
	 */
	private const HIDDEN = [
		EntityRelationMapper::class => [EntityRelationMapperInterface::class, DbRegistrar::class, 'Db'],
		FlowRunMapper::class => [FlowRunMapperInterface::class, DbRegistrar::class, 'Db'],
		MappingMapper::class => [MappingMapperInterface::class, DbRegistrar::class, 'Db'],
	];

	/**
	 * Class => the outside callers deliberately left naming it, relative to lib/.
	 *
	 * ExportHandler and ImportHandler are rewritten by the open flow
	 * export/import PR (#5968); they and the registrar that builds them keep
	 * the concrete MappingMapper until that lands.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const LEFT = [
		MappingMapper::class => [
			'Service/Configuration/ConfigurationServiceRegistrar.php',
			'Service/Configuration/ExportHandler.php',
			'Service/Configuration/ImportHandler.php',
		],
	];

	/**
	 * One row per hidden class.
	 *
	 * @return array<string, array{0: class-string, 1: class-string, 2: class-string, 3: string}>
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
		$this->assertTrue(interface_exists($interface), $interface . ' exists');
		$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
	}//end testTheClassImplementsItsInterface()

	/**
	 * The component's registrar binds the interface to the class.
	 *
	 * @param class-string $class     The hidden class.
	 * @param class-string $interface The interface callers type against.
	 * @param class-string $registrar The registrar of the class's component.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testTheRegistrarBindsTheInterface(string $class, string $interface, string $registrar): void {
		$this->assertSame($class, constant($registrar . '::BINDINGS')[$interface] ?? null, $interface . ' is bound in ' . $registrar);
	}//end testTheRegistrarBindsTheInterface()

	/**
	 * No lib/ file outside the class's component names the class.
	 *
	 * @param class-string $class     The hidden class.
	 * @param class-string $interface The interface callers type against.
	 * @param class-string $registrar The registrar of the class's component.
	 * @param string       $component The first-level lib/ directory of the class.
	 *
	 * @return void
	 */
	#[DataProvider('hiddenClasses')]
	public function testNoOtherComponentNamesTheClass(string $class, string $interface, string $registrar, string $component): void {
		$lib    = realpath(self::LIB) . '/';
		$naming = array_filter(
			$this->filesOutside(component: $component),
			fn (string $path): bool => in_array($class, $this->namesIn(path: $path), true)
		);
		$naming = array_map(static fn (string $path): string => substr($path, strlen($lib)), array_values($naming));
		sort($naming);

		$this->assertSame(self::LEFT[$class] ?? [], $naming, $class . ' is named outside lib/' . $component . '; type the caller against ' . $interface);
	}//end testNoOtherComponentNamesTheClass()

	/**
	 * DbRegistrar registers every binding as an alias.
	 *
	 * @return void
	 */
	public function testDbRegistrarAliasesEveryBinding(): void {
		$context = new RecordingRegistrationContext();
		DbRegistrar::register(context: $context);

		$aliases = array_column($context->aliases, 'target', 'alias');
		$this->assertSame(DbRegistrar::BINDINGS, $aliases);
	}//end testDbRegistrarAliasesEveryBinding()

	/**
	 * Every DbRegistrar binding points at a Db class implementing the interface.
	 *
	 * @return void
	 */
	public function testEveryDbBindingIsImplementedByADbClass(): void {
		foreach (DbRegistrar::BINDINGS as $interface => $class) {
			$this->assertStringStartsWith('OCA\\OpenRegister\\Db\\', $class);
			$this->assertTrue((new \ReflectionClass($class))->implementsInterface($interface), $class . ' implements ' . $interface);
		}
	}//end testEveryDbBindingIsImplementedByADbClass()

	/**
	 * ContractBindings binds no class of the Db component any more.
	 *
	 * @return void
	 */
	public function testContractBindingsBindsNoDbClass(): void {
		$dbClasses = array_filter(
			ContractBindings::BINDINGS,
			static fn (string $class): bool => str_starts_with($class, 'OCA\\OpenRegister\\Db\\')
		);

		$this->assertSame([], $dbClasses);
	}//end testContractBindingsBindsNoDbClass()

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
	 * Counts imports, fully qualified names, and qualified or bare names
	 * resolved against the file's namespace.
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

			$namespace = $this->namespaceAfter(token: $token, namespace: $namespace);
			$names[]   = $this->resolve(token: $token, namespace: $namespace);
		}

		return array_values(array_filter($names));
	}//end namesIn()

	/**
	 * The namespace in force after a token.
	 *
	 * The first qualified name of the file is its namespace declaration.
	 *
	 * @param array{0: int, 1: string, 2: int} $token     The token.
	 * @param string                           $namespace The namespace so far.
	 *
	 * @return string
	 */
	private function namespaceAfter(array $token, string $namespace): string {
		if ($namespace === '' && $token[0] === T_NAME_QUALIFIED) {
			return $token[1];
		}

		return $namespace;
	}//end namespaceAfter()

	/**
	 * The class name a token names, or null.
	 *
	 * An import is a qualified name already fully spelled out, so it is
	 * kept as written as well as resolved against the namespace.
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
