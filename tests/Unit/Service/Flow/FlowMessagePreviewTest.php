<?php

/**
 * FlowMessagePreview renders, per item, the message a notify/email/talk step
 * would send: recipients resolved per channel and title and body filled.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplatingInterface;
use OCA\OpenRegister\Service\Flow\FlowMessagePreview;
use OCA\OpenRegister\Service\Flow\FlowRecipientAddresses;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowMessagePreview
 */
class FlowMessagePreviewTest extends TestCase {

	/**
	 * Recipient resolver.
	 *
	 * @var FlowRecipientAddresses&MockObject
	 */
	private FlowRecipientAddresses&MockObject $addresses;

	/**
	 * Class under test.
	 *
	 * @var FlowMessagePreview
	 */
	private FlowMessagePreview $preview;

	/**
	 * Templating fills "{name}" from the item json and marks the context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->addresses = $this->createMock(FlowRecipientAddresses::class);
		$templating      = $this->createMock(NotificationTemplatingInterface::class);
		$templating->method('interpolate')->willReturnCallback(
			static fn (string $template, array $data, array $context) => str_replace('{name}', (string) ($data['name'] ?? ''), $template).($context['tag'] ?? '')
		);
		$this->preview = new FlowMessagePreview($this->addresses, $templating);
	}

	/**
	 * A notification uses title and message, recipients as uids then addresses, without addresses accepted.
	 *
	 * @return void
	 */
	public function testNotificationMessage(): void {
		$this->addresses->expects($this->once())->method('resolveRecipients')
			->with(['alice'], ['name' => 'Ann'], false)
			->willReturn(['uids' => ['alice'], 'addresses' => [['address' => 'x@example.org']]]);

		$this->assertSame(
			[['channel' => 'notification', 'to' => ['alice', 'x@example.org'], 'subject' => 'Hi Ann!', 'body' => 'For Ann!']],
			$this->preview->messages(
				'notification',
				['recipients' => ['alice'], 'title' => 'Hi {name}', 'message' => 'For {name}', 'subject' => 'unused'],
				[['json' => ['name' => 'Ann']]],
				['tag' => '!']
			)
		);
	}

	/**
	 * An email uses subject and body and accepts plain addresses.
	 *
	 * @return void
	 */
	public function testEmailMessage(): void {
		$this->addresses->expects($this->once())->method('resolveRecipients')
			->with([], [], true)
			->willReturn(['uids' => [], 'addresses' => [['address' => 'a@example.org'], ['address' => 'b@example.org']]]);

		$this->assertSame(
			[['channel' => 'email', 'to' => ['a@example.org', 'b@example.org'], 'subject' => 'S', 'body' => 'B']],
			$this->preview->messages('email', ['subject' => 'S', 'body' => 'B', 'title' => 'unused'], [[]], [])
		);
	}

	/**
	 * A talk message goes to the filled, trimmed conversation without resolving recipients.
	 *
	 * @return void
	 */
	public function testTalkMessage(): void {
		$this->addresses->expects($this->never())->method('resolveRecipients');

		$this->assertSame(
			[
				['channel' => 'talk', 'to' => ['room-Ann'], 'subject' => '', 'body' => 'Hello Ann'],
				['channel' => 'talk', 'to' => ['room-Bo'], 'subject' => '', 'body' => 'Hello Bo'],
			],
			$this->preview->messages(
				'talk',
				['conversation' => ' room-{name} ', 'message' => 'Hello {name}'],
				[['json' => ['name' => 'Ann']], ['json' => ['name' => 'Bo']]],
				[]
			)
		);
	}

	/**
	 * No items, no messages.
	 *
	 * @return void
	 */
	public function testNoItemsNoMessages(): void {
		$this->addresses->expects($this->never())->method('resolveRecipients');
		$this->assertSame([], $this->preview->messages('email', [], [], []));
	}
}
