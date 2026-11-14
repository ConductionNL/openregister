<?php

/**
 * EmailSender: a mail sent from an object carries a minted Message-ID and is linked to the object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\MailAddressee;
use OCA\OpenRegister\Notifications\Service\Notification\MailContent;
use OCA\OpenRegister\Notifications\Service\Notification\MailObjectBinding;
use OCA\OpenRegister\Notifications\Service\OutboundMailLinkWriter;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingMessage;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingSymfonyMessage;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\EmailSender
 * @covers \OCA\OpenRegister\Notifications\Service\OutboundMailLinkWriter
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\MailAddressee
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\MailContent
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\MailObjectBinding
 * @uses \OCA\OpenRegister\Db\EmailLink
 * @uses \OCA\OpenRegister\Db\EmailLink\EmailLinkFieldTypes
 */
class EmailSenderOutboundLinkTest extends TestCase {

	/** @var EmailLink[] The links stored. */
	private array $stored = [];

	private function sender(RecordingMessage $message, bool $sendFails = false, bool $insertFails = false): EmailSender {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		if ($sendFails === true) {
			$mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		}

		$links = $this->createMock(EmailLinkMapperInterface::class);
		$links->method('insert')->willReturnCallback(
			function (EmailLink $link) use ($insertFails): EmailLink {
				if ($insertFails === true) {
					throw new RuntimeException('table gone');
				}

				$this->stored[] = $link;
				return $link;
			}
		);

		return new EmailSender(
			userManager: $this->createMock(IUserManager::class),
			mailer: $mailer,
			logger: $this->createMock(LoggerInterface::class),
			outboundLinks: new OutboundMailLinkWriter(links: $links)
		);
	}

	private function bound(): MailContent {
		return new MailContent(
			subject: 'Uw aanvraag',
			body: 'Body',
			object: new MailObjectBinding(objectUuid: 'case-1', registerId: 4, schemaId: 9, linkedBy: 'alice')
		);
	}

	public function testAMailSentFromAnObjectIsLinkedOutboundWithItsMintedMessageId(): void {
		$message = new RecordingSymfonyMessage();

		$outcome = $this->sender($message)->sendMailTo(new MailAddressee(address: 'piet@example.nl', displayName: 'Piet'), $this->bound());

		$this->assertSame(EmailSender::OUTCOME_DISPATCHED, $outcome);
		$this->assertMatchesRegularExpression('/^<[0-9a-f]{32}@[a-z0-9.-]+>$/', $message->headers['Message-ID']);
		$this->assertCount(1, $this->stored);
		$link = $this->stored[0];
		$this->assertSame($message->headers['Message-ID'], $link->getRfcMessageId());
		$this->assertSame($message->headers['Message-ID'], $link->getMailMessageUid());
		$this->assertSame(EmailLink::DIRECTION_OUTBOUND, $link->getDirection());
		$this->assertSame(['case-1', 4, 9, 0, 0], [$link->getObjectUuid(), $link->getRegisterId(), $link->getSchemaId(), $link->getMailAccountId(), $link->getMailMessageId()]);
		$this->assertSame(['Uw aanvraag', 'alice'], [$link->getSubject(), $link->getLinkedBy()]);
		$this->assertSame('outbound', $link->details()['direction']);
		$this->assertSame('Piet', $link->details()['recipientName']);
	}

	public function testAMailWithoutAnObjectGetsNoMessageIdAndNoLink(): void {
		$message = new RecordingSymfonyMessage();

		$this->sender($message)->sendMailTo(new MailAddressee(address: 'piet@example.nl'), new MailContent(subject: 'S', body: 'B'));

		$this->assertArrayNotHasKey('Message-ID', $message->headers);
		$this->assertSame([], $this->stored);
	}

	public function testAFailedSendIsNotLinked(): void {
		$outcome = $this->sender(new RecordingSymfonyMessage(), sendFails: true)->sendMailTo(new MailAddressee(address: 'piet@example.nl'), $this->bound());

		$this->assertSame(EmailSender::OUTCOME_FAILED, $outcome);
		$this->assertSame([], $this->stored);
	}

	public function testAMailerWithoutHeadersSendsUnlinked(): void {
		$outcome = $this->sender(new RecordingMessage())->sendMailTo(new MailAddressee(address: 'piet@example.nl'), $this->bound());

		$this->assertSame(EmailSender::OUTCOME_DISPATCHED, $outcome);
		$this->assertSame([], $this->stored);
	}

	public function testAFailedLinkWriteLeavesTheMailDispatched(): void {
		$message = new RecordingSymfonyMessage();

		$outcome = $this->sender($message, insertFails: true)->sendMailTo(new MailAddressee(address: 'piet@example.nl'), $this->bound());

		$this->assertSame(EmailSender::OUTCOME_DISPATCHED, $outcome);
		$this->assertArrayHasKey('Message-ID', $message->headers);
	}
}
