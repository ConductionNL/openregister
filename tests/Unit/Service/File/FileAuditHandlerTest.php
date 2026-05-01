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
    }//end setUp()

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
    }//end testLogDownloadAuthenticated()

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
    }//end testLogDownloadAnonymous()

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
    }//end testLogBulkDownload()

    /**
     * Test download logging does not throw even if internal error.
     */
    public function testLogDownloadDoesNotThrow(): void
    {
        $this->userSession->method('getUser')->willThrowException(new \Exception('Session error'));

        // Should not propagate exception.
        $this->handler->logDownload(42, 'test.pdf', 1024, 'application/pdf', 'abc-123');
        $this->assertTrue(true);
    }//end testLogDownloadDoesNotThrow()

    /**
     * Test that logFileAction persists an AuditTrail row tagged to the
     * parent ObjectEntity with the namespaced file action.
     */
    public function testLogFileActionPersistsAuditTrail(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('behandelaar-1');
        $user->method('getDisplayName')->willReturn('Behandelaar 1');
        $this->userSession->method('getUser')->willReturn($user);

        $object = new ObjectEntity();
        $object->setId(7);
        $object->setUuid('obj-abc-123');
        $object->setRegister(3);
        $object->setSchema(11);

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->with(
                    $this->callback(
                    function (AuditTrail $entry) {
                        if ($entry->getAction() !== 'file.renamed') {
                            return false;
                        }

                        if ($entry->getObject() !== 7) {
                            return false;
                        }

                        if ($entry->getObjectUuid() !== 'obj-abc-123') {
                            return false;
                        }

                        if ($entry->getRegister() !== 3 || $entry->getSchema() !== 11) {
                            return false;
                        }

                        if ($entry->getUser() !== 'behandelaar-1') {
                            return false;
                        }

                        $changed = $entry->getChanged();
                        if (($changed['fileId'] ?? null) !== 42) {
                            return false;
                        }

                        return ($changed['data']['newName'] ?? null) === 'new.pdf';
                    }
                    )
                    )
            ->willReturnArgument(0);

        $result = $this->handler->logFileAction(
            $object,
            42,
            'file.renamed',
            ['oldName' => 'old.pdf', 'newName' => 'new.pdf']
        );

        $this->assertInstanceOf(AuditTrail::class, $result);
    }//end testLogFileActionPersistsAuditTrail()

    /**
     * Test that logFileAction swallows insert failures and returns null
     * (audit logging must never break the underlying file operation).
     */
    public function testLogFileActionDoesNotThrowOnInsertFailure(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $object = new ObjectEntity();
        $object->setId(7);
        $object->setUuid('obj-abc-123');

        $this->auditTrailMapper
            ->method('insert')
            ->willThrowException(new \Exception('DB unreachable'));

        $this->logger->expects($this->once())->method('warning');

        $result = $this->handler->logFileAction($object, 42, 'file.locked', []);

        $this->assertNull($result);
    }//end testLogFileActionDoesNotThrowOnInsertFailure()

    /**
     * Test that logFileAction falls back to 'System' when no user is
     * authenticated (e.g. background job).
     */
    public function testLogFileActionFallsBackToSystemUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $object = new ObjectEntity();
        $object->setId(7);
        $object->setUuid('obj-abc-123');

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->with(
                    $this->callback(
                    function (AuditTrail $entry) {
                        return $entry->getUser() === 'System' && $entry->getUserName() === 'System';
                    }
                    )
                    )
            ->willReturnArgument(0);

        $this->handler->logFileAction($object, 42, 'file.unlocked', ['force' => false]);
    }//end testLogFileActionFallsBackToSystemUser()
}//end class
