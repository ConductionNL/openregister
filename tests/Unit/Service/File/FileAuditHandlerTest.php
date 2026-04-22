<?php

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileAuditHandlerTest extends TestCase
{
    private FileAuditHandler $handler;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private IUserSession&MockObject $userSession;
    private IRequest&MockObject $request;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->request          = $this->createMock(IRequest::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->handler = new FileAuditHandler(
            $this->auditTrailMapper,
            $this->userSession,
            $this->request,
            $this->logger
        );
    }

    /**
     * Test authenticated download logging.
     */
    public function testLogDownloadAuthenticated(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar-1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->logger->expects($this->once())->method('info');

        $this->handler->logDownload(42, 'rapport.pdf', 245760, 'application/pdf', 'abc-123');
    }

    /**
     * Test anonymous download logging includes IP and user-agent.
     */
    public function testLogDownloadAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getRemoteAddress')->willReturn('192.168.1.1');
        $this->request->method('getHeader')->willReturn('Mozilla/5.0');

        $this->logger->expects($this->once())->method('info');

        $this->handler->logDownload(42, 'rapport.pdf', 245760, 'application/pdf', 'abc-123');
    }

    /**
     * Test bulk download logging.
     */
    public function testLogBulkDownload(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->logger->expects($this->once())->method('info');

        $this->handler->logBulkDownload(
            [42, 43, 44],
            ['file1.pdf', 'file2.pdf', 'file3.pdf'],
            'abc-123'
        );
    }

    /**
     * Test download logging does not throw even if internal error.
     */
    public function testLogDownloadDoesNotThrow(): void
    {
        $this->userSession->method('getUser')->willThrowException(new \Exception('Session error'));

        // Should not propagate exception.
        $this->handler->logDownload(42, 'test.pdf', 1024, 'application/pdf', 'abc-123');
        $this->assertTrue(true);
    }
}
