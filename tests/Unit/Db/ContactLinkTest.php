<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ContactLink;
use PHPUnit\Framework\TestCase;

class ContactLinkTest extends TestCase {
	public function testJsonSerializeReturnsAllFields(): void {
		$link = new ContactLink();
		$link->setObjectUuid('abc-123');
		$link->setRegisterId(5);
		$link->setContactUid('jan-uid');
		$link->setAddressbookId(1);
		$link->setContactUri('jan-de-vries.vcf');
		$link->setDisplayName('Jan de Vries');
		$link->setEmail('jan@example.nl');
		$link->setRole('applicant');
		$link->setLinkedBy('admin');
		$link->setLinkedAt(new DateTime('2026-03-25T11:00:00+00:00'));

		$json = $link->jsonSerialize();

		$this->assertSame('abc-123', $json['objectUuid']);
		$this->assertSame('jan-uid', $json['contactUid']);
		$this->assertSame(1, $json['addressbookId']);
		$this->assertSame('Jan de Vries', $json['displayName']);
		$this->assertSame('jan@example.nl', $json['email']);
		$this->assertSame('applicant', $json['role']);
	}

	public function testJsonSerializeHandlesNulls(): void {
		$link = new ContactLink();

		$json = $link->jsonSerialize();

		$this->assertNull($json['displayName']);
		$this->assertNull($json['email']);
		$this->assertNull($json['role']);
	}

	public function testSettersAndGetters(): void {
		$link = new ContactLink();
		$link->setRole('handler');
		$link->setContactUid('contact-123');

		$this->assertSame('handler', $link->getRole());
		$this->assertSame('contact-123', $link->getContactUid());
	}

	/**
	 * Tier-2: jsonSerialize emits the widened payload (phone / org /
	 * avatarUrl / schemaId / metadata) plus the original fields.
	 */
	public function testJsonSerializeEmitsTier2Fields(): void {
		$link = new ContactLink();
		$link->setObjectUuid('abc-123');
		$link->setSchemaId(7);
		$link->setPhone('+31 6 1234');
		$link->setOrg('Acme B.V.');
		$link->setAvatarUrl('https://example.com/jan.jpg');
		$link->setMetadata(json_encode(['note' => 'hello']));

		$json = $link->jsonSerialize();

		$this->assertSame(7, $json['schemaId']);
		$this->assertSame('+31 6 1234', $json['phone']);
		$this->assertSame('Acme B.V.', $json['org']);
		$this->assertSame('https://example.com/jan.jpg', $json['avatarUrl']);
		$this->assertSame(['note' => 'hello'], $json['metadata']);
	}

	/**
	 * Tier-2: corrupt metadata JSON does not blow up the serialiser.
	 */
	public function testJsonSerializeToleratesCorruptMetadata(): void {
		$link = new ContactLink();
		$link->setMetadata('{ this is not json');

		$json = $link->jsonSerialize();

		$this->assertNull($json['metadata']);
	}

	/**
	 * People on objects: a user link says so, and carries validity and note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function testJsonSerializeEmitsThePeopleOnObjectsFields(): void {
		$link = new ContactLink();
		$link->setUserId('jan');
		$link->setContactUid(ContactLink::USER_UID_PREFIX . 'jan');
		$link->setValidFrom(new \DateTime('2026-01-01'));
		$link->setValidUntil(new \DateTime('2026-12-31'));
		$link->setNote('Handles the permit');

		$json = $link->jsonSerialize();

		$this->assertTrue($link->isUserLink());
		$this->assertSame('user', $json['kind']);
		$this->assertSame('jan', $json['userId']);
		$this->assertSame('user:jan', $json['contactUid']);
		$this->assertSame('2026-01-01', $json['validFrom']);
		$this->assertSame('2026-12-31', $json['validUntil']);
		$this->assertSame('Handles the permit', $json['note']);
	}//end testJsonSerializeEmitsThePeopleOnObjectsFields()

	/**
	 * A contact link is kind `contact`, with the new fields null and open validity active.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function testAContactLinkIsKindContactAndActiveWhenOpen(): void {
		$link = new ContactLink();
		$link->setContactUid('jan-uid');

		$json = $link->jsonSerialize();

		$this->assertFalse($link->isUserLink());
		$this->assertSame('contact', $json['kind']);
		$this->assertNull($json['userId']);
		$this->assertNull($json['validFrom']);
		$this->assertNull($json['validUntil']);
		$this->assertNull($json['note']);
		$this->assertTrue($json['active']);
	}//end testAContactLinkIsKindContactAndActiveWhenOpen()

	/**
	 * Validity: outside the window is inactive, on the bounds is active, a bound unset is open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function testIsActiveOnFollowsTheValidityWindow(): void {
		$link = new ContactLink();
		$link->setValidFrom(new \DateTime('2026-03-01'));
		$link->setValidUntil(new \DateTime('2026-03-31'));

		$this->assertFalse($link->isActiveOn(new \DateTime('2026-02-28')));
		$this->assertTrue($link->isActiveOn(new \DateTime('2026-03-01')));
		$this->assertTrue($link->isActiveOn(new \DateTime('2026-03-31')));
		$this->assertFalse($link->isActiveOn(new \DateTime('2026-04-01')));

		$link->setValidUntil(null);
		$this->assertTrue($link->isActiveOn(new \DateTime('2030-01-01')));
		$this->assertFalse($link->isActiveOn(new \DateTime('2026-02-28')));

		$ended = new ContactLink();
		$ended->setValidUntil(new \DateTime('yesterday'));
		$this->assertFalse($ended->jsonSerialize()['active']);
	}//end testIsActiveOnFollowsTheValidityWindow()
}
