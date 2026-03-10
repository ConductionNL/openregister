<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\NotesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotesController
 *
 * @package Unit\Controller
 */
class NotesControllerTest extends TestCase
{
    private NotesController $controller;
    private IRequest&MockObject $request;
    private NoteService&MockObject $noteService;
    private ObjectService&MockObject $objectService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->noteService = $this->createMock(NoteService::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->controller = new NotesController(
            'openregister',
            $this->request,
            $this->noteService,
            $this->objectService
        );
    }

    private function createRealObjectEntity(): ObjectEntity
    {
        $object = new ObjectEntity();
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($object, 1);
        $object->setUuid('uuid-123');
        return $object;
    }

    public function testIndexReturnsNotesForObject(): void
    {
        $object = $this->createRealObjectEntity();
        $this->objectService->method('getObject')->willReturn($object);

        $notes = [['id' => 1, 'message' => 'Note 1']];
        $this->noteService->method('getNotesForObject')->willReturn($notes);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->index('reg', 'schema', 'obj-id');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertSame($notes, $data['results']);
    }

    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('getObject')->willReturn(null);

        $result = $this->controller->index('reg', 'schema', 'nonexistent');

        $this->assertSame(404, $result->getStatus());
    }

    public function testIndexReturns404OnDoesNotExistException(): void
    {
        $this->objectService->method('getObject')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->index('reg', 'schema', 'obj-id');

        $this->assertSame(404, $result->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->objectService->method('getObject')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->index('reg', 'schema', 'obj-id');

        $this->assertSame(500, $result->getStatus());
    }

    public function testCreateReturnsCreatedNote(): void
    {
        $object = $this->createRealObjectEntity();
        $this->objectService->method('getObject')->willReturn($object);

        $note = ['id' => 1, 'message' => 'New note'];
        $this->noteService->method('createNote')->willReturn($note);
        $this->request->method('getParams')->willReturn(['message' => 'New note']);

        $result = $this->controller->create('reg', 'schema', 'obj-id');

        $this->assertSame(201, $result->getStatus());
    }

    public function testCreateReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('getObject')->willReturn(null);
        $this->request->method('getParams')->willReturn(['message' => 'test']);

        $result = $this->controller->create('reg', 'schema', 'nonexistent');

        $this->assertSame(404, $result->getStatus());
    }

    public function testCreateReturns400WhenMessageEmpty(): void
    {
        $object = $this->createRealObjectEntity();
        $this->objectService->method('getObject')->willReturn($object);
        $this->request->method('getParams')->willReturn(['message' => '']);

        $result = $this->controller->create('reg', 'schema', 'obj-id');

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns400OnException(): void
    {
        $object = $this->createRealObjectEntity();
        $this->objectService->method('getObject')->willReturn($object);
        $this->request->method('getParams')->willReturn(['message' => 'test']);
        $this->noteService->method('createNote')
            ->willThrowException(new Exception('Failed'));

        $result = $this->controller->create('reg', 'schema', 'obj-id');

        $this->assertSame(400, $result->getStatus());
    }

    public function testDestroyReturnsSuccess(): void
    {
        $object = $this->createRealObjectEntity();
        $this->objectService->method('getObject')->willReturn($object);
        $this->noteService->expects($this->once())->method('deleteNote');

        $result = $this->controller->destroy('reg', 'schema', 'obj-id', '1');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDestroyReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('getObject')->willReturn(null);

        $result = $this->controller->destroy('reg', 'schema', 'nonexistent', '1');

        $this->assertSame(404, $result->getStatus());
    }

    public function testDestroyReturns400OnException(): void
    {
        $object = $this->createRealObjectEntity();
        $this->objectService->method('getObject')->willReturn($object);
        $this->noteService->method('deleteNote')
            ->willThrowException(new Exception('Delete failed'));

        $result = $this->controller->destroy('reg', 'schema', 'obj-id', '1');

        $this->assertSame(400, $result->getStatus());
    }
}
