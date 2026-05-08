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
     * Test bulk download logging persists a SINGLE audit row for the ZIP,
     * not one row per file inside the archive.
     */
    public function testLogBulkDownload(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $user->method('getDisplayName')->willReturn('Admin User');
        $this->userSession->method('getUser')->willReturn($user);

        $object = new ObjectEntity();
        $object->setId(123);
        $object->setUuid('abc-123');
        $object->setRegister('reg-1');
        $object->setSchema('sch-1');

        $captured = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(
                function ($auditTrail) use (&$captured) {
                    $captured = $auditTrail;
                    return $auditTrail instanceof AuditTrail;
                }
            ))
            ->willReturnArgument(0);

        $result = $this->handler->logBulkDownload(
            $object,
            [42, 43, 44],
            ['file1.pdf', 'file2.pdf', 'file3.pdf'],
            'object_abc-123_files.zip',
            512000
        );

        $this->assertInstanceOf(AuditTrail::class, $result);
        $this->assertSame('file.bulk_downloaded', $captured->getAction());
        $changed = $captured->getChanged();
        $this->assertSame([42, 43, 44], $changed['fileIds']);
        $this->assertSame(['file1.pdf', 'file2.pdf', 'file3.pdf'], $changed['fileNames']);
        $this->assertSame(3, $changed['fileCount']);
        $this->assertSame('object_abc-123_files.zip', $changed['zipName']);
        $this->assertSame(512000, $changed['totalBytes']);
        $this->assertSame('admin', $captured->getUser());
    }//end testLogBulkDownload()

    /**
     * Test bulk download by an anonymous caller still produces a single
     * audit row, with the user attributed to the remote IP for traceability.
     */
    public function testLogBulkDownloadAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->request->method('getRemoteAddress')->willReturn('192.168.1.50');

        $object = new ObjectEntity();
        $object->setId(123);
        $object->setUuid('abc-123');
        $object->setRegister('reg-1');
        $object->setSchema('sch-1');

        $captured = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(
                function ($auditTrail) use (&$captured) {
                    $captured = $auditTrail;
                    return true;
                }
            ))
            ->willReturnArgument(0);

        $this->handler->logBulkDownload(
            $object,
            [42],
            ['only.pdf'],
            'one.zip'
        );

        $this->assertSame('Anonymous', $captured->getUser());
        $this->assertStringContainsString('192.168.1.50', $captured->getUserName());
    }//end testLogBulkDownloadAnonymous()

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
