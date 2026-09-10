<?php

/**
 * The task-anchored calendar leaf, and mostly its GUARD.
 *
 * The link layer under this controller is the object leaf's link layer,
 * already proven by its own tests. What is new is the gate in front of it,
 * so these assertions are about who reaches it and what a refusal says.
 *
 * Three properties carry the leaf and each has a test that fails when it
 * is broken:
 *
 * 1. A caller who may not READ the task reaches no verb of the leaf, and
 *    gets the same 404 body `task#show` answers, so the leaf cannot confirm
 *    that a guessed uuid names a real task.
 * 2. Every denial happens BEFORE the calendar services are touched, which
 *    is what `expects($this->never())` on each of them says. `destroy` is
 *    the one that matters most: it strips properties off a real VEVENT.
 * 3. `destroy` reaches only events the TASK is linked to. An event id the
 *    caller invents resolves to nothing and is answered 404, never
 *    unlinked from whatever subject actually owns it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TaskEventsController;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Authorization and HTTP translation for /api/flow-tasks/{uuid}/events.
 *
 * @covers \OCA\OpenRegister\Controller\TaskEventsController
 */
class TaskEventsControllerTest extends TestCase {

	/**
	 * Resolves a task by uuid, mocked.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * Read visibility, mocked.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The link table layer, mocked.
	 *
	 * @var CalendarLinkService&MockObject
	 */
	private CalendarLinkService&MockObject $links;

	/**
	 * The VEVENT layer, mocked.
	 *
	 * @var CalendarEventService&MockObject
	 */
	private CalendarEventService&MockObject $events;

