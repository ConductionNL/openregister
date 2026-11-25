<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service;

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\MailObjectBinding;
use OCA\OpenRegister\Notifications\Service\OutboundMailLinkWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The outbound link a mail sent from an object leaves behind: the minted `Message-ID` is what a
 * reply threads on, so every column the resolver and the e-mails list read is pinned here
 * (reply-threading-by-headers 1.3, 3.1).
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
#[CoversClass(OutboundMailLinkWriter::class)]
#[UsesClass(EmailLink::class)]
#[UsesClass(MailObjectBinding::class)]
final class OutboundMailLinkWriterTest extends TestCase {

	/** @var array<int, EmailLink> */
	private array $inserted = [];

	private function writer(): OutboundMailLinkWriter {
		$links = $this->createMock(EmailLinkMapperInterface::class);
		$links->expects($this->once())->method('insert')->willReturnCallback(
			function (EmailLink $link): EmailLink {
				$this->inserted[] = $link;
				return $link;
			}
		);

		return new OutboundMailLinkWriter(links: $links);
	}

	public function testTheLinkCarriesTheMintedMessageIdAndNoMailAccount(): void {
		$this->writer()->record(
			object: new MailObjectBinding(objectUuid: 'obj-1', registerId: 4, schemaId: 7, linkedBy: 'alice'),
			rfcMessageId: '<m-1@example.org>',
			subject: 'Your request',
			recipient: 'bob@example.org'
		);

		$link = $this->inserted[0];
		$this->assertSame('obj-1', $link->getObjectUuid());
		$this->assertSame(4, $link->getRegisterId());
		$this->assertSame(7, $link->getSchemaId());
		$this->assertSame('<m-1@example.org>', $link->getRfcMessageId(), 'A reply threads on this value.');
		$this->assertSame('<m-1@example.org>', $link->getMailMessageUid());
		$this->assertSame(0, $link->getMailAccountId());
		$this->assertSame(0, $link->getMailMessageId());
		$this->assertSame(EmailLink::DIRECTION_OUTBOUND, $link->getDirection());
		$this->assertSame('Your request', $link->getSubject());
		$this->assertSame(['direction' => 'outbound', 'recipientName' => 'bob@example.org'], json_decode((string) $link->getMetadata(), true));
		$this->assertSame('alice', $link->getLinkedBy());
		$this->assertNotNull($link->getLinkedAt());
		$this->assertNotNull($link->getMailDate());
	}

	public function testAnObjectWithoutASchemaLeavesTheSchemaUnset(): void {
		$this->writer()->record(
			object: new MailObjectBinding(objectUuid: 'obj-2', registerId: 4, schemaId: null, linkedBy: 'alice'),
			rfcMessageId: '<m-2@example.org>',
			subject: 'Hello',
			recipient: 'bob@example.org'
		);

		$this->assertNull($this->inserted[0]->getSchemaId());
	}

	public function testALongSubjectIsCutToTheColumnWithoutBreakingACharacter(): void {
		$this->writer()->record(
			object: new MailObjectBinding(objectUuid: 'obj-3', registerId: 4, schemaId: 7, linkedBy: 'alice'),
			rfcMessageId: '<m-3@example.org>',
			subject: str_repeat('é', 600),
			recipient: 'bob@example.org'
		);

		$this->assertSame(str_repeat('é', 512), $this->inserted[0]->getSubject());
	}

	public function testAMessageIdTheColumnCannotHoldIsNotStoredAsOne(): void {
		$tooLong = '<'.str_repeat('a', 1000).'@example.org>';

		$this->writer()->record(
			object: new MailObjectBinding(objectUuid: 'obj-4', registerId: 4, schemaId: 7, linkedBy: 'alice'),
			rfcMessageId: $tooLong,
			subject: 'Hello',
			recipient: 'bob@example.org'
		);

		$this->assertNull($this->inserted[0]->getRfcMessageId());
		$this->assertSame(EmailLink::DIRECTION_OUTBOUND, $this->inserted[0]->getDirection());
	}
}//end class
