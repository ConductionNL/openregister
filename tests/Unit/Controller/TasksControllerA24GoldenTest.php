<?php

/**
 * Golden outcomes of the object task leaf and the user task aggregate,
 * pinned before a refactor.
 *
 * Covers the object guard (absent, DoesNotExist), the task-ownership guard
 * on update/destroy (requireTaskOf before the write), the per-verb error
 * mapping, and the aggregate's session gate plus the admin/group facts it
 * hands to the inbox query.
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
use OCA\OpenRegister\Controller\TasksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TasksControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins allowed and refused outcomes of TasksController.
 */
class TasksControllerA24GoldenTest extends TestCase {

	/**
	 * CalDAV task layer.
	 *
	 * @var ObjectCalendarTasksInterface&MockObject
	 */
	private ObjectCalendarTasksInterface&MockObject $taskService;

	/**
	 * Object resolution.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->taskService = $this->createMock(ObjectCalendarTasksInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * A request answering these params.
	 *
	 * @param array<string, mixed> $params The params.
	 *
	 * @return IRequest&MockObject
	 */
	private function request(array $params): IRequest&MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return $request;
	}//end request()

	/**
	 * The object-leaf controller.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return TasksController
	 */
	private function controller(array $params = []): TasksController {
		return TasksControllerFactory::create('openregister', $this->request($params), $this->taskService, $this->objectService);
	}//end controller()

	/**
	 * The aggregate controller.
	 *
	 * @param array<string, mixed>  $params The request params.
	 * @param string|null           $uid    The session uid.
	 * @param TaskInboxService|null $inbox  The inbox.
	 * @param IGroupManager|null    $groups The group backend.
	 *
	 * @return TasksController
	 */
	private function aggregate(array $params, ?string $uid, ?TaskInboxService $inbox, ?IGroupManager $groups): TasksController {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return TasksControllerFactory::create(
			'openregister',
			$this->request($params),
			$this->taskService,
			$this->objectService,
			$inbox,
			$session,
			null,
			$groups
		);
	}//end aggregate()

	/**
	 * The object resolves.
	 *
	 * @return ObjectEntity
	 */
	private function givenObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');
		$object->setRegister('1');
		$object->setSchema('2');
		$this->objectService->method('getObject')->willReturn($object);

		return $object;
	}//end givenObject()

	/**
	 * Every object verb.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function objectVerbs(): array {
		return [
			'index' => [fn (TasksController $c) => $c->index('r', 's', 'o')],
			'create' => [fn (TasksController $c) => $c->create('r', 's', 'o')],
			'update' => [fn (TasksController $c) => $c->update('r', 's', 'o', 'task.ics')],
			'destroy' => [fn (TasksController $c) => $c->destroy('r', 's', 'o', 'task.ics')],
		];
	}//end objectVerbs()

	/**
	 * A null object is 404 and no task is touched; the path is set in order.
	 *
	 * @param callable $verb The verb.
	 *
	 * @dataProvider objectVerbs
	 *
	 * @return void
	 */
	public function testAbsentObjectIsRefused(callable $verb): void {
		$this->objectService->expects($this->once())->method('setRegister')->with('r');
		$this->objectService->expects($this->once())->method('setSchema')->with('s');
		$this->objectService->expects($this->once())->method('setObject')->with('o');
		$this->objectService->method('getObject')->willReturn(null);
		$this->expectNoTaskTouched();

		$response = $verb($this->controller(['summary' => 'x', 'calendarId' => 'c']));

		$this->assertSame([404, ['error' => 'Object not found']], [$response->getStatus(), $response->getData()]);
	}//end testAbsentObjectIsRefused()

	/**
	 * A DoesNotExist from resolution is the same 404.
	 *
	 * @param callable $verb The verb.
	 *
	 * @dataProvider objectVerbs
	 *
	 * @return void
	 */
	public function testMissingObjectIsRefused(callable $verb): void {
		$this->objectService->method('getObject')->willThrowException(new DoesNotExistException('no'));
		$this->expectNoTaskTouched();

		$response = $verb($this->controller(['summary' => 'x', 'calendarId' => 'c']));

		$this->assertSame([404, ['error' => 'Object not found']], [$response->getStatus(), $response->getData()]);
	}//end testMissingObjectIsRefused()

	/**
	 * Index: listing, empty on no calendar, 500 on other failures.
	 *
	 * @return void
	 */
	public function testIndex(): void {
		$this->givenObject();
		$this->taskService->method('getTasksForObject')->with('obj-uuid')->willReturnOnConsecutiveCalls(
			[['id' => 'a']],
			$this->throwException(new NoVtodoCalendarException('none')),
			$this->throwException(new Exception('boom'))
		);

		$ok = $this->controller()->index('r', 's', 'o');
		$none = $this->controller()->index('r', 's', 'o');
		$failed = $this->controller()->index('r', 's', 'o');

		$this->assertSame([200, ['results' => [['id' => 'a']], 'total' => 1]], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame([200, ['results' => [], 'total' => 0]], [$none->getStatus(), $none->getData()]);
		$this->assertSame([500, ['error' => 'boom']], [$failed->getStatus(), $failed->getData()]);
	}//end testIndex()

	/**
	 * Create: summary required, created 201 with the uuid title fallback, failure 400.
	 *
	 * @return void
	 */
	public function testCreate(): void {
		$this->givenObject();
		$this->taskService->method('createTask')
			->with(1, 2, 'obj-uuid', 'obj-uuid', ['summary' => 'Do'])
			->willReturnOnConsecutiveCalls(['id' => 'n'], $this->throwException(new Exception('caldav', 404)));

		$blank = $this->controller(['summary' => ''])->create('r', 's', 'o');
		$ok = $this->controller(['summary' => 'Do'])->create('r', 's', 'o');
		$failed = $this->controller(['summary' => 'Do'])->create('r', 's', 'o');

		$this->assertSame([400, ['error' => 'Task summary is required']], [$blank->getStatus(), $blank->getData()]);
		$this->assertSame([201, ['id' => 'n']], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame([400, ['error' => 'caldav']], [$failed->getStatus(), $failed->getData()]);
	}//end testCreate()

	/**
	 * Update with an explicit calendar: ownership checked before the write.
	 *
	 * @return void
	 */
	public function testUpdateChecksOwnershipFirst(): void {
		$this->givenObject();
		$order = [];
		$this->taskService->expects($this->never())->method('getTasksForObject');
		$this->taskService->method('requireTaskOf')->with('obj-uuid', 'cal-1', 'task.ics')
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'require';
			});
		$this->taskService->method('updateTask')->with('cal-1', 'task.ics', ['calendarId' => 'cal-1', 'x' => 1])
			->willReturnCallback(function () use (&$order): array {
				$order[] = 'update';
				return ['id' => 'task.ics'];
			});

		$response = $this->controller(['calendarId' => 'cal-1', 'x' => 1])->update('r', 's', 'o', 'task.ics');

		$this->assertSame([200, ['id' => 'task.ics']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['require', 'update'], $order);
	}//end testUpdateChecksOwnershipFirst()

	/**
	 * Update/destroy without a calendar look the task up among the object's tasks.
	 *
	 * @return void
	 */
	public function testLookupFindsCalendar(): void {
		$this->givenObject();
		$this->taskService->method('getTasksForObject')->willReturn([
			['id' => 'other.ics', 'calendarId' => 'cal-x'],
			['id' => 'task.ics', 'calendarId' => 'cal-2'],
			['id' => 'task.ics', 'calendarId' => 'cal-3'],
		]);
		$this->taskService->expects($this->exactly(2))->method('requireTaskOf')->with('obj-uuid', 'cal-2', 'task.ics');
		$this->taskService->expects($this->once())->method('updateTask')->with('cal-2', 'task.ics', [])->willReturn(['ok' => 1]);
		$this->taskService->expects($this->once())->method('deleteTask')->with('cal-2', 'task.ics');

		$updated = $this->controller()->update('r', 's', 'o', 'task.ics');
		$deleted = $this->controller(['calendarId' => 'ignored'])->destroy('r', 's', 'o', 'task.ics');

		$this->assertSame([200, ['ok' => 1]], [$updated->getStatus(), $updated->getData()]);
		$this->assertSame([200, ['success' => true]], [$deleted->getStatus(), $deleted->getData()]);
	}//end testLookupFindsCalendar()

	/**
	 * A task not among the object's tasks is 404 'Task not found', nothing written.
	 *
	 * @return void
	 */
	public function testUnknownTaskIsRefused(): void {
		$this->givenObject();
		$this->taskService->method('getTasksForObject')->willReturn([['id' => 'other.ics', 'calendarId' => 'c']]);
		$this->taskService->expects($this->never())->method('requireTaskOf');
		$this->taskService->expects($this->never())->method('updateTask');
		$this->taskService->expects($this->never())->method('deleteTask');

		$updated = $this->controller()->update('r', 's', 'o', 'task.ics');
		$deleted = $this->controller()->destroy('r', 's', 'o', 'task.ics');

		$this->assertSame([404, ['error' => 'Task not found']], [$updated->getStatus(), $updated->getData()]);
		$this->assertSame([404, ['error' => 'Task not found']], [$deleted->getStatus(), $deleted->getData()]);
	}//end testUnknownTaskIsRefused()

	/**
	 * A foreign task (requireTaskOf refuses with 404) is never written.
	 *
	 * @return void
	 */
	public function testForeignTaskIsRefused(): void {
		$this->givenObject();
		$this->taskService->method('getTasksForObject')->willReturn([['id' => 'task.ics', 'calendarId' => 'c']]);
		$this->taskService->method('requireTaskOf')->willThrowException(new Exception('Task not found', 404));
		$this->taskService->expects($this->never())->method('updateTask');
		$this->taskService->expects($this->never())->method('deleteTask');

		$updated = $this->controller(['calendarId' => 'c'])->update('r', 's', 'o', 'task.ics');
		$deleted = $this->controller()->destroy('r', 's', 'o', 'task.ics');

		$this->assertSame([404, ['error' => 'Task not found']], [$updated->getStatus(), $updated->getData()]);
		$this->assertSame([404, ['error' => 'Task not found']], [$deleted->getStatus(), $deleted->getData()]);
	}//end testForeignTaskIsRefused()

	/**
	 * Write failures without code 404 are 400.
	 *
	 * @return void
	 */
	public function testWriteFailuresAre400(): void {
		$this->givenObject();
		$this->taskService->method('getTasksForObject')->willReturn([['id' => 'task.ics', 'calendarId' => 'c']]);
		$this->taskService->method('updateTask')->willThrowException(new Exception('bad', 3));
		$this->taskService->method('deleteTask')->willThrowException(new Exception('worse'));

		$updated = $this->controller(['calendarId' => 'c'])->update('r', 's', 'o', 'task.ics');
		$deleted = $this->controller()->destroy('r', 's', 'o', 'task.ics');

		$this->assertSame([400, ['error' => 'bad']], [$updated->getStatus(), $updated->getData()]);
		$this->assertSame([400, ['error' => 'worse']], [$deleted->getStatus(), $deleted->getData()]);
	}//end testWriteFailuresAre400()

	/**
	 * The aggregate refuses without a session, a uid, or an inbox.
	 *
	 * @return void
	 */
	public function testAggregateRefusedWithoutSession(): void {
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->expects($this->never())->method('inbox');

		$cases = [
			$this->aggregate([], null, $inbox, null)->allUserTasks(),
			$this->aggregate([], '  ', $inbox, null)->allUserTasks(),
			$this->aggregate([], 'alice', null, null)->allUserTasks(),
		];

		foreach ($cases as $response) {
			$this->assertSame([401, ['error' => 'No session']], [$response->getStatus(), $response->getData()]);
		}
	}//end testAggregateRefusedWithoutSession()

	/**
	 * An unknown state is 400 before the inbox is asked.
	 *
	 * @return void
	 */
	public function testAggregateRefusesUnknownState(): void {
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->expects($this->never())->method('inbox');

		$response = $this->aggregate(['state' => 'nonsense-state'], 'alice', $inbox, null)->allUserTasks();

		$this->assertSame(400, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testAggregateRefusesUnknownState()

	/**
	 * The admin and group facts come from the backend, and fail closed.
	 *
	 * @return void
	 */
	public function testAggregateCriteria(): void {
		$seen = [];
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('inbox')->willReturnCallback(function (TaskInboxCriteria $criteria, int $limit, int $offset) use (&$seen): array {
			$seen[] = [$criteria, $limit, $offset];
			return ['results' => [], 'total' => 0];
		});

		$admin = $this->createMock(IGroupManager::class);
		$admin->method('isAdmin')->with('alice')->willReturn(true);
		$admin->method('getUserGroupIds')->willReturn(['g1']);
		$broken = $this->createMock(IGroupManager::class);
		$broken->method('isAdmin')->willThrowException(new Exception('ldap'));
		$broken->method('getUserGroupIds')->willThrowException(new Exception('ldap'));

		$params = ['_limit' => '500', 'offset' => '7', 'scope' => 'all', 'sort' => '-priority', 'isTerminal' => 'false', 'status' => 'open'];
		$first = $this->aggregate($params, 'alice', $inbox, $admin)->allUserTasks();
		$this->aggregate(['limit' => '3'], 'alice', $inbox, $broken)->allUserTasks();
		$this->aggregate([], 'alice', $inbox, null)->allUserTasks();

		$this->assertSame([200, ['results' => [], 'total' => 0]], [$first->getStatus(), $first->getData()]);
		$first0 = $seen[0][0];
		$this->assertSame(
			[true, ['g1'], 'all', 'priority', true, false, 200, 7],
			[$first0->isAdmin, $first0->groupIds, $first0->scope, $first0->sort, $first0->sortDescending, $first0->isTerminal, $seen[0][1], $seen[0][2]]
		);
		$this->assertSame('alice', $seen[0][0]->uid);
		$this->assertNotSame([], $seen[0][0]->states);
		$this->assertSame([false, [], null, 3, 0], [$seen[1][0]->isAdmin, $seen[1][0]->groupIds, $seen[1][0]->isTerminal, $seen[1][1], $seen[1][2]]);
		$this->assertSame([false, [], [], 50, 0], [$seen[2][0]->isAdmin, $seen[2][0]->groupIds, $seen[2][0]->states, $seen[2][1], $seen[2][2]]);
		$this->assertNull($seen[2][0]->overdueAt);
	}//end testAggregateCriteria()

	/**
	 * The overdue filter sets a cutoff; an inbox failure is 500 with the message.
	 *
	 * @return void
	 */
	public function testAggregateOverdueAndFailure(): void {
		$seen = [];
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('inbox')->willReturnCallback(function (TaskInboxCriteria $criteria) use (&$seen): array {
			$seen[] = $criteria;
			throw new Exception('query failed');
		});

		$response = $this->aggregate(['overdue' => 'true'], 'alice', $inbox, null)->allUserTasks();

		$this->assertSame([500, ['error' => 'query failed']], [$response->getStatus(), $response->getData()]);
		$this->assertNotNull($seen[0]->overdueAt);
	}//end testAggregateOverdueAndFailure()

	/**
	 * No CalDAV operation is reached.
	 *
	 * @return void
	 */
	private function expectNoTaskTouched(): void {
		foreach (['getTasksForObject', 'createTask', 'updateTask', 'deleteTask', 'requireTaskOf'] as $method) {
			$this->taskService->expects($this->never())->method($method);
		}
	}//end expectNoTaskTouched()
}//end class
