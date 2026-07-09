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
            ->onlyMethods(['findByObjectUuid', 'findByContactUid', 'findByObjectAndContact', 'countByObjectUuid', 'deleteByObjectUuid', 'insert', 'update', 'delete'])
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

    /**
     * Phase B-1: full vCard with TEL / ORG / PHOTO produces all three
     * widened fields plus the original ContactLink fields.
     */
    public function testGetContactsForObjectEnrichesWithFullVcard(): void
    {
        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setDisplayName('Jan de Vries');
        $link->setEmail('jan@example.nl');
        $link->setAddressbookId(2);
        $link->setContactUri('jan.vcf');
        $link->setContactUid('jan-uid');

        // vCard 3.0 PHOTO with VALUE=URI parameter is how NC Contacts
        // emits external image URLs (it auto-classifies bare PHOTO bodies
        // as base64 binary; see Sabre VObject Property\Binary handling).
        $vcardData = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:jan-uid\r\nFN:Jan de Vries\r\nEMAIL:jan@example.nl\r\nTEL;TYPE=CELL:+31 6 1234 5678\r\nORG:Acme B.V.;Engineering\r\nPHOTO;VALUE=URI:https://example.com/jan.jpg\r\nEND:VCARD\r\n";

        $this->contactLinkMapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);
        $this->contactLinkMapper->method('countByObjectUuid')->with('abc-123')->willReturn(1);
        $this->cardDavBackend->method('getCard')->with(2, 'jan.vcf')->willReturn(['carddata' => $vcardData]);

        $result = $this->service->getContactsForObject('abc-123');

        $row = $result['results'][0];
        $this->assertSame('+31 6 1234 5678', $row['phone']);
        $this->assertSame('Acme B.V.', $row['org']);
        $this->assertSame('https://example.com/jan.jpg', $row['avatarUrl']);
        // Original payload still present.
        $this->assertSame('Jan de Vries', $row['displayName']);
        $this->assertSame('jan@example.nl', $row['email']);
    }

    /**
     * Phase B-1: inline base64 PHOTO (vCard 3.0 binary) is wrapped as a
     * data URL using the TYPE parameter for the media type.
     */
    public function testGetContactsForObjectEnrichesWithInlineBase64Photo(): void
    {
        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setDisplayName('Lisa');
        $link->setAddressbookId(2);
        $link->setContactUri('lisa.vcf');
        $link->setContactUid('lisa-uid');

        $b64 = base64_encode('FAKE_PNG_BYTES');
        $vcardData = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:lisa-uid\r\nFN:Lisa\r\nPHOTO;ENCODING=b;TYPE=JPEG:$b64\r\nEND:VCARD\r\n";

        $this->contactLinkMapper->method('findByObjectUuid')->willReturn([$link]);
        $this->contactLinkMapper->method('countByObjectUuid')->willReturn(1);
        $this->cardDavBackend->method('getCard')->willReturn(['carddata' => $vcardData]);

        $result = $this->service->getContactsForObject('abc-123');

        $row = $result['results'][0];
        $this->assertStringStartsWith('data:image/jpeg;base64,', $row['avatarUrl']);
        $this->assertStringContainsString($b64, $row['avatarUrl']);
    }

    /**
     * Phase B-1: vCard without TEL / ORG / PHOTO returns the widened
     * fields as null (with avatarUrl falling back to the per-uid
     * Contacts route). No exception thrown.
     */
    public function testGetContactsForObjectEnrichesWithPartialVcard(): void
    {
        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setDisplayName('Anna');
        $link->setAddressbookId(2);
        $link->setContactUri('anna.vcf');
        $link->setContactUid('anna-uid');

        // No TEL / ORG / PHOTO.
        $vcardData = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:anna-uid\r\nFN:Anna\r\nEND:VCARD\r\n";

        $this->contactLinkMapper->method('findByObjectUuid')->willReturn([$link]);
        $this->contactLinkMapper->method('countByObjectUuid')->willReturn(1);
        $this->cardDavBackend->method('getCard')->willReturn(['carddata' => $vcardData]);

        $result = $this->service->getContactsForObject('abc-123');

        $row = $result['results'][0];
        $this->assertNull($row['phone']);
        $this->assertNull($row['org']);
        // PHOTO absent → fallback to per-uid Contacts route.
        $this->assertSame('/index.php/apps/contacts/photo/anna-uid', $row['avatarUrl']);
    }

    /**
     * Phase B-1: when CardDAV returns false (card deleted) the link
     * is still surfaced with null widened fields.
     */
    public function testGetContactsForObjectGracefulWhenVcardMissing(): void
    {
        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setDisplayName('Ghost');
        $link->setAddressbookId(2);
        $link->setContactUri('ghost.vcf');
        $link->setContactUid('ghost-uid');

        $this->contactLinkMapper->method('findByObjectUuid')->willReturn([$link]);
        $this->contactLinkMapper->method('countByObjectUuid')->willReturn(1);
        $this->cardDavBackend->method('getCard')->willReturn(false);

        $result = $this->service->getContactsForObject('abc-123');

        $row = $result['results'][0];
        $this->assertNull($row['phone']);
        $this->assertNull($row['org']);
        $this->assertNull($row['avatarUrl']);
        $this->assertSame('Ghost', $row['displayName']);
    }

    /**
     * Phase B-1: CardDAV throwing during getCard must not propagate.
     */
    public function testGetContactsForObjectGracefulWhenCardDavThrows(): void
    {
        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setDisplayName('Throwy');
        $link->setAddressbookId(2);
        $link->setContactUri('throwy.vcf');
        $link->setContactUid('throwy-uid');

        $this->contactLinkMapper->method('findByObjectUuid')->willReturn([$link]);
        $this->contactLinkMapper->method('countByObjectUuid')->willReturn(1);
        $this->cardDavBackend->method('getCard')->willThrowException(new \RuntimeException('DAV blew up'));

        $result = $this->service->getContactsForObject('abc-123');

        $row = $result['results'][0];
        $this->assertNull($row['phone']);
        $this->assertNull($row['org']);
        $this->assertNull($row['avatarUrl']);
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

        // Tier-2: linkContact now consults findByObjectAndContact for
        // idempotent upsert. Returning null means "no prior row" and
        // the service falls through to the insert path.
        $this->contactLinkMapper->method('findByObjectAndContact')->willReturn(null);

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

    /**
     * Tier-2: when a row already exists for the (objectUuid, contactUid)
     * pair, linkContact updates it in-place instead of inserting.
     *
     * @return void
     */
    public function testLinkContactUpsertsExistingRow(): void
    {
        $this->setupUser();

        $vcardData = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:jan-uid\r\nFN:Jan de Vries\r\nEMAIL:jan@example.nl\r\nEND:VCARD\r\n";

        $this->cardDavBackend->method('getCard')->willReturn(['carddata' => $vcardData]);
        $this->cardDavBackend->expects($this->once())->method('updateCard');

        $existing = new ContactLink();
        $existing->setId(99);
        $existing->setObjectUuid('abc-123');
        $existing->setContactUid('jan-uid');
        $existing->setRole('observer');

        $this->contactLinkMapper->method('findByObjectAndContact')
            ->with('abc-123', 'jan-uid')
            ->willReturn($existing);

        // Must update (not insert) and the role must be refreshed.
        $this->contactLinkMapper->expects($this->never())->method('insert');
        $this->contactLinkMapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (ContactLink $link): ContactLink {
                $this->assertSame(99, $link->getId());
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

    /**
     * Idempotency: unlinkContact must drop the link row even when the
     * underlying vCard has been removed (CardDavBackend::getCard returns
     * false). Phase D-2 found that the registry-path delete was
     * returning HTTP 500 on this case, leaving orphan link rows that
     * could only be cleaned via direct DB DELETE.
     *
     * @return void
     */
    public function testUnlinkContactToleratesMissingVcard(): void
    {
        $link = new ContactLink();
        $link->setId(123);
        $link->setObjectUuid('abc-123');
        $link->setAddressbookId(1);
        $link->setContactUri('gone.vcf');

        $this->contactLinkMapper->method('find')->with(123)->willReturn($link);

        // CardDAV reports the vCard is gone.
        $this->cardDavBackend->method('getCard')
            ->with(1, 'gone.vcf')
            ->willReturn(false);
        // Must NOT attempt updateCard on a missing vCard.
        $this->cardDavBackend->expects($this->never())->method('updateCard');

        // Link row deletion MUST still happen.
        $this->contactLinkMapper->expects($this->once())
            ->method('delete')
            ->with($link);

        $this->service->unlinkContact(123);
    }

    /**
     * Idempotency: a Throwable from the CardDAV cleanup path (corrupt
     * vCard, deserialisation failure, etc.) must NOT prevent the link
     * row deletion. The previous catch was \Exception only; PHP 8.x
     * Errors (TypeError etc.) bypassed it and bubbled as HTTP 500.
     *
     * @return void
     */
    public function testUnlinkContactToleratesThrowableDuringCleanup(): void
    {
        $link = new ContactLink();
        $link->setId(456);
        $link->setObjectUuid('abc-123');
        $link->setAddressbookId(2);
        $link->setContactUri('corrupt.vcf');

        $this->contactLinkMapper->method('find')->with(456)->willReturn($link);

        // CardDAV throws a Throwable that the prior \Exception catch
        // would have missed (Error vs Exception hierarchy).
        $this->cardDavBackend->method('getCard')
            ->with(2, 'corrupt.vcf')
            ->willThrowException(new \Error('vCard storage corrupted'));

        // Link row deletion MUST still happen.
        $this->contactLinkMapper->expects($this->once())
            ->method('delete')
            ->with($link);

        $this->service->unlinkContact(456);
    }

    /**
     * Tier-2: unlinkContactByUid resolves the link row via the
     * (objectUuid, contactUid) composite index, then delegates to the
     * id-based unlinkContact path.
     */
    public function testUnlinkContactByUidResolvesAndDelegates(): void
    {
        $link = new ContactLink();
        $link->setId(42);
        $link->setObjectUuid('abc-123');
        $link->setContactUid('jan-uid');
        $link->setAddressbookId(1);
        $link->setContactUri('jan.vcf');

        $this->contactLinkMapper->method('findByObjectAndContact')
            ->with('abc-123', 'jan-uid')
            ->willReturn($link);
        $this->contactLinkMapper->method('find')->with(42)->willReturn($link);
        $this->cardDavBackend->method('getCard')->willReturn(false);

        $this->contactLinkMapper->expects($this->once())
            ->method('delete')
            ->with($link);

        $this->service->unlinkContactByUid('abc-123', 'jan-uid');
    }

    /**
     * Tier-2: unlinkContactByUid raises 404 when no row matches.
     */
    public function testUnlinkContactByUidThrowsWhenMissing(): void
    {
        $this->contactLinkMapper->method('findByObjectAndContact')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Contact link not found');

        $this->service->unlinkContactByUid('abc-123', 'missing-uid');
    }

    public function testGetObjectsForContactReturnsLinks(): void
    {
        $this->setupUser('alice');
        // The caller owns addressbook 7; the link lives there → visible.
        $this->cardDavBackend->method('getAddressBooksForUser')
            ->with('principals/users/alice')
            ->willReturn([['id' => 7]]);

        $link = new ContactLink();
        $link->setObjectUuid('abc-123');
        $link->setRole('applicant');
        $link->setAddressbookId(7);

        $this->contactLinkMapper->method('findByContactUid')->with('jan-uid')->willReturn([$link]);

        $results = $this->service->getObjectsForContact('jan-uid');

        $this->assertCount(1, $results);
        $this->assertSame('abc-123', $results[0]['objectUuid']);
    }

    public function testGetObjectsForContactHidesLinksInOtherUsersAddressbooks(): void
    {
        // IDOR: the link lives in addressbook 99, which the caller does not own.
        $this->setupUser('bob');
        $this->cardDavBackend->method('getAddressBooksForUser')
            ->with('principals/users/bob')
            ->willReturn([['id' => 7]]);

        $link = new ContactLink();
        $link->setObjectUuid('secret-obj');
        $link->setAddressbookId(99);

        $this->contactLinkMapper->method('findByContactUid')->willReturn([$link]);

        $results = $this->service->getObjectsForContact('someone-elses-uid');

        $this->assertSame([], $results);
    }

    public function testGetObjectsForContactRejectsAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        // No addressbook lookup, no links returned for an anonymous session.
        $this->contactLinkMapper->expects($this->never())->method('findByContactUid');

        $this->assertSame([], $this->service->getObjectsForContact('jan-uid'));
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
