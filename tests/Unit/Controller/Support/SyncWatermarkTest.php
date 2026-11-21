<?php

/**
 * The list read's sync watermark (incremental-sync-for-api-consumers REQ-SYNC-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use DateTimeImmutable;
use OCA\OpenRegister\Controller\Support\SyncWatermark;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\Support\SyncWatermark
 */
class SyncWatermarkTest extends TestCase {

	public function testTheValueIsIso8601InUtcTruncatedToTheSecond(): void {
		$watermark = new SyncWatermark(new DateTimeImmutable('2026-11-14T10:15:30.987654+02:00'));

		$this->assertSame('2026-11-14T08:15:30+00:00', $watermark->value());
	}

	public function testWithoutAMomentItIsNow(): void {
		$before = time();
		$value  = (new SyncWatermark())->value();
		$after  = time();

		$at = (new DateTimeImmutable($value))->getTimestamp();
		$this->assertGreaterThanOrEqual($before, $at);
		$this->assertLessThanOrEqual($after, $at);
		$this->assertStringEndsWith('+00:00', $value);
	}

	public function testAListEnvelopeIsStampedAndKeepsItsFields(): void {
		$watermark = new SyncWatermark(new DateTimeImmutable('2026-11-14T08:00:00+00:00'));
		$response  = new JSONResponse(data: ['results' => [], 'total' => 0]);

		$stamped = $watermark->stamp(response: $response);

		$this->assertSame($response, $stamped);
		$this->assertSame(['results' => [], 'total' => 0, 'syncWatermark' => '2026-11-14T08:00:00+00:00'], $stamped->getData());
	}

	public function testAnErrorOrANonEnvelopeIsLeftAlone(): void {
		$watermark = new SyncWatermark(new DateTimeImmutable('2026-11-14T08:00:00+00:00'));

		$error = new JSONResponse(data: ['results' => [], 'error' => 'x'], statusCode: 500);
		$this->assertArrayNotHasKey('syncWatermark', $watermark->stamp(response: $error)->getData());

		$notFound = new JSONResponse(data: ['results' => []], statusCode: 404);
		$this->assertArrayNotHasKey('syncWatermark', $watermark->stamp(response: $notFound)->getData());

		$jsonLd = new JSONResponse(data: ['@context' => 'x', 'member' => []]);
		$this->assertSame(['@context' => 'x', 'member' => []], $watermark->stamp(response: $jsonLd)->getData());

		$list = new JSONResponse(data: [1, 2]);
		$this->assertSame([1, 2], $watermark->stamp(response: $list)->getData());
	}
}
