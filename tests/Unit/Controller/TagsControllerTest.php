<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TagsController;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TagsControllerTest extends TestCase {

	private TagsController $controller;

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	private FileService&MockObject $fileService;

	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->fileService = $this->createMock(FileService::class);

		// getAllTags() requires an authenticated session (anonymous-deny guard,
		// see openregister#194) — mock a logged-in user so these tests exercise
		// the 200 success path rather than the 401 guard.
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->controller = new TagsController(
			'openregister',
			$this->request,
			$this->objectService,
			$this->fileService,
			$this->createMock(TaggingHandler::class),
			$this->userSession
		);
	}//end setUp()

	public function testGetAllTagsReturnsJsonResponse(): void {
		$tags = ['tag1', 'tag2', 'tag3'];

		$this->fileService
			->expects($this->once())
			->method('getAllTags')
			->willReturn($tags);

		$result = $this->controller->getAllTags();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals($tags, $result->getData());
		$this->assertEquals(200, $result->getStatus());
	}//end testGetAllTagsReturnsJsonResponse()

	public function testGetAllTagsReturnsEmptyArray(): void {
		$this->fileService
			->expects($this->once())
			->method('getAllTags')
			->willReturn([]);

		$result = $this->controller->getAllTags();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals([], $result->getData());
		$this->assertEquals(200, $result->getStatus());
	}//end testGetAllTagsReturnsEmptyArray()

	public function testGetAllTagsRejectsAnonymousSession(): void {
		// No user on the session (anonymous caller) — the anonymous-deny
		// guard (openregister#194) must reject with 401 before FileService
		// is ever consulted.
		$anonymousSession = $this->createMock(IUserSession::class);
		$anonymousSession->method('getUser')->willReturn(null);

		$controller = new TagsController(
			'openregister',
			$this->request,
			$this->objectService,
			$this->fileService,
			$this->createMock(TaggingHandler::class),
			$anonymousSession
		);

		$this->fileService->expects($this->never())->method('getAllTags');

		$result = $controller->getAllTags();

		$this->assertEquals(401, $result->getStatus());
		$this->assertEquals(['error' => 'Authentication is required'], $result->getData());
	}//end testGetAllTagsRejectsAnonymousSession()
}//end class
