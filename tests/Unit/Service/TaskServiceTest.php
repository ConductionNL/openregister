<?php

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Kernel\Contracts\LinkedTaskDraft;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\TaskLocation;
use OCA\OpenRegister\Service\TaskService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TaskService.
 *
 * Note: Tests that require CalDavBackend (which is not autoloadable in unit tests)
 * are skipped unless the class is available.
 */
class TaskServiceTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')
			->with('tasks.page.index')
			->willReturn('/index.php/apps/tasks/');
	}

	private function requireCalDav(): void {
		if (class_exists('OCA\DAV\CalDAV\CalDavBackend') === false) {
			$this->markTestSkipped('CalDavBackend not available in unit test environment');
		}
	}

	private function createService(): TaskService {
		$this->requireCalDav();

		$calDavBackend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
		return new TaskService($calDavBackend, $this->userSession, $this->logger, $this->urlGenerator);
	}

	private function createServiceWithBackend(): array {
		$this->requireCalDav();

		$calDavBackend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
		$service = new TaskService($calDavBackend, $this->userSession, $this->logger, $this->urlGenerator);
		return [$service, $calDavBackend];
	}

	private function mockUserWithCalendar(string $uid = 'testuser', &$calDavBackend = null): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		if ($calDavBackend !== null) {
			$calDavBackend->method('getCalendarsForUser')->willReturn([
				[
					'id' => 1,
					'uri' => 'personal',
					'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VTODO',
				],
			]);
		}
	}

	// ── getTasksForObject (no user) ──

	public function testGetTasksForObjectThrowsWhenNoUser(): void {
		$service = $this->createService();
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$service->getTasksForObject('some-uuid');
	}

	// ── requireTaskAt ──

	/**
	 * A service whose user has calendar 1, holding one task linked to `uuid-mine`.
	 *
	 * @return TaskService
	 */
	private function serviceWithOneLinkedTask(): TaskService {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);
		$calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\nSUMMARY:Mine\r\n"
			. "X-OPENREGISTER-OBJECT:uuid-mine\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$calDavBackend->method('getCalendarObjects')->willReturn([['uri' => 'task.ics']]);
		$calDavBackend->method('getCalendarObject')->willReturn(['calendardata' => $calendarData]);
		return $service;
	}

	public function testRequireTaskOfAcceptsATaskLinkedToTheObject(): void {
		$this->serviceWithOneLinkedTask()->requireTaskAt(objectUuid: 'uuid-mine', at: new TaskLocation(calendarId: '1', uri: 'task.ics'));
		$this->addToAssertionCount(1);
	}

	public function testRequireTaskOfRefusesATaskOfAnotherObject(): void {
		$this->expectExceptionCode(404);
		$this->serviceWithOneLinkedTask()->requireTaskAt(objectUuid: 'uuid-other', at: new TaskLocation(calendarId: '1', uri: 'task.ics'));
	}

	public function testRequireTaskOfRefusesACalendarThatIsNotTheCallers(): void {
		$this->expectExceptionCode(404);
		$this->serviceWithOneLinkedTask()->requireTaskAt(objectUuid: 'uuid-mine', at: new TaskLocation(calendarId: '99', uri: 'task.ics'));
	}

	public function testRequireTaskOfRefusesAnUnknownTask(): void {
		$this->expectExceptionCode(404);
		$this->serviceWithOneLinkedTask()->requireTaskAt(objectUuid: 'uuid-mine', at: new TaskLocation(calendarId: '1', uri: 'other.ics'));
	}

	public function testRequireTaskOfRefusesACallerWithoutACalendar(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$calDavBackend->method('getCalendarsForUser')->willReturn([]);

		$this->expectExceptionCode(404);
		$service->requireTaskAt(objectUuid: 'uuid-mine', at: new TaskLocation(calendarId: '1', uri: 'task.ics'));
	}

	// ── getTasksForObject (no calendar) ──

	public function testGetTasksForObjectThrowsWhenNoCalendar(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$calDavBackend->method('getCalendarsForUser')->willReturn([]);

		$this->expectException(NoVtodoCalendarException::class);
		$this->expectExceptionMessage('No VTODO-supporting calendar found');

		$service->getTasksForObject('some-uuid');
	}

	// ── getTasksForObject (with calendar, no matching tasks) ──

	public function testGetTasksForObjectReturnsEmptyWhenNoTasks(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);
		$calDavBackend->method('getCalendarObjects')->willReturn([]);

		$result = $service->getTasksForObject('some-uuid');
		$this->assertSame([], $result);
	}

	// ── getTasksForObject (with matching task) ──

	public function testGetTasksForObjectReturnsMatchingTasks(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);

		$objectUuid = 'test-uuid-123';
		$calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
			. "SUMMARY:Test task\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:0\r\n"
			. "X-OPENREGISTER-REGISTER:1\r\nX-OPENREGISTER-SCHEMA:2\r\n"
			. "X-OPENREGISTER-OBJECT:{$objectUuid}\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n";

		$calDavBackend->method('getCalendarObjects')->willReturn([
			['uri' => 'abc.ics'],
		]);
		$calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => $calendarData,
		]);

		$result = $service->getTasksForObject($objectUuid);

		$this->assertCount(1, $result);
		$this->assertSame($objectUuid, $result[0]['objectUuid']);
		$this->assertSame('Test task', $result[0]['summary']);
		$this->assertSame(1, $result[0]['registerId']);
		$this->assertSame(2, $result[0]['schemaId']);
	}

	// ── createObjectTask ──

	public function testCreateTaskCreatesCalendarObject(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);

		$calDavBackend->expects($this->once())->method('createCalendarObject');

		$result = $service->createObjectTask(ref: new ObjectRef(register: 1, schema: 2, id: 'uuid-123'), draft: new LinkedTaskDraft(objectTitle: 'Object Title', data: ['summary' => 'My task', 'status' => 'NEEDS-ACTION']));

		$this->assertIsArray($result);
		$this->assertSame('My task', $result['summary']);
		$this->assertSame('uuid-123', $result['objectUuid']);
	}

	// ── changeTask ──

	public function testUpdateTaskThrowsWhenNotFound(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$calDavBackend->method('getCalendarObject')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Task not found');

		$service->changeTask(at: new TaskLocation(calendarId: '1', uri: 'task.ics'), data: ['summary' => 'Updated']);
	}

	public function testUpdateTaskUpdatesFields(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();

		$calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
			. "SUMMARY:Original\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:0\r\n"
			. "DTSTAMP:20240101T000000Z\r\n"
			. "X-OPENREGISTER-REGISTER:1\r\nX-OPENREGISTER-SCHEMA:2\r\n"
			. "X-OPENREGISTER-OBJECT:uuid-123\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n";

		$calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => $calendarData,
		]);
		$calDavBackend->expects($this->once())->method('updateCalendarObject');

		$result = $service->changeTask(at: new TaskLocation(calendarId: '1', uri: 'task.ics'), data: ['summary' => 'Updated']);

		$this->assertSame('Updated', $result['summary']);
	}

	// ── deleteTask ──

	public function testDeleteTaskThrowsWhenNotFound(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$calDavBackend->method('getCalendarObject')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Task not found');

		$service->deleteTask('1', 'task.ics');
	}

	public function testDeleteTaskDeletesCalendarObject(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => 'something',
		]);
		$calDavBackend->expects($this->once())->method('deleteCalendarObject');

		$service->deleteTask('1', 'task.ics');
	}

	// ── createObjectTask with due date ──

	public function testCreateTaskWithDueDate(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);
		$calDavBackend->expects($this->once())->method('createCalendarObject');

		$result = $service->createObjectTask(
			ref: new ObjectRef(register: 1, schema: 2, id: 'uuid-123'),
			draft: new LinkedTaskDraft(
				objectTitle: 'My Object',
				data: [
					'summary' => 'Task with due',
					'description' => 'Some description',
					'due' => '2024-12-31 12:00:00',
					'priority' => 5,
					'status' => 'IN-PROCESS',
				]
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('Task with due', $result['summary']);
		$this->assertSame('uuid-123', $result['objectUuid']);
		$this->assertSame(1, $result['registerId']);
		$this->assertSame(2, $result['schemaId']);
		$this->assertSame('in-process', $result['status']);
	}

	// ── createObjectTask with special characters in summary ──

	public function testCreateTaskEscapesSpecialCharacters(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);
		$calDavBackend->expects($this->once())->method('createCalendarObject');

		$result = $service->createObjectTask(ref: new ObjectRef(register: 1, schema: 2, id: 'uuid-456'), draft: new LinkedTaskDraft(objectTitle: 'Title, with; special\chars', data: ['summary' => 'Task, with; special\nchars']));

		$this->assertIsArray($result);
		// Summary should be returned (after ical round-trip).
		$this->assertStringContainsString('Task', $result['summary']);
	}

	// ── changeTask sets COMPLETED timestamp ──

	public function testUpdateTaskSetsCompletedTimestamp(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();

		$calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
			. "SUMMARY:Task\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:0\r\n"
			. "DTSTAMP:20240101T000000Z\r\n"
			. "X-OPENREGISTER-REGISTER:1\r\nX-OPENREGISTER-SCHEMA:2\r\n"
			. "X-OPENREGISTER-OBJECT:uuid-123\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n";

		$calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => $calendarData,
		]);
		$calDavBackend->expects($this->once())->method('updateCalendarObject');

		$result = $service->changeTask(at: new TaskLocation(calendarId: '1', uri: 'task.ics'), data: ['status' => 'COMPLETED']);

		$this->assertSame('completed', $result['status']);
		$this->assertNotNull($result['completed']);
	}

	// ── changeTask with due date removal ──

	public function testUpdateTaskRemovesDueDate(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();

		$calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
			. "SUMMARY:Task\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:0\r\n"
			. "DUE:20241231T120000Z\r\n"
			. "DTSTAMP:20240101T000000Z\r\n"
			. "X-OPENREGISTER-REGISTER:1\r\nX-OPENREGISTER-SCHEMA:2\r\n"
			. "X-OPENREGISTER-OBJECT:uuid-123\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n";

		$calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => $calendarData,
		]);
		$calDavBackend->expects($this->once())->method('updateCalendarObject');

		$result = $service->changeTask(at: new TaskLocation(calendarId: '1', uri: 'task.ics'), data: ['due' => '']);

		$this->assertIsArray($result);
		// Due should be removed.
		$this->assertNull($result['due']);
	}

	// ── changeTask sets new due date ──

	public function testUpdateTaskSetsDueDate(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();

		$calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
			. "SUMMARY:Task\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:0\r\n"
			. "DTSTAMP:20240101T000000Z\r\n"
			. "X-OPENREGISTER-REGISTER:1\r\nX-OPENREGISTER-SCHEMA:2\r\n"
			. "X-OPENREGISTER-OBJECT:uuid-123\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n";

		$calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => $calendarData,
		]);
		$calDavBackend->expects($this->once())->method('updateCalendarObject');

		$result = $service->changeTask(at: new TaskLocation(calendarId: '1', uri: 'task.ics'), data: ['due' => '2025-06-15 10:00:00']);

		$this->assertNotNull($result['due']);
	}

	// ── getTasksForObject skips objects with missing calendardata ──

	public function testGetTasksForObjectSkipsEmptyCalendarData(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);

		$calDavBackend->method('getCalendarObjects')->willReturn([
			['uri' => 'obj1.ics'],
			['uri' => 'obj2.ics'],
		]);
		// First object has no calendardata.
		$calDavBackend->method('getCalendarObject')->willReturnOnConsecutiveCalls(
			['calendardata' => ''],
			null
		);

		$result = $service->getTasksForObject('some-uuid');
		$this->assertSame([], $result);
	}

	// ── getTasksForObject skips non-matching UUIDs ──

	public function testGetTasksForObjectSkipsNonMatchingUuid(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();
		$this->mockUserWithCalendar('testuser', $calDavBackend);

		$calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:abc\r\n"
			. "SUMMARY:Task\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:0\r\n"
			. "X-OPENREGISTER-REGISTER:1\r\nX-OPENREGISTER-SCHEMA:2\r\n"
			. "X-OPENREGISTER-OBJECT:different-uuid\r\n"
			. "END:VTODO\r\nEND:VCALENDAR\r\n";

		$calDavBackend->method('getCalendarObjects')->willReturn([['uri' => 'abc.ics']]);
		$calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => $calendarData,
		]);

		$result = $service->getTasksForObject('search-uuid');
		$this->assertSame([], $result);
	}

	// ── calendar with object-style component set ──

	public function testFindUserCalendarWithArrayComponentSet(): void {
		[$service, $calDavBackend] = $this->createServiceWithBackend();

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$calDavBackend->method('getCalendarsForUser')->willReturn([
			[
				'id' => 10,
				'uri' => 'personal',
				'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => ['VEVENT', 'VTODO'],
			],
		]);
		$calDavBackend->method('getCalendarObjects')->willReturn([]);

		$result = $service->getTasksForObject('any-uuid');
		$this->assertSame([], $result);
	}
}
