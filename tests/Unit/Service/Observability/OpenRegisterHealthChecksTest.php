<?php

/**
 * OpenRegister's search index, webhook delivery and database latency health checks.
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
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Service\Observability\DatabaseHealthMetricsProvider;
use OCA\OpenRegister\Service\Observability\OpenRegisterHealthChecks;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\OpenRegisterHealthChecks
 * @uses   \OCA\OpenRegister\Service\Observability\DatabaseHealthMetricsProvider
 */
final class OpenRegisterHealthChecksTest extends TestCase {

	/**
	 * Every check passes: three ok results of degraded severity without a message.
	 *
	 * @return void
	 */
	public function testAllChecksPass(): void {
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->expects($this->once())
			->method('outcomesSince')
			->with($this->callback(static fn (DateTime $since): bool => $since->format('Y-m-d H:i:s') === '2026-11-15 11:45:00'))
			->willReturn(['successful' => 4, 'failed' => 2]);

		$this->assertSame(
			[
				'search_backend' => ['ok' => true, 'severity' => 'degraded', 'message' => ''],
				'webhooks' => ['ok' => true, 'severity' => 'degraded', 'message' => ''],
				'database_latency' => ['ok' => true, 'severity' => 'degraded', 'message' => ''],
			],
			$this->checks(db: $this->db(searchFails: false, roundTripFails: false), logs: $logs)->check()
		);
	}//end testAllChecksPass()

	/**
	 * The search table read reads one row of the chunk table.
	 *
	 * @return void
	 */
	public function testSearchProbeReadsOneChunkRow(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->once())->method('select')->with('id')->willReturnSelf();
		$qb->expects($this->once())->method('from')->with('openregister_chunks')->willReturnSelf();
		$qb->expects($this->once())->method('setMaxResults')->with(1)->willReturnSelf();
		$qb->expects($this->once())->method('executeQuery')->willReturn($this->createMock(IResult::class));

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('executeQuery')->willReturn($this->createMock(IResult::class));

		$this->assertTrue($this->checks(db: $db, logs: $this->quietLogs())->check()['search_backend']['ok']);
	}//end testSearchProbeReadsOneChunkRow()

	/**
	 * An unreachable search index fails its check with a short code and logs why.
	 *
	 * @return void
	 */
	public function testUnreachableSearchIndexIsReported(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('search backend probe failed: gone'));

		$result = $this->checks(
			db: $this->db(searchFails: true, roundTripFails: false),
			logs: $this->quietLogs(),
			logger: $logger
		)->check();

		$this->assertSame(['ok' => false, 'severity' => 'degraded', 'message' => 'unreachable'], $result['search_backend']);
		$this->assertTrue($result['database_latency']['ok']);
	}//end testUnreachableSearchIndexIsReported()

	/**
	 * Only failed attempts in the window fail the webhook check.
	 *
	 * @return void
	 */
	public function testOnlyFailedDeliveriesFailTheWebhookCheck(): void {
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->method('outcomesSince')->willReturn(['successful' => 0, 'failed' => 3]);

		$this->assertSame(
			['ok' => false, 'severity' => 'degraded', 'message' => 'deliveries failing'],
			$this->checks(db: $this->db(searchFails: false, roundTripFails: false), logs: $logs)->check()['webhooks']
		);
	}//end testOnlyFailedDeliveriesFailTheWebhookCheck()

	/**
	 * A window without any delivery attempt is healthy.
	 *
	 * @return void
	 */
	public function testAQuietWindowIsHealthy(): void {
		$this->assertTrue(
			$this->checks(db: $this->db(searchFails: false, roundTripFails: false), logs: $this->quietLogs())->check()['webhooks']['ok']
		);
	}//end testAQuietWindowIsHealthy()

	/**
	 * An unreadable webhook log counts as failing.
	 *
	 * @return void
	 */
	public function testAnUnreadableWebhookLogFails(): void {
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->method('outcomesSince')->willThrowException(new RuntimeException('locked'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('webhook log unreadable: locked'));

		$this->assertFalse(
			$this->checks(db: $this->db(searchFails: false, roundTripFails: false), logs: $logs, logger: $logger)->check()['webhooks']['ok']
		);
	}//end testAnUnreadableWebhookLogFails()

	/**
	 * A failed database round-trip fails the latency check.
	 *
	 * @return void
	 */
	public function testAFailedRoundTripFailsTheLatencyCheck(): void {
		$this->assertSame(
			['ok' => false, 'severity' => 'degraded', 'message' => 'slow or unavailable'],
			$this->checks(db: $this->db(searchFails: false, roundTripFails: true), logs: $this->quietLogs())->check()['database_latency']
		);
	}//end testAFailedRoundTripFailsTheLatencyCheck()

	/**
	 * The checks under test, with a fixed clock.
	 *
	 * @param IDBConnection             $db     The database.
	 * @param WebhookLogMapperInterface $logs   The webhook log.
	 * @param LoggerInterface|null      $logger The logger.
	 *
	 * @return OpenRegisterHealthChecks
	 */
	private function checks(IDBConnection $db, WebhookLogMapperInterface $logs, ?LoggerInterface $logger = null): OpenRegisterHealthChecks {
		$logger = ($logger ?? $this->createMock(LoggerInterface::class));
		$time   = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-15 12:00:00'));

		return new OpenRegisterHealthChecks(
			db: $db,
			logs: $logs,
			database: new DatabaseHealthMetricsProvider(db: $db, time: $time, logger: $logger),
			time: $time,
			logger: $logger
		);
	}//end checks()

	/**
	 * A webhook log without attempts in the window.
	 *
	 * @return WebhookLogMapperInterface
	 */
	private function quietLogs(): WebhookLogMapperInterface {
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->method('outcomesSince')->willReturn(['successful' => 0, 'failed' => 0]);
		return $logs;
	}//end quietLogs()

	/**
	 * A database whose search read and round-trip succeed or throw.
	 *
	 * @param bool $searchFails    The search table read throws.
	 * @param bool $roundTripFails The SELECT 1 round-trip throws.
	 *
	 * @return IDBConnection
	 */
	private function db(bool $searchFails, bool $roundTripFails): IDBConnection {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		if ($searchFails === true) {
			$qb->method('executeQuery')->willThrowException(new RuntimeException('gone'));
		} else {
			$qb->method('executeQuery')->willReturn($this->createMock(IResult::class));
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		if ($roundTripFails === true) {
			$db->method('executeQuery')->willThrowException(new RuntimeException('down'));
		} else {
			$db->method('executeQuery')->willReturn($this->createMock(IResult::class));
		}

		return $db;
	}//end db()
}//end class
