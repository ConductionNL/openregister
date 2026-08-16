<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\TasksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
	 * Record the arguments TaskService::getAllUserTasks() is called with.
	 *
	 * @param array<int, mixed> $capture Receives [status, limit, offset, assignee].
	 * @param array<string, mixed> $result The payload the service returns.
	 *
	 * @return void
	 */
	private function expectAllUserTasksCall(array &$capture, array $result): void {
		$this->taskService
			->expects($this->once())
			->method('getAllUserTasks')
			->willReturnCallback(
				static function (?string $status, int $limit, int $offset, ?string $assignee) use (&$capture, $result) {
					$capture = [$status, $limit, $offset, $assignee];
					return $result;
				}
			);
	}

	public function testAllUserTasksReturnsTheServicePayload(): void {
		$payload = [
			'results' => [
				['id' => 'task-1', 'summary' => 'Review permit application', 'status' => 'NEEDS-ACTION'],
			],
			'total' => 1,
		];

		$this->request->method('getParam')->willReturn(null);

		$capture = [];
		$this->expectAllUserTasksCall($capture, $payload);

		$result = $this->controller->allUserTasks();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($payload, $result->getData());
		// Defaults when the caller sends no query parameters at all.
		$this->assertEquals([null, 50, 0, null], $capture);
	}

	public function testAllUserTasksForwardsStatusPagingAndAssigneeFilters(): void {
		$params = [
			'status' => 'NEEDS-ACTION',
			'_limit' => '25',
			'_offset' => '10',
			'assignee' => 'alice',
		];
		$this->request->method('getParam')
			->willReturnCallback(
				static function (string $key, $default = null) use ($params) {
					return ($params[$key] ?? $default);
				}
			);

		$capture = [];
		$this->expectAllUserTasksCall($capture, ['results' => [], 'total' => 0]);

		$result = $this->controller->allUserTasks();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals(['NEEDS-ACTION', 25, 10, 'alice'], $capture);
	}

	/**
	 * `limit`/`offset` are the legacy spellings of `_limit`/`_offset`; both
	 * must reach the service or a client written against either one silently
	 * gets the default page.
	 *
	 * @return void
	 */
	public function testAllUserTasksAcceptsTheLegacyLimitAndOffsetSpellings(): void {
		$params = ['limit' => '15', 'offset' => '5'];
		$this->request->method('getParam')
			->willReturnCallback(
				static function (string $key, $default = null) use ($params) {
					return ($params[$key] ?? $default);
				}
			);

		$capture = [];
		$this->expectAllUserTasksCall($capture, ['results' => [], 'total' => 0]);

		$this->controller->allUserTasks();

		$this->assertEquals([null, 15, 5, null], $capture);
	}

	/**
	 * The page size is capped server-side at 200 — an uncapped `_limit` would
	 * let one request walk every VTODO in every calendar.
	 */
	public function testAllUserTasksCapsThePageSizeAtTwoHundred(): void {
		$params = ['_limit' => '100000'];
		$this->request->method('getParam')
			->willReturnCallback(
				static function (string $key, $default = null) use ($params) {
					return ($params[$key] ?? $default);
				}
			);

		$capture = [];
		$this->expectAllUserTasksCall($capture, ['results' => [], 'total' => 0]);

		$this->controller->allUserTasks();

		$this->assertEquals(200, $capture[1]);
	}

	public function testAllUserTasksReturns500WhenTheCalendarBackendFails(): void {
		$this->request->method('getParam')->willReturn(null);

		$this->taskService
			->method('getAllUserTasks')
			->willThrowException(new \Exception('No user logged in'));

		$result = $this->controller->allUserTasks();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(500, $result->getStatus());
		$this->assertEquals('No user logged in', $result->getData()['error']);
	}
}
