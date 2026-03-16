<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\TasksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Gap tests for TasksController covering uncovered branches.
 */
class TasksControllerGapTest extends TestCase
{
    private TasksController $controller;
    private IRequest&MockObject $request;
    private TaskService&MockObject $taskService;
    private ObjectService&MockObject $objectService;

    protected function setUp(): void
    {
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

    private function createObjectEntity(): ObjectEntity
    {
        $object = new ObjectEntity();
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($object, 1);
        $object->setUuid('test-uuid');
        $object->setRegister('1');
        $object->setSchema('2');
        $object->setName('Test Object');
        return $object;
    }

    private function setupObjectValidation(?ObjectEntity $object): void
    {
        $this->objectService->expects($this->once())->method('setSchema');
        $this->objectService->expects($this->once())->method('setRegister');
        $this->objectService->expects($this->once())->method('setObject');
        $this->objectService->expects($this->once())
            ->method('getObject')
            ->willReturn($object);
    }

    /**
     * Test update with calendarId in request data (covers calendarId !== null branch).
     */
    public function testUpdateWithCalendarIdInRequest(): void
    {
        $object = $this->createObjectEntity();
        $this->setupObjectValidation($object);

        $this->request->method('getParams')->willReturn([
            'summary' => 'Updated task',
            'calendarId' => '42',
        ]);

        $this->taskService->expects($this->once())
            ->method('updateTask')
            ->with('42', 'task-uri', $this->anything())
            ->willReturn(['id' => 'task-uri', 'summary' => 'Updated task']);

        $result = $this->controller->update('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test update without calendarId - task found in existing tasks (covers lookup branch).
     */
    public function testUpdateFindsCalendarIdFromExistingTasks(): void
    {
        $object = $this->createObjectEntity();
        $this->setupObjectValidation($object);

        $this->request->method('getParams')->willReturn([
            'summary' => 'Updated task',
        ]);

        $this->taskService->method('getTasksForObject')
            ->with('test-uuid')
            ->willReturn([
                ['id' => 'other-task', 'calendarId' => '10'],
                ['id' => 'task-uri', 'calendarId' => '20'],
            ]);

        $this->taskService->expects($this->once())
            ->method('updateTask')
            ->with('20', 'task-uri', $this->anything())
            ->willReturn(['id' => 'task-uri', 'summary' => 'Updated task']);

        $result = $this->controller->update('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * Test update when task not found (covers calendarId === null branch).
     */
    public function testUpdateTaskNotFound(): void
    {
        $object = $this->createObjectEntity();
        $this->setupObjectValidation($object);

        $this->request->method('getParams')->willReturn([
            'summary' => 'Updated task',
        ]);

        $this->taskService->method('getTasksForObject')
            ->willReturn([
                ['id' => 'other-task', 'calendarId' => '10'],
            ]);

        $result = $this->controller->update('reg', 'schema', 'id', 'nonexistent-task');

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('not found', strtolower($data['error']));
    }

    /**
     * Test update with null object (covers object === null branch).
     */
    public function testUpdateObjectNotFound(): void
    {
        $this->setupObjectValidation(null);

        $result = $this->controller->update('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(404, $result->getStatus());
    }

    /**
     * Test update throws DoesNotExistException.
     */
    public function testUpdateDoesNotExistException(): void
    {
        $this->objectService->method('setSchema');
        $this->objectService->method('setRegister');
        $this->objectService->method('setObject');
        $this->objectService->method('getObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->update('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(404, $result->getStatus());
    }

    /**
     * Test update throws generic exception.
     */
    public function testUpdateGenericException(): void
    {
        $this->objectService->method('setSchema');
        $this->objectService->method('setRegister');
        $this->objectService->method('setObject');
        $this->objectService->method('getObject')
            ->willThrowException(new \Exception('Generic error'));

        $result = $this->controller->update('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test destroy when task not found in task list (covers calendarId === null).
     */
    public function testDestroyTaskNotFoundInList(): void
    {
        $object = $this->createObjectEntity();
        $this->setupObjectValidation($object);

        $this->taskService->method('getTasksForObject')
            ->willReturn([
                ['id' => 'other-task', 'calendarId' => '10'],
            ]);

        $result = $this->controller->destroy('reg', 'schema', 'id', 'nonexistent-task');

        $this->assertEquals(404, $result->getStatus());
    }

    /**
     * Test destroy success (covers calendarId found + deleteTask).
     */
    public function testDestroySuccess(): void
    {
        $object = $this->createObjectEntity();
        $this->setupObjectValidation($object);

        $this->taskService->method('getTasksForObject')
            ->willReturn([
                ['id' => 'task-uri', 'calendarId' => '20'],
            ]);

        $this->taskService->expects($this->once())
            ->method('deleteTask')
            ->with('20', 'task-uri');

        $result = $this->controller->destroy('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    /**
     * Test destroy with null object.
     */
    public function testDestroyObjectNotFound(): void
    {
        $this->setupObjectValidation(null);

        $result = $this->controller->destroy('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(404, $result->getStatus());
    }

    /**
     * Test destroy throws DoesNotExistException.
     */
    public function testDestroyDoesNotExistException(): void
    {
        $this->objectService->method('setSchema');
        $this->objectService->method('setRegister');
        $this->objectService->method('setObject');
        $this->objectService->method('getObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(404, $result->getStatus());
    }

    /**
     * Test destroy throws generic exception.
     */
    public function testDestroyGenericException(): void
    {
        $this->objectService->method('setSchema');
        $this->objectService->method('setRegister');
        $this->objectService->method('setObject');
        $this->objectService->method('getObject')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->destroy('reg', 'schema', 'id', 'task-uri');

        $this->assertEquals(400, $result->getStatus());
    }

    /**
     * Test create with empty summary (covers summary validation).
     */
    public function testCreateEmptySummary(): void
    {
        $object = $this->createObjectEntity();
        $this->setupObjectValidation($object);

        $this->request->method('getParams')->willReturn([
            'summary' => '',
        ]);

        $result = $this->controller->create('reg', 'schema', 'id');

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('summary', strtolower($data['error']));
    }

    /**
     * Test create with object that has null name (covers getName() ?? getUuid() fallback).
     */
    public function testCreateWithNullObjectName(): void
    {
        $object = new ObjectEntity();
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($object, 1);
        $object->setUuid('test-uuid');
        $object->setRegister('1');
        $object->setSchema('2');
        // name is null by default

        $this->setupObjectValidation($object);

        $this->request->method('getParams')->willReturn([
            'summary' => 'Test task',
        ]);

        $this->taskService->expects($this->once())
            ->method('createTask')
            ->with(
                1,
                2,
                'test-uuid',
                'test-uuid', // Falls back to UUID when name is null
                $this->anything()
            )
            ->willReturn(['id' => 'new-task', 'summary' => 'Test task']);

        $result = $this->controller->create('reg', 'schema', 'id');

        $this->assertEquals(201, $result->getStatus());
    }
}
