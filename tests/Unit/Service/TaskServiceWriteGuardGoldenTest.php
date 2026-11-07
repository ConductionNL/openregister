<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the CalDAV leaf's write paths, pinned on the code before it
 * was split into small units: the engine-identity refusal on createTask (every
 * marker, at both levels the writer reads, and the payloads it lets through),
 * the fail-closed write-back gate on a projected update, the exact VTODO
 * document createTask writes and the exact document a standalone update
 * stores, and the Tasks-app deep link.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\TaskService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class TaskServiceWriteGuardGoldenTest extends TestCase {
	private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	private const UUID = '00000000-0000-0000-0000-000000000007';

	private CalDavBackend&MockObject $backend;

	private IUserSession&MockObject $session;

	private IURLGenerator&MockObject $urls;

	protected function setUp(): void {
		parent::setUp();
		$this->backend = $this->createMock(CalDavBackend::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->urls = $this->createMock(IURLGenerator::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}

	private function service(?TaskVtodoWriteBackGate $gate = null): TaskService {
		return new TaskService($this->backend, $this->session, new NullLogger(), $this->urls, $gate);
	}

	/**
	 * The volatile lines (random UID, clock DTSTAMP/COMPLETED) masked out.
	 */
	private function stable(string $document): string {
		return (string)preg_replace(['/^UID:[^\r\n]*/m', '/^DTSTAMP:[^\r\n]*/m', '/^COMPLETED:[^\r\n]*/m'], ['UID:*', 'DTSTAMP:*', 'COMPLETED:*'], $document);
	}

	public static function forgedPayloads(): array {
		return [
			'top X-OPENREGISTER-TASK' => [['X-OPENREGISTER-TASK' => self::UUID]],
			'top taskUuid'            => [['taskUuid' => self::UUID]],
			'top engineTask'          => [['engineTask' => self::UUID]],
			'top integer zero'        => [['taskUuid' => 0]],
			'fields X-OPENREGISTER'   => [['fields' => ['X-OPENREGISTER-TASK' => self::UUID]]],
			'fields taskUuid'         => [['fields' => ['taskUuid' => ' x ']]],
			'fields engineTask'       => [['fields' => ['engineTask' => self::UUID]]],
			'blank top, real fields'  => [['taskUuid' => '  ', 'fields' => ['engineTask' => 'e']]],
		];
	}

	#[DataProvider('forgedPayloads')]
	public function testAForgedEngineIdentityIsRefusedBeforeAnyCalendarIsTouched(array $forged): void {
		$this->backend->expects($this->never())->method('getCalendarsForUser');
		$this->backend->expects($this->never())->method('createCalendarObject');

		try {
			$this->service()->createTask(1, 2, 'obj', 'Object', array_merge(['summary' => 'Forged'], $forged));
			$this->fail('expected a refusal');
		} catch (TaskAccessDeniedException $refused) {
			$this->assertSame(
				'An engine task cannot be created through the object task endpoint; only OpenRegister projects engine tasks into a calendar.',
				$refused->getMessage()
			);
		}
	}

	public static function allowedPayloads(): array {
		return [
			'no marker'             => [[]],
			'blank markers'         => [['X-OPENREGISTER-TASK' => '', 'taskUuid' => '   ', 'engineTask' => null]],
			'blank field markers'   => [['fields' => ['taskUuid' => '', 'engineTask' => "\t"]]],
			'fields not an array'   => [['fields' => 'taskUuid']],
			'lookalike keys'        => [['task' => self::UUID, 'fields' => ['X-OPENREGISTER-TASKS' => self::UUID]]],
		];
	}

	#[DataProvider('allowedPayloads')]
	public function testAPayloadWithoutAnEngineIdentityIsCreated(array $payload): void {
		$this->backend->method('getCalendarsForUser')->willReturn([['id' => 5, 'uri' => 'personal', self::COMPONENTS => 'VTODO']]);
		$this->backend->expects($this->once())->method('createCalendarObject');

		$result = $this->service()->createTask(1, 2, 'obj', 'Object', array_merge(['summary' => 'Fine'], $payload));

		$this->assertSame('Fine', $result['summary']);
	}

	public function testCreateTaskWritesThisExactDocument(): void {
		$this->backend->method('getCalendarsForUser')->with('principals/users/alice')
			->willReturn([['id' => 5, 'uri' => 'personal', self::COMPONENTS => 'VTODO']]);
		$written = [];
		$this->backend->expects($this->once())->method('createCalendarObject')->willReturnCallback(
			static function (int $calendarId, string $uri, string $data) use (&$written): string {
				$written = [$calendarId, $uri, $data];
				return 'etag';
			}
		);

		$result = $this->service()->createTask(
			3,
			4,
			'obj-1',
			'Title; with, chars',
			[
				'summary'     => "Line\none, two; three\\",
				'description' => 'Desc, here',
				'status'      => 'in-process',
				'priority'    => '7',
				'due'         => '2030-01-02T03:04:05Z',
				'fields'      => ['assignee' => 'bob'],
			]
		);

		$expected = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//OpenRegister//Tasks//EN\r\nBEGIN:VTODO\r\nUID:*\r\nDTSTAMP:*\r\n"
			. "SUMMARY:Line\\none\\, two\\; three\\\\\r\nDESCRIPTION:Desc\\, here\r\nSTATUS:IN-PROCESS\r\nPRIORITY:7\r\n"
			. "DUE:20300102T030405Z\r\nX-OPENREGISTER-REGISTER:3\r\nX-OPENREGISTER-SCHEMA:4\r\nX-OPENREGISTER-OBJECT:obj-1\r\n"
			. 'X-OPENREGISTER-DATA:' . base64_encode('{"assignee":"bob"}') . "\r\n"
			. "LINK;LINKREL=\"related\";LABEL=\"Title\\; with\\, chars\";VALUE=URI:/apps/openregister/api/objects/3/4/obj-1\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n";
		$this->assertSame(5, $written[0]);
		$this->assertMatchesRegularExpression('/^[0-9A-F]{32}\.ics$/', $written[1]);
		$this->assertSame($expected, $this->stable($written[2]));
		$this->assertSame(substr($written[1], 0, -4), $result['uid']);
		$this->assertSame('in-process', $result['status']);
		$this->assertSame('5', (string)$result['calendarId']);
	}

	public function testCreateTaskWithDefaultsWritesThisExactDocument(): void {
		$this->backend->method('getCalendarsForUser')->willReturn([['id' => 5, 'uri' => 'personal', self::COMPONENTS => 'VTODO']]);
		$written = '';
		$this->backend->method('createCalendarObject')->willReturnCallback(
			static function (int $calendarId, string $uri, string $data) use (&$written): string {
				$written = $data;
				return 'etag';
			}
		);

		$this->service()->createTask(1, 2, 'o', 'T', ['description' => '', 'due' => '', 'fields' => []]);

		$this->assertSame(
			"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//OpenRegister//Tasks//EN\r\nBEGIN:VTODO\r\nUID:*\r\nDTSTAMP:*\r\n"
			. "SUMMARY:Untitled task\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:0\r\nX-OPENREGISTER-REGISTER:1\r\nX-OPENREGISTER-SCHEMA:2\r\n"
			. "X-OPENREGISTER-OBJECT:o\r\nLINK;LINKREL=\"related\";LABEL=\"T\";VALUE=URI:/apps/openregister/api/objects/1/2/o\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n",
			$this->stable($written)
		);
	}

	public function testABadDueDateThrowsBeforeAnythingIsWritten(): void {
		$this->backend->method('getCalendarsForUser')->willReturn([['id' => 5, 'uri' => 'personal', self::COMPONENTS => 'VTODO']]);
		$this->backend->expects($this->never())->method('createCalendarObject');

		$this->expectException(Exception::class);
		$this->service()->createTask(1, 2, 'o', 'T', ['due' => 'not a date at all']);
	}

	private function projected(string $status): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:" . self::UUID . "\r\nSUMMARY:Approve\r\nSTATUS:{$status}\r\n"
			. 'X-OPENREGISTER-TASK:' . self::UUID . "\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}

	public static function anyChange(): array {
		return [
			'status'     => [['status' => 'completed']],
			'no status'  => [['summary' => 'x']],
			'empty'      => [[]],
		];
	}

	#[DataProvider('anyChange')]
	public function testAProjectedUpdateWithoutAGateIsRefusedWhateverItAsks(array $change): void {
		$this->backend->method('getCalendarObject')->with(9, 'p.ics')->willReturn(['calendardata' => $this->projected('IN-PROCESS')]);
		$this->backend->expects($this->never())->method('updateCalendarObject');

		try {
			$this->service()->updateTask('9', 'p.ics', $change);
			$this->fail('expected a refusal');
		} catch (TaskAccessDeniedException $refused) {
			$this->assertSame(
				'This calendar entry projects an engine task and the write-back gate is unavailable, so the change was not applied.',
				$refused->getMessage()
			);
		}
	}

	public function testAProjectedUpdateWithoutStatusHandsTheStoredDocumentToTheGate(): void {
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $this->projected('IN-PROCESS')]);
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->expects($this->once())->method('handleWrite')->with($this->projected('IN-PROCESS'), 'alice')->willReturn(null);
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$result = $this->service($gate)->updateTask('9', 'p.ics', ['summary' => 'ignored']);

		$this->assertSame('in-process', $result['status']);
		$this->assertSame('Approve', $result['summary']);
	}

	public function testAProjectedStatusChangeIsRequestedAndTheRenderingStored(): void {
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $this->projected('NEEDS-ACTION')]);
		$requested = '';
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->expects($this->once())->method('handleWrite')->willReturnCallback(
			function (string $calendarData, ?string $actor) use (&$requested): string {
				$requested = $calendarData;
				$this->assertSame('alice', $actor);
				return $this->projected('COMPLETED');
			}
		);
		$this->backend->expects($this->once())->method('updateCalendarObject')->with(9, 'p.ics', $this->projected('COMPLETED'));

		$result = $this->service($gate)->updateTask('9', 'p.ics', ['status' => 'completed']);

		$this->assertStringContainsString("STATUS:COMPLETED\r\n", $requested);
		$this->assertStringNotContainsString('NEEDS-ACTION', $requested);
		$this->assertSame('completed', $result['status']);
	}

	public function testAGateErrorSurfacesUnchanged(): void {
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $this->projected('NEEDS-ACTION')]);
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->method('handleWrite')->willThrowException(new RuntimeException('engine said no'));
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->expectExceptionObject(new RuntimeException('engine said no'));
		$this->service($gate)->updateTask('9', 'p.ics', ['status' => 'completed']);
	}

	public function testAStandaloneUpdateStoresThisExactDocument(): void {
		$stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VTODO\r\nUID:s\r\nSUMMARY:Old\r\nSTATUS:NEEDS-ACTION\r\n"
			. "DUE:20300101T000000Z\r\nX-OPENREGISTER-OBJECT:obj\r\nX-OPENREGISTER-DATA:e30=\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$this->backend->method('getCalendarObject')->with(9, 's.ics')->willReturn(['calendardata' => $stored]);
		$written = '';
		$this->backend->expects($this->once())->method('updateCalendarObject')->willReturnCallback(
			static function (int $calendarId, string $uri, string $data) use (&$written): string {
				$written = $data;
				return 'etag';
			}
		);

		$result = $this->service()->updateTask(
			'9',
			's.ics',
			['summary' => 'New', 'description' => 'D', 'status' => 'completed', 'priority' => '3', 'due' => '', 'fields' => ['a' => 1]]
		);

		$this->assertSame(
			"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VTODO\r\nUID:*\r\nX-OPENREGISTER-OBJECT:obj\r\nSUMMARY:New\r\n"
			. "DESCRIPTION:D\r\nSTATUS:COMPLETED\r\nCOMPLETED:*\r\nPRIORITY:3\r\nX-OPENREGISTER-DATA:" . base64_encode('{"a":1}') . "\r\n"
			. "DTSTAMP:*\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
			$this->stable($written)
		);
		$this->assertSame('9', (string)$result['calendarId']);
	}

	public function testAStandaloneUpdateWithEmptyFieldsDropsTheBlobAndSetsADue(): void {
		$stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:s\r\nSUMMARY:Old\r\nX-OPENREGISTER-DATA:e30=\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => $stored]);
		$written = '';
		$this->backend->method('updateCalendarObject')->willReturnCallback(
			static function (int $calendarId, string $uri, string $data) use (&$written): string {
				$written = $data;
				return 'etag';
			}
		);

		$this->service()->updateTask('9', 's.ics', ['status' => 'in-process', 'due' => '2031-05-06T07:08:09Z', 'fields' => []]);

		$this->assertSame(
			"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:*\r\nSUMMARY:Old\r\nSTATUS:IN-PROCESS\r\nDUE:20310506T070809Z\r\n"
			. "DTSTAMP:*\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
			$this->stable($written)
		);
	}

	public function testAStandaloneUpdateOfANonVtodoThrows(): void {
		$this->backend->method('getCalendarObject')->willReturn(['calendardata' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"]);
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->expectExceptionObject(new Exception('Calendar object is not a VTODO'));
		$this->service()->updateTask('9', 's.ics', ['summary' => 'x']);
	}

	public static function deepLinks(): array {
		return [
			'resolves'          => ['personal cal', 'a b.ics', '/index.php/apps/tasks/', '/index.php/apps/tasks/#/calendars/personal%20cal/tasks/a%20b.ics'],
			'empty calendar uri' => ['', 'a.ics', '/apps/tasks', null],
			'missing calendar uri' => [null, 'a.ics', '/apps/tasks', null],
			'route throws'      => ['personal', 'a.ics', null, null],
		];
	}

	#[DataProvider('deepLinks')]
	public function testTheTasksAppDeepLink(?string $calendarUri, string $taskUri, ?string $base, ?string $expected): void {
		$this->backend->method('getCalendarsForUser')->willReturn([['id' => 5, 'uri' => $calendarUri, self::COMPONENTS => 'VTODO']]);
		$this->backend->method('getCalendarObjects')->willReturn([['uri' => $taskUri]]);
		$this->backend->method('getCalendarObject')->willReturn(
			['calendardata' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:u\r\nSUMMARY:S\r\nX-OPENREGISTER-OBJECT:obj\r\nEND:VTODO\r\nEND:VCALENDAR\r\n"]
		);
		if ($base === null) {
			$this->urls->method('linkToRoute')->willThrowException(new RuntimeException('no tasks app'));
		} else {
			$this->urls->method('linkToRoute')->with('tasks.page.index')->willReturn($base);
		}

		$tasks = $this->service()->getTasksForObject('obj');

		$this->assertCount(1, $tasks);
		$this->assertSame($expected, ($tasks[0]['url'] ?? null));
	}
}
