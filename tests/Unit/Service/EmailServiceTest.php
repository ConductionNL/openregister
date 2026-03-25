<?php

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Service\EmailService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmailServiceTest extends TestCase
{
    private EmailLinkMapper&MockObject $emailLinkMapper;
    private IAppManager&MockObject $appManager;
    private IDBConnection&MockObject $db;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private EmailService $service;

    protected function setUp(): void
    {
        $this->emailLinkMapper = $this->createMock(EmailLinkMapper::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new EmailService(
            $this->emailLinkMapper,
            $this->appManager,
            $this->db,
            $this->userSession,
            $this->logger
        );
    }

    private function createUser(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testIsMailAvailableReturnsTrueWhenEnabled(): void
    {
        $this->appManager->method('isEnabledForUser')->with('mail')->willReturn(true);
        $this->assertTrue($this->service->isMailAvailable());
    }

    public function testIsMailAvailableReturnsFalseWhenDisabled(): void
    {
        $this->appManager->method('isEnabledForUser')->with('mail')->willReturn(false);
        $this->assertFalse($this->service->isMailAvailable());
    }

    public function testGetEmailsForObjectReturnsResults(): void
    {
        $link = new EmailLink();
        $link->setObjectUuid('abc-123');
        $link->setSubject('Test');

        $this->emailLinkMapper->method('findByObjectUuid')->with('abc-123', 10, 0)->willReturn([$link]);
        $this->emailLinkMapper->method('countByObjectUuid')->with('abc-123')->willReturn(1);

        $result = $this->service->getEmailsForObject('abc-123', 10, 0);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Test', $result['results'][0]['subject']);
    }

    public function testGetEmailsForObjectReturnsEmpty(): void
    {
        $this->emailLinkMapper->method('findByObjectUuid')->willReturn([]);
        $this->emailLinkMapper->method('countByObjectUuid')->willReturn(0);

        $result = $this->service->getEmailsForObject('nonexistent');

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['results']);
    }

    public function testLinkEmailThrowsOnDuplicate(): void
    {
        $existing = new EmailLink();
        $this->emailLinkMapper->method('findByObjectAndMessage')->willReturn($existing);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Email already linked to this object');

        $this->service->linkEmail('abc-123', 5, 1, 42);
    }

    public function testUnlinkEmailSuccess(): void
    {
        $link = new EmailLink();
        $this->emailLinkMapper->method('find')->with(7)->willReturn($link);
        $this->emailLinkMapper->expects($this->once())->method('delete')->with($link);

        $this->service->unlinkEmail(7);
    }

    public function testUnlinkEmailNotFound(): void
    {
        $this->emailLinkMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Email link not found');

        $this->service->unlinkEmail(999);
    }

    public function testSearchBySenderReturnLinks(): void
    {
        $link = new EmailLink();
        $link->setObjectUuid('abc-123');
        $link->setSender('sender@test.local');

        $this->emailLinkMapper->method('findBySender')->with('sender@test.local')->willReturn([$link]);

        $results = $this->service->searchBySender('sender@test.local');

        $this->assertCount(1, $results);
        $this->assertSame('sender@test.local', $results[0]['sender']);
    }

    public function testDeleteLinksForObject(): void
    {
        $this->emailLinkMapper->expects($this->once())
            ->method('deleteByObjectUuid')
            ->with('abc-123')
            ->willReturn(3);

        $count = $this->service->deleteLinksForObject('abc-123');

        $this->assertSame(3, $count);
    }
}
