<?php

/**
 * The database connection health gauge.
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

use DateTime;
use OCA\OpenRegister\Service\Observability\DatabaseHealthMetricsProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\DatabaseHealthMetricsProvider
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class DatabaseHealthMetricsProviderTest extends TestCase {

	/**
	 * A quick round-trip is healthy.
	 *
	 * @return void
	 */
	public function testAQuickRoundTripIsHealthy(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$metrics = (new DatabaseHealthMetricsProvider(
			db: $this->answeringDb(),
			time: $this->clock(start: '2026-11-14 12:00:00.000000', end: '2026-11-14 12:00:04.900000'),
			logger: $logger
		))->metrics();

		$this->assertCount(1, $metrics);
		$this->assertSame('db_connection_healthy', $metrics[0]->name);
		$this->assertSame('gauge', $metrics[0]->type);
		$this->assertSame([['labels' => [], 'value' => 1]], $metrics[0]->samples);
	}//end testAQuickRoundTripIsHealthy()

	/**
	 * A round-trip over five seconds is unhealthy and logged.
	 *
	 * @return void
	 */
	public function testASlowRoundTripIsUnhealthy(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Observability] database round-trip took 5100 ms');

		$metrics = (new DatabaseHealthMetricsProvider(
			db: $this->answeringDb(),
			time: $this->clock(start: '2026-11-14 12:00:00.000000', end: '2026-11-14 12:00:05.100000'),
			logger: $logger
		))->metrics();

		$this->assertSame([['labels' => [], 'value' => 0]], $metrics[0]->samples);
	}//end testASlowRoundTripIsUnhealthy()

	/**
	 * A failing round-trip is unhealthy and logged.
	 *
	 * @return void
	 */
	public function testAFailingRoundTripIsUnhealthy(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new RuntimeException('Connection refused'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14 12:00:00'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Observability] database round-trip failed: Connection refused');

		$metrics = (new DatabaseHealthMetricsProvider(db: $db, time: $time, logger: $logger))->metrics();

		$this->assertSame([['labels' => [], 'value' => 0]], $metrics[0]->samples);
	}//end testAFailingRoundTripIsUnhealthy()

	/**
	 * A connection that answers `SELECT 1` once.
	 *
	 * @return IDBConnection
	 */
	private function answeringDb(): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->expects($this->once())->method('fetch')->willReturn([1]);
		$result->expects($this->once())->method('closeCursor');
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('executeQuery')->with('SELECT 1')->willReturn($result);

		return $db;
	}//end answeringDb()

	/**
	 * A clock that reads the start, then the end.
	 *
	 * @param string $start The first reading.
	 * @param string $end   The second reading.
	 *
	 * @return ITimeFactory
	 */
	private function clock(string $start, string $end): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->expects($this->exactly(2))->method('getDateTime')->willReturnOnConsecutiveCalls(new DateTime($start), new DateTime($end));

		return $time;
	}//end clock()
}//end class
