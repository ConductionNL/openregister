<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\TasksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TasksControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var TasksController
	 */
	private TasksController $controller;

	/**
	 * The mocked HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked CalDAV task service.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $taskService;

	/**
	 * The mocked object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->taskService = $this->createMock(TaskService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new TasksController(
			'openregister',
			$this->request,
			$this->taskService,
			$this->objectService
		);
	}

	private function createObjectEntity(): ObjectEntity {
		$object = new ObjectEntity();
		$ref = new \ReflectionClass($object);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($object, 1);
		$object->setUuid('test-uuid');
		$object->setRegister('1');
		$object->setSchema('2');
		return $object;
	}

	private function setupObjectValidation(?ObjectEntity $object): void {
		$this->objectService->expects($this->once())->method('setSchema');
		$this->objectService->expects($this->once())->method('setRegister');
		$this->objectService->expects($this->once())->method('setObject');
		$this->objectService->expects($this->once())
			->method('getObject')
			->willReturn($object);
	}

	public function testIndexSuccess(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$tasks = [['id' => 'task-1', 'summary' => 'Test']];
		$this->taskService
			->expects($this->once())
			->method('getTasksForObject')
			->with('test-uuid')
			->willReturn($tasks);

		$result = $this->controller->index('reg', 'schema', '1');

		$this->assertInstanceOf(JSONResponse::class, $result);
		$data = $result->getData();
		$this->assertEquals($tasks, $data['results']);
		$this->assertEquals(1, $data['total']);
		$this->assertEquals(200, $result->getStatus());
	}

	public function testIndexObjectNotFound(): void {
		$this->setupObjectValidation(null);

		$result = $this->controller->index('reg', 'schema', '1');

		$this->assertEquals(404, $result->getStatus());
		$this->assertEquals('Object not found', $result->getData()['error']);
	}

	public function testIndexDoesNotExistException(): void {
		$this->objectService->method('setSchema');
		$this->objectService->method('setRegister');
		$this->objectService->method('setObject');
		$this->objectService
			->method('getObject')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->controller->index('reg', 'schema', '1');

		$this->assertEquals(404, $result->getStatus());
	}

	public function testIndexReturnsEmptyWhenNoVtodoCalendar(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$this->taskService
			->expects($this->once())
			->method('getTasksForObject')
			->willThrowException(new NoVtodoCalendarException('admin'));

		$result = $this->controller->index('reg', 'schema', '1');

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals([], $result->getData()['results']);
		$this->assertEquals(0, $result->getData()['total']);
	}

	public function testIndexGeneralException(): void {
		$this->objectService->method('setSchema');
		$this->objectService->method('setRegister');
		$this->objectService->method('setObject');
		$this->objectService
			->method('getObject')
			->willThrowException(new \Exception('Something broke'));

		$result = $this->controller->index('reg', 'schema', '1');

		$this->assertEquals(500, $result->getStatus());
		$this->assertEquals('Something broke', $result->getData()['error']);
	}

	public function testCreateSuccess(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$this->request->method('getParams')->willReturn(['summary' => 'New Task']);

		$taskData = ['id' => 'task-new', 'summary' => 'New Task'];
		$this->taskService
			->expects($this->once())
			->method('createTask')
			->willReturn($taskData);

		$result = $this->controller->create('reg', 'schema', '1');

		$this->assertEquals(201, $result->getStatus());
		$this->assertEquals($taskData, $result->getData());
	}

	public function testCreateMissingSummary(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->create('reg', 'schema', '1');

		$this->assertEquals(400, $result->getStatus());
		$this->assertEquals('Task summary is required', $result->getData()['error']);
	}

	public function testCreateObjectNotFound(): void {
		$this->setupObjectValidation(null);

		$result = $this->controller->create('reg', 'schema', '1');

		$this->assertEquals(404, $result->getStatus());
	}

	public function testUpdateSuccess(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$this->request->method('getParams')->willReturn(['calendarId' => 'cal-1']);

		$taskData = ['id' => 'task-1', 'summary' => 'Updated'];
		$this->taskService
			->expects($this->once())
			->method('updateTask')
			->willReturn($taskData);

		$result = $this->controller->update('reg', 'schema', '1', 'task-1');

		$this->assertEquals(200, $result->getStatus());
	}

	public function testUpdateTaskNotFound(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$this->request->method('getParams')->willReturn([]);
		$this->taskService
			->method('getTasksForObject')
			->willReturn([]);

		$result = $this->controller->update('reg', 'schema', '1', 'task-1');

		$this->assertEquals(404, $result->getStatus());
		$this->assertEquals('Task not found', $result->getData()['error']);
	}

	public function testDestroySuccess(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$this->taskService
			->method('getTasksForObject')
			->willReturn([['id' => 'task-1', 'calendarId' => 'cal-1']]);

		$this->taskService
			->expects($this->once())
			->method('deleteTask')
			->with('cal-1', 'task-1');

		$result = $this->controller->destroy('reg', 'schema', '1', 'task-1');

		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['success']);
	}

	public function testDestroyTaskNotFound(): void {
		$object = $this->createObjectEntity();
		$this->setupObjectValidation($object);

		$this->taskService
			->method('getTasksForObject')
			->willReturn([]);

		$result = $this->controller->destroy('reg', 'schema', '1', 'task-1');

		$this->assertEquals(404, $result->getStatus());
		$this->assertEquals('Task not found', $result->getData()['error']);
	}

	// ── GET /api/tasks — the caller's own tasks across all calendars ───────

	/**
	 * Build a controller wired to the engine inbox for the aggregate tests.
	 *
	 * @param string|null $uid The session user, or null for no session.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{0: TasksController, 1: TaskInboxService&MockObject}
	 */
	private function inboxController(?string $uid, array $params = []): array {
		$inbox = $this->createMock(TaskInboxService::class);
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$this->request->method('getParam')
			->willReturnCallback(
				static function (string $key, $default = null) use ($params) {
					return ($params[$key] ?? $default);
				}
			);

		$controller = new TasksController(
			'openregister',
			$this->request,
			$this->taskService,
			$this->objectService,
			$inbox,
			$session,
			new TaskTemporalProjection(),
			null
		);

		return [$controller, $inbox];
	}

	/**
	 * The aggregate answers from the engine inbox, scoped to the session
	 * user, and no calendar is enumerated to produce it.
	 *
	 * @return void
	 */
	public function testAllUserTasksAnswersFromTheInboxForTheSessionUser(): void {
		[$controller, $inbox] = $this->inboxController('alice');
		$payload = ['results' => [['uuid' => 't-1']], 'total' => 1, 'limit' => 50, 'offset' => 0];

		$captured = null;
		$inbox->expects($this->once())->method('inbox')
			->willReturnCallback(
				static function (TaskInboxCriteria $criteria, int $limit, int $offset) use (&$captured, $payload) {
					$captured = [$criteria, $limit, $offset];
					return $payload;
				}
			);
		$this->taskService->expects($this->never())->method('getAllUserTasks');

		$result = $controller->allUserTasks();

		$this->assertSame(200, $result->getStatus());
		$this->assertSame($payload, $result->getData());
		$this->assertSame('alice', $captured[0]->uid);
		$this->assertSame(TaskInboxCriteria::SCOPE_ALL, $captured[0]->scope);
		$this->assertSame([50, 0], [$captured[1], $captured[2]]);
	}

	/**
	 * `assignee` is no longer a filter: the aggregate is already scoped to
	 * the caller, and no parameter names another user's tasks.
	 *
	 * @return void
	 */
	public function testAllUserTasksIgnoresTheAssigneeParameterAndNormalisesStatus(): void {
		[$controller, $inbox] = $this->inboxController('alice', [
			'status' => 'done',
			'assignee' => 'bob',
			'_limit' => '25',
			'_offset' => '10',
		]);

		$captured = null;
		$inbox->method('inbox')
			->willReturnCallback(
				static function (TaskInboxCriteria $criteria, int $limit, int $offset) use (&$captured) {
					$captured = [$criteria, $limit, $offset];
					return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
				}
			);

		$controller->allUserTasks();

		$this->assertSame('alice', $captured[0]->uid, 'the caller, never the assignee parameter');
		$this->assertSame(['completed'], $captured[0]->states, 'legacy status resolves through TaskState');
		$this->assertSame([25, 10], [$captured[1], $captured[2]]);
	}

	public function testAllUserTasksAcceptsTheLegacyLimitAndOffsetSpellings(): void {
		[$controller, $inbox] = $this->inboxController('alice', ['limit' => '15', 'offset' => '5']);

		$inbox->expects($this->once())->method('inbox')
			->with($this->anything(), 15, 5)
			->willReturn(['results' => [], 'total' => 0, 'limit' => 15, 'offset' => 5]);

		$controller->allUserTasks();
	}

	public function testAllUserTasksCapsThePageSizeAtTwoHundred(): void {
		[$controller, $inbox] = $this->inboxController('alice', ['_limit' => '100000']);

		$inbox->expects($this->once())->method('inbox')
			->with($this->anything(), 200, 0)
			->willReturn(['results' => [], 'total' => 0, 'limit' => 200, 'offset' => 0]);

		$controller->allUserTasks();
	}

	public function testAllUserTasksRefusesAnUnmappedStatusRatherThanIgnoringIt(): void {
		[$controller, $inbox] = $this->inboxController('alice', ['status' => 'whatever']);
		$inbox->expects($this->never())->method('inbox');

		$result = $controller->allUserTasks();

		$this->assertSame(400, $result->getStatus());
		$this->assertStringContainsString("'whatever'", $result->getData()['error']);
	}

	public function testAllUserTasksWithoutASessionIsUnauthorized(): void {
		[$controller, $inbox] = $this->inboxController(null);
		$inbox->expects($this->never())->method('inbox');

		$result = $controller->allUserTasks();

		$this->assertSame(401, $result->getStatus());
	}

	public function testAllUserTasksReturns500WhenTheInboxFails(): void {
		[$controller, $inbox] = $this->inboxController('alice');
		$inbox->method('inbox')->willThrowException(new \RuntimeException('database down'));

		$result = $controller->allUserTasks();

		$this->assertSame(500, $result->getStatus());
		$this->assertSame('database down', $result->getData()['error']);
	}

	/**
	 * A create payload attempting to set an engine task identity is refused
	 * by the service; the controller reports it as a 400, and no VTODO is
	 * created.
	 *
	 * @return void
	 */
	public function testCreateRefusesAnEngineTaskIdentity(): void {
		$this->setupObjectValidation($this->createObjectEntity());
		$this->request->method('getParams')->willReturn(['summary' => 'Forged', 'X-OPENREGISTER-TASK' => 'some-uuid']);
		$this->taskService->method('createTask')
			->willThrowException(new TaskAccessDeniedException('An engine task cannot be created through the object task endpoint.'));

		$result = $this->controller->create('1', '2', 'test-uuid');

		$this->assertSame(400, $result->getStatus());
		$this->assertStringContainsString('engine task', $result->getData()['error']);
	}
}
