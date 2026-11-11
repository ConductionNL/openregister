<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Notifications\Controller\EmailsController;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsControllerServices;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsDomain;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsObjectAccess;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsPlatform;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Unit tests for EmailsController.
 */
class EmailsControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private EmailService&MockObject $emailService;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private EmailsController $controller;
	private EmailLinkService&MockObject $emailLinkService;
	private bool $mayUpdate = true;

	/** @var array<int, string> The actions the permission handler was asked about. */
	private array $askedActions = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->emailService = $this->createMock(EmailService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->emailLinkService = $this->createMock(EmailLinkService::class);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action): bool {
				$this->askedActions[] = $action;
				return $action === 'update' ? $this->mayUpdate : true;
			}
		);

		$this->controller = new EmailsController(
			$this->request,
			new EmailsControllerServices(
				new EmailsDomain($this->emailService, $this->emailLinkService),
				new EmailsObjectAccess($this->objectService, $permissions),
				new EmailsPlatform($this->userSession, $this->logger)
			)
		);
	}

	private function setupObjectService(string $uuid = 'abc-123'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister(1);
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($object);
		return $object;
	}

	// ── index() ──

	public function testIndexReturnsEmailLinks(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->request->method('getParams')->willReturn([]);

		$expected = ['results' => [['linkId' => 1]], 'total' => 1];
		$this->emailService->method('getEmailsForObject')
			->with('abc-123', new ListQuery(limit: ListQuery::UNBOUNDED))
			->willReturn($expected);

		$response = $this->controller->index('reg', 'sch', 'abc-123');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($expected, $response->getData());
	}

	public function testIndexReturns501WhenMailNotAvailable(): void {
		$this->emailService->method('isMailAvailable')->willReturn(false);

		$response = $this->controller->index('reg', 'sch', 'abc-123');
		$this->assertSame(501, $response->getStatus());
		$this->assertSame('APP_NOT_AVAILABLE', $response->getData()['code']);
	}

	public function testIndexReturns404WhenObjectNotFound(): void {
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn(null);

		$response = $this->controller->index('reg', 'sch', 'nonexistent');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── create() ──

	public function testCreateReturns201OnSuccess(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->request->method('getParams')->willReturn([
			'mailAccountId' => 1,
			'mailMessageId' => 42,
		]);

		$emailLink = new EmailLink();
		$this->emailService->method('linkEmail')->willReturn($emailLink);

		$response = $this->controller->create('reg', 'sch', 'abc-123');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateReturnsBadRequestWhenMissingFields(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller->create('reg', 'sch', 'abc-123');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroy() ──

	public function testDestroyReturnsSuccess(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);

		$this->emailLinkService->expects($this->once())->method('unlinkEmail')->with('abc-123', 7);

		$response = $this->controller->destroy('reg', 'sch', 'abc-123', '7');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}

	public function testCreateRefusesAnAccountOfSomebodyElse(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->request->method('getParams')->willReturn(['mailAccountId' => 9, 'mailMessageId' => 2]);
		$this->emailLinkService->expects($this->once())->method('assertAccountOwnership')->with(9)
			->willThrowException(new \Exception('Mail account not accessible to this user', 403));
		$this->emailService->expects($this->never())->method('linkEmail');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->create('reg', 'sch', 'abc-123')->getStatus());
	}

	public function testCreateNeedsTheObjectUpdateRight(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->request->method('getParams')->willReturn(['mailAccountId' => 1, 'mailMessageId' => 2]);
		$this->emailService->expects($this->never())->method('linkEmail');
		$this->mayUpdate = false;

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->create('reg', 'sch', 'abc-123')->getStatus());
		$this->assertSame(['update'], $this->askedActions, 'the right asked is update, not read');
	}

	public function testDestroyNeedsTheObjectUpdateRight(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->emailLinkService->expects($this->never())->method('unlinkEmail');
		$this->mayUpdate = false;

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->destroy('reg', 'sch', 'abc-123', '7')->getStatus());
		$this->assertSame(['update'], $this->askedActions, 'the right asked is update, not read');
	}

	public function testDestroyOfAnotherObjectsLinkIsNotFound(): void {
		$this->setupObjectService();
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->emailService->expects($this->never())->method('unlinkEmail');
		$this->emailLinkService->expects($this->once())
			->method('unlinkEmail')
			->with('abc-123', 99)
			->willThrowException(new \Exception('Email link not found', 404));

		$response = $this->controller->destroy('reg', 'sch', 'abc-123', '99');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Email link not found'], $response->getData());
	}

	public function testDestroyOnAnObjectWithoutAUuidRemovesNothing(): void {
		$object = new ObjectEntity();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($object);
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->emailService->expects($this->never())->method('unlinkEmail');
		$this->emailLinkService->expects($this->never())->method('unlinkEmail');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->destroy('reg', 'sch', 'abc-123', '7')->getStatus());
	}

	// ── search() ──

	public function testSearchReturnsBadRequestWithoutSender(): void {
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller->search();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSearchReturnsResults(): void {
		$this->emailService->method('isMailAvailable')->willReturn(true);
		$this->request->method('getParams')->willReturn(['sender' => 'test@example.nl']);
		$this->emailService->method('searchBySender')
			->with('test@example.nl')
			->willReturn([['id' => 1]]);

		$response = $this->controller->search();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}

	// ── bySender() ──

	public function testBySenderReturnsDiscoveredObjects(): void {
		$expected = ['objectUuid' => 'abc-123'];

		$this->request->method('getParam')
			->with('sender')
			->willReturn('burger@test.local');

		$this->emailService->expects($this->once())
			->method('searchBySender')
			->with('burger@test.local')
			->willReturn($expected);

		$response = $this->controller->bySender();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($expected, $response->getData());
	}

	public function testBySenderReturnsBadRequestWithoutSender(): void {
		$this->request->method('getParam')
			->with('sender')
			->willReturn(null);

		$response = $this->controller->bySender();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('The sender parameter is required', $response->getData()['error']);
	}

	public function testBySenderReturns500OnException(): void {
		$this->request->method('getParam')
			->with('sender')
			->willReturn('test@example.nl');

		$this->emailService->method('searchBySender')
			->willThrowException(new \Exception('DB error'));

		$response = $this->controller->bySender();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testResolveHandsTheTwoHeadersToTheServiceAndAnswersWhatItSays(): void {
		$threads = $this->createMock(\OCA\OpenRegister\Notifications\Service\ReplyThreadService::class);
		$threads->expects($this->once())->method('resolve')
			->with(['In-Reply-To' => '<a@x>', 'References' => ['<b@x>']])
			->willReturn(['state' => 'threaded', 'objectUuid' => 'case-1', 'scope' => 'user']);
		$this->request->method('getParam')->willReturnMap(
			[['inReplyTo', '', '<a@x>'], ['references', '', ['<b@x>']]]
		);
		$controller = new EmailsController(
			$this->request,
			new EmailsControllerServices(
				new EmailsDomain($this->emailService, $this->emailLinkService),
				new EmailsObjectAccess($this->objectService, $this->createMock(PermissionHandler::class)),
				new EmailsPlatform($this->userSession, $this->logger),
				$threads
			)
		);

		$response = $controller->resolve();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('case-1', $response->getData()['objectUuid']);
	}

	public function testResolveWithoutTheServiceIs501(): void {
		$this->assertSame(501, $this->controller->resolve()->getStatus());
	}
}
