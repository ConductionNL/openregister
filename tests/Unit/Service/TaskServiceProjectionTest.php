<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CalDAV leaf as a projection writer: an engine identity cannot be
 * forged through createTask(); a projected VTODO's update goes through the
 * gate and stores the engine's rendering; without a gate it is refused;
 * findUserCalendar() resolves the NAMED user; and standalone VTODOs behave
 * exactly as before.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-compatibility-with-nextcloud-tasks-app
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\TaskService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskServiceProjectionTest extends TestCase {
	private const UUID = '00000000-0000-0000-0000-000000000002';

	private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	private \OCA\DAV\CalDAV\CalDavBackend&MockObject $backend;

	private IUserSession&MockObject $session;

	protected function setUp(): void {
		parent::setUp();
		$this->backend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
		$this->session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('session-user');
		$this->session->method('getUser')->willReturn($user);
	}

	private function service(?TaskVtodoWriteBackGate $gate = null): TaskService {
		return new TaskService($this->backend, $this->session, new NullLogger(), $this->createMock(IURLGenerator::class), $gate);
	}

	private function projected(string $status): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:" . self::UUID . "\r\nSUMMARY:Approve\r\nSTATUS:{$status}\r\n"
			. 'X-OPENREGISTER-TASK:' . self::UUID . "\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}

	public function testAnEngineIdentityCannotBeForgedThroughCreateTask(): void {
		$this->backend->expects($this->never())->method('createCalendarObject');
		$this->backend->expects($this->never())->method('getCalendarsForUser');

		foreach ([['X-OPENREGISTER-TASK' => self::UUID], ['taskUuid' => self::UUID], ['fields' => ['X-OPENREGISTER-TASK' => self::UUID]]] as $forged) {
			try {
				$this->service()->createTask(1, 2, 'obj', 'Object', array_merge(['summary' => 'Forged'], $forged));
				$this->fail('expected a refusal');
			} catch (TaskAccessDeniedException $refused) {
				$this->assertStringContainsString('engine task', $refused->getMessage());
			}
		}
	}

	public function testAStandaloneCreateStillWorksAndCarriesNoTaskIdentity(): void {
		$this->backend->method('getCalendarsForUser')->with('principals/users/session-user')
			->willReturn([['id' => 5, 'uri' => 'personal', self::COMPONENTS => 'VTODO']]);
		$written = null;
		$this->backend->expects($this->once())->method('createCalendarObject')->willReturnCallback(
			static function (int $calendarId, string $uri, string $data) use (&$written): string {
				$written = $data;
				return 'etag';
			}
		);

		$result = $this->service()->createTask(1, 2, 'obj', 'Object', ['summary' => 'Buy milk']);

		$this->assertSame('Buy milk', $result['summary']);
		$this->assertStringNotContainsString('X-OPENREGISTER-TASK:', (string)$written);
		$this->assertStringContainsString('X-OPENREGISTER-OBJECT:obj', (string)$written);
	}

	public function testFindUserCalendarResolvesTheNamedUserNotTheSession(): void {
		$this->backend->method('getCalendarsForUser')->willReturnCallback(
			static fn (string $principal): array => $principal === 'principals/users/approver'
				? [['id' => 9, 'uri' => 'personal', self::COMPONENTS => 'VTODO']]
				: [['id' => 5, 'uri' => 'personal', self::COMPONENTS => 'VTODO']]
		);

		$this->assertSame(['id' => 9, 'uri' => 'personal'], $this->service()->findUserCalendar('approver'));
		$this->assertSame(['id' => 5, 'uri' => 'personal'], $this->service()->findUserCalendar());
	}

	public function testAProjectedUpdateGoesThroughTheGateAndStoresTheEnginesRendering(): void {
		$this->backend->method('getCalendarObject')->with(9, 'x.ics')->willReturn(['calendardata' => $this->projected('IN-PROCESS')]);
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->expects($this->once())->method('handleWrite')
			->with($this->stringContains('STATUS:COMPLETED'), 'session-user')
			->willReturn($this->projected('COMPLETED'));
		$this->backend->expects($this->once())->method('updateCalendarObject')->with(9, 'x.ics', $this->projected('COMPLETED'));

		$result = $this->service($gate)->updateTask('9', 'x.ics', ['status' => 'completed', 'summary' => 'ignored by the engine']);

		$this->assertSame('completed', $result['status']);
		$this->assertSame('Approve', $result['summary'], 'the stored document is the rendering, not the request');
	}

	public function testAProjectedUpdateWithoutAGateIsRefusedNotApplied(): void {
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $this->projected('IN-PROCESS')]);
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->expectException(TaskAccessDeniedException::class);
		$this->service()->updateTask('9', 'x.ics', ['status' => 'completed']);
	}

	public function testAGateRefusalSurfacesAndNothingIsWritten(): void {
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $this->projected('IN-PROCESS')]);
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->method('handleWrite')->willThrowException(new TaskAccessDeniedException('not the assignee'));
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->expectException(TaskAccessDeniedException::class);
		$this->service($gate)->updateTask('9', 'x.ics', ['status' => 'completed']);
	}

	public function testAStandaloneUpdateNeverTouchesTheGate(): void {
		$standalone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nSUMMARY:Buy milk\r\nSTATUS:NEEDS-ACTION\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $standalone]);
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->expects($this->never())->method('handleWrite');
		$this->backend->expects($this->once())->method('updateCalendarObject')->with(9, 'x.ics', $this->stringContains('STATUS:COMPLETED'));

		$result = $this->service($gate)->updateTask('9', 'x.ics', ['status' => 'completed']);

		$this->assertSame('completed', $result['status']);
	}

	public function testDeletingAProjectedVtodoDeletesTheEntryAndReachesNoEngine(): void {
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $this->projected('IN-PROCESS')]);
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->expects($this->never())->method('handleWrite');
		$gate->expects($this->never())->method('request');
		$this->backend->expects($this->once())->method('deleteCalendarObject')->with(9, 'x.ics');

		$this->service($gate)->deleteTask('9', 'x.ics');
	}
}
