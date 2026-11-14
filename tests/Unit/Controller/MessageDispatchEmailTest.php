<?php

/**
 * The e-mail channel of the send leaf: POST /api/integrations/email/send.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-e-mail-is-a-channel-of-the-dispatch-leaf
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\MessageDispatchController;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageScheduler;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCA\OpenRegister\Service\Integration\Providers\OutboundMessage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Composing, sending and scheduling an e-mail, and the Message-ID every one gets.
 *
 * @covers \OCA\OpenRegister\Controller\MessageDispatchController
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\MessageIdMinter
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\OutboundMessage
 */
class MessageDispatchEmailTest extends TestCase {

	private const MESSAGE_ID = '/^<[0-9a-f]{32}@mail\.zuiddrecht\.nl>$/';

	private const MAIL = ['to' => 'inwoner@example.org', 'subject' => 'Uw aanvraag', 'text' => 'Hallo'];

	private MessageDispatcherInterface&MockObject $dispatcher;

	private ScheduledMessageScheduler&MockObject $scheduler;

	protected function setUp(): void {
		$this->dispatcher = $this->createMock(MessageDispatcherInterface::class);
		$this->scheduler  = $this->createMock(ScheduledMessageScheduler::class);
	}

	private function send(array $params): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getServerHost')->willReturn('mail.zuiddrecht.nl:443');

		return (new MessageDispatchController($request, $this->dispatcher, $this->scheduler))->emailSend();
	}

	public function testAnEmailGoesThroughTheSmtpSourceWithAMintedMessageId(): void {
		$sent = null;
		$this->dispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
			static function (string $source, OutboundMessage $message) use (&$sent): array {
				$sent = [$source, $message];
				return ['status' => 'sent', 'source' => $source, 'response' => ['queued' => true], 'messageId' => $message->headers['Message-ID']];
			}
		);

		$response = $this->send(self::MAIL + ['html' => '<p>Hallo</p>', 'replyTo' => ' balie@zuiddrecht.nl ']);

		[$source, $message] = $sent;
		$this->assertSame('smtp-mail', $source);
		$this->assertSame('', $message->path);
		$this->assertSame(
			['to' => ['inwoner@example.org'], 'subject' => 'Uw aanvraag', 'text' => 'Hallo', 'html' => '<p>Hallo</p>', 'replyTo' => 'balie@zuiddrecht.nl'],
			$message->body
		);
		$this->assertMatchesRegularExpression(self::MESSAGE_ID, $message->headers['Message-ID']);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame($message->headers['Message-ID'], $response->getData()['messageId']);
	}

	public function testEveryEmailGetsItsOwnMessageId(): void {
		$ids = [];
		$this->dispatcher->method('dispatch')->willReturnCallback(
			static function (string $source, OutboundMessage $message) use (&$ids): array {
				$ids[] = $message->headers['Message-ID'];
				return ['status' => 'sent'];
			}
		);

		$this->send(self::MAIL);
		$this->send(self::MAIL);

		$this->assertCount(2, array_unique($ids));
	}

	public function testSeveralRecipientsAndAPathAreKept(): void {
		$this->dispatcher->expects($this->once())->method('dispatch')
			->with('smtp-mail', $this->callback(static fn (OutboundMessage $m): bool => $m->path === 'send' && $m->body['to'] === ['a@example.org', 'b@example.org']))
			->willReturn(['status' => 'sent']);

		$this->assertSame(200, $this->send(['to' => ['a@example.org', ' b@example.org'], 'path' => ' send '] + self::MAIL)->getStatus());
	}

	/**
	 * @return array<string, array{0: array<string,mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'no recipient' => [['to' => null], 'to must be an e-mail address or a list of them'],
			'not an address' => [['to' => 'inwoner'], 'to must be an e-mail address or a list of them'],
			'one bad address in a list' => [['to' => ['a@example.org', 'nope']], 'to must be an e-mail address or a list of them'],
			'an empty list' => [['to' => []], 'to must be an e-mail address or a list of them'],
			'no subject' => [['subject' => '  '], 'subject is required'],
			'no text' => [['text' => null], 'text is required'],
			'a text that is not text' => [['text' => ['x']], 'text is required'],
			'a bad reply address' => [['replyTo' => 'balie'], 'replyTo must be an e-mail address'],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testAMalformedEmailIsRefusedAndNothingLeaves(array $override, string $error): void {
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->scheduler->expects($this->never())->method('schedule');

		$response = $this->send(array_merge(self::MAIL, $override));

		$this->assertSame([400, ['error' => $error]], [$response->getStatus(), $response->getData()]);
	}

	public function testADegradedSourceIs503WithItsCause(): void {
		$this->dispatcher->method('dispatch')->willReturn(['unavailable' => true, 'cause' => 'openconnector_source_missing']);

		$response = $this->send(self::MAIL);

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['cause' => 'openconnector_source_missing'], $response->getData()['details']);
	}

	public function testAScheduledEmailStoresTheMessageIdItWillLeaveWith(): void {
		$this->dispatcher->expects($this->never())->method('dispatch');
		$stored = null;
		$this->scheduler->expects($this->once())->method('schedule')->willReturnCallback(
			static function (array $message) use (&$stored): array {
				$stored = $message;
				return ['id' => '9', 'state' => 'pending'];
			}
		);

		$response = $this->send(self::MAIL + ['sendAt' => '2026-11-16T08:00:00+01:00']);

		$this->assertSame('email', $stored['channel']);
		$this->assertSame('smtp-mail', $stored['source']);
		$this->assertSame(['to' => ['inwoner@example.org'], 'subject' => 'Uw aanvraag', 'text' => 'Hallo'], $stored['body']);
		$this->assertMatchesRegularExpression(self::MESSAGE_ID, $stored['headers']['Message-ID']);
		$this->assertSame(202, $response->getStatus());
		$this->assertSame(['id' => '9', 'state' => 'pending', 'messageId' => $stored['headers']['Message-ID']], $response->getData());
	}

	public function testAPastSendAtIsRefusedWithoutAMessageId(): void {
		$this->scheduler->method('schedule')->willThrowException(new InvalidArgumentException('sendAt must be in the future'));

		$response = $this->send(self::MAIL + ['sendAt' => '2020-01-01T08:00:00+01:00']);

		$this->assertSame([400, ['error' => 'sendAt must be in the future']], [$response->getStatus(), $response->getData()]);
	}
}//end class