	/**
	 * Every collaborator mocked, and a session for `alice`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(originalClassName: TaskService::class);
		$this->authorization = $this->createMock(originalClassName: TaskAuthorizationService::class);
		$this->links = $this->createMock(originalClassName: CalendarLinkService::class);
		$this->events = $this->createMock(originalClassName: CalendarEventService::class);
	}//end setUp()

	/**
	 * A controller whose request carries these parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return TaskEventsController The controller under test.
	 */
	private function controller(array $params = []): TaskEventsController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new TaskEventsController(
			appName: 'openregister',
			request: $request,
			tasks: $this->tasks,
			authorization: $this->authorization,
			links: $this->links,
			events: $this->events,
			userSession: $session
		);
	}//end controller()

	/**
	 * A task carrying its subject's register and schema.
	 *
	 * @return Task The task.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setTitle('Sign the decision');
		$task->setRegisterId(4);
		$task->setSchemaId(9);

		return $task;
	}//end task()

	/**
	 * The task resolves and the caller may read it.
	 *
	 * @return void
	 */
	private function givenReadable(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(true);
	}//end givenReadable()

	/**
	 * The task resolves and the caller may NOT read it.
	 *
	 * @return void
	 */
	private function givenUnreadable(): void {
		$this->tasks->method('get')->willReturn($this->task());
		$this->authorization->method('mayRead')->willReturn(false);
	}//end givenUnreadable()

	/**
	 * Neither calendar service is reachable at all in this test.
	 *
	 * @return void
	 */
	private function expectNoCalendarWork(): void {
		$this->links->expects($this->never())->method('getLinkedEvents');
		$this->links->expects($this->never())->method('createAndLinkEvent');
		$this->links->expects($this->never())->method('linkEvent');
		$this->links->expects($this->never())->method('unlinkEvent');
		$this->events->expects($this->never())->method('unlinkEvent');
	}//end expectNoCalendarWork()

	/**
	 * 🔴 THE test: a caller who may not read the task may not read its
	 * events, and no calendar is touched.
	 *
	 * @return void
	 */
	public function testIndexIs404AndReadsNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->expectNoCalendarWork();

		$response = $this->controller()->index(uuid: 't-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testIndexIs404AndReadsNothingForACallerWhoMayNotReadTheTask()

	/**
	 * The unreadable task and the absent one are indistinguishable.
	 *
	 * @return void
	 */
	public function testAnAbsentTaskAnswersExactlyAsAnUnreadableOne(): void {
		$this->expectNoCalendarWork();

		$this->tasks->method('get')->willThrowException(new DoesNotExistException('gone'));
		$absent = $this->controller()->index(uuid: 't-1');

		$this->tasks = $this->createMock(originalClassName: TaskService::class);
		$this->givenUnreadable();
		$hidden = $this->controller()->index(uuid: 't-1');

		$this->assertSame($hidden->getStatus(), $absent->getStatus());
		$this->assertSame($hidden->getData(), $absent->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $absent->getStatus());
	}//end testAnAbsentTaskAnswersExactlyAsAnUnreadableOne()

	/**
	 * A readable task reads the events linked to the TASK's uuid.
	 *
	 * @return void
	 */
	public function testIndexReadsTheEventsOfTheTaskUuid(): void {
		$this->givenReadable();
		$this->links->expects($this->once())->method('getLinkedEvents')
			->with('t-1')
			->willReturn([['id' => 'e.ics', 'summary' => 'Review']]);

		$response = $this->controller()->index(uuid: 't-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
		$this->assertSame('Review', $response->getData()['results'][0]['summary']);
	}//end testIndexReadsTheEventsOfTheTaskUuid()

	/**
	 * A caller who may not read the task may not put an event on it.
	 *
	 * @return void
	 */
	public function testCreateIs404AndWritesNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->expectNoCalendarWork();

		$response = $this->controller(['summary' => 'Sneaky'])->create(uuid: 't-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testCreateIs404AndWritesNothingForACallerWhoMayNotReadTheTask()

	/**
	 * A readable task anchors the new event on the TASK's uuid and carries
	 * the task's own title into the calendar.
	 *
	 * @return void
	 */
	public function testCreateAnchorsTheEventOnTheTaskUuidAndTitle(): void {
		$this->givenReadable();
		$link = $this->createMock(originalClassName: CalendarLink::class);
		$link->method('jsonSerialize')->willReturn(['id' => 3]);

		$this->links->expects($this->once())->method('createAndLinkEvent')->willReturnCallback(
			function (string $objectUuid, int $registerId, int $schemaId, array $eventData) use ($link): CalendarLink {
				$this->assertSame('t-1', $objectUuid);
				$this->assertSame(4, $registerId);
				$this->assertSame(9, $schemaId);
				$this->assertSame('Sign the decision', $eventData['objectTitle']);
				$this->assertSame('Review', $eventData['summary']);

				return $link;
			}
		);

		$response = $this->controller(['summary' => 'Review'])->create(uuid: 't-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 3], $response->getData());
	}//end testCreateAnchorsTheEventOnTheTaskUuidAndTitle()

	/**
	 * A blank summary is a 400 and writes nothing.
	 *
	 * @return void
	 */
	public function testABlankSummaryIs400AndWritesNothing(): void {
		$this->givenReadable();
		$this->expectNoCalendarWork();

		$response = $this->controller(['summary' => '  '])->create(uuid: 't-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Event summary is required'], $response->getData());
	}//end testABlankSummaryIs400AndWritesNothing()

	/**
	 * A caller who may not read the task may not attach an existing event.
	 *
	 * @return void
	 */
	public function testLinkIs404AndLinksNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->expectNoCalendarWork();

		$response = $this->controller(['calendarUri' => 'personal', 'eventUid' => 'u-1'])->link(uuid: 't-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testLinkIs404AndLinksNothingForACallerWhoMayNotReadTheTask()

	/**
	 * A readable task links the named event against its own uuid.
	 *
	 * @return void
	 */
	public function testLinkAttachesTheEventToTheTaskUuid(): void {
		$this->givenReadable();
		$link = $this->createMock(originalClassName: CalendarLink::class);
		$link->method('jsonSerialize')->willReturn(['id' => 5]);
		$this->links->expects($this->once())->method('linkEvent')
			->with('t-1', 4, 9, 'personal', 'u-1')
			->willReturn($link);

		$response = $this->controller(['calendarUri' => 'personal', 'eventUid' => 'u-1'])->link(uuid: 't-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 5], $response->getData());
	}//end testLinkAttachesTheEventToTheTaskUuid()

	/**
	 * A half-named event is a 400 and links nothing.
	 *
	 * @return void
	 */
	public function testAHalfNamedEventIs400AndLinksNothing(): void {
		$this->givenReadable();
		$this->expectNoCalendarWork();

		$response = $this->controller(['calendarUri' => 'personal'])->link(uuid: 't-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'calendarUri and eventUid are required'], $response->getData());
	}//end testAHalfNamedEventIs400AndLinksNothing()

	/**
	 * A caller who may not read the task may not detach its events.
	 *
	 * @return void
	 */
	public function testUnlinkIs404AndUnlinksNothingForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->expectNoCalendarWork();

		$response = $this->controller()->unlink(uuid: 't-1', eventUid: 'u-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testUnlinkIs404AndUnlinksNothingForACallerWhoMayNotReadTheTask()

	/**
	 * A readable task detaches the named event from its own uuid.
	 *
	 * @return void
	 */
	public function testUnlinkDetachesTheEventFromTheTaskUuid(): void {
		$this->givenReadable();
		$this->links->expects($this->once())->method('unlinkEvent')->with('t-1', 'u-1');

		$response = $this->controller()->unlink(uuid: 't-1', eventUid: 'u-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testUnlinkDetachesTheEventFromTheTaskUuid()

	/**
	 * A caller who may not read the task may not destroy its events. The
	 * VEVENT layer must not be reached at all: this verb writes to a real
	 * calendar entry.
	 *
	 * @return void
	 */
	public function testDestroyIs404AndTouchesNoCalendarForACallerWhoMayNotReadTheTask(): void {
		$this->givenUnreadable();
		$this->expectNoCalendarWork();

		$response = $this->controller()->destroy(uuid: 't-1', eventId: 'e.ics');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testDestroyIs404AndTouchesNoCalendarForACallerWhoMayNotReadTheTask()

	/**
	 * An event id the task is not linked to is a 404, and nothing is
	 * stripped: `destroy` reaches only this task's own linked events.
	 *
	 * @return void
	 */
	public function testDestroyRefusesAnEventTheTaskIsNotLinkedTo(): void {
		$this->givenReadable();
		$this->links->method('getLinkedEvents')->willReturn([['id' => 'mine.ics', 'calendarId' => 2, 'uid' => 'u-1']]);
		$this->links->expects($this->never())->method('unlinkEvent');
		$this->events->expects($this->never())->method('unlinkEvent');

		$response = $this->controller()->destroy(uuid: 't-1', eventId: 'someone-elses.ics');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Event not found'], $response->getData());
	}//end testDestroyRefusesAnEventTheTaskIsNotLinkedTo()

	/**
	 * A linked event is stripped on the VEVENT and removed from the link
	 * table, in that order, 200.
	 *
	 * @return void
	 */
	public function testDestroyStripsTheVeventAndRemovesTheLink(): void {
		$this->givenReadable();
		$this->links->method('getLinkedEvents')->willReturn([['id' => 'mine.ics', 'calendarId' => 2, 'uid' => 'u-1']]);
		$this->events->expects($this->once())->method('unlinkEvent')->with('2', 'mine.ics');
		$this->links->expects($this->once())->method('unlinkEvent')->with('t-1', 'u-1');

		$response = $this->controller()->destroy(uuid: 't-1', eventId: 'mine.ics');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testDestroyStripsTheVeventAndRemovesTheLink()
}//end class
