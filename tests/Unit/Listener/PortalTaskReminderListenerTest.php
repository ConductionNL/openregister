<?php

/**
 * The timer consumption: a preBreach rung on an external task becomes a
 * portal reminder to the party; a slaBreached rung delivers nothing to them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Listener\PortalTaskReminderListener;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A stand-in for flow-business-timers' fired event, with its published surface.
 */
final class FakeTimerFiredEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $kind `rung` or `expiry`.
	 * @param string|null $rungKey The rung key.
	 * @param array<int, array<string, string>> $recipients The recipients.
	 * @param object|null $timer The timer.
	 */
	public function __construct(
		private readonly string $kind,
		private readonly ?string $rungKey,
		private readonly array $recipients,
		private readonly ?object $timer,
	) {
		parent::__construct();
	}//end __construct()

	public function getKind(): string {
		return $this->kind;
	}//end getKind()

	public function getRungKey(): ?string {
		return $this->rungKey;
	}//end getRungKey()

	public function getRecipients(): array {
		return $this->recipients;
	}//end getRecipients()

	public function getTimer(): ?object {
		return $this->timer;
	}//end getTimer()

	public function getPriority(): ?string {
		return 'medium';
	}//end getPriority()

	public function getMessage(): ?string {
		return 'reminder.first';
	}//end getMessage()
}//end class

/**
 * Tests for {@see PortalTaskReminderListener}.
 *
 * @covers \OCA\OpenRegister\Listener\PortalTaskReminderListener
 */
class PortalTaskReminderListenerTest extends TestCase {

	/**
	 * The seam, mocked.
	 *
	 * @var PortalTaskDeliveryService&MockObject
	 */
	private PortalTaskDeliveryService&MockObject $delivery;

	/**
	 * The lifecycle, mocked.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * The listener under test.
	 *
	 * @var PortalTaskReminderListener
	 */
	private PortalTaskReminderListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->delivery = $this->createMock(PortalTaskDeliveryService::class);
		$this->delivery->method('messageFor')->willReturn(['title' => 'Send the payslip']);
		$this->tasks = $this->createMock(TaskService::class);
		$this->listener = new PortalTaskReminderListener(delivery: $this->delivery, tasks: $this->tasks, logger: new NullLogger());
	}//end setUp()

	/**
	 * A timer anchored to a task.
	 *
	 * @param string $subjectType The subject type.
	 *
	 * @return object The timer.
	 */
	private function timer(string $subjectType = 'task'): object {
		return new class ($subjectType) {
			public function __construct(private readonly string $type) {
			}

			public function getSubjectType(): string {
				return $this->type;
			}

			public function getSubjectUuid(): string {
				return 't-1';
			}
		};
	}//end timer()

	/**
	 * An open external task matched to `party:bsn-1`.
	 *
	 * @return Task The task.
	 */
	private function externalTask(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');

		return $task;
	}//end externalTask()

	/**
	 * The party as the timers resolve it: type external, id the party reference.
	 *
	 * @return array<string, string> The recipient.
	 */
	private function partyRecipient(): array {
		return ['type' => 'external', 'id' => 'party:bsn-1', 'role' => 'handler'];
	}//end partyRecipient()

	/**
	 * A preBreach rung addressed to the party becomes a reminder delivery
	 * request through the seam, carrying the rung.
	 *
	 * @return void
	 */
	public function testAPreBreachRungRemindsThePartyThroughTheSeam(): void {
		$task = $this->externalTask();
		$this->tasks->method('get')->with('t-1')->willReturn($task);
		$this->delivery->expects($this->once())
			->method('request')
			->with(
				$task,
				PortalTaskDelivery::KIND_REMINDER,
				$this->callback(static fn (array $message): bool => $message['rungKey'] === 'preBreach:2:businessDays' && $message['priority'] === 'medium')
			)
			->willReturn([]);

		$this->listener->handle(new FakeTimerFiredEvent('rung', 'preBreach:2:businessDays', [$this->partyRecipient()], $this->timer()));
	}//end testAPreBreachRungRemindsThePartyThroughTheSeam()

	/**
	 * A slaBreached rung escalates inward: NOTHING is delivered to the party,
	 * even when the timers listed them as a recipient.
	 *
	 * @return void
	 */
	public function testABreachRungDeliversNothingToTheParty(): void {
		$this->tasks->method('get')->willReturn($this->externalTask());
		$this->delivery->expects($this->never())->method('request');

		$this->listener->handle(
			new FakeTimerFiredEvent('rung', 'slaBreached:0:hours', [$this->partyRecipient(), ['type' => 'role', 'id' => 'teamLeader', 'role' => 'teamLeader']], $this->timer())
		);
	}//end testABreachRungDeliversNothingToTheParty()

	/**
	 * Nothing is delivered for: an expiry, a rung not addressed to the party,
	 * a non-task subject, a non-external task, a terminal task, a vanished
	 * task, or an event that is not a timer fire.
	 *
	 * @return void
	 */
	public function testNothingElseReachesTheSeam(): void {
		$this->delivery->expects($this->never())->method('request');
		$this->tasks->method('get')->willReturnCallback(
			function (string $uuid): Task {
				if ($uuid === 'gone') {
					throw new DoesNotExistException('gone');
				}

				return $this->externalTask();
			}
		);

		$this->listener->handle(new FakeTimerFiredEvent('expiry', null, [$this->partyRecipient()], $this->timer()));
		$this->listener->handle(new FakeTimerFiredEvent('rung', 'preBreach:1:hours', [['type' => 'user', 'id' => 'alice', 'role' => 'handler']], $this->timer()));
		$this->listener->handle(new FakeTimerFiredEvent('rung', 'preBreach:1:hours', [$this->partyRecipient()], $this->timer('object')));
		$this->listener->handle(new FakeTimerFiredEvent('rung', 'preBreach:1:hours', [$this->partyRecipient()], null));
		$this->listener->handle(new Event());

		$internal = $this->externalTask();
		$internal->setPerformerType(Task::PERFORMER_USER);
		$internal->setAssignee('alice');
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('get')->willReturn($internal);
		(new PortalTaskReminderListener(delivery: $this->delivery, tasks: $tasks, logger: new NullLogger()))
			->handle(new FakeTimerFiredEvent('rung', 'preBreach:1:hours', [['type' => 'user', 'id' => 'alice', 'role' => 'handler']], $this->timer()));

		$closed = $this->externalTask();
		$closed->setState(Task::STATE_COMPLETED);
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('get')->willReturn($closed);
		(new PortalTaskReminderListener(delivery: $this->delivery, tasks: $tasks, logger: new NullLogger()))
			->handle(new FakeTimerFiredEvent('rung', 'preBreach:1:hours', [$this->partyRecipient()], $this->timer()));

		$this->addToAssertionCount(1);
	}//end testNothingElseReachesTheSeam()

	/**
	 * A seam failure is logged and swallowed: the rung has fired regardless.
	 *
	 * @return void
	 */
	public function testASeamFailureIsSwallowed(): void {
		$this->tasks->method('get')->willReturn($this->externalTask());
		$this->delivery->method('request')->willThrowException(new \RuntimeException('db gone'));

		$this->listener->handle(new FakeTimerFiredEvent('rung', 'preBreach:1:hours', [$this->partyRecipient()], $this->timer()));
		$this->addToAssertionCount(1);
	}//end testASeamFailureIsSwallowed()
}//end class
