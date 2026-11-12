<?php

/**
 * Callers outside lib/Db and lib/Lifecycle reach MagicMapper and SetFieldsAction through contracts.
 *
 * FilesProvider resolves its object finder through MagicObjectFinderInterface,
 * which DbRegistrar aliases to the one shared MagicMapper. LifecycleActionRegistry
 * reads its built-in action map from BuiltinLifecycleActionsInterface instead of
 * naming SetFieldsAction. This test pins the binding, the map and the callers.
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

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use OCA\OpenRegister\Lifecycle\BuiltinLifecycleActionsInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @coversNothing
 */
final class F03InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * MagicObjectFinderInterface is aliased to MagicMapper, which implements it.
	 *
	 * @return void
	 */
	public function testMagicObjectFinderIsBoundToMagicMapper(): void {
		$bindings = (new ReflectionClass(DbRegistrar::class))->getConstant('BINDINGS');

		self::assertIsArray(actual: $bindings);
		self::assertSame(expected: MagicMapper::class, actual: $bindings[MagicObjectFinderInterface::class] ?? null);
		self::assertTrue(condition: is_subclass_of(object_or_class: MagicMapper::class, class: MagicObjectFinderInterface::class));
	}//end testMagicObjectFinderIsBoundToMagicMapper()

	/**
	 * The built-in action map is unchanged and every handler is a lifecycle action.
	 *
	 * @return void
	 */
	public function testBuiltinActionMap(): void {
		self::assertSame(
			expected: [
				'set-fields' => SetFieldsAction::class,
				'set-field' => SetFieldsAction::class,
			],
			actual: BuiltinLifecycleActionsInterface::BUILTINS
		);
		foreach (BuiltinLifecycleActionsInterface::BUILTINS as $handler) {
			self::assertTrue(condition: is_subclass_of(object_or_class: $handler, class: LifecycleActionInterface::class));
		}
	}//end testBuiltinActionMap()

	/**
	 * The callers no longer name the concrete classes.
	 *
	 * @return void
	 */
	public function testCallersNameOnlyContracts(): void {
		$files = (string) file_get_contents(filename: self::LIB . '/Service/Integration/BuiltinProviders/FilesProvider.php');
		self::assertStringNotContainsString(needle: 'Db\\MagicMapper\'', haystack: $files);
		self::assertStringContainsString(needle: 'MagicObjectFinderInterface::class', haystack: $files);

		$registry = (string) file_get_contents(filename: self::LIB . '/Service/Lifecycle/LifecycleActionRegistry.php');
		self::assertStringNotContainsString(needle: 'SetFieldsAction', haystack: $registry);
		self::assertStringContainsString(needle: 'BuiltinLifecycleActionsInterface::BUILTINS', haystack: $registry);
	}//end testCallersNameOnlyContracts()
}//end class
