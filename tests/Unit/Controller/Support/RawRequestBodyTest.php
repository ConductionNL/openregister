<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\RawRequestBody;
use PHPUnit\Framework\TestCase;

/**
 * The raw body is read as sent, and never past the limit asked for.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
class RawRequestBodyTest extends TestCase {

	public function testTheBodyIsReadExactlyAsSent(): void {
		$body = new RawRequestBody(stream: 'data://text/plain,' . rawurlencode('{"a": 1}'));

		$this->assertSame('{"a": 1}', $body->read(max: 100));
	}//end testTheBodyIsReadExactlyAsSent()

	public function testReadingStopsAtTheLimit(): void {
		$body = new RawRequestBody(stream: 'data://text/plain,' . str_repeat('x', 50));

		$this->assertSame(10, strlen($body->read(max: 10)));
	}//end testReadingStopsAtTheLimit()
}//end class
