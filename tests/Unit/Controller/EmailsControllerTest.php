<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\EmailsController;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EmailsController.
 */
class EmailsControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private EmailService&MockObject $emailService;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private EmailsController $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new EmailsController(
            'openregister',
            $this->request,
            $this->emailService,
            $this->objectService,
            $this->userSession,
            $this->logger
        );
    }

    private function setupObjectService(string $uuid = 'abc-123'): ObjectEntity
    {
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

    public function testIndexReturnsEmailLinks(): void
    {
        $this->setupObjectService();
        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);

        $expected = ['results' => [['linkId' => 1]], 'total' => 1];
        $this->emailService->method('getEmailsForObject')
            ->with('abc-123', null, null)
            ->willReturn($expected);

        $response = $this->controller->index('reg', 'sch', 'abc-123');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($expected, $response->getData());
    }

    public function testIndexReturns501WhenMailNotAvailable(): void
    {
        $this->emailService->method('isMailAvailable')->willReturn(false);

        $response = $this->controller->index('reg', 'sch', 'abc-123');
        $this->assertSame(501, $response->getStatus());
        $this->assertSame('APP_NOT_AVAILABLE', $response->getData()['code']);
    }

    public function testIndexReturns404WhenObjectNotFound(): void
    {
        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setObject')->willReturnSelf();
        $this->objectService->method('getObject')->willReturn(null);

        $response = $this->controller->index('reg', 'sch', 'nonexistent');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    // ── create() ──

    public function testCreateReturns201OnSuccess(): void
    {
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

    public function testCreateReturnsBadRequestWhenMissingFields(): void
    {
        $this->setupObjectService();
        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->create('reg', 'sch', 'abc-123');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    // ── destroy() ──

    public function testDestroyReturnsSuccess(): void
    {
        $this->setupObjectService();
        $this->emailService->method('isMailAvailable')->willReturn(true);

        $response = $this->controller->destroy('reg', 'sch', 'abc-123', '7');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['success']);
    }

    // ── search() ──

    public function testSearchReturnsBadRequestWithoutSender(): void
    {
        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->search();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testSearchReturnsResults(): void
    {
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

    public function testBySenderReturnsDiscoveredObjects(): void
    {
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

    public function testBySenderReturnsBadRequestWithoutSender(): void
    {
        $this->request->method('getParam')
            ->with('sender')
            ->willReturn(null);

        $response = $this->controller->bySender();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('The sender parameter is required', $response->getData()['error']);
    }

    public function testBySenderReturns500OnException(): void
    {
        $this->request->method('getParam')
            ->with('sender')
            ->willReturn('test@example.nl');

        $this->emailService->method('searchBySender')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->bySender();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }
}
