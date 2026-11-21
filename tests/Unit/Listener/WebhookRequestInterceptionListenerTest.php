<?php

/**
 * Tests for WebhookRequestInterceptionListener: the interception webhooks answer an object write request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectWriteRequestEvent;
use OCA\OpenRegister\Listener\WebhookRequestInterceptionListener;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\WebhookRequestInterceptionListener
 */
class WebhookRequestInterceptionListenerTest extends TestCase {

	/**
	 * The body becomes what interceptRequest answers for the request and write.
	 *
	 * @return void
	 */
	public function testTheBodyBecomesTheInterceptionAnswer(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(['title' => 'x']);
		$event = new ObjectWriteRequestEvent(request: $request, eventType: 'object.creating');

		$webhooks = $this->createMock(originalClassName: WebhookService::class);
		$webhooks->expects($this->once())->method('interceptRequest')
			->with($request, 'object.creating')
			->willReturn(['title' => 'y']);

		(new WebhookRequestInterceptionListener(webhookService: $webhooks))->handle($event);

		$this->assertSame(expected: ['title' => 'y'], actual: $event->getBody());
	}//end testTheBodyBecomesTheInterceptionAnswer()

	/**
	 * A failing interception reaches the dispatcher's caller, which decides what happens.
	 *
	 * @return void
	 */
	public function testAFailingInterceptionIsNotSwallowed(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn([]);
		$webhooks = $this->createMock(originalClassName: WebhookService::class);
		$webhooks->method('interceptRequest')->willThrowException(new RuntimeException('down'));

		$this->expectException(exception: RuntimeException::class);
		$event = new ObjectWriteRequestEvent(request: $request, eventType: 'object.creating');
		(new WebhookRequestInterceptionListener(webhookService: $webhooks))->handle($event);
	}//end testAFailingInterceptionIsNotSwallowed()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$webhooks = $this->createMock(originalClassName: WebhookService::class);
		$webhooks->expects($this->never())->method('interceptRequest');

		(new WebhookRequestInterceptionListener(webhookService: $webhooks))->handle(new Event());
		$this->addToAssertionCount(count: 1);
	}//end testAnotherEventIsIgnored()
}//end class
