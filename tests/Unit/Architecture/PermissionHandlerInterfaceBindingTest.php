<?php

/**
 * PermissionHandler is reached through its interface.
 *
 * Callers type against PermissionHandlerInterface and ContractBindings aliases it
 * to PermissionHandler, so the heavily referenced file is the small interface. A
 * missing alias is a runtime 500 that no other unit test sees, so this test pins
 * the binding, the implementation and the only callers still allowed to name the
 * class: the ones that call its static helpers.
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
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class PermissionHandlerInterfaceBindingTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Files that still name the class: the static-helper callers, and one log prefix.
	 *
	 * @var list<string>
	 */
	private const STATIC_CALLERS = [
		'Db/MagicMapper/MagicRbacHandler.php',
		'Service/Rbac/RoleBlockExpander.php',
	];

	/**
	 * The class implements the interface.
	 *
	 * @return void
	 */
	public function testTheClassImplementsTheInterface(): void {
		$this->assertTrue(condition: is_subclass_of(object_or_class: PermissionHandler::class, class: PermissionHandlerInterface::class));
	}//end testTheClassImplementsTheInterface()

	/**
	 * ContractBindings aliases the interface to the class.
	 *
	 * @return void
	 */
	public function testTheInterfaceIsBound(): void {
		$this->assertSame(expected: PermissionHandler::class, actual: ContractBindings::BINDINGS[PermissionHandlerInterface::class] ?? null);
	}//end testTheInterfaceIsBound()

	/**
	 * Outside its own directory only the static callers name the class.
	 *
	 * @return void
	 */
	public function testOnlyStaticCallersNameTheClass(): void {
		$naming = [];
		$files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = substr($file->getPathname(), strlen(self::LIB) + 1);
			if ($file->getExtension() !== 'php' || str_starts_with($path, 'Service/Object/') === true || $path === 'Service/ContractBindings.php') {
				continue;
			}

			$code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file->getPathname()));
			if (preg_match('/\bPermissionHandler\b(?!Interface)/', (string) $code) === 1) {
				$naming[] = $path;
			}
		}

		sort($naming);
		$this->assertSame(expected: self::STATIC_CALLERS, actual: $naming);
	}//end testOnlyStaticCallersNameTheClass()
}//end class
