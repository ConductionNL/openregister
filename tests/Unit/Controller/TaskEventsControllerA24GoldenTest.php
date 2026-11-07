<?php

/**
 * Golden outcomes of the task calendar leaf, pinned before a refactor.
 *
 * Every verb is driven through the guard outcomes (absent, invisible,
 * readable) and the event-ownership refusal of destroy, asserting the exact
 * status plus body so a restructuring that moves a check goes red.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\TaskEventsController;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TaskEventsControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins allowed and refused outcomes of TaskEventsController.
 */
class TaskEventsControllerA24GoldenTest extends TestCase {

	/**
	 * Task resolver.
	 *
	 * @var TaskManagerInterface&MockObject
	 */
	private TaskManagerInterface&MockObject $tasks;

	/**
	 * Visibility decisions.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The link layer.
	 *
	 * @var CalendarLinkService&MockObject
	 */
	private CalendarLinkService&MockObject $links;

	/**
	 * The VEVENT layer.
	 *
	 * @var ObjectCalendarEventsInterface&MockObject
	 */
	private ObjectCalendarEventsInterface&MockObject $events;

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskManagerInterface::class);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->links = $this->createMock(CalendarLinkService::class);
		$this->events = $this->createMock(ObjectCalendarEventsInterface::class);
	}//end setUp()

	/**
	 * The controller with these request params and session user.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param string|null          $uid    The session uid, or null for none.
	 *
	 * @return TaskEventsController
	 */
	private function controller(array $params = [], ?string $uid = 'alice'): TaskEventsController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return TaskEventsControllerFactory::create('openregister', $request, $this->tasks, $this->authorization, $this->links, $this->events, $session);
	}//end controller()

	/**
	 * A task with register/schema and no title.
	 *
	 * @return Task
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('real-uuid');
		$task->setRegisterId(4);
		$task->setSchemaId(9);

		return $task;
	}//end task()

	/**
	 * Every verb, as a closure over a controller.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function verbs(): array {
		return [
			'index' => [fn (TaskEventsController $c) => $c->index('t-1')],
			'create' => [fn (TaskEventsController $c) => $c->create('t-1')],
			'link' => [fn (TaskEventsController $c) => $c->link('t-1')],
			'unlink' => [fn (TaskEventsController $c) => $c->unlink('t-1', 'uid-1')],
			'destroy' => [fn (TaskEventsController $c) => $c->destroy('t-1', 'ev.ics')],
		];
	}//end verbs()

	/**
	 * Absent or unresolvable task: 404 with the shared body, nothing touched.
	 *
	 * @param callable $verb The verb.
	 *
	 * @dataProvider verbs
	 *
	 * @return void
	 */
	public function testAbsentTaskIsRefused(callable $verb): void {
		$this->tasks->method('get')->willThrowException(new DoesNotExistException('gone'));
		$this->authorization->expects($this->never())->method('mayRead');
		$this->expectNothingTouched();

		$response = $verb($this->controller($this->fullParams()));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testAbsentTaskIsRefused()

	/**
	 * Invisible task: the same 404, asked for alice, nothing touched.
	 *
	 * @param callable $verb The verb.
	 *
	 * @dataProvider verbs
	 *
	 * @return void
	 */
	public function testInvisibleTaskIsRefused(callable $verb): void {
		$task = $this->task();
		$this->tasks->method('get')->with('t-1')->willReturn($task);
		$this->authorization->expects($this->once())->method('mayRead')->with($task, 'alice')->willReturn(false);
		$this->expectNothingTouched();

		$response = $verb($this->controller($this->fullParams()));

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such task'], $response->getData());
	}//end testInvisibleTaskIsRefused()

	/**
	 * Without a session the visibility question is asked for a null uid.
	 *
	 * @return void
	 */
	public function testNoSessionAsksForNullUid(): void {
		$task = $this->task();
		$this->tasks->method('get')->willReturn($task);
		$this->authorization->expects($this->once())->method('mayRead')->with($task, null)->willReturn(false);

		$this->assertSame(404, $this->controller([], null)->index('t-1')->getStatus());
	}//end testNoSessionAsksForNullUid()

	/**
	 * Readable index lists, and a link failure is 500 with the message.
	 *
	 * @return void
	 */
	public function testIndexAllowedAndFailure(): void {
		$this->givenReadable();
		$this->links->method('getLinkedEvents')->with('real-uuid')->willReturnOnConsecutiveCalls(
			[['id' => 'a']],
			$this->throwException(new Exception('calendar down'))
		);

		$ok = $this->controller()->index('t-1');
		$failed = $this->controller()->index('t-1');

		$this->assertSame([200, ['results' => [['id' => 'a']], 'total' => 1]], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame([500, ['error' => 'calendar down']], [$failed->getStatus(), $failed->getData()]);
	}//end testIndexAllowedAndFailure()

	/**
	 * Readable create: blank summary 400, otherwise link created with the uuid title fallback.
	 *
	 * @return void
	 */
	public function testCreate(): void {
		$this->givenReadable();
		$link = new CalendarLink();
		$this->links->expects($this->once())->method('createAndLinkEvent')
			->with('real-uuid', 4, 9, ['summary' => 'Meet', 'objectTitle' => 'real-uuid'])
			->willReturn($link);

		$blank = $this->controller(['summary' => '  '])->create('t-1');
		$ok = $this->controller(['summary' => 'Meet'])->create('t-1');

		$this->assertSame([400, ['error' => 'Event summary is required']], [$blank->getStatus(), $blank->getData()]);
		$this->assertSame([201, $link->jsonSerialize()], [$ok->getStatus(), $ok->getData()]);
	}//end testCreate()

	/**
	 * Create failure is 400 with the message.
	 *
	 * @return void
	 */
	public function testCreateFailure(): void {
		$this->givenReadable();
		$this->links->method('createAndLinkEvent')->willThrowException(new Exception('nope'));

		$response = $this->controller(['summary' => 'Meet'])->create('t-1');

		$this->assertSame([400, ['error' => 'nope']], [$response->getStatus(), $response->getData()]);
	}//end testCreateFailure()

	/**
	 * Readable link: both identifiers required, trimmed, failures 400.
	 *
	 * @return void
	 */
	public function testLink(): void {
		$this->givenReadable();
		$link = new CalendarLink();
		$this->links->method('linkEvent')
			->with('real-uuid', 4, 9, 'cal', 'uid-1')
			->willReturnOnConsecutiveCalls($link, $this->throwException(new Exception('dup')));

		$missing = $this->controller(['calendarUri' => 'cal', 'eventUid' => ' '])->link('t-1');
		$ok = $this->controller(['calendarUri' => ' cal ', 'eventUid' => 'uid-1'])->link('t-1');
		$failed = $this->controller(['calendarUri' => 'cal', 'eventUid' => 'uid-1'])->link('t-1');

		$this->assertSame([400, ['error' => 'calendarUri and eventUid are required']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame([201, $link->jsonSerialize()], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame([400, ['error' => 'dup']], [$failed->getStatus(), $failed->getData()]);
	}//end testLink()

	/**
	 * Readable unlink: success, failure 400.
	 *
	 * @return void
	 */
	public function testUnlink(): void {
		$this->givenReadable();
		$this->links->method('unlinkEvent')->with('real-uuid', 'uid-1')
			->willReturnOnConsecutiveCalls(null, $this->throwException(new Exception('no')));

		$ok = $this->controller()->unlink('t-1', 'uid-1');
		$failed = $this->controller()->unlink('t-1', 'uid-1');

		$this->assertSame([200, ['success' => true]], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame([400, ['error' => 'no']], [$failed->getStatus(), $failed->getData()]);
	}//end testUnlink()

	/**
	 * Destroy of an event not linked to this task is 404 and deletes nothing.
	 *
	 * @param array<int, array<string, mixed>> $linked The task's linked events.
	 *
	 * @testWith [[]]
	 *           [[{"id": "other.ics", "calendarId": 1, "uid": "u"}]]
	 *           [[{"id": "ev.ics", "uid": "u"}, {"id": "ev.ics", "calendarId": 2, "uid": "u"}]]
	 *
	 * @return void
	 */
	public function testDestroyForeignEventIsRefused(array $linked): void {
		$this->givenReadable();
		$this->links->method('getLinkedEvents')->willReturn($linked);
		$this->events->expects($this->never())->method('deleteEvent');
		$this->links->expects($this->never())->method('unlinkEvent');

		$response = $this->controller()->destroy('t-1', 'ev.ics');

		$this->assertSame([404, ['error' => 'Event not found']], [$response->getStatus(), $response->getData()]);
	}//end testDestroyForeignEventIsRefused()

	/**
	 * Destroy deletes, then unlinks by uid.
	 *
	 * @return void
	 */
	public function testDestroyAllowed(): void {
		$this->givenReadable();
		$this->links->method('getLinkedEvents')->willReturn([['id' => 'ev.ics', 'calendarId' => 3, 'uid' => 'u-9']]);
		$this->events->expects($this->once())->method('deleteEvent')->with('3', 'ev.ics');
		$this->links->expects($this->once())->method('unlinkEvent')->with('real-uuid', 'u-9');

		$response = $this->controller()->destroy('t-1', 'ev.ics');

		$this->assertSame([200, ['success' => true]], [$response->getStatus(), $response->getData()]);
	}//end testDestroyAllowed()

	/**
	 * Destroy of an event without uid deletes and does not unlink; failures are 400.
	 *
	 * @return void
	 */
	public function testDestroyWithoutUidAndFailure(): void {
		$this->givenReadable();
		$this->links->method('getLinkedEvents')->willReturnOnConsecutiveCalls(
			[['id' => 'ev.ics', 'calendarId' => 3]],
			$this->throwException(new Exception('lookup failed'))
		);
		$this->events->expects($this->once())->method('deleteEvent')->with('3', 'ev.ics');
		$this->links->expects($this->never())->method('unlinkEvent');

		$ok = $this->controller()->destroy('t-1', 'ev.ics');
		$failed = $this->controller()->destroy('t-1', 'ev.ics');

		$this->assertSame([200, ['success' => true]], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame([400, ['error' => 'lookup failed']], [$failed->getStatus(), $failed->getData()]);
	}//end testDestroyWithoutUidAndFailure()

	/**
	 * The task resolves and is readable for alice.
	 *
	 * @return void
	 */
	private function givenReadable(): void {
		$task = $this->task();
		$this->tasks->method('get')->with('t-1')->willReturn($task);
		$this->authorization->method('mayRead')->with($task, 'alice')->willReturn(true);
	}//end givenReadable()

	/**
	 * Params that would satisfy every verb's validation.
	 *
	 * @return array<string, string>
	 */
	private function fullParams(): array {
		return ['summary' => 'Meet', 'calendarUri' => 'cal', 'eventUid' => 'uid-1'];
	}//end fullParams()

	/**
	 * Neither calendar layer is reached.
	 *
	 * @return void
	 */
	private function expectNothingTouched(): void {
		foreach (['getLinkedEvents', 'createAndLinkEvent', 'linkEvent', 'unlinkEvent'] as $method) {
			$this->links->expects($this->never())->method($method);
		}

		$this->events->expects($this->never())->method('deleteEvent');
	}//end expectNothingTouched()
}//end class
