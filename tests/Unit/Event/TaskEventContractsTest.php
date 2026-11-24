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
use OCA\OpenRegister\Event\AbstractTaskEvent;
use OCA\OpenRegister\Event\AbstractTaskSequenceEvent;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskSequenceUndecidedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskSequenceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The shared bases of the task events carry the Kernel contracts, while every
 * concrete event keeps the getter types its listeners (buildiq, filinq, hermiq,
 * decidiq, integriq, dossiq) read.
 *
 * @covers \OCA\OpenRegister\Event\AbstractTaskEvent
 * @covers \OCA\OpenRegister\Event\AbstractTaskSequenceEvent
 * @covers \OCA\OpenRegister\Event\TaskTerminalEvent
 * @covers \OCA\OpenRegister\Event\TaskTransitionedEvent
 * @covers \OCA\OpenRegister\Event\TaskSequenceCompletedEvent
 * @covers \OCA\OpenRegister\Event\TaskSequenceUndecidedEvent
 */
final class TaskEventContractsTest extends TestCase {
	private function returnType(string $class, string $method): string {
		$type = (new ReflectionMethod($class, $method))->getReturnType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);

		return $type->getName();
	}//end returnType()

	private function firstParameterType(string $class): string {
		$type = (new ReflectionMethod($class, '__construct'))->getParameters()[0]->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);

		return $type->getName();
	}//end firstParameterType()

	public function testTheBasesCarryTheKernelContracts(): void {
		$this->assertSame(TaskInterface::class, $this->firstParameterType(AbstractTaskEvent::class));
		$this->assertSame(TaskInterface::class, $this->returnType(AbstractTaskEvent::class, 'getTask'));
		$this->assertSame(TaskSequenceInterface::class, $this->firstParameterType(AbstractTaskSequenceEvent::class));
		$this->assertSame(TaskSequenceInterface::class, $this->returnType(AbstractTaskSequenceEvent::class, 'getSequence'));
	}//end testTheBasesCarryTheKernelContracts()

	public function testTheConcreteEventsKeepTheirTypes(): void {
		$this->assertSame(Task::class, $this->firstParameterType(TaskTransitionedEvent::class));
		$this->assertSame(Task::class, $this->returnType(TaskTransitionedEvent::class, 'getTask'));

		foreach ([TaskSequenceCompletedEvent::class, TaskSequenceUndecidedEvent::class] as $class) {
			$this->assertSame(TaskSequence::class, $this->firstParameterType($class), $class);
			$this->assertSame(TaskSequence::class, $this->returnType($class, 'getSequence'), $class);
		}
	}//end testTheConcreteEventsKeepTheirTypes()

	public function testTheTerminalEventCarriesTheKernelTaskContract(): void {
		$this->assertSame(TaskInterface::class, $this->firstParameterType(TaskTerminalEvent::class));
		$this->assertSame(TaskInterface::class, $this->returnType(TaskTerminalEvent::class, 'getTask'));
	}//end testTheTerminalEventCarriesTheKernelTaskContract()

	public function testTheTerminalEventAcceptsAnyTaskContract(): void {
		$task  = $this->createMock(TaskInterface::class);
		$event = new TaskTerminalEvent(task: $task, committed: false);

		$this->assertSame($task, $event->getTask());
		$this->assertFalse($event->isCommitted());
	}//end testTheTerminalEventAcceptsAnyTaskContract()

	public function testTheTerminalEventReadsItsTaskThroughTheContract(): void {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState('completed');
		$task->setOutcome('approved');
		$event = new TaskTerminalEvent(task: $task);

		$this->assertSame($task, $event->getTask());
		$this->assertSame('t-1', $event->getTaskUuid());
		$this->assertSame('completed', $event->getState());
		$this->assertSame('approved', $event->getOutcome());
		$this->assertTrue($event->isCommitted());
	}//end testTheTerminalEventReadsItsTaskThroughTheContract()

	public function testTheRowsImplementTheContracts(): void {
		$this->assertInstanceOf(TaskInterface::class, new Task());
		$this->assertInstanceOf(TaskSequenceInterface::class, new TaskSequence());
	}//end testTheRowsImplementTheContracts()

	public function testTheBaseAndTheConcreteGetterReturnTheSameRow(): void {
		$task     = new Task();
		$sequence = new TaskSequence();
		$terminal = new TaskTerminalEvent(task: $task);
		$closed   = new TaskSequenceUndecidedEvent(sequence: $sequence, task: $task);

		$this->assertSame($task, $terminal->getTask());
		$this->assertSame($task, (new ReflectionMethod(AbstractTaskEvent::class, 'getTask'))->invoke($terminal));
		$this->assertSame($sequence, $closed->getSequence());
		$this->assertSame($sequence, (new ReflectionMethod(AbstractTaskSequenceEvent::class, 'getSequence'))->invoke($closed));
	}//end testTheBaseAndTheConcreteGetterReturnTheSameRow()

	public function testABaseAcceptsAnyTaskContract(): void {
		$task  = $this->createMock(TaskInterface::class);
		$event = new class($task) extends AbstractTaskEvent {
		};

		$this->assertSame($task, $event->getTask());
	}//end testABaseAcceptsAnyTaskContract()

	public function testABaseAcceptsAnySequenceContract(): void {
		$sequence = $this->createMock(TaskSequenceInterface::class);
		$event    = new class($sequence) extends AbstractTaskSequenceEvent {
		};

		$this->assertSame($sequence, $event->getSequence());
	}//end testABaseAcceptsAnySequenceContract()
}//end class
