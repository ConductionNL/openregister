<?php

/**
 * The note, task and calendar providers ask whether an item is of the addressed object before they touch it.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Integration
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use Exception;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Service\TaskService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProviderServices;

/**
 * @covers \OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider
 * @covers \OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider
 */
class ProviderItemsBelongToObjectTest extends TestCase {

	/**
	 * A provider over a note service that refuses every note.
	 *
	 * @return NotesProvider
	 */
	private function notesProvider(): NotesProvider {
		$notes = $this->createMock(NoteService::class);
		$notes->method('requireNoteOf')->with('uuid-mine', 5)->willThrowException(new Exception('Note not found', 404));
		$notes->expects($this->never())->method('deleteNote');
		$notes->expects($this->never())->method('editNote');

		return new NotesProvider($notes, $this->createMock(IL10N::class));
	}

	/**
	 * A provider over a task service that refuses every task.
	 *
	 * @return TasksProvider
	 */
	private function tasksProvider(): TasksProvider {
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('requireTaskOf')->with('uuid-mine', '7', 'task.ics')->willThrowException(new Exception('Task not found', 404));
		$tasks->expects($this->never())->method('deleteTask');
		$tasks->expects($this->never())->method('updateTask');

		return new TasksProvider(
			new TasksProviderServices(
				$tasks,
				$this->createMock(RegisterMapperInterface::class),
				$this->createMock(SchemaMapperInterface::class),
				$this->createMock(ObjectOperationsInterface::class),
			),
			$this->createMock(IL10N::class),
		);
	}

	public function testDeletingANoteOfAnotherObjectIsRefused(): void {
		$this->expectExceptionCode(404);
		$this->notesProvider()->delete('r', 's', 'uuid-mine', '5');
	}

	public function testUpdatingANoteOfAnotherObjectIsRefused(): void {
		$this->expectExceptionCode(404);
		$this->notesProvider()->update('r', 's', 'uuid-mine', '5', ['message' => 'x']);
	}

	public function testDeletingATaskOfAnotherObjectIsRefused(): void {
		$this->expectExceptionCode(404);
		$this->tasksProvider()->delete('r', 's', 'uuid-mine', '7/task.ics');
	}

	public function testUpdatingATaskOfAnotherObjectIsRefused(): void {
		$this->expectExceptionCode(404);
		$this->tasksProvider()->update('r', 's', 'uuid-mine', '7/task.ics', ['summary' => 'x']);
	}
}
