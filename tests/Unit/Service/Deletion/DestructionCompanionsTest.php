<?php

/**
 * DestructionCompanions: the optional collaborators of a declared destruction, typed by their contracts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Deletion;

use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderLookupInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectTimelineLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\Deletion\DestructionCompanions;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\TaskService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The tasks and timeline links are reached through contracts owned below Core, and every companion stays optional.
 *
 * @covers \OCA\OpenRegister\Service\Deletion\DestructionCompanions
 */
final class DestructionCompanionsTest extends TestCase {

	/**
	 * With nothing wired, every companion is absent.
	 *
	 * @return void
	 */
	public function testEveryCompanionIsOptional(): void {
		$companions = new DestructionCompanions();

		$this->assertNull($companions->noteService);
		$this->assertNull($companions->fileService);
		$this->assertNull($companions->taskService);
		$this->assertNull($companions->relationCleanup);
		$this->assertNull($companions->objectFolders());
	}//end testEveryCompanionIsOptional()

	/**
	 * The file service the container resolves (FileService) answers both the file count and the folder lookup.
	 *
	 * @return void
	 */
	public function testTheFileServiceAlsoFindsTheFolderWhenNoLookupIsWired(): void {
		$files = $this->createMock(FileService::class);

		$companions = new DestructionCompanions(fileService: $files);

		$this->assertSame($files, $companions->fileService);
		$this->assertSame($files, $companions->objectFolders());
	}//end testTheFileServiceAlsoFindsTheFolderWhenNoLookupIsWired()

	/**
	 * A wired folder lookup wins over the file service.
	 *
	 * @return void
	 */
	public function testAWiredFolderLookupIsUsed(): void {
		$files   = $this->createMock(FileService::class);
		$folders = $this->createMock(ObjectFolderLookupInterface::class);

		$companions = new DestructionCompanions(fileService: $files, objectFolders: $folders);

		$this->assertSame($folders, $companions->objectFolders());
	}//end testAWiredFolderLookupIsUsed()

	/**
	 * A file service that cannot look folders up gives no folder lookup.
	 *
	 * @return void
	 */
	public function testAFileReaderWithoutFolderLookupGivesNone(): void {
		$companions = new DestructionCompanions(fileService: $this->createMock(ObjectFilesInterface::class));

		$this->assertNull($companions->objectFolders());
	}//end testAFileReaderWithoutFolderLookupGivesNone()

	/**
	 * The files and the folder are typed by Core contracts, which the container binds to FileService.
	 *
	 * @return void
	 */
	public function testTheFileCompanionsAreTypedByCoreContracts(): void {
		$constructor = (new ReflectionClass(DestructionCompanions::class))->getConstructor();
		$types       = [];
		foreach ($constructor->getParameters() as $parameter) {
			$types[$parameter->getName()] = (string)$parameter->getType();
		}

		$this->assertSame('?'.ObjectFilesInterface::class, $types['fileService']);
		$this->assertSame('?'.ObjectFolderLookupInterface::class, $types['objectFolders']);
		$this->assertTrue((new ReflectionClass(FileService::class))->implementsInterface(ObjectFilesInterface::class));
		$this->assertTrue((new ReflectionClass(FileService::class))->implementsInterface(ObjectFolderLookupInterface::class));
	}//end testTheFileCompanionsAreTypedByCoreContracts()

	/**
	 * Any implementation of the two contracts is carried as given.
	 *
	 * @return void
	 */
	public function testTheTaskAndTimelineContractsAreCarriedAsGiven(): void {
		$tasks = $this->createMock(ObjectCalendarTasksInterface::class);
		$links = $this->createMock(ObjectTimelineLinksInterface::class);

		$companions = new DestructionCompanions(taskService: $tasks, relationCleanup: $links);

		$this->assertSame($tasks, $companions->taskService);
		$this->assertSame($links, $companions->relationCleanup);
	}//end testTheTaskAndTimelineContractsAreCarriedAsGiven()

	/**
	 * The container still resolves the same services: each concrete class implements the contract it is typed by.
	 *
	 * @return void
	 */
	public function testTheConcreteServicesImplementTheContracts(): void {
		$this->assertTrue((new ReflectionClass(TaskService::class))->implementsInterface(ObjectCalendarTasksInterface::class));
		$this->assertTrue((new ReflectionClass(ObjectRelationCleanupService::class))->implementsInterface(ObjectTimelineLinksInterface::class));
	}//end testTheConcreteServicesImplementTheContracts()
}//end class
