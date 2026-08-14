<?php

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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
    private ObjectEntity&MockObject $object;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->request          = $this->createMock(IRequest::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->object           = $this->createMock(ObjectEntity::class);
        $this->object->method('getUuid')->willReturn('abc-123');

        $this->handler = new FileAuditHandler(
            $this->auditTrailMapper,
            $this->userSession,
            $this->request,
            $this->logger
        );
    }

    /**
     * Test authenticated download logging persists an audit trail entry.
     */
    public function testLogDownloadAuthenticated(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar-1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->auditTrailMapper->expects($this->once())
            ->method('createAuditTrailEntry')
            ->with(
                $this->object,
                'file.downloaded',
                $this->callback(function (array $data) {
                    return $data['fileId'] === 42
                        && $data['fileName'] === 'rapport.pdf'
                        && $data['fileSize'] === 245760
                        && $data['mimeType'] === 'application/pdf'
                        && array_key_exists('remoteAddress', $data) === false;
                })
            )
            ->willReturn($this->createMock(AuditTrail::class));

        $this->handler->logDownload(
            object: $this->object,
            fileId: 42,
            fileName: 'rapport.pdf',
            fileSize: 245760,
            mimeType: 'application/pdf'
        );
    }

    /**
     * Test anonymous download logging includes IP and user-agent.
     */
    public function testLogDownloadAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getRemoteAddress')->willReturn('192.168.1.1');
        $this->request->method('getHeader')->willReturn('Mozilla/5.0');

        $this->auditTrailMapper->expects($this->once())
            ->method('createAuditTrailEntry')
            ->with(
                $this->object,
                'file.downloaded',
                $this->callback(function (array $data) {
                    return $data['remoteAddress'] === '192.168.1.1'
                        && $data['userAgent'] === 'Mozilla/5.0';
                })
            )
            ->willReturn($this->createMock(AuditTrail::class));

        $this->handler->logDownload(
            object: $this->object,
            fileId: 42,
            fileName: 'rapport.pdf',
            fileSize: 245760,
            mimeType: 'application/pdf'
        );
    }

    /**
     * Test bulk download logging persists a single audit trail entry.
     */
    public function testLogBulkDownload(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->auditTrailMapper->expects($this->once())
            ->method('createAuditTrailEntry')
            ->with(
                $this->object,
                'file.bulk_downloaded',
                $this->callback(function (array $data) {
                    return $data['fileIds'] === [42, 43, 44]
                        && $data['fileNames'] === ['file1.pdf', 'file2.pdf', 'file3.pdf'];
                })
            )
            ->willReturn($this->createMock(AuditTrail::class));

        $this->handler->logBulkDownload(
            object: $this->object,
            fileIds: [42, 43, 44],
            fileNames: ['file1.pdf', 'file2.pdf', 'file3.pdf']
        );
    }

    /**
     * Test download logging does not throw even if the mapper fails internally.
     */
    public function testLogDownloadDoesNotThrow(): void
    {
        $this->userSession->method('getUser')->willThrowException(new \Exception('Session error'));

        $this->logger->expects($this->once())->method('warning');

        // Should not propagate exception.
        $this->handler->logDownload(
            object: $this->object,
            fileId: 42,
            fileName: 'test.pdf',
            fileSize: 1024,
            mimeType: 'application/pdf'
        );
        $this->assertTrue(true);
    }

    /**
     * Test generic file action logging (e.g. rename) persists an audit trail entry.
     */
    public function testLogFileAction(): void
    {
        $this->auditTrailMapper->expects($this->once())
            ->method('createAuditTrailEntry')
            ->with(
                $this->object,
                'file.renamed',
                ['fileId' => 42, 'oldName' => 'scan.pdf', 'newName' => 'besluit.pdf']
            )
            ->willReturn($this->createMock(AuditTrail::class));

        $this->handler->logFileAction(
            object: $this->object,
            action: 'file.renamed',
            data: ['fileId' => 42, 'oldName' => 'scan.pdf', 'newName' => 'besluit.pdf']
        );
    }
}
