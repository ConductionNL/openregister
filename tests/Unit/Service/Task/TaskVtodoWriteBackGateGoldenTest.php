<?php

/**
 * Golden (characterisation) matrix for TaskVtodoWriteBackGate.
 *
 * Pins what crosses the gate (ALLOWED: the one lifecycle call with its exact
 * arguments) and every REFUSED path: the exception type and message, and the
 * exact sequence of side effects a refusal makes — the audit entry and its
 * fields, the projection reconcile, the refusal notice and the log lines,
 * in order. The gate can then be restructured without moving any of them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRulesInterface;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\Task\WriteBack\WriteBackRefusal;
use OCA\OpenRegister\Service\Task\WriteBack\WriteBackRefusalAudit;
use OCA\OpenRegister\Service\Task\WriteBack\WriteBackRefusalNotice;
use OCA\OpenRegister\Service\Task\WriteBack\WriteBackTaskResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every branch of handleWrite(), request() and isProjected(), pinned.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate
 * @covers \OCA\OpenRegister\Service\Task\WriteBack\WriteBackAttempt
 * @covers \OCA\OpenRegister\Service\Task\WriteBack\WriteBackRefusal
 * @covers \OCA\OpenRegister\Service\Task\WriteBack\WriteBackRefusalAudit
 * @covers \OCA\OpenRegister\Service\Task\WriteBack\WriteBackRefusalNotice
 * @covers \OCA\OpenRegister\Service\Task\WriteBack\WriteBackTaskResolver
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @uses \OCA\OpenRegister\Service\Task\TaskVtodoStatusMapping
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\TaskObjectAdapter
 */
final class TaskVtodoWriteBackGateGoldenTest extends TestCase {

	private const UUID = TaskCalendarProjectorTest::PROJECTED_UUID;

	private const MISSING = '[TaskVtodoWriteBackGate] Refused: The calendar entry names a task that does not exist.';

	/**
	 * Every side effect, in the order it happened.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * How the collaborators behave: `task` (null = missing), `echo`,
	 * `lifecycle` (a Throwable to throw, else the returned task),
	 * `auditFails`, `notifyFails`.
	 *
	 * @var array<string, mixed>
	 */
	private array $world = [];

	/**
	 * A projected VTODO document.
	 *
	 * @param string $status The VTODO status.
	 *
	 * @return string The document.
	 */
	private static function vtodo(string $status): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:" . self::UUID . "\r\nSUMMARY:Approve\r\nSTATUS:{$status}\r\n"
			. 'X-OPENREGISTER-TASK:' . self::UUID . "\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}//end vtodo()

	/**
	 * The lifecycle collaborator, recording each verb call.
	 *
	 * @return TaskServiceInterface The mock.
	 */
	private function lifecycle(): TaskServiceInterface {
		$tasks  = $this->createMock(TaskServiceInterface::class);
		$answer = function (array $call): Task {
			$this->events[] = $call;
			$outcome = $this->world['lifecycle'];
			if ($outcome instanceof Throwable) {
				throw $outcome;
			}

			return $outcome;
		};
		$tasks->method('complete')->willReturnCallback(
			static fn (string $uuid, string $outcome, ?string $resultText, ?string $comment, ?string $actor): Task => $answer(
				['complete', $uuid, $outcome, $resultText, $comment, $actor]
			)
		);
		$tasks->method('cancel')->willReturnCallback(
			static fn (string $uuid, ?string $reason, ?string $actor): Task => $answer(['cancel', $uuid, $reason, $actor])
		);

		return $tasks;
	}//end lifecycle()

