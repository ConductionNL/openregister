<?php

/**
 * SaveObject's role interfaces are bound, and the callers of one role use it.
 *
 * SaveObjectInterface extends SaveObjectWriterInterface (saveObject) and
 * SaveObjectRelationScannerInterface (scanForRelations). A missing alias is a
 * 500 at the first request that no other unit test sees, so the bindings are
 * pinned here, together with the files still typing against the whole
 * interface, so the wide interface does not collect callers again.
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
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\Object\SaveObjectRelationScannerInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class A05InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The lib/ files that still name SaveObjectInterface: they use more than one role.
	 *
	 * @var list<string>
	 */
	private const WHOLE_INTERFACE_USERS = [
		'Service/ContractBindings.php',
		'Service/Object/BulkSaveStores.php',
		'Service/Object/CascadingHandler.php',
		'Service/Object/SaveObject.php',
		'Service/ObjectService.php',
	];

	/**
	 * One row per role interface.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function roles(): array {
		return [
			'writer'           => [SaveObjectWriterInterface::class],
			'relation scanner' => [SaveObjectRelationScannerInterface::class],
		];
	}//end roles()

	/**
	 * The whole interface extends the role, so SaveObject implements it.
	 *
	 * @param class-string $role The role interface.
	 *
	 * @return void
	 */
	#[DataProvider('roles')]
	public function testTheWholeInterfaceExtendsTheRole(string $role): void {
		$this->assertTrue(interface_exists($role), $role . ' exists');
		$this->assertTrue(is_subclass_of(SaveObjectInterface::class, $role), 'SaveObjectInterface extends ' . $role);
		$this->assertTrue(is_subclass_of(SaveObject::class, $role), 'SaveObject implements ' . $role);
	}//end testTheWholeInterfaceExtendsTheRole()

	/**
	 * ContractBindings aliases the role to SaveObject and registers the alias.
	 *
	 * @param class-string $role The role interface.
	 *
	 * @return void
	 */
	#[DataProvider('roles')]
	public function testTheRoleIsBoundToSaveObject(string $role): void {
		$this->assertSame(SaveObject::class, ContractBindings::BINDINGS[$role] ?? null);

		$context = new RecordingRegistrationContext();
		ContractBindings::register(context: $context);
		$aliases = array_column($context->aliases, 'target', 'alias');
		$this->assertSame(SaveObject::class, $aliases[$role] ?? null);
	}//end testTheRoleIsBoundToSaveObject()

	/**
	 * Each role declares exactly the method its callers use.
	 *
	 * @return void
	 */
	public function testEachRoleDeclaresOneMethod(): void {
		$this->assertSame(['saveObject'], get_class_methods(SaveObjectWriterInterface::class));
		$this->assertSame(['scanForRelations'], get_class_methods(SaveObjectRelationScannerInterface::class));
	}//end testEachRoleDeclaresOneMethod()

	/**
	 * Only the callers that need several roles still name the whole interface.
	 *
	 * @return void
	 */
	public function testOnlyMultiRoleCallersNameTheWholeInterface(): void {
		$naming = [];
		$files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = substr($file->getPathname(), strlen(self::LIB) + 1);
			if ($file->getExtension() !== 'php' || $path === 'Service/Object/SaveObjectInterface.php') {
				continue;
			}

			$code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file->getPathname()));
			if (preg_match('/\bSaveObjectInterface\b/', (string) $code) === 1) {
				$naming[] = $path;
			}
		}

		sort($naming);
		$this->assertSame(self::WHOLE_INTERFACE_USERS, $naming);
	}//end testOnlyMultiRoleCallersNameTheWholeInterface()
}//end class
