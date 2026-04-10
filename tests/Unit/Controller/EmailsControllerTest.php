<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\EmailsController;
use OCA\OpenRegister\Service\EmailService;
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
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private EmailsController $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new EmailsController(
            'openregister',
            $this->request,
            $this->emailService,
            $this->userSession,
            $this->logger
        );
    }

    public function testByMessageReturnsLinkedObjects(): void
    {
        $expected = [
            'results' => [
                ['linkId' => 1, 'objectUuid' => 'abc-123', 'registerTitle' => 'Vergunningen'],
            ],
            'total' => 1,
        ];

        $this->emailService->expects($this->once())
            ->method('findByMessageId')
            ->with(1, 42)
            ->willReturn($expected);

        $response = $this->controller->byMessage(1, 42);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($expected, $response->getData());
    }

    public function testByMessageReturnsBadRequestForInvalidIds(): void
    {
        $response = $this->controller->byMessage(0, 42);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Invalid account ID or message ID', $response->getData()['error']);
    }

    public function testByMessageReturnsBadRequestForNegativeId(): void
    {
        $response = $this->controller->byMessage(-1, 42);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testBySenderReturnsDiscoveredObjects(): void
    {
        $expected = [
            'results' => [
                ['objectUuid' => 'abc-123', 'linkedEmailCount' => 2],
            ],
            'total' => 1,
        ];

        $this->request->method('getParam')
            ->with('sender')
            ->willReturn('burger@test.local');

        $this->emailService->expects($this->once())
            ->method('findObjectsBySender')
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

    public function testBySenderReturnsBadRequestForInvalidEmail(): void
    {
        $this->request->method('getParam')
            ->with('sender')
            ->willReturn('not-an-email');

        $response = $this->controller->bySender();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Invalid email address format', $response->getData()['error']);
    }

    public function testQuickLinkCreatesLink(): void
    {
        $params = [
            'mailAccountId' => 1,
            'mailMessageId' => 42,
            'objectUuid' => 'abc-123',
            'registerId' => 1,
        ];

        $this->request->method('getParams')
            ->willReturn($params);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->emailService->expects($this->once())
            ->method('quickLink')
            ->willReturn(['linkId' => 1, 'objectUuid' => 'abc-123']);

        $response = $this->controller->quickLink();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    public function testQuickLinkReturnsBadRequestForMissingField(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'mailAccountId' => 1,
                'mailMessageId' => 42,
                // missing objectUuid and registerId
            ]);

        $response = $this->controller->quickLink();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testQuickLinkReturnsConflictOnDuplicate(): void
    {
        $params = [
            'mailAccountId' => 1,
            'mailMessageId' => 42,
            'objectUuid' => 'abc-123',
            'registerId' => 1,
        ];

        $this->request->method('getParams')
            ->willReturn($params);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->emailService->method('quickLink')
            ->willThrowException(new \RuntimeException('Email already linked to this object', 409));

        $response = $this->controller->quickLink();

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
    }

    public function testDeleteLinkSuccess(): void
    {
        $this->emailService->expects($this->once())
            ->method('deleteLink')
            ->with(7);

        $response = $this->controller->deleteLink(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('deleted', $response->getData()['status']);
    }

    public function testDeleteLinkReturnsNotFound(): void
    {
        $this->emailService->method('deleteLink')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

        $response = $this->controller->deleteLink(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testDeleteLinkReturnsBadRequestForInvalidId(): void
    {
        $response = $this->controller->deleteLink(0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }
}
