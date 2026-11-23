<?php

/**
 * Tests for ObjectWriteRequestEvent: the body starts as the request parameters and a listener may replace it.
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

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Event\ObjectWriteRequestEvent;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Event\ObjectWriteRequestEvent
 */
class ObjectWriteRequestEventTest extends TestCase {

	/**
	 * Without a listener the body is the request parameters, and the request and write are reported.
	 *
	 * @return void
	 */
	public function testTheBodyStartsAsTheRequestParameters(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(['title' => 'x']);

		$event = new ObjectWriteRequestEvent(request: $request, eventType: 'object.creating');

		$this->assertSame(expected: ['title' => 'x'], actual: $event->getBody());
		$this->assertSame(expected: $request, actual: $event->getRequest());
		$this->assertSame(expected: 'object.creating', actual: $event->getEventType());
	}//end testTheBodyStartsAsTheRequestParameters()

	/**
	 * A listener's body replaces the request parameters.
	 *
	 * @return void
	 */
	public function testAListenerReplacesTheBody(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn(['title' => 'x']);
		$event = new ObjectWriteRequestEvent(request: $request, eventType: 'object.creating');

		$event->setBody(['title' => 'y']);

		$this->assertSame(expected: ['title' => 'y'], actual: $event->getBody());
	}//end testAListenerReplacesTheBody()
}//end class
