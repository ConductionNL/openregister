<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\ObjectCleanupListener;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TaskService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectCleanupListenerTest extends TestCase
{
    private ObjectCleanupListener $listener;
    private NoteService&MockObject $noteService;
    private TaskService&MockObject $taskService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->noteService = $this->createMock(NoteService::class);
        $this->taskService = $this->createMock(TaskService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new ObjectCleanupListener(
            $this->noteService,
            $this->taskService,
            $this->logger,
        );
    }

    public function testEarlyReturnForNonObjectDeletedEvent(): void
    {
        $event = $this->createMock(Event::class);
        $this->noteService->expects($this->never())->method('deleteNotesForObject');
        $this->listener->handle($event);
    }

    public function testDeletesNotesForObject(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid-123');
        $event = new ObjectDeletedEvent($object);

        $this->noteService->expects($this->once())
            ->method('deleteNotesForObject')
            ->with('test-uuid-123');

        $this->taskService->expects($this->once())
            ->method('getTasksForObject')
            ->with('test-uuid-123')
            ->willReturn([]);

        $this->listener->handle($event);
    }

    public function testDeletesTasksForObject(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid-456');
        $event = new ObjectDeletedEvent($object);

        $this->noteService->method('deleteNotesForObject');

        $tasks = [
            ['calendarId' => '1', 'id' => 'task-1'],
            ['calendarId' => '2', 'id' => 'task-2'],
        ];
        $this->taskService->expects($this->once())
            ->method('getTasksForObject')
            ->willReturn($tasks);

        $this->taskService->expects($this->exactly(2))
            ->method('deleteTask');

        $this->listener->handle($event);
    }

    public function testNoteServiceExceptionLogsWarning(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid');
        $event = new ObjectDeletedEvent($object);

        $this->noteService->method('deleteNotesForObject')
            ->willThrowException(new \Exception('Note DB error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should still try to clean tasks
        $this->taskService->method('getTasksForObject')->willReturn([]);

        $this->listener->handle($event);
    }

    public function testTaskServiceExceptionLogsWarning(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid');
        $event = new ObjectDeletedEvent($object);

        $this->noteService->method('deleteNotesForObject');

        $this->taskService->method('getTasksForObject')
            ->willThrowException(new \Exception('Task DB error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $this->listener->handle($event);
    }

    public function testIndividualTaskDeleteFailureLogsWarning(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid');
        $event = new ObjectDeletedEvent($object);

        $this->noteService->method('deleteNotesForObject');

        $this->taskService->method('getTasksForObject')
            ->willReturn([['calendarId' => '1', 'id' => 'task-fail']]);

        $this->taskService->method('deleteTask')
            ->willThrowException(new \Exception('Cannot delete task'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $this->listener->handle($event);
    }
}
