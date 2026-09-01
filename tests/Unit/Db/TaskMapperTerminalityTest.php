<?php

/**
 * Both TaskMapper write paths announce terminality, and only terminality:
 * a completed task dispatches TaskTerminalEvent from update() and from a
 * winning updateIfOpen(); an open task and a lost conditional update do not.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @covers \OCA\OpenRegister\Event\TaskTerminalEvent
 */
class TaskMapperTerminalityTest extends TestCase {
	use FluentQueryBuilderTrait;

	private function task(string $state): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->resetUpdatedFields();
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setOutcome('approved');

		return $task;
	}//end task()

	public function testAWinningConditionalUpdateOfATerminalTaskDispatches(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::once())->method('dispatchTyped')->with(self::callback(
			static fn (TaskTerminalEvent $event): bool => $event->getTaskUuid() === 't-7'
				&& $event->getState() === Task::STATE_COMPLETED
				&& $event->getOutcome() === 'approved'
		));
		$mapper = new TaskMapper(db: $this->connectionWith(affectedRows: 1), dispatcher: $dispatcher);
		self::assertTrue($mapper->updateIfOpen(task: $this->task(Task::STATE_COMPLETED)));
	}//end testAWinningConditionalUpdateOfATerminalTaskDispatches()

	public function testALostRaceAndAnOpenTaskDispatchNothing(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');

		$lost = new TaskMapper(db: $this->connectionWith(affectedRows: 0), dispatcher: $dispatcher);
		self::assertFalse($lost->updateIfOpen(task: $this->task(Task::STATE_COMPLETED)), 'the row was closed by someone else');

		$open = new TaskMapper(db: $this->connectionWith(affectedRows: 1), dispatcher: $dispatcher);
		self::assertTrue($open->updateIfOpen(task: $this->task(Task::STATE_ACTIVE)));
	}//end testALostRaceAndAnOpenTaskDispatchNothing()

	public function testWithoutADispatcherTheMapperStaysConstructibleAndSilent(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(affectedRows: 1));
		self::assertTrue($mapper->updateIfOpen(task: $this->task(Task::STATE_TERMINATED)));
	}//end testWithoutADispatcherTheMapperStaysConstructibleAndSilent()
}//end class
