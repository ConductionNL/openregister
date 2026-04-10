<?php

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Listener\ObjectCleanupListener;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TaskService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectCleanupListenerTest extends TestCase
{
    private NoteService&MockObject $noteService;
    private TaskService&MockObject $taskService;
    private EmailService&MockObject $emailService;
    private CalendarEventService&MockObject $calendarEventService;
    private ContactService&MockObject $contactService;
    private DeckCardService&MockObject $deckCardService;
    private LoggerInterface&MockObject $logger;
    private ObjectCleanupListener $listener;

    protected function setUp(): void
    {
        $this->noteService = $this->createMock(NoteService::class);
        $this->taskService = $this->createMock(TaskService::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->calendarEventService = $this->createMock(CalendarEventService::class);
        $this->contactService = $this->createMock(ContactService::class);
        $this->deckCardService = $this->createMock(DeckCardService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new ObjectCleanupListener(
            $this->noteService,
            $this->taskService,
            $this->emailService,
            $this->calendarEventService,
            $this->contactService,
            $this->deckCardService,
            $this->logger
        );
    }

    private function createDeleteEvent(string $uuid = 'abc-123'): ObjectDeletedEvent
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('getUuid')->willReturn($uuid);
        return new ObjectDeletedEvent($object);
    }

    public function testHandleCallsAllCleanupMethods(): void
    {
        $event = $this->createDeleteEvent();

        $this->noteService->expects($this->once())->method('deleteNotesForObject')->with('abc-123');
        $this->taskService->expects($this->once())->method('getTasksForObject')->with('abc-123')->willReturn([]);
        $this->emailService->expects($this->once())->method('deleteLinksForObject')->with('abc-123');
        $this->calendarEventService->expects($this->once())->method('unlinkEventsForObject')->with('abc-123');
        $this->contactService->expects($this->once())->method('deleteLinksForObject')->with('abc-123');
        $this->deckCardService->expects($this->once())->method('deleteLinksForObject')->with('abc-123');

        $this->listener->handle($event);
    }

    public function testHandleIgnoresNonObjectDeletedEvents(): void
    {
        $event = $this->createMock(Event::class);

        $this->noteService->expects($this->never())->method('deleteNotesForObject');

        $this->listener->handle($event);
    }

    public function testHandleContinuesWhenOneCleanupFails(): void
    {
        $event = $this->createDeleteEvent();

        // Email cleanup throws.
        $this->emailService->method('deleteLinksForObject')
            ->willThrowException(new \Exception('DB error'));

        // Other services should still be called.
        $this->noteService->expects($this->once())->method('deleteNotesForObject');
        $this->taskService->expects($this->once())->method('getTasksForObject')->willReturn([]);
        $this->calendarEventService->expects($this->once())->method('unlinkEventsForObject');
        $this->contactService->expects($this->once())->method('deleteLinksForObject');
        $this->deckCardService->expects($this->once())->method('deleteLinksForObject');

        // Logger should log the warning.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->listener->handle($event);
    }
}
