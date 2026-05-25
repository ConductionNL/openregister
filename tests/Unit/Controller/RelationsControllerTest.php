<?php

namespace Unit\Controller;

use OCA\OpenRegister\Controller\RelationsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RelationsControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private ObjectService&MockObject $objectService;
    private NoteService&MockObject $noteService;
    private TaskService&MockObject $taskService;
    private EmailService&MockObject $emailService;
    private CalendarEventService&MockObject $calendarEventService;
    private ContactService&MockObject $contactService;
    private DeckCardService&MockObject $deckCardService;
    private RelationsController $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->noteService = $this->createMock(NoteService::class);
        $this->taskService = $this->createMock(TaskService::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->calendarEventService = $this->createMock(CalendarEventService::class);
        $this->contactService = $this->createMock(ContactService::class);
        $this->deckCardService = $this->createMock(DeckCardService::class);

        $this->controller = new RelationsController(
            'openregister',
            $this->request,
            $this->objectService,
            $this->noteService,
            $this->taskService,
            $this->emailService,
            $this->calendarEventService,
            $this->contactService,
            $this->deckCardService
        );
    }

    private function setupObject(string $uuid = 'abc-123'): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $this->objectService->method('getObject')->willReturn($object);
        return $object;
    }

    public function testIndexReturnsAllRelationTypes(): void
    {
        $this->setupObject();
        $this->request->method('getParams')->willReturn([]);

        $this->noteService->method('getNotesForObject')->willReturn([['id' => 1, 'message' => 'Note']]);
        $this->taskService->method('getTasksForObject')->willReturn([]);
        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->emailService->method('getEmailsForObject')->willReturn(['results' => [], 'total' => 0]);
        $this->calendarEventService->method('getEventsForObject')->willReturn([]);
        $this->contactService->method('getContactsForObject')->willReturn(['results' => [], 'total' => 0]);
        $this->deckCardService->method('isDeckAvailable')->willReturn(true);
        $this->deckCardService->method('getCardsForObject')->willReturn(['results' => [], 'total' => 0]);

        $response = $this->controller->index('1', '2', 'abc-123');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('notes', $data);
        $this->assertArrayHasKey('tasks', $data);
        $this->assertArrayHasKey('emails', $data);
        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('contacts', $data);
        $this->assertArrayHasKey('deck', $data);
    }

    public function testIndexOmitsMailWhenNotAvailable(): void
    {
        $this->setupObject();
        $this->request->method('getParams')->willReturn([]);

        $this->noteService->method('getNotesForObject')->willReturn([]);
        $this->taskService->method('getTasksForObject')->willReturn([]);
        $this->emailService->method('isMailAvailable')->willReturn(false);
        $this->calendarEventService->method('getEventsForObject')->willReturn([]);
        $this->contactService->method('getContactsForObject')->willReturn(['results' => [], 'total' => 0]);
        $this->deckCardService->method('isDeckAvailable')->willReturn(false);

        $response = $this->controller->index('1', '2', 'abc-123');

        $data = $response->getData();
        $this->assertArrayNotHasKey('emails', $data);
        $this->assertArrayNotHasKey('deck', $data);
        $this->assertArrayHasKey('notes', $data);
    }

    public function testIndexFiltersTypes(): void
    {
        $this->setupObject();
        $this->request->method('getParams')->willReturn(['types' => 'emails,contacts']);

        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->emailService->method('getEmailsForObject')->willReturn(['results' => [], 'total' => 0]);
        $this->contactService->method('getContactsForObject')->willReturn(['results' => [], 'total' => 0]);

        $response = $this->controller->index('1', '2', 'abc-123');

        $data = $response->getData();
        $this->assertArrayHasKey('emails', $data);
        $this->assertArrayHasKey('contacts', $data);
        $this->assertArrayNotHasKey('notes', $data);
        $this->assertArrayNotHasKey('tasks', $data);
    }

    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('getObject')->willReturn(null);
        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->index('1', '2', 'nonexistent');

        $this->assertSame(404, $response->getStatus());
    }

    /**
     * Regression test for the naive `rtrim($type, 's')` singularisation in
     * buildTimeline(). The previous implementation worked by accident for the
     * current key set but silently mangled any future key whose singular form
     * ends in 's' (e.g. `news`, `address`). The new explicit map guarantees
     * the documented mapping notes→note, tasks→task, emails→email,
     * events→event, contacts→contact, deck→deck.
     *
     * @return void
     */
    public function testTimelineViewSetsSingularTypeForEachItem(): void
    {
        $this->setupObject();
        $this->request->method('getParams')->willReturn(['view' => 'timeline']);

        $this->noteService->method('getNotesForObject')->willReturn([
            ['id' => 1, 'message' => 'Note A', 'createdAt' => '2026-05-24T10:00:00Z'],
        ]);
        $this->taskService->method('getTasksForObject')->willReturn([
            ['id' => 2, 'title' => 'Task A', 'createdAt' => '2026-05-24T11:00:00Z'],
        ]);
        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->emailService->method('getEmailsForObject')->willReturn([
            'results' => [
                ['id' => 3, 'subject' => 'Email A', 'date' => '2026-05-24T12:00:00Z'],
            ],
            'total' => 1,
        ]);
        $this->calendarEventService->method('getEventsForObject')->willReturn([
            ['id' => 4, 'summary' => 'Event A', 'dtstart' => '2026-05-24T13:00:00Z'],
        ]);
        $this->contactService->method('getContactsForObject')->willReturn([
            'results' => [
                ['id' => 5, 'displayName' => 'Contact A', 'linkedAt' => '2026-05-24T14:00:00Z'],
            ],
            'total' => 1,
        ]);
        $this->deckCardService->method('isDeckAvailable')->willReturn(true);
        $this->deckCardService->method('getCardsForObject')->willReturn([
            'results' => [
                ['id' => 6, 'title' => 'Card A', 'createdAt' => '2026-05-24T15:00:00Z'],
            ],
            'total' => 1,
        ]);

        $response = $this->controller->index('1', '2', 'abc-123');
        $timeline = $response->getData();

        $this->assertIsArray($timeline);
        $this->assertCount(6, $timeline);

        // Index timeline items by their original id so order-independent.
        $typeById = [];
        foreach ($timeline as $entry) {
            $typeById[$entry['id']] = $entry['type'];
        }

        $this->assertSame('note', $typeById[1]);
        $this->assertSame('task', $typeById[2]);
        $this->assertSame('email', $typeById[3]);
        $this->assertSame('event', $typeById[4]);
        $this->assertSame('contact', $typeById[5]);
        // Deck stays singular - the old rtrim() left it as 'deck', and the new
        // explicit map continues to do so. This pins that contract.
        $this->assertSame('deck', $typeById[6]);
    }
}
