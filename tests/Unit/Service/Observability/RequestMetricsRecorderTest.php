<?php

/**
 * The APCu recording path and the flush of the request metrics.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use OCA\OpenRegister\Service\Observability\ApcuCounters;
use OCA\OpenRegister\Service\Observability\RequestMetricsRecorder;
use OCA\OpenRegister\Service\Observability\RequestMetricsStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\RequestMetricsRecorder
 */
final class RequestMetricsRecorderTest extends TestCase {

	/**
	 * The increments made, in order.
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $increments = [];

	/**
	 * A request increments its count, its duration sum and its bucket.
	 *
	 * @return void
	 */
	public function testRecordIncrementsCountSumAndBucket(): void {
		$this->recorder(apcu: $this->apcu())->record(method: 'get', route: 'objects.index', status: 200, durationMs: 42);

		$this->assertSame(
			[
				['openregister_reqm:r|GET|objects.index|200', 1],
				['openregister_reqm:t|GET|objects.index|200', 42],
				['openregister_reqm:b|GET|objects.index|50', 1],
			],
			$this->increments
		);
	}//end testRecordIncrementsCountSumAndBucket()

	/**
	 * An unknown duration counts the request only; separators in a key part are replaced.
	 *
	 * @return void
	 */
	public function testWithoutADurationOnlyTheCountIsIncremented(): void {
		$this->recorder(apcu: $this->apcu())->record(method: 'POST', route: 'a|b c', status: 422, durationMs: null);

		$this->assertSame([['openregister_reqm:r|POST|a_b_c|422', 1]], $this->increments);
	}//end testWithoutADurationOnlyTheCountIsIncremented()

	/**
	 * Without APCu nothing is incremented.
	 *
	 * @return void
	 */
	public function testWithoutApcuNothingIsRecorded(): void {
		$apcu = $this->createMock(ApcuCounters::class);
		$apcu->method('available')->willReturn(false);
		$apcu->expects($this->never())->method('increment');

		$this->recorder(apcu: $apcu)->record(method: 'GET', route: 'x.y', status: 200, durationMs: 5);
	}//end testWithoutApcuNothingIsRecorded()

	/**
	 * An error increments its type counter.
	 *
	 * @return void
	 */
	public function testRecordErrorIncrementsItsType(): void {
		$this->recorder(apcu: $this->apcu())->recordError(type: 'database');

		$this->assertSame([['openregister_reqm:e|database', 1]], $this->increments);
	}//end testRecordErrorIncrementsItsType()

	/**
	 * A cache lookup increments its cache's hit or miss counter by the number of lookups.
	 *
	 * @return void
	 */
	public function testRecordCacheLookupIncrementsItsCacheAndOutcome(): void {
		$recorder = $this->recorder(apcu: $this->apcu());
		$recorder->recordCacheLookup(cache: 'object', hit: true);
		$recorder->recordCacheLookup(cache: 'name', hit: false, count: 3);

		$this->assertSame([['openregister_reqm:c|object|hit', 1], ['openregister_reqm:c|name|miss', 3]], $this->increments);
	}//end testRecordCacheLookupIncrementsItsCacheAndOutcome()

	/**
	 * Each duration lands in the smallest bound it does not exceed, above 10 s in `inf`.
	 *
	 * @return void
	 */
	public function testBucketOf(): void {
		$this->assertSame('10', RequestMetricsRecorder::bucketOf(durationMs: 0));
		$this->assertSame('10', RequestMetricsRecorder::bucketOf(durationMs: 10));
		$this->assertSame('50', RequestMetricsRecorder::bucketOf(durationMs: 11));
		$this->assertSame('10000', RequestMetricsRecorder::bucketOf(durationMs: 10000));
		$this->assertSame('inf', RequestMetricsRecorder::bucketOf(durationMs: 10001));
	}//end testBucketOf()

