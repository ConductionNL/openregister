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

use OCA\OpenRegister\Kernel\Contracts\Core\ObjectTimelineLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\Deletion\DestructionCompanions;
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
	}//end testEveryCompanionIsOptional()

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
