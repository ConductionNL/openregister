<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskSequenceUndecidedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * Pins what a listening app reads off the task events: decidiq, filinq and hermiq
 * listen to them, and the task services build them with named arguments.
 */
class TaskEventsGoldenTest extends TestCase {
	private function task(): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState('completed');
		$task->setOutcome('approved');
		$task->setAssignee('bob');
		$task->setLastAction('approve');

		return $task;
	}//end task()

	public function testATerminalEventCarriesTheTaskAndTheCommitFlag(): void {
		$task  = $this->task();
		$event = new TaskTerminalEvent(task: $task, committed: false);

		$this->assertSame($task, $event->getTask());
		$this->assertFalse($event->isCommitted());
		$this->assertSame('task-1', $event->getTaskUuid());
		$this->assertSame('completed', $event->getState());
		$this->assertSame('approved', $event->getOutcome());
		$this->assertTrue((new TaskTerminalEvent(task: $task))->isCommitted());
	}//end testATerminalEventCarriesTheTaskAndTheCommitFlag()

	public function testATransitionCarriesWhatCameBefore(): void {
		$task  = $this->task();
		$event = new TaskTransitionedEvent(task: $task, previousAssignee: 'alice', previousState: 'active', actor: 'carol');

		$this->assertSame($task, $event->getTask());
		$this->assertSame('approve', $event->getAction());
		$this->assertSame('alice', $event->getPreviousAssignee());
		$this->assertSame('active', $event->getPreviousState());
		$this->assertSame('carol', $event->getActor());
		$this->assertTrue($event->assigneeChanged());
	}//end testATransitionCarriesWhatCameBefore()

	public function testATransitionDefaultsToNothingBefore(): void {
		$event = new TaskTransitionedEvent(task: $this->task());

		$this->assertNull($event->getPreviousAssignee());
		$this->assertNull($event->getPreviousState());
		$this->assertNull($event->getActor());
	}//end testATransitionDefaultsToNothingBefore()

	public function testTheSameHolderIsNoChange(): void {
		$this->assertFalse((new TaskTransitionedEvent(task: $this->task(), previousAssignee: 'bob'))->assigneeChanged());
	}//end testTheSameHolderIsNoChange()

	public function testACompletedSequenceCarriesItsDecision(): void {
		$sequence = new TaskSequence();
		$task     = $this->task();
		$event    = new TaskSequenceCompletedEvent(sequence: $sequence, finalTask: $task, decider: 'bob', statusOnApprove: 'done');

		$this->assertSame($sequence, $event->getSequence());
		$this->assertSame($task, $event->getFinalTask());
		$this->assertSame('bob', $event->getDecider());
		$this->assertSame('done', $event->getStatusOnApprove());
	}//end testACompletedSequenceCarriesItsDecision()

	public function testAnUndecidedSequenceCarriesItsTask(): void {
		$sequence = new TaskSequence();
		$task     = $this->task();
		$event    = new TaskSequenceUndecidedEvent(sequence: $sequence, task: $task);

		$this->assertSame($sequence, $event->getSequence());
		$this->assertSame($task, $event->getTask());
	}//end testAnUndecidedSequenceCarriesItsTask()

	public function testTheConstructorsKeepTheirArguments(): void {
		$expected = [
			TaskTerminalEvent::class          => ['task', 'committed'],
			TaskTransitionedEvent::class      => ['task', 'previousAssignee', 'previousState', 'actor'],
			TaskSequenceCompletedEvent::class => ['sequence', 'finalTask', 'decider', 'statusOnApprove'],
			TaskSequenceUndecidedEvent::class => ['sequence', 'task'],
		];

		foreach ($expected as $class => $arguments) {
			$parameters = (new ReflectionClass($class))->getConstructor()->getParameters();
			$this->assertSame($arguments, array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters), $class);
			$this->assertTrue(is_subclass_of($class, Event::class));
		}
	}//end testTheConstructorsKeepTheirArguments()

	public function testNoTaskEventIsAnother(): void {
		$this->assertFalse(is_subclass_of(TaskTerminalEvent::class, TaskTransitionedEvent::class));
		$this->assertFalse(is_subclass_of(TaskTransitionedEvent::class, TaskTerminalEvent::class));
		$this->assertFalse(is_subclass_of(TaskSequenceCompletedEvent::class, TaskSequenceUndecidedEvent::class));
		$this->assertFalse(is_subclass_of(TaskSequenceUndecidedEvent::class, TaskSequenceCompletedEvent::class));
	}//end testNoTaskEventIsAnother()
}//end class
