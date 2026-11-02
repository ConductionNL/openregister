<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\EmailLink;
use PHPUnit\Framework\TestCase;

class EmailLinkTest extends TestCase {
	public function testJsonSerializeReturnsAllFields(): void {
		$link = new EmailLink();
		$link->setObjectUuid('abc-123');
		$link->setRegisterId(5);
		$link->setMailAccountId(1);
		$link->setMailMessageId(42);
		$link->setMailMessageUid('MSG-001');
		$link->setSubject('Test email subject');
		$link->setSender('sender@test.local');
		$link->setMailDate(new DateTime('2026-03-25T10:00:00+00:00'));
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
		$this->assertStringContainsString('2026-03-25', $json['mailDate']);
		$this->assertStringContainsString('2026-03-25', $json['linkedAt']);
	}

	/**
	 * The sender's display name and the link's details read from the metadata
	 * (direction, recipient, attachments, how it was linked, its archive copy),
	 * flat beside the fields; the metadata string keeps its shape and unknown
	 * keys stay out.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/email-link-sender-name-and-details/spec.md
	 */
	public function testJsonSerializeCarriesSenderNameAndDetails(): void {
		$link = new EmailLink();
		$link->setSenderName('Sanne de Vries');
		$link->setMetadata((string) json_encode([
			'source'          => 'example-set:portaliq/x',
			'direction'       => 'inbound',
			'attachmentCount' => 1,
			'linkedVia'       => 'mailbox',
			'archivedAs'      => 'pdf',
			'mailboxName'     => 'het Woo-postvak',
			'other'           => 'kept out',
		]));

		$json = $link->jsonSerialize();

		$this->assertSame(expected: 'Sanne de Vries', actual: $json['senderName']);
		$this->assertSame(
			expected: [
				'direction'       => 'inbound',
				'recipientName'   => null,
				'attachmentCount' => 1,
				'linkedVia'       => 'mailbox',
				'archivedAs'      => 'pdf',
				'mailboxName'     => 'het Woo-postvak',
			],
			actual: $json['details']
		);
		$this->assertIsString(actual: $json['metadata']);
	}

	/**
	 * An empty link serialises with nulls, and every detail key present.
	 *
	 * @return void
	 */
	public function testJsonSerializeHandlesNulls(): void {
		$link = new EmailLink();

		$json = $link->jsonSerialize();

		$this->assertNull(actual: $json['objectUuid']);
		$this->assertNull(actual: $json['mailDate']);
		$this->assertNull(actual: $json['linkedAt']);
		$this->assertNull(actual: $json['subject']);
		$this->assertNull(actual: $json['senderName']);
		$this->assertSame(
			expected: [
				'direction'       => null,
				'recipientName'   => null,
				'attachmentCount' => null,
				'linkedVia'       => null,
				'archivedAs'      => null,
				'mailboxName'     => null,
			],
			actual: $json['details']
		);
	}

	public function testSettersAndGetters(): void {
		$link = new EmailLink();
		$link->setObjectUuid('def-456');
		$link->setMailAccountId(2);
		$link->setMailMessageId(99);

		$this->assertSame('def-456', $link->getObjectUuid());
		$this->assertSame(2, $link->getMailAccountId());
		$this->assertSame(99, $link->getMailMessageId());
	}
}
