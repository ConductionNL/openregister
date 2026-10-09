<?php

/**
 * The email channel sender: an address send with and without unsubscribe material.
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
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingMessage;
use OCA\OpenRegister\Tests\Unit\Service\Notification\Fixture\RecordingSymfonyMessage;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Notification\EmailSender
 */
class EmailSenderTest extends TestCase {

	/**
	 * Every message handed to the mailer.
	 *
	 * @var array<int, RecordingMessage>
	 */
	private array $sent = [];

	private function sender(RecordingMessage $message): EmailSender {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturnCallback(
			function (RecordingMessage $msg): array {
				$this->sent[] = $msg;
				return [];
			}
		);

		return new EmailSender(
			userManager: $this->createMock(IUserManager::class),
			mailer: $mailer,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	public function testUnsubscribeMaterialSetsTheHeaders(): void {
		$message = new RecordingSymfonyMessage();

		$outcome = $this->sender($message)->sendToAddress(
			address: 'piet@example.nl',
			displayName: '',
			subject: 'Your case',
			body: 'Body',
			unsubscribe: ['url' => 'https://nc.example/u/abc', 'oneClickUrl' => 'https://nc.example/u/abc']
		);

		$this->assertSame(EmailSender::OUTCOME_DISPATCHED, $outcome);
		$this->assertCount(1, $this->sent);
		$this->assertSame('<https://nc.example/u/abc>', $message->headers['List-Unsubscribe']);
		$this->assertSame('List-Unsubscribe=One-Click', $message->headers['List-Unsubscribe-Post']);
		$this->assertSame('Body', $message->body);
	}

	public function testNoMaterialSetsNoHeaders(): void {
		$message = new RecordingSymfonyMessage();

		$this->sender($message)->sendToAddress(address: 'piet@example.nl', displayName: '', subject: 'S', body: 'B');

		$this->assertSame([], $message->headers);
		$this->assertCount(1, $this->sent);
	}

	public function testAMailerWithoutHeadersStillSends(): void {
		$message = new RecordingMessage();

		$outcome = $this->sender($message)->sendToAddress(
			address: 'piet@example.nl',
			displayName: '',
			subject: 'S',
			body: 'B',
			unsubscribe: ['oneClickUrl' => 'https://nc.example/u/abc']
		);

		$this->assertSame(EmailSender::OUTCOME_DISPATCHED, $outcome);
		$this->assertCount(1, $this->sent);
	}
}