	/**
	 * A flush subtracts each taken value and writes one row per label set: request, bucket, error, cache.
	 *
	 * @return void
	 */
	public function testFlushMovesTheCountersIntoRows(): void {
		$taken = [
			'openregister_reqm:r|GET|objects.index|200' => 5,
			'openregister_reqm:t|GET|objects.index|200' => 300,
			'openregister_reqm:b|GET|objects.index|100' => 4,
			'openregister_reqm:b|GET|objects.index|inf' => 1,
			'openregister_reqm:e|validation' => 2,
			'openregister_reqm:c|object|hit' => 6,
			'openregister_reqm:c|name' => 8,
			'openregister_reqm:x|garbage' => 9,
		];
		$apcu       = $this->apcu(entries: $taken);
		$subtracted = [];
		$apcu->method('subtract')->willReturnCallback(
			function (string $key, int $amount) use (&$subtracted): void {
				$subtracted[$key] = $amount;
			}
		);

		$rows  = [];
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('add')->willReturnCallback(
			function (string $metricType, string $entityType, string $entityId, string $status, int $count, int $sumMs) use (&$rows): void {
				$rows[] = [$metricType, $entityType, $entityId, $status, $count, $sumMs];
			}
		);

		$this->assertSame(5, $this->recorder(apcu: $apcu, store: $store)->flush());
		$this->assertSame($taken, $subtracted);
		$this->assertSame(
			[
				['http_request', 'GET', 'objects.index', '200', 5, 300],
				['http_request_bucket', 'GET', 'objects.index', '100', 4, 0],
				['http_request_bucket', 'GET', 'objects.index', 'inf', 1, 0],
				['http_error', 'validation', '', '', 2, 0],
				['cache_lookup', 'object', '', 'hit', 6, 0],
			],
			$rows
		);
		$this->assertSame([], $this->increments);
	}//end testFlushMovesTheCountersIntoRows()

	/**
	 * A row the store refuses goes back into APCu, the others are written, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAFailedRowIsPutBack(): void {
		$apcu  = $this->apcu(entries: ['openregister_reqm:r|GET|a.b|200' => 3, 'openregister_reqm:t|GET|a.b|200' => 30, 'openregister_reqm:e|other' => 1]);
		$store = $this->createMock(RequestMetricsStore::class);
		$store->method('add')->willReturnCallback(
			static function (string $metricType): void {
				if ($metricType === 'http_request') {
					throw new RuntimeException('db down');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('db down'));

		$this->assertSame(1, $this->recorder(apcu: $apcu, store: $store, logger: $logger)->flush());
		$this->assertSame([['openregister_reqm:r|GET|a.b|200', 3], ['openregister_reqm:t|GET|a.b|200', 30]], $this->increments);
	}//end testAFailedRowIsPutBack()

	/**
	 * Only the request that claims the 60-second marker flushes.
	 *
	 * @return void
	 */
	public function testFlushIfDueFlushesOnlyWithTheMarker(): void {
		$apcu = $this->apcu(entries: ['openregister_reqm:e|other' => 1]);
		$apcu->expects($this->exactly(2))->method('claim')->with('openregister_reqm_flush', 60)->willReturnOnConsecutiveCalls(true, false);
		$store = $this->createMock(RequestMetricsStore::class);
		$store->expects($this->once())->method('add');

		$recorder = $this->recorder(apcu: $apcu, store: $store);
		$this->assertSame(1, $recorder->flushIfDue());
		$this->assertSame(0, $recorder->flushIfDue());
	}//end testFlushIfDueFlushesOnlyWithTheMarker()

	/**
	 * An available APCu double that records increments and lists the given entries.
	 *
	 * @param array<string, int> $entries The entries to list.
	 *
	 * @return ApcuCounters&MockObject
	 */
	private function apcu(array $entries = []): ApcuCounters {
		$apcu = $this->createMock(ApcuCounters::class);
		$apcu->method('available')->willReturn(true);
		$apcu->method('entries')->with('openregister_reqm:')->willReturn($entries);
		$apcu->method('increment')->willReturnCallback(
			function (string $key, int $amount): void {
				$this->increments[] = [$key, $amount];
			}
		);

		return $apcu;
	}//end apcu()

	/**
	 * The recorder under test.
	 *
	 * @param ApcuCounters             $apcu   The counters.
	 * @param RequestMetricsStore|null $store  The store.
	 * @param LoggerInterface|null     $logger The logger.
	 *
	 * @return RequestMetricsRecorder
	 */
	private function recorder(ApcuCounters $apcu, ?RequestMetricsStore $store = null, ?LoggerInterface $logger = null): RequestMetricsRecorder {
		return new RequestMetricsRecorder(
			apcu: $apcu,
			store: $store ?? $this->createMock(RequestMetricsStore::class),
			logger: $logger ?? $this->createMock(LoggerInterface::class)
		);
	}//end recorder()
}//end class
