<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\EmailLink;
use PHPUnit\Framework\TestCase;

class EmailLinkTest extends TestCase
{
    public function testJsonSerializeReturnsAllFields(): void
    {
        $link = new EmailLink();
        $link->setObjectUuid('abc-123');
        $link->setRegisterId(5);
        $link->setMailAccountId(1);
        $link->setMailMessageId(42);
        $link->setMailMessageUid('MSG-001');
        $link->setSubject('Test email subject');
        $link->setSender('sender@test.local');
        $link->setDate(new DateTime('2026-03-25T10:00:00+00:00'));
        $link->setLinkedBy('admin');
        $link->setLinkedAt(new DateTime('2026-03-25T11:00:00+00:00'));

        $json = $link->jsonSerialize();

        $this->assertSame('abc-123', $json['objectUuid']);
        $this->assertSame(5, $json['registerId']);
        $this->assertSame(1, $json['mailAccountId']);
        $this->assertSame(42, $json['mailMessageId']);
        $this->assertSame('MSG-001', $json['mailMessageUid']);
        $this->assertSame('Test email subject', $json['subject']);
        $this->assertSame('sender@test.local', $json['sender']);
        $this->assertSame('admin', $json['linkedBy']);
        $this->assertStringContainsString('2026-03-25', $json['date']);
        $this->assertStringContainsString('2026-03-25', $json['linkedAt']);
    }

    public function testJsonSerializeHandlesNulls(): void
    {
        $link = new EmailLink();

        $json = $link->jsonSerialize();

        $this->assertNull($json['objectUuid']);
        $this->assertNull($json['date']);
        $this->assertNull($json['linkedAt']);
        $this->assertNull($json['subject']);
    }

    public function testSettersAndGetters(): void
    {
        $link = new EmailLink();
        $link->setObjectUuid('def-456');
        $link->setMailAccountId(2);
        $link->setMailMessageId(99);

        $this->assertSame('def-456', $link->getObjectUuid());
        $this->assertSame(2, $link->getMailAccountId());
        $this->assertSame(99, $link->getMailMessageId());
    }
}
