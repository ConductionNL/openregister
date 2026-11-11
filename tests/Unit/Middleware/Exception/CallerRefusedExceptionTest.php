<?php

/**
 * Characterisation test for CallerRefusedException: pins both refusals'
 * status, message, body and headers so a change to how they are built can be
 * shown to leave the response unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Middleware\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware\Exception;

use OCA\OpenRegister\Middleware\Exception\CallerRefusedException;
use OCA\OpenRegister\Middleware\Exception\CeilingBreach;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\Exception\CallerRefusedException
 */
class CallerRefusedExceptionTest extends TestCase {

	public function testOverLimitAnswersWithTheCeilingInBodyAndHeaders(): void {
		$refusal = CallerRefusedException::overCeiling(new CeilingBreach(5, 30, 1000, 990));
		$response = $refusal->toResponse();

		$this->assertSame(429, $refusal->getCode());
		$this->assertSame('This caller is limited to 5 calls per 30 seconds. The limit resets in 10 seconds.', $refusal->getMessage());
		$this->assertSame(429, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'This caller is limited to 5 calls per 30 seconds. The limit resets in 10 seconds.',
				'limit' => 5,
				'windowSeconds' => 30,
				'remaining' => 0,
				'resetAt' => '1970-01-01T00:16:40+00:00',
				'retryAfterSeconds' => 10,
			],
			$response->getData()
		);
		$headers = $response->getHeaders();
		$this->assertSame('10', $headers['Retry-After']);
		$this->assertSame('5', $headers['RateLimit-Limit']);
		$this->assertSame('0', $headers['RateLimit-Remaining']);
		$this->assertSame('10', $headers['RateLimit-Reset']);
	}

	public function testOverLimitNeverAsksForLessThanOneSecond(): void {
		$response = CallerRefusedException::overCeiling(new CeilingBreach(5, 60, 100, 200))->toResponse();

		$this->assertSame(1, $response->getData()['retryAfterSeconds']);
		$this->assertSame('1', $response->getHeaders()['Retry-After']);
	}

	public function testWrongAddressAnswersForbiddenWithoutCeilingHeaders(): void {
		$refusal = CallerRefusedException::wrongAddress();
		$response = $refusal->toResponse();

		$this->assertSame(403, $refusal->getCode());
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['error' => 'This caller is bound to a set of source addresses, and this call did not come from one of them.'],
			$response->getData()
		);
		$this->assertArrayNotHasKey('Retry-After', $response->getHeaders());
		$this->assertArrayNotHasKey('RateLimit-Limit', $response->getHeaders());
	}
}//end class
