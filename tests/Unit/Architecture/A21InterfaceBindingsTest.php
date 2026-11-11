<?php

/**
 * The A21 Service classes are reached through interfaces next to them.
 *
 * Each class listed here had more than ten lib files naming it. It now
 * implements an interface its callers type against, and ContractBindings
 * aliases that interface to the class. A missing alias is a runtime 500 that
 * no other unit test sees, so this test pins the binding as well as the
 * implementation and the callers that were switched.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Service\UserTaskListInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class A21InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Interface => the class ContractBindings binds it to.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		ObjectCalendarTasksInterface::class => TaskService::class,
		RegisterScopedSchemaResolverInterface::class => RegisterScopedSchemaResolver::class,
		UserTaskListInterface::class => TaskService::class,
	];

	/**
	 * Class => the lib files (under lib/) that type against an interface instead of it.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const SWITCHED_CALLERS = [
		TaskService::class => [
			'Service/Configuration/SeedRelatedItems.php',
			'Service/Integration/BuiltinProviders/TasksProvider.php',
			'Service/ObjectRelationCleanupService.php',
			'Service/ObjectSource/CalDavVtodoObjectSourceProvider.php',
		],
		RegisterScopedSchemaResolver::class => [
			'Search/Service/Quality/DedupRuleResolver.php',
			'Service/Federation/ShareTargets.php',
		],
	];

	/**
	 * One case per binding.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function bindings(): array {
		$cases = [];
		foreach (array_keys(self::BINDINGS) as $contract) {
			$cases[$contract] = [$contract];
		}

		return $cases;
	}//end bindings()

	/**
	 * One case per switched caller.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function callers(): array {
		$cases = [];
		foreach (self::SWITCHED_CALLERS as $class => $files) {
			foreach ($files as $file) {
				$cases[$file] = [$class, $file];
			}
		}

		return $cases;
	}//end callers()

	/**
	 * The class implements the interface and ContractBindings aliases it.
	 *
	 * @param class-string $contract The interface.
	 *
	 * @return void
	 */
	#[DataProvider('bindings')]
	public function testTheInterfaceIsImplementedAndBound(string $contract): void {
		$class = self::BINDINGS[$contract];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
		$this->assertArrayHasKey($contract, ContractBindings::BINDINGS);
		$this->assertSame($class, ContractBindings::BINDINGS[$contract]);
	}//end testTheInterfaceIsImplementedAndBound()

	/**
	 * A switched caller no longer names the class in code.
	 *
	 * @param class-string $class The class.
	 * @param string       $file  The caller, relative to lib/.
	 *
	 * @return void
	 */
	#[DataProvider('callers')]
	public function testTheCallerNoLongerNamesTheClass(string $class, string $file): void {
		$source = (string) file_get_contents(self::LIB . '/' . $file);
		$this->assertFalse($this->namesClass(source: $source, class: $class), $file . ' still names ' . $class);
	}//end testTheCallerNoLongerNamesTheClass()

	/**
	 * Whether code (not comments or strings) in this source names the class.
	 *
	 * A bare short name counts when the file imports the class or shares its namespace.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short     = substr($class, (int) strrpos($class, '\\') + 1);
		$namespace = substr($class, 0, (int) strrpos($class, '\\'));
		$bareCount = (str_contains($source, 'use ' . $class . ';') === true
			|| str_contains($source, 'namespace ' . $namespace . ';') === true);
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true
				&& ltrim($token[1], '\\') === $class
			) {
				return true;
			}

			if ($bareCount === true && $token[0] === T_STRING && $token[1] === $short) {
				return true;
			}
		}

		return false;
	}//end namesClass()
}//end class
