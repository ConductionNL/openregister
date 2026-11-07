<?php

/**
 * Role interfaces next to top-level Service classes are implemented and bound.
 *
 * FileService had 27 files naming it. Its callers now type against role
 * interfaces (reader, writer, folders, handler support) that FileService
 * implements and ContractBindings aliases to it. A missing alias is a runtime
 * 500 that no other unit test sees, so this test pins each binding.
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
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCA\OpenRegister\Service\FileReaderInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class A19InterfaceBindingsTest extends TestCase {

	/**
	 * Interface => the class it is bound to.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BOUND = [
		FileFolderServiceInterface::class => FileService::class,
		FileHandlerSupportInterface::class => FileService::class,
		FileReaderInterface::class => FileService::class,
		FileWriterInterface::class => FileService::class,
		ObjectFilesInterface::class => FileService::class,
	];

	/**
	 * Interface => the role interfaces it extends.
	 *
	 * @var array<class-string, list<class-string>>
	 */
	private const EXTENDS = [
		FileServiceInterface::class => [ObjectFilesInterface::class, FileFolderServiceInterface::class],
		ObjectFilesInterface::class => [FileReaderInterface::class, FileWriterInterface::class],
		FileHandlerSupportInterface::class => [ObjectFilesInterface::class, FileFolderServiceInterface::class],
	];

	/**
	 * One case per bound interface.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function bound(): array {
		$cases = [];
		foreach (self::BOUND as $contract => $class) {
			$cases[$contract] = [$contract, $class];
		}

		return $cases;
	}//end bound()

	/**
	 * One case per extending interface.
	 *
	 * @return array<string, array{class-string, list<class-string>}>
	 */
	public static function extending(): array {
		$cases = [];
		foreach (self::EXTENDS as $contract => $parents) {
			$cases[$contract] = [$contract, $parents];
		}

		return $cases;
	}//end extending()

	/**
	 * The class implements the interface and ContractBindings aliases it.
	 *
	 * @param class-string $contract The interface.
	 * @param class-string $class    The class.
	 *
	 * @return void
	 */
	#[DataProvider('bound')]
	public function testTheInterfaceIsImplementedAndBound(string $contract, string $class): void {
		$this->assertTrue(interface_exists(interface: $contract), $contract . ' exists');
		$this->assertContains($contract, class_implements(object_or_class: $class), $class . ' implements ' . $contract);
		$this->assertSame($class, ContractBindings::BINDINGS[$contract] ?? null, $contract . ' is bound');
	}//end testTheInterfaceIsImplementedAndBound()

	/**
	 * The interface extends its role interfaces, so existing callers keep every method.
	 *
	 * @param class-string       $contract The interface.
	 * @param list<class-string> $parents  The role interfaces.
	 *
	 * @return void
	 */
	#[DataProvider('extending')]
	public function testTheInterfaceExtendsItsRoles(string $contract, array $parents): void {
		foreach ($parents as $parent) {
			$this->assertContains($parent, class_implements(object_or_class: $contract), $contract . ' extends ' . $parent);
		}
	}//end testTheInterfaceExtendsItsRoles()
}//end class
