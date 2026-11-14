<?php

/**
 * Scheduling on the send endpoints, and an object's message list and cancel routes.
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
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\MessageDispatchController;
use OCA\OpenRegister\Controller\ScheduledMessagesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageRefusal;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageScheduler;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\MessageDispatchController
 * @covers \OCA\OpenRegister\Controller\ScheduledMessagesController
 * @uses   \OCA\OpenRegister\Notifications\Controller\Support\ReadableObject
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageRefusal
 */
class ScheduledSendControllersTest extends TestCase {

	private ScheduledMessageScheduler&MockObject $scheduler;

	private MessageDispatcherInterface&MockObject $dispatcher;

	protected function setUp(): void {
		$this->scheduler = $this->createMock(ScheduledMessageScheduler::class);
		$this->dispatcher = $this->createMock(MessageDispatcherInterface::class);
	}

	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		return $request;
	}

	/**
	 * Objects the caller may read: case-1 resolves, anything else throws.
	 */
	private function objects(): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$current = null;
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('setObject')->willReturnCallback(
			function ($id) use (&$current, $objects) {
				if ($id !== 'case-1') {
					throw new RuntimeException('not readable');
				}

				$current = new ObjectEntity();
				$current->setUuid('case-1');
				return $objects;
			}
		);
		$objects->method('getObject')->willReturnCallback(static function () use (&$current): ?ObjectEntity {
			return $current;
		});

		return $objects;
	}

	private function send(array $params, bool $withScheduler = true): \OCP\AppFramework\Http\JSONResponse {
		$controller = new MessageDispatchController(
			$this->request($params),
			$this->dispatcher,
			($withScheduler === true) ? $this->scheduler : null,
			$this->objects()
		);

		return $controller->smsSend();
	}

	private const SEND = ['source' => 'twilio-sms', 'path' => 'Messages.json', 'body' => ['Body' => 'Hi'], 'headers' => ['X-A' => 1]];

	public function testASendWithSendAtIsScheduledNotSentAndAnswers202(): void {
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->scheduler->expects($this->once())->method('schedule')
			->with(
				['channel' => 'sms', 'source' => 'twilio-sms', 'path' => 'Messages.json', 'body' => ['Body' => 'Hi'], 'headers' => ['X-A' => '1'], 'sendAt' => '2026-11-16T08:00:00+01:00'],
				$this->callback(static fn (?ObjectEntity $object): bool => $object?->getUuid() === 'case-1'),
				$this->anything()
			)
			->willReturn(['id' => '5', 'state' => 'pending']);

		$response = $this->send(self::SEND + ['sendAt' => '2026-11-16T08:00:00+01:00', 'object' => ['register' => 'zaken', 'schema' => 'zaak', 'id' => 'case-1']]);

		$this->assertSame([202, ['id' => '5', 'state' => 'pending']], [$response->getStatus(), $response->getData()]);
	}

	public function testAnUnboundScheduledSendPassesNoObject(): void {
		$this->scheduler->expects($this->once())->method('schedule')->with($this->anything(), null, $this->anything())->willReturn(['id' => '1']);

		$this->assertSame(202, $this->send(self::SEND + ['sendAt' => '2026-11-16T08:00:00+01:00'])->getStatus());
	}

	public function testAPastSendAtIsABadRequest(): void {
		$this->scheduler->method('schedule')->willThrowException(new InvalidArgumentException('sendAt must be in the future'));

		$response = $this->send(self::SEND + ['sendAt' => '2020-01-01T00:00:00+00:00']);

		$this->assertSame([400, ['error' => 'sendAt must be in the future']], [$response->getStatus(), $response->getData()]);
	}

	public function testAnObjectTheCallerMayNotReadIsNotFoundAndNothingIsScheduled(): void {
		$this->scheduler->expects($this->never())->method('schedule');

		$response = $this->send(self::SEND + ['sendAt' => '2026-11-16T08:00:00+01:00', 'object' => ['register' => 'zaken', 'schema' => 'zaak', 'id' => 'secret']]);

		$this->assertSame(404, $response->getStatus());
	}

	public function testAnIncompleteObjectIsABadRequest(): void {
		$this->scheduler->expects($this->never())->method('schedule');

		$response = $this->send(self::SEND + ['sendAt' => '2026-11-16T08:00:00+01:00', 'object' => ['register' => 'zaken', 'id' => 'case-1']]);

		$this->assertSame([400, ['error' => 'object must name register, schema and id']], [$response->getStatus(), $response->getData()]);
	}

	public function testWithoutTheSchedulerAScheduledSendIsRefusedNotSentNow(): void {
		$this->dispatcher->expects($this->never())->method('dispatch');

		$response = $this->send(self::SEND + ['sendAt' => '2026-11-16T08:00:00+01:00'], withScheduler: false);

		$this->assertSame([503, ['error' => 'scheduled sending is not available']], [$response->getStatus(), $response->getData()]);
	}

	public function testASendWithoutSendAtIsSentNowAsBefore(): void {
		$this->scheduler->expects($this->never())->method('schedule');
		$this->dispatcher->expects($this->once())->method('dispatch')->willReturn(['sid' => 'SM1']);

		$response = $this->send(self::SEND);

		$this->assertSame([200, ['sid' => 'SM1']], [$response->getStatus(), $response->getData()]);
	}

	private function messages(): ScheduledMessagesController {
		return new ScheduledMessagesController($this->request([]), $this->scheduler, $this->objects());
	}

	public function testTheListAnswersTheObjectsMessages(): void {
		$this->scheduler->expects($this->once())->method('forObject')
			->with($this->callback(static fn (ObjectEntity $o): bool => $o->getUuid() === 'case-1'))
			->willReturn([['id' => '1', 'state' => 'pending']]);

		$response = $this->messages()->index('zaken', 'zaak', 'case-1');

		$this->assertSame(['results' => [['id' => '1', 'state' => 'pending']], 'total' => 1], $response->getData());
	}

	public function testListAndCancelOnAnObjectTheCallerMayNotReadAreNotFound(): void {
		$this->scheduler->expects($this->never())->method('forObject');
		$this->scheduler->expects($this->never())->method('cancel');

		$this->assertSame(404, $this->messages()->index('zaken', 'zaak', 'secret')->getStatus());
		$this->assertSame(404, $this->messages()->cancel('zaken', 'zaak', 'secret', '1')->getStatus());
	}

	public function testCancelAnswersTheMessageOrTheRefusalsStatus(): void {
		$this->scheduler->method('cancel')->willReturnCallback(
			static function (ObjectEntity $object, string $id): array {
				if ($id === '2') {
					throw new ScheduledMessageRefusal('This message can no longer be cancelled: it reads sent', 409);
				}

				return ['id' => $id, 'state' => 'cancelled'];
			}
		);

		$this->assertSame(['id' => '1', 'state' => 'cancelled'], $this->messages()->cancel('zaken', 'zaak', 'case-1', '1')->getData());
		$refused = $this->messages()->cancel('zaken', 'zaak', 'case-1', '2');
		$this->assertSame([409, ['message' => 'This message can no longer be cancelled: it reads sent']], [$refused->getStatus(), $refused->getData()]);
	}
}
