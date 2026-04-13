<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ContactLink;
use PHPUnit\Framework\TestCase;

class ContactLinkTest extends TestCase
{
    public function testJsonSerializeReturnsAllFields(): void
    {
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

    public function testJsonSerializeHandlesNulls(): void
    {
        $link = new ContactLink();

        $json = $link->jsonSerialize();

        $this->assertNull($json['displayName']);
        $this->assertNull($json['email']);
        $this->assertNull($json['role']);
    }

    public function testSettersAndGetters(): void
    {
        $link = new ContactLink();
        $link->setRole('handler');
        $link->setContactUid('contact-123');

        $this->assertSame('handler', $link->getRole());
        $this->assertSame('contact-123', $link->getContactUid());
    }
}
