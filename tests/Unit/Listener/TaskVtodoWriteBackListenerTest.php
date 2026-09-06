<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The safety-net hook: a committed write on a projected VTODO reaches the
 * same gate the Sabre plugin uses (both hooks, one gate); a refusal is a
 * revert, not a failure; a deleted projection is rebuilt; a standalone VTODO
 * is ignored.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Listener\TaskVtodoWriteBackListener;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskVtodoWriteBackListenerTest extends TestCase {
	private const UUID = '00000000-0000-0000-0000-000000000002';

	private TaskVtodoWriteBackGate&MockObject $gate;

	private TaskProjectionService&MockObject $projections;

	protected function setUp(): void {
		parent::setUp();
		$this->gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$this->projections = $this->createMock(TaskProjectionService::class);
	}

	private function listener(?string $uid = 'EXAMPLE_APPROVER_USER'): TaskVtodoWriteBackListener {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new TaskVtodoWriteBackListener($this->gate, $this->projections, $session, new NullLogger());
	}

	private function projected(string $status): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:" . self::UUID . "\r\nSUMMARY:Approve\r\nSTATUS:{$status}\r\n"
			. 'X-OPENREGISTER-TASK:' . self::UUID . "\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}

	private function updated(string $ics): CalendarObjectUpdatedEvent {
		return new CalendarObjectUpdatedEvent(9, ['id' => 9, 'principaluri' => 'principals/users/EXAMPLE_APPROVER_USER'], [], ['uri' => 'x.ics', 'calendardata' => $ics]);
	}

	public function testACommittedTickReachesTheGateWithTheSessionActor(): void {
		$this->gate->expects($this->once())->method('handleWrite')
			->with($this->projected('COMPLETED'), 'EXAMPLE_APPROVER_USER')
			->willReturn('RENDERED');
		// The stored document is the user's; the reconcile makes it the engine's.
		$this->projections->expects($this->once())->method('reconcile')->with(self::UUID);

		$this->listener()->handle($this->updated($this->projected('COMPLETED')));
	}

	public function testARefusedCommittedWriteIsARevertNotAFailure(): void {
		// The gate has reverted, audited and notified; the listener only reports.
		$this->gate->method('handleWrite')->willThrowException(new TaskAccessDeniedException('denied'));
		$this->projections->expects($this->never())->method('reconcile');

		$this->listener('stranger')->handle($this->updated($this->projected('COMPLETED')));
		$this->addToAssertionCount(1);
	}

	public function testALostRaceSurfacesAsARevertNotAFiveHundred(): void {
		$this->gate->method('handleWrite')->willThrowException(new TaskConflictException('lost a race'));

		$this->listener()->handle($this->updated($this->projected('COMPLETED')));
		$this->addToAssertionCount(1);
	}

	public function testAnEchoLeavesTheCalendarAlone(): void {
		$this->gate->method('handleWrite')->willReturn(null);
		$this->projections->expects($this->never())->method('reconcile');

		$this->listener()->handle($this->updated($this->projected('IN-PROCESS')));
	}

	public function testAStandaloneVtodoIsIgnored(): void {
		$standalone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nSUMMARY:Buy milk\r\nSTATUS:COMPLETED\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$this->gate->expects($this->never())->method('handleWrite');
		$this->projections->expects($this->never())->method('reconcile');

		$this->listener()->handle($this->updated($standalone));
		$this->listener()->handle(new CalendarObjectDeletedEvent(9, [], [], ['uri' => 'x.ics', 'calendardata' => $standalone]));
	}

	public function testADeletedProjectionIsRebuilt(): void {
		$this->gate->expects($this->never())->method('handleWrite');
		$this->projections->expects($this->once())->method('reconcile')->with(self::UUID);

		$this->listener()->handle(new CalendarObjectDeletedEvent(9, [], [], ['uri' => 'x.ics', 'calendardata' => $this->projected('IN-PROCESS')]));
	}

	public function testWithoutASessionTheActorIsNullAndTheGateDecides(): void {
		$this->gate->expects($this->once())->method('handleWrite')->with($this->anything(), null)
			->willThrowException(new TaskAccessDeniedException('no acting identity'));

		$this->listener(null)->handle($this->updated($this->projected('COMPLETED')));
		$this->addToAssertionCount(1);
	}
}
