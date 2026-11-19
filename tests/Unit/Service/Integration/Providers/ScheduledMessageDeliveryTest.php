<?php
/**
 * Unit tests for ScheduledMessageDelivery: a claimed scheduled message becomes one dispatch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Kernel\Contracts\Notifications\ScheduledMessageDeliveryInterface;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCA\OpenRegister\Service\Integration\Providers\OutboundMessage;
use OCA\OpenRegister\Service\Integration\Providers\ScheduledMessageDelivery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Integration\Providers\ScheduledMessageDelivery
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\OutboundMessage
 */
class ScheduledMessageDeliveryTest extends TestCase {

	public function testItAnswersTheNotificationsPort(): void {
		$delivery = new ScheduledMessageDelivery(dispatcher: $this->createMock(MessageDispatcherInterface::class));

		$this->assertInstanceOf(ScheduledMessageDeliveryInterface::class, $delivery);
	}//end testItAnswersTheNotificationsPort()

	public function testTheStoredRowBecomesOneDispatchAndItsAnswerIsReturned(): void {
		$seen = [];
		$dispatcher = $this->createMock(MessageDispatcherInterface::class);
		$dispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
			static function (string $source, OutboundMessage $message) use (&$seen): array {
				$seen = ['source' => $source, 'path' => $message->path, 'body' => $message->body, 'headers' => $message->headers];
				return ['status' => 'sent', 'response' => ['sid' => 'SM1']];
			}
		);

		$result = (new ScheduledMessageDelivery(dispatcher: $dispatcher))->deliver(
			message: ['id' => 7, 'source' => 'twilio', 'path' => 'Messages.json', 'body' => ['To' => '+31'], 'headers' => ['X-A' => 'b']]
		);

		$this->assertSame(['status' => 'sent', 'response' => ['sid' => 'SM1']], $result);
		$this->assertSame(['source' => 'twilio', 'path' => 'Messages.json', 'body' => ['To' => '+31'], 'headers' => ['X-A' => 'b']], $seen);
	}//end testTheStoredRowBecomesOneDispatchAndItsAnswerIsReturned()

	public function testMissingFieldsAreSentEmpty(): void {
		$seen = [];
		$dispatcher = $this->createMock(MessageDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			static function (string $source, OutboundMessage $message) use (&$seen): array {
				$seen = ['source' => $source, 'path' => $message->path, 'body' => $message->body, 'headers' => $message->headers];
				return ['unavailable' => true, 'cause' => 'opencon-source-missing'];
			}
		);

		$result = (new ScheduledMessageDelivery(dispatcher: $dispatcher))->deliver(message: []);

		$this->assertSame(['unavailable' => true, 'cause' => 'opencon-source-missing'], $result);
		$this->assertSame(['source' => '', 'path' => '', 'body' => [], 'headers' => []], $seen);
	}//end testMissingFieldsAreSentEmpty()

	public function testADispatcherThrowReachesTheSweepUnchanged(): void {
		$dispatcher = $this->createMock(MessageDispatcherInterface::class);
		$dispatcher->method('dispatch')->willThrowException(new RuntimeException('smtp down'));

		$this->expectExceptionObject(new RuntimeException('smtp down'));

		(new ScheduledMessageDelivery(dispatcher: $dispatcher))->deliver(message: ['source' => 'twilio']);
	}//end testADispatcherThrowReachesTheSweepUnchanged()
}//end class
