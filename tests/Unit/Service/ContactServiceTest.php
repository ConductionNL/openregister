<?php

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Service\ContactService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContactServiceTest extends TestCase
{
    private ContactLinkMapper&MockObject $contactLinkMapper;
    private CardDavBackend&MockObject $cardDavBackend;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private ContactService $service;

    protected function setUp(): void
    {
        $this->contactLinkMapper = $this->getMockBuilder(ContactLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByObjectUuid', 'findByContactUid', 'countByObjectUuid', 'deleteByObjectUuid', 'insert', 'delete'])
            ->addMethods(['find'])
            ->getMock();
        $this->cardDavBackend = $this->createMock(CardDavBackend::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ContactService(
            $this->contactLinkMapper,
            $this->cardDavBackend,
            $this->userSession,
            $this->logger
        );
    }

    private function setupUser(string $uid = 'admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testGetContactsForObjectReturnsResults(): void
    {
        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setDisplayName('Jan de Vries');

        $this->contactLinkMapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);
        $this->contactLinkMapper->method('countByObjectUuid')->with('abc-123')->willReturn(1);

        $result = $this->service->getContactsForObject('abc-123');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Jan de Vries', $result['results'][0]['displayName']);
    }

    public function testGetContactsForObjectEmpty(): void
    {
        $this->contactLinkMapper->method('findByObjectUuid')->willReturn([]);
        $this->contactLinkMapper->method('countByObjectUuid')->willReturn(0);

        $result = $this->service->getContactsForObject('nonexistent');

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['results']);
    }

    public function testLinkContactThrowsWhenContactNotFound(): void
    {
        $this->setupUser();
        $this->cardDavBackend->method('getCard')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Contact not found');

        $this->service->linkContact('abc-123', 5, 1, 'nonexistent.vcf', 'applicant');
    }

    public function testLinkContactSuccess(): void
    {
        $this->setupUser();

        $vcardData = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:jan-uid\r\nFN:Jan de Vries\r\nEMAIL:jan@example.nl\r\nEND:VCARD\r\n";

        $this->cardDavBackend->method('getCard')->willReturn(['carddata' => $vcardData]);
        $this->cardDavBackend->expects($this->once())->method('updateCard');

        $this->contactLinkMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (ContactLink $link): ContactLink {
                $this->assertSame('abc-123', $link->getObjectUuid());
                $this->assertSame('Jan de Vries', $link->getDisplayName());
                $this->assertSame('jan@example.nl', $link->getEmail());
                $this->assertSame('applicant', $link->getRole());
                return $link;
            });

        $this->service->linkContact('abc-123', 5, 1, 'jan.vcf', 'applicant');
    }

    public function testUnlinkContactNotFound(): void
    {
        $this->contactLinkMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Contact link not found');

        $this->service->unlinkContact(999);
    }

    public function testGetObjectsForContactReturnsLinks(): void
    {
        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setRole('applicant');

        $this->contactLinkMapper->method('findByContactUid')->with('jan-uid')->willReturn([$link]);

        $results = $this->service->getObjectsForContact('jan-uid');

        $this->assertCount(1, $results);
        $this->assertSame('abc-123', $results[0]['objectUuid']);
    }

    public function testDeleteLinksForObjectCleansUp(): void
    {
        $link = new ContactLink();
        $link->setAddressbookId(1);
        $link->setContactUri('jan.vcf');
        $link->setContactUid('jan-uid');

        $vcardData = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:jan-uid\r\nFN:Jan\r\nX-OPENREGISTER-OBJECT:abc-123\r\nEND:VCARD\r\n";

        $this->contactLinkMapper->method('findByObjectUuid')->willReturn([$link]);
        $this->cardDavBackend->method('getCard')->willReturn(['carddata' => $vcardData]);
        $this->cardDavBackend->expects($this->once())->method('updateCard');
        $this->contactLinkMapper->expects($this->once())->method('deleteByObjectUuid')->with('abc-123');

        $this->service->deleteLinksForObject('abc-123');
    }
}
