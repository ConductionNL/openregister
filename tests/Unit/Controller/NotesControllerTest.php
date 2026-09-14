<?php

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- PHPUnit fixture properties are named by their type.
// phpcs:disable Squiz.PHP.DisallowInlineIf.Found -- PHPUnit fixture defaults.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Controller\NotesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotesController
 *
 * @package Unit\Controller
 */
class NotesControllerTest extends TestCase {
	private NotesController $controller;
	private IRequest&MockObject $request;
	private NoteService&MockObject $noteService;
	private ObjectService&MockObject $objectService;
	private TimelineVisibilityService&MockObject $visibility;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->noteService = $this->createMock(NoteService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->visibility = $this->createMock(TimelineVisibilityService::class);
		$this->visibility->method('isKnownValue')->willReturnCallback(
			static fn (?string $value): bool => in_array($value, ['internal', 'public'], true)
		);
		$this->visibility->method('normalise')->willReturnCallback(
			static fn (?string $value): string => ($value === 'public' ? 'public' : 'internal')
		);

		$this->controller = new NotesController(
			'openregister',
			$this->request,
			$this->noteService,
			$this->objectService,
			$this->visibility
		);
	}

	private function createRealObjectEntity(): ObjectEntity {
		$object = new ObjectEntity();
		$ref = new \ReflectionClass($object);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($object, 1);
		$object->setUuid('uuid-123');
		return $object;
	}

	public function testIndexReturnsNotesForObject(): void {
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

	public function testIndexReturns404WhenObjectNotFound(): void {
		$this->objectService->method('getObject')->willReturn(null);

		$result = $this->controller->index('reg', 'schema', 'nonexistent');

		$this->assertSame(404, $result->getStatus());
	}

	public function testIndexReturns404OnDoesNotExistException(): void {
		$this->objectService->method('getObject')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->index('reg', 'schema', 'obj-id');

		$this->assertSame(404, $result->getStatus());
	}

	public function testIndexReturns500OnException(): void {
		$this->objectService->method('getObject')
			->willThrowException(new Exception('DB error'));

		$result = $this->controller->index('reg', 'schema', 'obj-id');

		$this->assertSame(500, $result->getStatus());
	}

	public function testCreateReturnsCreatedNote(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);

		$note = ['id' => 1, 'message' => 'New note'];
		$this->noteService->method('createNote')->willReturn($note);
		$this->request->method('getParams')->willReturn(['message' => 'New note']);

		$result = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(201, $result->getStatus());
	}

	public function testCreateReturns404WhenObjectNotFound(): void {
		$this->objectService->method('getObject')->willReturn(null);
		$this->request->method('getParams')->willReturn(['message' => 'test']);

		$result = $this->controller->create('reg', 'schema', 'nonexistent');

		$this->assertSame(404, $result->getStatus());
	}

	public function testCreateReturns400WhenMessageEmpty(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['message' => '']);

		$result = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(400, $result->getStatus());
	}

	public function testCreateReturns400OnException(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['message' => 'test']);
		$this->noteService->method('createNote')
			->willThrowException(new Exception('Failed'));

		$result = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(400, $result->getStatus());
	}

	public function testDestroyReturnsSuccess(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->noteService->expects($this->once())->method('deleteNote');

		$result = $this->controller->destroy('reg', 'schema', 'obj-id', '1');

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
	}

	public function testDestroyReturns404WhenObjectNotFound(): void {
		$this->objectService->method('getObject')->willReturn(null);

		$result = $this->controller->destroy('reg', 'schema', 'nonexistent', '1');

		$this->assertSame(404, $result->getStatus());
	}

	public function testDestroyReturns400OnException(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->noteService->method('deleteNote')
			->willThrowException(new Exception('Delete failed'));

		$result = $this->controller->destroy('reg', 'schema', 'obj-id', '1');

		$this->assertSame(400, $result->getStatus());
	}

	public function testIndexServesAReaderThePublicViewItDidNotAskFor(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn([]);
		$this->visibility->method('mayManage')->willReturn(false);
		$this->visibility->method('effectiveFilter')->willReturn('public');

		$this->noteService->expects($this->once())
			->method('getNotesForObject')
			->with('uuid-123', 50, 0, 'public')
			->willReturn([['id' => 2, 'message' => 'Public', 'visibility' => 'public']]);

		$result = $this->controller->index('reg', 'schema', 'obj-id');

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame('public', $data['visibility']);
		$this->assertFalse($data['canSetVisibility']);
		$this->assertCount(1, $data['results']);
	}

	public function testIndexLeavesAHandlerUnfiltered(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn([]);
		$this->visibility->method('mayManage')->willReturn(true);
		$this->visibility->method('effectiveFilter')->willReturn(null);

		$this->noteService->expects($this->once())
			->method('getNotesForObject')
			->with('uuid-123', 50, 0, null)
			->willReturn([['id' => 1], ['id' => 2]]);

		$result = $this->controller->index('reg', 'schema', 'obj-id');

		$data = $result->getData();
		$this->assertNull($data['visibility']);
		$this->assertTrue($data['canSetVisibility']);
		$this->assertCount(2, $data['results']);
	}

	public function testCreateRefusesAReaderThatSetsTheFlag(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['message' => 'Hello', 'visibility' => 'public']);
		$this->visibility->method('mayManage')->willReturn(false);
		$this->noteService->expects($this->never())->method('createNote');

		$result = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(403, $result->getStatus());
	}

	public function testCreateRefusesAValueOutsideTheVocabulary(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['message' => 'Hello', 'visibility' => 'everyone']);
		$this->noteService->expects($this->never())->method('createNote');

		$result = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(400, $result->getStatus());
	}

	public function testCreatePassesTheFlagOnForAHandler(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['message' => 'Hello', 'visibility' => 'public']);
		$this->visibility->method('mayManage')->willReturn(true);

		$this->noteService->expects($this->once())
			->method('createNote')
			->with('uuid-123', 'Hello', 'public')
			->willReturn(['id' => 1, 'message' => 'Hello', 'visibility' => 'public']);

		$result = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(201, $result->getStatus());
	}

	public function testUpdateRefusesAReaderThatFlipsTheFlag(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['visibility' => 'public']);
		$this->visibility->method('mayManage')->willReturn(false);
		$this->noteService->expects($this->never())->method('updateNote');

		$result = $this->controller->update('reg', 'schema', 'obj-id', '5');

		$this->assertSame(403, $result->getStatus());
	}

	public function testUpdateAuditsTheMove(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn(['visibility' => 'public']);
		$this->visibility->method('mayManage')->willReturn(true);

		$this->noteService->method('getNote')->willReturn(['id' => 5, 'visibility' => 'internal']);
		$this->noteService->expects($this->once())
			->method('updateNote')
			->with(5, null, 'public')
			->willReturn(['id' => 5, 'visibility' => 'public']);

		$this->visibility->expects($this->once())
			->method('auditVisibilityChange')
			->with($object, 5, 'internal', 'public')
			->willReturn(true);

		$result = $this->controller->update('reg', 'schema', 'obj-id', '5');

		$this->assertSame(200, $result->getStatus());
	}

	public function testUpdateWithNeitherMessageNorFlagIsRefused(): void {
		$object = $this->createRealObjectEntity();
		$this->objectService->method('getObject')->willReturn($object);
		$this->request->method('getParams')->willReturn([]);

		$result = $this->controller->update('reg', 'schema', 'obj-id', '5');

		$this->assertSame(400, $result->getStatus());
	}
}
