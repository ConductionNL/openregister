<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The calendar projection: into the ASSIGNEE's calendar, with the property
 * table from design D-5; a pooled task in nobody's calendar; reassignment
 * moving the entry; idempotent re-renders; a destroyed projection rebuilt;
 * a missing calendar skipped and logged, never thrown.
 *
 * Fixtures (design, Seed Data): one assigned task with a projection
 * (assignee EXAMPLE_APPROVER_USER, uuid ...0002), one pooled task with no
 * projection, one assignee with no VTODO-capable calendar.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-projection-carries-a-real-assignee-not-prose
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskProjectionState;
use OCA\OpenRegister\Db\TaskProjectionStateMapper;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\VtodoCalendarLocator;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskCalendarProjectorTest extends TestCase {
	public const APPROVER = 'EXAMPLE_APPROVER_USER';

	public const PROJECTED_UUID = '00000000-0000-0000-0000-000000000002';

	public const POOLED_UUID = '00000000-0000-0000-0000-000000000003';

	private \OCA\DAV\CalDAV\CalDavBackend&MockObject $backend;

	private VtodoCalendarLocator&MockObject $calendars;

	private TaskProjectionStateMapper&MockObject $states;

	private LoggerInterface&MockObject $logger;

	/** @var array<string, TaskProjectionState> */
	private array $stateRows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->backend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
		$this->calendars = $this->createMock(VtodoCalendarLocator::class);
		$this->states = $this->createMock(TaskProjectionStateMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// An in-memory state store: save() persists, findForTask() reads back.
		$this->stateRows = [];
		$this->states->method('findForTask')->willReturnCallback(
			fn (string $uuid): ?TaskProjectionState => $this->stateRows[$uuid] ?? null
		);
		$this->states->method('save')->willReturnCallback(
			function (TaskProjectionState $state): TaskProjectionState {
				if ($state->getId() === null) {
					$state->setId(count($this->stateRows) + 1);
				}

				$this->stateRows[(string)$state->getTaskUuid()] = $state;
				return $state;
			}
		);
		$this->states->method('delete')->willReturnCallback(
			function (TaskProjectionState $state): TaskProjectionState {
				unset($this->stateRows[(string)$state->getTaskUuid()]);
				return $state;
			}
		);
	}

	private function projector(): TaskCalendarProjector {
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('displayTitle')->willReturnCallback(
			static fn (Task $task): string => (string)($task->getTitle() ?? 'Task')
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://cloud.example/index.php/apps/openregister/flow-tasks/' . $params['uuid']
		);

		return new TaskCalendarProjector($this->backend, $this->calendars, $this->states, $inbox, $urls, $this->logger);
	}

	/**
	 * Fixture 1: the assigned task with a projection.
	 */
	public static function assignedTask(): Task {
		$task = new Task();
		$task->setId(2);
		$task->setUuid(self::PROJECTED_UUID);
		$task->setTitle('Approve the permit');
		$task->setDescription('Check the drawings against the zoning plan.');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setLastAction('assign');
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee(self::APPROVER);
		$task->setPriority('high');
		$task->setDueAt(new DateTime('2026-09-10T12:00:00+00:00'));
		$task->setObjectUuid('obj-42');
		$task->setRegisterId(8);
		$task->setSchemaId(18);

		return $task;
	}

	/**
	 * Fixture 2: the pooled task with no projection.
	 */
	public static function pooledTask(): Task {
		$task = new Task();
		$task->setId(3);
		$task->setUuid(self::POOLED_UUID);
		$task->setTitle('Second signature');
		$task->setState(Task::STATE_ENABLED);
		$task->setIsTerminal(false);
		$task->setLastAction('offer');
		$task->setPerformerType(Task::PERFORMER_GROUP);
		$task->setCandidateGroups(['permits']);

		return $task;
	}

	private function calendarFor(string $uid, int $id): void {
		$this->calendars->method('forUser')->with($uid)->willReturn(['id' => $id, 'uri' => 'personal']);
	}

	public function testRenderCarriesThePropertyTable(): void {
		$folded = $this->projector()->render(self::assignedTask());
		// Unfold RFC 5545 continuation lines so the assertions read whole properties.
		$ics = str_replace("\r\n ", '', $folded);

		$this->assertStringContainsString('BEGIN:VTODO', $ics);
		$this->assertSame(1, substr_count($ics, 'UID:'), 'exactly one UID, the task uuid');
		$this->assertSame(1, substr_count($ics, 'DTSTAMP:'));
		$this->assertStringContainsString('UID:' . self::PROJECTED_UUID, $ics);
		$this->assertStringContainsString('SUMMARY:Approve the permit', $ics);
		$this->assertStringContainsString('DUE:20260910T120000Z', $ics);
		$this->assertStringContainsString('PRIORITY:3', $ics);
		$this->assertStringContainsString('STATUS:IN-PROCESS', $ics);
		$this->assertMatchesRegularExpression(
			'#URL(;VALUE=URI)?:https://cloud\.example/index\.php/apps/openregister/flow-tasks/' . self::PROJECTED_UUID . '#',
			$ics
		);
		$this->assertStringContainsString('X-OPENREGISTER-TASK:' . self::PROJECTED_UUID, $ics);
		$this->assertStringContainsString('X-OPENREGISTER-TASK-ASSIGNEE:' . self::APPROVER, $ics);
		$this->assertStringContainsString('X-OPENREGISTER-REGISTER:8', $ics);
		$this->assertStringContainsString('X-OPENREGISTER-OBJECT:obj-42', $ics);
		$this->assertMatchesRegularExpression(
			'#LINK;LINKREL=related;LABEL="?Approve the permit"?;VALUE=URI:/apps/openregister/api/objects/8/18/obj-42#',
			$ics
		);
		// The assignee is an identity, never prose.
		$this->assertStringNotContainsString('Assigned to', $ics);
		// The deep link is a page, not the API.
		$this->assertDoesNotMatchRegularExpression('#URL(;VALUE=URI)?:https://cloud\.example/index\.php/apps/openregister/api#', $ics);
	}

	public function testATerminalTaskRendersTerminal(): void {
		$task = self::assignedTask();
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$task->setCompletedAt(new DateTime('2026-09-01T09:00:00+00:00'));

		$ics = $this->projector()->render($task);

		$this->assertStringContainsString('STATUS:COMPLETED', $ics);
		$this->assertStringContainsString('COMPLETED:20260901T090000Z', $ics);
		$this->assertStringContainsString('PERCENT-COMPLETE:100', $ics);
	}

	public function testATaskLandsInTheAssigneesCalendarNotTheActors(): void {
		$this->calendarFor(self::APPROVER, 9);
		$this->backend->expects($this->once())->method('createCalendarObject')
			->with(9, 'openregister-task-' . self::PROJECTED_UUID . '.ics', $this->stringContains('X-OPENREGISTER-TASK:' . self::PROJECTED_UUID));
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->projector()->project(self::assignedTask(), null);

		$state = $this->stateRows[self::PROJECTED_UUID];
		$this->assertSame(self::APPROVER, $state->getAssignee());
		$this->assertSame(9, $state->getCalendarId());
		$this->assertNotEmpty($state->getRenderedHash());
	}

	public function testAnUnclaimedPooledTaskIsInNobodysCalendar(): void {
		$this->calendars->expects($this->never())->method('forUser');
		$this->backend->expects($this->never())->method('createCalendarObject');

		$this->projector()->project(self::pooledTask(), null);

		$this->assertArrayNotHasKey(self::POOLED_UUID, $this->stateRows);
	}

	public function testAnAssigneeWithoutACalendarIsSkippedAndLoggedNamingTheTask(): void {
		$this->calendars->method('forUser')->willThrowException(new NoVtodoCalendarException('nocal'));
		$this->backend->expects($this->never())->method('createCalendarObject');
		$this->logger->expects($this->once())->method('info')
			->with(
				$this->stringContains('no VTODO-capable calendar'),
				$this->callback(static fn (array $context): bool => $context['task'] === self::PROJECTED_UUID && $context['surface'] === 'caldav')
			);

		$task = self::assignedTask();
		$task->setAssignee('nocal');

		// No exception: the transition this projection follows is unaffected.
		$this->projector()->project($task, null);
		$this->assertArrayNotHasKey(self::PROJECTED_UUID, $this->stateRows);
	}

	public function testRenderingTwiceWritesOnce(): void {
		$this->calendarFor(self::APPROVER, 9);
		$this->backend->expects($this->once())->method('createCalendarObject');
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$projector = $this->projector();
		$projector->project(self::assignedTask(), null);
		$projector->project(self::assignedTask(), self::APPROVER);
	}

	public function testAChangedTaskUpdatesTheExistingEntry(): void {
		$this->calendarFor(self::APPROVER, 9);
		$this->backend->expects($this->once())->method('createCalendarObject');
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => 'BEGIN:VCALENDAR', 'uri' => 'x']);
		$this->backend->expects($this->once())->method('updateCalendarObject')
			->with(9, 'openregister-task-' . self::PROJECTED_UUID . '.ics', $this->stringContains('STATUS:COMPLETED'));

		$projector = $this->projector();
		$projector->project(self::assignedTask(), null);

		$completed = self::assignedTask();
		$completed->setState(Task::STATE_COMPLETED);
		$completed->setIsTerminal(true);
		$projector->project($completed, self::APPROVER);
	}

	public function testReassignmentMovesTheEntry(): void {
		$this->calendars->method('forUser')->willReturnCallback(
			static fn (string $uid): array => $uid === self::APPROVER ? ['id' => 9, 'uri' => 'personal'] : ['id' => 21, 'uri' => 'personal']
		);
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => 'BEGIN:VCALENDAR', 'uri' => 'x']);
		$this->backend->expects($this->once())->method('deleteCalendarObject')
			->with(9, 'openregister-task-' . self::PROJECTED_UUID . '.ics');
		$created = [];
		$this->backend->method('createCalendarObject')->willReturnCallback(
			static function (int $calendarId) use (&$created): string {
				$created[] = $calendarId;
				return 'etag';
			}
		);

		$projector = $this->projector();
		$projector->project(self::assignedTask(), null);

		$reassigned = self::assignedTask();
		$reassigned->setAssignee('second-approver');
		$reassigned->setLastAction('reassign');
		$projector->project($reassigned, self::APPROVER);

		$this->assertSame([9, 21], $created);
		$this->assertSame('second-approver', $this->stateRows[self::PROJECTED_UUID]->getAssignee());
	}

	public function testADestroyedProjectionIsRebuiltWithTheSameContent(): void {
		$this->calendarFor(self::APPROVER, 9);
		$rendered = [];
		$this->backend->method('createCalendarObject')->willReturnCallback(
			static function (int $calendarId, string $uri, string $data) use (&$rendered): string {
				$rendered[] = $data;
				return 'etag';
			}
		);
		// The VTODO is gone from the calendar.
		$this->backend->method('getCalendarObject')->willReturn(null);

		$projector = $this->projector();
		$projector->project(self::assignedTask(), null);
		$projector->reconcile(self::assignedTask());

		$this->assertCount(2, $rendered);
		$this->assertSame(TaskCalendarProjector::contentHash($rendered[0]), TaskCalendarProjector::contentHash($rendered[1]));
	}

	public function testAnEditedProjectionIsOverwrittenOnReconcile(): void {
		$this->calendarFor(self::APPROVER, 9);
		$this->backend->method('createCalendarObject')->willReturn('etag');
		$edited = str_replace('SUMMARY:Approve the permit', 'SUMMARY:Something else', $this->projector()->render(self::assignedTask()));
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $edited, 'uri' => 'x']);
		$this->backend->expects($this->once())->method('updateCalendarObject')
			->with(9, $this->anything(), $this->stringContains('SUMMARY:Approve the permit'));

		$projector = $this->projector();
		$projector->project(self::assignedTask(), null);
		$projector->reconcile(self::assignedTask());
	}

	public function testATerminalTaskNeverProjectedGetsNoEntry(): void {
		$task = self::assignedTask();
		$task->setState(Task::STATE_TERMINATED);
		$task->setIsTerminal(true);
		$this->backend->expects($this->never())->method('createCalendarObject');

		$this->projector()->project($task, null);
	}

	public function testTheEchoIsRecognisedByContentNotBytes(): void {
		$this->calendarFor(self::APPROVER, 9);
		$this->backend->method('createCalendarObject')->willReturn('etag');
		$projector = $this->projector();
		$projector->project(self::assignedTask(), null);

		// A client re-serialises with a different DTSTAMP and folding: still an echo.
		$reserialised = preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:20300101T000000Z', $projector->render(self::assignedTask()));
		$this->assertTrue($projector->isEcho(self::PROJECTED_UUID, (string)$reserialised));

		// A status edit is not.
		$ticked = str_replace('STATUS:IN-PROCESS', 'STATUS:COMPLETED', $projector->render(self::assignedTask()));
		$this->assertFalse($projector->isEcho(self::PROJECTED_UUID, $ticked));
		$this->assertFalse($projector->isEcho('unknown-task', $ticked));
	}

	public function testTwoUsersWithTheSameDisplayNameStayDistinct(): void {
		// The projection carries the uid, so the display name never enters it.
		$ics = $this->projector()->render(self::assignedTask());
		$fields = TaskCalendarProjector::engineFields($ics);

		$this->assertSame(self::APPROVER, $fields['assignee']);
		$this->assertSame(self::PROJECTED_UUID, TaskCalendarProjector::taskUuidOf($ics));
	}

	public function testAStandaloneVtodoHasNoTaskIdentity(): void {
		$standalone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\nSUMMARY:Buy milk\r\nSTATUS:NEEDS-ACTION\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

		$this->assertNull(TaskCalendarProjector::taskUuidOf($standalone));
		$this->assertNull(TaskCalendarProjector::taskUuidOf('not a calendar'));
	}

	public function testACalendarBackendThatFailsEveryWriteThrowsForTheCallerToIsolate(): void {
		$this->calendarFor(self::APPROVER, 9);
		$this->backend->method('createCalendarObject')->willThrowException(new \RuntimeException('calendar down'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('calendar down');

		$this->projector()->project(self::assignedTask(), null);
	}
}