	/**
	 * The mapper, the audit and the projections, recording.
	 *
	 * @return array{0: TaskMapper, 1: TaskAuditMapper, 2: TaskProjectionService} The mocks.
	 */
	private function stores(): array {
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid): Task {
				$this->events[] = ['find', $uuid];
				if ($this->world['task'] === null) {
					throw new DoesNotExistException('no such task');
				}

				return $this->world['task'];
			}
		);

		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willReturnCallback(
			function (TaskAudit $entry): TaskAudit {
				$this->events[] = [
					'audit', $entry->getTaskId(), $entry->getAction(), $entry->getStateAfter(), $entry->getActor(),
					$entry->getPerformerType(), $entry->getOnBehalfOf(), $entry->getMandate(), $entry->getReason(),
					$entry->getAuthorized(),
				];
				if (($this->world['auditFails'] ?? false) === true) {
					throw new RuntimeException('audit down');
				}

				return $entry;
			}
		);

		$projections = $this->createMock(TaskProjectionService::class);
		$projections->method('reconcileTask')->willReturnCallback(
			function (Task $task): bool {
				$this->events[] = ['reconcile', $task->getUuid()];

				return true;
			}
		);

		return [$mapper, $audits, $projections];
	}//end stores()

	/**
	 * The projector, the inbox, the dispatcher and the logger, recording.
	 *
	 * @return array{0: TaskCalendarProjector, 1: TaskInboxService, 2: NotificationRuleDispatcherInterface, 3: LoggerInterface} The mocks.
	 */
	private function surfaces(): array {
		$projector = $this->createMock(TaskCalendarProjector::class);
		$projector->method('isEcho')->willReturnCallback(fn (): bool => ($this->world['echo'] ?? false));
		$projector->method('render')->willReturnCallback(static fn (Task $task): string => 'RENDERED:' . $task->getState());

		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('enrich')->willReturn(['displayTitle' => 'Approve']);

		$dispatcher = $this->createMock(NotificationRuleDispatcherInterface::class);
		$dispatcher->method('dispatchWithSchema')->willReturnCallback(
			function (ObjectEntity $object, string $trigger, array $context): void {
				$payload = $object->getObject();
				$this->events[] = ['notify', $payload['writeBackActor'], $payload['writeBackReason'], $trigger, $context];
				if (($this->world['notifyFails'] ?? false) === true) {
					throw new RuntimeException('notify down');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->events[] = [$level, $message, $context];
				}
			);
		}

		return [$projector, $inbox, $dispatcher, $logger];
	}//end surfaces()

	/**
	 * The gate under the current world.
	 *
	 * @return TaskVtodoWriteBackGate The gate.
	 */
	private function gate(): TaskVtodoWriteBackGate {
		[$mapper, $audits, $projections] = $this->stores();
		[$projector, $inbox, $dispatcher, $logger] = $this->surfaces();

		return new TaskVtodoWriteBackGate(
			tasks: $this->lifecycle(),
			resolver: new WriteBackTaskResolver(mapper: $mapper, logger: $logger),
			projector: $projector,
			refusal: new WriteBackRefusal(
				audit: new WriteBackRefusalAudit(audits: $audits, logger: $logger),
				projections: $projections,
				notice: new WriteBackRefusalNotice(
					inbox: $inbox,
					dispatcher: $dispatcher,
					rules: $this->createMock(TaskNotificationRulesInterface::class),
					logger: $logger
				),
				logger: $logger
			)
		);
	}//end gate()

	/**
	 * Run an act, answering its result or the class and message it threw.
	 *
	 * @param callable $act The act.
	 *
	 * @return mixed The result, or [class, message].
	 */
	private static function outcome(callable $act): mixed {
		try {
			return $act();
		} catch (Throwable $thrown) {
			return [$thrown::class, $thrown->getMessage()];
		}
	}//end outcome()

	/**
	 * An open task with an audit trail identity.
	 *
	 * @return Task The task.
	 */
	private static function openTask(): Task {
		$task = TaskCalendarProjectorTest::assignedTask();
		$task->setOnBehalfOf('olga');
		$task->setMandate('m-1');

		return $task;
	}//end openTask()

	/**
	 * The audit, reconcile, notice and log a refusal makes, in order.
	 *
	 * @param string      $verb    The verb recorded.
	 * @param string|null $actor   The actor.
	 * @param string      $reason  The reason.
	 * @param boolean     $audited Whether the gate writes the audit entry.
	 *
	 * @return array<int, array<int, mixed>> The events.
	 */
	private static function refusal(string $verb, ?string $actor, string $reason, bool $audited): array {
		$events = [];
		if ($audited === true) {
			$events[] = ['audit', 2, $verb, Task::STATE_ACTIVE, $actor, Task::PERFORMER_USER, 'olga', 'm-1', 'Calendar write-back refused: ' . $reason, false];
		}

		$events[] = ['reconcile', self::UUID];
		$events[] = ['notify', $actor, $reason, 'transition', ['action' => TaskNotificationRulesInterface::ACTION_WRITE_BACK_REFUSED, 'actor' => $actor]];
		$events[] = [
			'info',
			'[TaskVtodoWriteBackGate] Refused a calendar write-back: ' . $reason,
			['task' => self::UUID, 'verb' => $verb, 'actor' => $actor],
		];

		return $events;
	}//end refusal()

	/**
	 * Not projected, or the projector's own echo: left alone, nothing happens.
	 *
	 * @return void
	 */
	public function testWritesThatAreNotTheEnginesBusiness(): void {
		$this->world = ['task' => self::openTask(), 'lifecycle' => self::openTask()];
		$plain = "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nUID:x\r\nSTATUS:COMPLETED\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

		$this->assertNull($this->gate()->handleWrite(calendarData: $plain, actor: 'alice'));
		$this->assertFalse($this->gate()->isProjected(calendarData: $plain));
		$this->assertTrue($this->gate()->isProjected(calendarData: self::vtodo(status: 'COMPLETED')));

		$this->world['echo'] = true;
		$this->assertNull($this->gate()->handleWrite(calendarData: self::vtodo(status: 'COMPLETED'), actor: 'alice'));
		$this->assertSame([], $this->events);
	}//end testWritesThatAreNotTheEnginesBusiness()

	/**
	 * A write naming a missing task is refused with a warning, nothing else.
	 *
	 * @return void
	 */
	public function testAMissingTaskIsRefused(): void {
		$this->world = ['task' => null, 'lifecycle' => self::openTask()];

		$this->assertSame(
			[DoesNotExistException::class, 'no such task'],
			self::outcome(fn () => $this->gate()->handleWrite(calendarData: self::vtodo(status: 'COMPLETED'), actor: 'alice'))
		);
		$this->assertSame(
			[DoesNotExistException::class, 'no such task'],
			self::outcome(fn () => $this->gate()->request(taskUuid: self::UUID, verb: 'assign', actor: null))
		);
		$this->assertSame(
			[
				['find', self::UUID],
				['warning', self::MISSING, ['task' => self::UUID, 'actor' => 'alice']],
				['find', self::UUID],
				['warning', self::MISSING, ['task' => self::UUID, 'actor' => null]],
			],
			$this->events
		);
	}//end testAMissingTaskIsRefused()

	/**
	 * A non-verb edit is rendered over; ALLOWED verbs cross with their exact arguments.
	 *
	 * @return void
	 */
	public function testAllowedWrites(): void {
		$done = self::openTask();
		$done->setState(Task::STATE_COMPLETED);
		$this->world = ['task' => self::openTask(), 'lifecycle' => $done];

		$this->assertSame('RENDERED:active', $this->gate()->handleWrite(calendarData: self::vtodo(status: 'IN-PROCESS'), actor: 'alice'));
		$this->assertSame('RENDERED:completed', $this->gate()->handleWrite(calendarData: self::vtodo(status: 'COMPLETED'), actor: 'alice'));
		$this->assertSame('RENDERED:completed', $this->gate()->handleWrite(calendarData: self::vtodo(status: 'CANCELLED'), actor: null));
		$this->assertSame($done, $this->gate()->request(taskUuid: 'other-uuid', verb: 'cancel', actor: 'bob'));

		$this->assertSame(
			[
				['find', self::UUID],
				['find', self::UUID],
				['find', self::UUID],
				['complete', self::UUID, 'done', null, null, 'alice'],
				['find', self::UUID],
				['find', self::UUID],
				['cancel', self::UUID, 'Cancelled from the calendar.', null],
				['find', 'other-uuid'],
				['cancel', 'other-uuid', 'Cancelled from the calendar.', 'bob'],
			],
			$this->events
		);
	}//end testAllowedWrites()

	/**
	 * An illegal status edit on a terminal task: audited, reverted, told, thrown.
	 *
	 * @return void
	 */
	public function testAnIllegalStatusIsRefused(): void {
		$task = self::openTask();
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$this->world = ['task' => $task, 'lifecycle' => $task];
		$reason = "Status 'NEEDS-ACTION' refused: task '" . self::UUID . "' is in terminal state 'completed' and cannot be reopened from a calendar.";

		$this->assertSame(
			[TaskConflictException::class, $reason],
			self::outcome(fn () => $this->gate()->handleWrite(calendarData: self::vtodo(status: 'NEEDS-ACTION'), actor: 'alice'))
		);

		$expected = self::refusal(verb: 'status', actor: 'alice', reason: $reason, audited: true);
		$expected[0][3] = Task::STATE_COMPLETED;
		$this->assertSame(array_merge([['find', self::UUID]], $expected), $this->events);
	}//end testAnIllegalStatusIsRefused()

	/**
	 * A verb outside VERBS is REFUSED before the lifecycle is reached.
	 *
	 * @return void
	 */
	public function testAVerbOutsideTheCalendarSetIsRefused(): void {
		$this->world = ['task' => self::openTask(), 'lifecycle' => self::openTask()];
		$reason = "Verb 'assign' cannot be requested from a calendar; only completion and cancellation can.";

		$this->assertSame(
			[TaskAccessDeniedException::class, $reason],
			self::outcome(fn () => $this->gate()->request(taskUuid: self::UUID, verb: 'assign', actor: 'alice'))
		);
		$this->assertSame(
			array_merge([['find', self::UUID]], self::refusal(verb: 'assign', actor: 'alice', reason: $reason, audited: true)),
			$this->events
		);
		$this->assertSame(['complete', 'cancel'], TaskVtodoWriteBackGate::VERBS);
	}//end testAVerbOutsideTheCalendarSetIsRefused()

	/**
	 * Lifecycle refusals: a denial is NOT re-audited; a conflict or a
	 * validation failure is.
	 *
	 * @return void
	 */
	public function testLifecycleRefusals(): void {
		$cases = [
			[new TaskAccessDeniedException(message: 'denied!'), false],
			[new TaskConflictException(message: 'raced!'), true],
			[new TaskValidationException(message: 'invalid!'), true],
		];

		foreach ($cases as [$thrown, $audited]) {
			$this->events = [];
			$this->world  = ['task' => self::openTask(), 'lifecycle' => $thrown];

			$this->assertSame(
				[$thrown::class, $thrown->getMessage()],
				self::outcome(fn () => $this->gate()->handleWrite(calendarData: self::vtodo(status: 'COMPLETED'), actor: 'alice'))
			);
			$this->assertSame(
				array_merge(
					[['find', self::UUID], ['find', self::UUID], ['complete', self::UUID, 'done', null, null, 'alice']],
					self::refusal(verb: 'complete', actor: 'alice', reason: $thrown->getMessage(), audited: $audited)
				),
				$this->events
			);
		}//end foreach
	}//end testLifecycleRefusals()

	/**
	 * An actor who cannot be named is not notified; the refusal is logged.
	 *
	 * @return void
	 */
	public function testAnUnnameableActorIsNotNotified(): void {
		foreach ([null, '  '] as $actor) {
			$this->events = [];
			$this->world  = ['task' => self::openTask(), 'lifecycle' => new TaskAccessDeniedException(message: 'nobody')];

			$this->assertSame(
				[TaskAccessDeniedException::class, 'nobody'],
				self::outcome(fn () => $this->gate()->request(taskUuid: self::UUID, verb: 'cancel', actor: $actor))
			);
			$this->assertSame(
				[
					['find', self::UUID],
					['cancel', self::UUID, 'Cancelled from the calendar.', $actor],
					['reconcile', self::UUID],
					['info', '[TaskVtodoWriteBackGate] Refusal has no nameable actor to notify.', ['task' => self::UUID]],
					['info', '[TaskVtodoWriteBackGate] Refused a calendar write-back: nobody', ['task' => self::UUID, 'verb' => 'cancel', 'actor' => $actor]],
				],
				$this->events
			);
		}//end foreach
	}//end testAnUnnameableActorIsNotNotified()

	/**
	 * A failing audit or notice is logged and the refusal still completes;
	 * a task without an id gets no audit entry.
	 *
	 * @return void
	 */
	public function testRefusalSideEffectsFailSoft(): void {
		$this->world = [
			'task' => self::openTask(),
			'lifecycle' => new TaskConflictException(message: 'raced!'),
			'auditFails' => true,
			'notifyFails' => true,
		];

		$this->assertSame(
			[TaskConflictException::class, 'raced!'],
			self::outcome(fn () => $this->gate()->request(taskUuid: self::UUID, verb: 'complete', actor: 'alice'))
		);
		$refusal = self::refusal(verb: 'complete', actor: 'alice', reason: 'raced!', audited: true);
		$this->assertSame(
			[
				['find', self::UUID],
				['complete', self::UUID, 'done', null, null, 'alice'],
				$refusal[0],
				['warning', '[TaskVtodoWriteBackGate] Could not record a write-back denial: audit down', ['task' => self::UUID, 'verb' => 'complete']],
				$refusal[1],
				$refusal[2],
				['warning', '[TaskVtodoWriteBackGate] Could not notify the actor of a refusal: notify down', ['task' => self::UUID, 'actor' => 'alice']],
				$refusal[3],
			],
			$this->events
		);

		$this->events = [];
		$unsaved = new Task();
		$unsaved->setUuid(self::UUID);
		$this->world = ['task' => $unsaved, 'lifecycle' => $unsaved];
		self::outcome(fn () => $this->gate()->request(taskUuid: self::UUID, verb: 'edit', actor: 'alice'));
		$this->assertSame(['find', 'reconcile', 'notify', 'info'], array_map(static fn (array $event): string => $event[0], $this->events));
	}//end testRefusalSideEffectsFailSoft()
}//end class
