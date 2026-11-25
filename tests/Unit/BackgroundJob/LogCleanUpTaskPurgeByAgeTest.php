<?php

/**
 * LogCleanUpTask purge-by-age tests: expired audit trail entries go, newer ones stay, the run counts them, and a record's own retention is a floor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\LogCleanUpTask;
use OCA\OpenRegister\BackgroundJob\Support\LogCleanUpTrails;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SearchTrailCleanupInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RetentionSettingsInterface;
use OCA\OpenRegister\Service\AuditRetentionResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/audit-trail-purge-by-age/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-deletes-audit-trail-entries-past-the-configured-retention
 */
#[CoversClass(LogCleanUpTask::class)]
#[CoversClass(AuditTrailMapper::class)]
#[CoversClass(AuditRetentionResolver::class)]
#[UsesClass(LogCleanUpTrails::class)]
#[UsesClass(AuditTrailMapperServices::class)]
#[UsesClass(ObjectEntity::class)]
class LogCleanUpTaskPurgeByAgeTest extends TestCase {

	/** @var list<array{string, mixed, mixed}> The conditions the purge statement was built with. */
	private array $conditions = [];

	/** @var list<string> The columns the purge statement writes. */
	private array $written = [];

	/**
	 * Only entries with a finite expiry in the past, not yet purged, are touched; the count is what the statement affected.
	 */
	public function testThePurgeTouchesOnlyEntriesWhoseExpiryHasPassedAndCountsThem(): void {
		$mapper = $this->mapperAffecting(rows: 4);

		$this->assertSame(4, $mapper->purgeExpiredLogs());
		$this->assertSame(
			[
				['isNotNull', 'expires', null],
				['lt', 'expires', 'NOW()'],
				['isNull', 'purged_at', null],
			],
			$this->conditions
		);
		$this->assertContains('purged_at', $this->written);
		$this->assertContains('changed', $this->written);
	}//end testThePurgeTouchesOnlyEntriesWhoseExpiryHasPassedAndCountsThem()

	/**
	 * clearLogs() keeps its yes/no contract over the count.
	 */
	public function testClearLogsAnswersWhetherAnythingWasPurged(): void {
		$this->assertTrue($this->mapperAffecting(rows: 2)->clearLogs());
		$this->assertFalse($this->mapperAffecting(rows: 0)->clearLogs());
	}//end testClearLogsAnswersWhetherAnythingWasPurged()

	/**
	 * The job records how many entries its run removed.
	 */
	public function testTheJobRecordsHowManyEntriesItRemoved(): void {
		$logged = $this->runJobPurging(count: 7);

		$this->assertSame('[LogCleanUpTask] Tombstoned 7 expired audit trail rows (payload purged, chain preserved)', $logged[0]['message']);
		$this->assertSame(7, $logged[0]['context']['purged']);
	}//end testTheJobRecordsHowManyEntriesItRemoved()

	/**
	 * A run with nothing past its expiry reports nothing removed and logs no purge.
	 */
	public function testARunWithNothingExpiredLogsNoPurge(): void {
		$logged = $this->runJobPurging(count: 0);

		$this->assertSame('[LogCleanUpTask] No expired audit trail logs found to clear', $logged[0]['message']);
		$this->assertArrayNotHasKey('purged', $logged[0]['context']);
	}//end testARunWithNothingExpiredLogsNoPurge()

	/**
	 * A record kept ten years keeps its update entry past one year, whatever the update log retention says.
	 *
	 * The expiry is stamped from the record's own retention when the entry is
	 * written, and the purge compares that expiry with now, so an entry one
	 * year old stays in place.
	 */
	public function testARecordsLongRetentionKeepsItsEntriesPastAShorterSetting(): void {
		$createdAt = new DateTimeImmutable('2025-11-24T00:00:00+00:00');
		$oneYearLater = $createdAt->add(new DateInterval('P1Y'));

		$resolved = $this->resolver()->resolve(
			object: $this->objectRetaining(retention: ['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P10Y']),
			createdAt: $createdAt
		);

		$this->assertSame('object.bewaartermijn', $resolved['source']);
		$this->assertEquals($createdAt->add(new DateInterval('P10Y')), $resolved['expires']);
		$this->assertGreaterThan($oneYearLater, $resolved['expires']);
	}//end testARecordsLongRetentionKeepsItsEntriesPastAShorterSetting()

	/**
	 * A retention shorter than the minimum lifetime is raised to it, so a young entry is never purged.
	 */
	public function testARetentionShorterThanTheMinimumIsRaisedToIt(): void {
		$createdAt = new DateTimeImmutable('2025-11-24T00:00:00+00:00');

		$resolved = $this->resolver()->resolve(
			object: $this->objectRetaining(retention: ['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P1D']),
			createdAt: $createdAt
		);

		$this->assertSame('object.bewaartermijn+floor', $resolved['source']);
		$this->assertEquals($createdAt->add(new DateInterval(AuditRetentionResolver::MINIMUM_LIFETIME)), $resolved['expires']);
		$this->assertGreaterThan($createdAt->add(new DateInterval('P29D')), $resolved['expires']);
	}//end testARetentionShorterThanTheMinimumIsRaisedToIt()

	/**
	 * A mapper whose purge statement affects the given number of rows, recording how it was built.
	 *
	 * @param int $rows The affected row count.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapperAffecting(int $rows): AuditTrailMapper {
		$this->conditions = [];
		$this->written    = [];

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['isNotNull', 'isNull', 'lt'] as $method) {
			$expr->method($method)->willReturnCallback(
				function (string $column, mixed $value = null) use ($method): string {
					$shown = $value instanceof IQueryFunction ? (string) $value : $value;
					$this->conditions[] = [$method, $column, $shown];
					return $method . ':' . $column;
				}
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('set')->willReturnCallback(
			function (string $column) use ($qb): IQueryBuilder {
				$this->written[] = $column;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturn(':param');
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('executeStatement')->willReturn($rows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new AuditTrailMapper(
			$db,
			new AuditTrailMapperServices(
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(IUserSession::class),
				$this->createMock(IRequest::class)
			)
		);
	}//end mapperAffecting()

	/**
	 * Run the job with an audit sweep that purges the given count, and return its audit log lines.
	 *
	 * @param int $count The entries the sweep purges.
	 *
	 * @return list<array{message: string, context: array<string, mixed>}>
	 */
	private function runJobPurging(int $count): array {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects($this->once())->method('purgeExpiredLogs')->willReturn($count);
		$audit->expects($this->never())->method('clearLogs');
		$retention = $this->createMock(RetentionSettingsInterface::class);
		$retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 0]);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$logged): void {
					if (str_contains($message, 'audit trail') === true) {
						$logged[] = ['message' => $message, 'context' => $context];
					}
				}
			);
		}

		$job = new LogCleanUpTask(
			$this->createMock(ITimeFactory::class),
			new LogCleanUpTrails($audit, $this->createMock(SearchTrailCleanupInterface::class)),
			$retention,
			$logger
		);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);

		$this->assertCount(1, $logged);
		return $logged;
	}//end runJobPurging()

	/**
	 * A resolver with no schema to fall back on.
	 *
	 * @return AuditRetentionResolver
	 */
	private function resolver(): AuditRetentionResolver {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		return new AuditRetentionResolver($container, new NullLogger());
	}//end resolver()

	/**
	 * A record carrying the given retention block.
	 *
	 * @param array<string, mixed> $retention The record's retention.
	 *
	 * @return ObjectEntity
	 */
	private function objectRetaining(array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('record-under-retention');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setRetention($retention);

		return $object;
	}//end objectRetaining()
}//end class
