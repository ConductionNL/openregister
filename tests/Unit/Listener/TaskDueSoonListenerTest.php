<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The listener hands a fired timer to the deadline warning, ignores any
 * other event, and never lets a failed warning escape into the timer sweep.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Listener\TaskDueSoonListener;
use OCA\OpenRegister\Service\Task\TaskDueSoonWarning;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TaskDueSoonListenerTest extends TestCase {
	private static function fired(): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setSubjectType('task');
		$timer->setSubjectUuid('t-1');
		$timer->setPurpose(FlowTimer::PURPOSE_DUE);
		$timer->setFireAt(new \DateTime('2027-03-12T16:00:00+00:00'));

		return new FlowTimerFiredEvent(
			timer: $timer,
			firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:warn', rungKey: 'warn', rungTrigger: 'preBreach')
		);
	}

	public function testAFiredTimerReachesTheWarning(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects($this->once())->method('get')->with('t-1')->willThrowException(new DoesNotExistException('gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new TaskDueSoonListener(warning: new TaskDueSoonWarning(tasks: $tasks), logger: $logger))->handle(self::fired());
	}

	public function testAnotherEventIsIgnored(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects($this->never())->method('get');

		(new TaskDueSoonListener(warning: new TaskDueSoonWarning(tasks: $tasks), logger: $this->createMock(LoggerInterface::class)))->handle(new Event());
	}

	public function testAFailedWarningIsLoggedNamingTheTimerAndNotRaised(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('get')->willThrowException(new RuntimeException('database away'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('database away'),
			['timer' => 'timer-1', 'rung' => 'warn']
		);

		(new TaskDueSoonListener(warning: new TaskDueSoonWarning(tasks: $tasks), logger: $logger))->handle(self::fired());
	}
}
