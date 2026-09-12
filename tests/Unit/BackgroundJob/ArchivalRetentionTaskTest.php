<?php

declare(strict_types=1);

/**
 * Unit tests for ArchivalRetentionTask — covers task 5.7 of
 * `add-archival-annotation-support`: feed a sweep with a known row backdated
 * past retention → sweep deletes it; row within retention → kept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5-7
 */

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ArchivalRetentionTask;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ArchivalRetentionTaskTest extends TestCase {
	private ArchivalRetentionTask $task;

	private IDBConnection&MockObject $db;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private MagicMapper&MockObject $magicMapper;
	private ObjectService&MockObject $objectService;
	private RetentionService&MockObject $retentionService;
	private IGroupManager&MockObject $groupManager;
	private INotificationManager&MockObject $notificationManager;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->retentionService = $this->createMock(RetentionService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === IGroupManager::class) {
					return $this->groupManager;
				}

				return $this->notificationManager;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->task = new ArchivalRetentionTask(
			$timeFactory,
			$this->db,
			$this->registerMapper,
			$this->schemaMapper,
			$this->magicMapper,
			$this->objectService,
			$this->retentionService,
			$this->container,
			$this->logger,
		);
	}

	private function runTask(mixed $argument = null): void {
		$reflection = new \ReflectionClass($this->task);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->task, $argument);
	}

	/**
	 * Build a Schema with the supplied id/slug + archival annotation.
	 */
	private function buildSchema(int $id, string $slug, array $archival): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle($slug);
		$schema->setConfiguration(['x-openregister-archival' => $archival]);
		return $schema;
	}

	/**
	 * Build a Register pointing at the supplied schema id.
	 */
	private function buildRegister(int $id, int $schemaId): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug('call-logs');
		$register->setTitle('Call Logs');
		$register->setSchemas([$schemaId]);
		return $register;
	}

	/**
	 * Wire the DB scaffolding to return $rows from the table scan.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function wireRowsFetch(array $rows): IResult&MockObject {
		$cursor = $this->createMock(IResult::class);
		$queryBuilder = $this->createMock(IQueryBuilder::class);

		$queryBuilder->method('select')->willReturnSelf();
		$queryBuilder->method('from')->willReturnSelf();
		$queryBuilder->method('executeQuery')->willReturn($cursor);

		// First N calls return rows, last call returns false (cursor exhausted).
		$sequence = $rows;
		$sequence[] = false;

		$cursor->method('fetch')->willReturnOnConsecutiveCalls(...$sequence);

		$this->db->method('getQueryBuilder')->willReturn($queryBuilder);

		return $cursor;
	}

	/**
	 * Wire MagicMapper::find() to return a real ObjectEntity per uuid.
	 *
	 * @param array<string, array<string, mixed>|null> $retentionByUuid Retention block per uuid.
	 */
	private function wireObjectLookup(array $retentionByUuid): void {
		$this->magicMapper->method('find')->willReturnCallback(
			static function (string|int $identifier) use ($retentionByUuid): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid((string)$identifier);
				$object->setRetention($retentionByUuid[(string)$identifier] ?? []);

				return $object;
			}
		);
	}

	/**
	 * Wire RetentionService::hasActiveLegalHold() to the REAL predicate shape,
	 * so a task that stopped asking would fail here rather than agree with a
	 * stub that always answers false.
	 */
	private function wireRealHoldPredicate(): void {
		$this->retentionService->method('hasActiveLegalHold')->willReturnCallback(
			static function (ObjectEntity $object): bool {
				$retention = ($object->getRetention() ?? []);

				return (($retention['legalHold']['active'] ?? false) === true);
			}
		);
	}

	/**
	 * Task 5.7 — happy path: a row backdated past retention gets deleted; a
	 * row within retention is preserved.
	 */
	public function testRunDeletesExpiredAndKeepsLiveRow(): void {
		$register = $this->buildRegister(1, 42);
		$schema = $this->buildSchema(
			42,
			'call_log',
			[
				'retention' => [
					'default' => 'P30D',
				],
			],
		);

		$this->registerMapper->expects($this->once())
			->method('findAll')
			->willReturn([$register]);

		$this->schemaMapper->expects($this->once())
			->method('find')
			->with(42)
			->willReturn($schema);

		$this->magicMapper->expects($this->once())
			->method('tableExistsForRegisterSchema')
			->willReturn(true);

		// Row 1 is 90 days old → expired (default P30D). Row 2 is 5 days old → live.
		$oldRow = [
			'_uuid' => 'expired-uuid-aaa',
			'_created' => (new \DateTimeImmutable())->modify('-90 days')->format('Y-m-d H:i:s'),
			'message' => 'old',
		];

		$liveRow = [
			'_uuid' => 'live-uuid-bbb',
			'_created' => (new \DateTimeImmutable())->modify('-5 days')->format('Y-m-d H:i:s'),
			'message' => 'fresh',
		];

		$cursor = $this->wireRowsFetch([$oldRow, $liveRow]);
		$cursor->expects($this->once())->method('closeCursor');

		$this->wireObjectLookup(['expired-uuid-aaa' => []]);
		$this->wireRealHoldPredicate();

		// Expect ObjectService to be re-anchored + the expired row deleted exactly once.
		$this->objectService->expects($this->once())
			->method('setRegister')
			->with($register);

		$this->objectService->expects($this->once())
			->method('setSchema')
			->with($schema);

		$this->objectService->expects($this->once())
			->method('deleteObject')
			->with(
				'expired-uuid-aaa',
				null,
				null,
				false,
				false,
				true,
			)
			->willReturn(true);

		// Summary log line includes the per-schema counters.
		$loggedSummary = null;
		$this->logger->method('info')
			->willReturnCallback(function (string $message, array $context) use (&$loggedSummary): void {
				if (str_contains($message, 'ArchivalRetentionTask') === true) {
					$loggedSummary = $context;
				}
			});

		$this->runTask();

		$this->assertNotNull($loggedSummary, 'Summary log entry must fire.');
		$this->assertSame('call_log', $loggedSummary['schemaSlug']);
		$this->assertSame(2, $loggedSummary['scanned']);
		$this->assertSame(1, $loggedSummary['expired']);
		$this->assertSame(1, $loggedSummary['deleted']);
		$this->assertSame(0, $loggedSummary['held']);
		$this->assertSame(0, $loggedSummary['unresolved']);
	}

	/**
	 * Task 5.7 — the inverse case: when every row is within retention, no
	 * delete fires and the summary records zero expired / deleted.
	 */
	public function testRunKeepsAllLiveRows(): void {
		$register = $this->buildRegister(1, 42);
		$schema = $this->buildSchema(
			42,
			'call_log',
			[
				'retention' => [
					'default' => 'P30D',
				],
			],
		);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);

		$row = [
			'_uuid' => 'live-uuid',
			'_created' => (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s'),
			'message' => 'still relevant',
		];

		$this->wireRowsFetch([$row]);

		$this->objectService->expects($this->never())->method('deleteObject');

		$loggedSummary = null;
		$this->logger->method('info')
			->willReturnCallback(function (string $message, array $context) use (&$loggedSummary): void {
				if (str_contains($message, 'ArchivalRetentionTask') === true) {
					$loggedSummary = $context;
				}
			});

		$this->runTask();

		$this->assertNotNull($loggedSummary);
		$this->assertSame(1, $loggedSummary['scanned']);
		$this->assertSame(0, $loggedSummary['expired']);
		$this->assertSame(0, $loggedSummary['deleted']);
	}

	/**
	 * Schemas without an archival annotation should be skipped entirely — no
	 * magic-table lookup, no row scan, no log entry.
	 */
	public function testRunSkipsSchemasWithoutArchivalAnnotation(): void {
		$register = $this->buildRegister(1, 99);
		$schema = new Schema();
		$schema->setId(99);
		$schema->setSlug('not_archival');
		$schema->setConfiguration([]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->magicMapper->expects($this->never())->method('tableExistsForRegisterSchema');
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->runTask();
	}

	/**
	 * THE DEFECT THIS TEST EXISTS FOR: an expired row under an active legal
	 * hold must not be deleted by the sweep.
	 *
	 * This is the one delete path that is allowed to destroy a record on an
	 * archival schema, and it does so by handing `_retentionSweep: true` to
	 * ObjectService, which is exactly the flag that waves the row past the
	 * archival immutability gate. So a hold that is not asked about here is not
	 * asked about anywhere on this path.
	 *
	 * @return void
	 */
	public function testAnExpiredRowUnderLegalHoldIsNotDeleted(): void {
		$register = $this->buildRegister(1, 42);
		$schema = $this->buildSchema(42, 'call_log', ['retention' => ['default' => 'P30D']]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);

		$heldRow = [
			'_uuid' => 'held-uuid-ccc',
			'_created' => (new \DateTimeImmutable())->modify('-90 days')->format('Y-m-d H:i:s'),
			'message' => 'evidence in a pending case',
		];

		$this->wireRowsFetch([$heldRow]);
		$this->wireObjectLookup(
			[
				'held-uuid-ccc' => [
					'legalHold' => [
						'active' => true,
						'reason' => 'Pending court case',
						'placedBy' => 'archivaris',
					],
				],
			]
		);
		$this->wireRealHoldPredicate();

		$this->objectService->expects($this->never())->method('deleteObject');

		$this->runTask();
	}

	/**
	 * SKIPPING MUST BE VISIBLE. A held row is counted under its own key and
	 * never under `deleted`, and the archivist group is told.
	 *
	 * A sweep that quietly passes over held records, with no count and no
	 * signal, is how somebody later concludes the sweep is broken.
	 *
	 * @return void
	 */
	public function testAHeldRowIsCountedSeparatelyAndNotified(): void {
		$register = $this->buildRegister(1, 42);
		$schema = $this->buildSchema(42, 'call_log', ['retention' => ['default' => 'P30D']]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);

		$expiredAt = (new \DateTimeImmutable())->modify('-90 days')->format('Y-m-d H:i:s');
		$this->wireRowsFetch(
			[
				['_uuid' => 'held-uuid', '_created' => $expiredAt, 'message' => 'held'],
				['_uuid' => 'free-uuid', '_created' => $expiredAt, 'message' => 'free'],
			]
		);
		$this->wireObjectLookup(
			[
				'held-uuid' => ['legalHold' => ['active' => true, 'reason' => 'Pending court case']],
				'free-uuid' => [],
			]
		);
		$this->wireRealHoldPredicate();

		$this->objectService->expects($this->once())
			->method('deleteObject')
			->with('free-uuid', null, null, false, false, true)
			->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anita');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		$this->groupManager->method('get')->with('archivaris')->willReturn($group);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify')->with($notification);

		$loggedSummary = null;
		$this->logger->method('info')
			->willReturnCallback(function (string $message, array $context) use (&$loggedSummary): void {
				if (str_contains($message, 'ArchivalRetentionTask] schema=') === true) {
					$loggedSummary = $context;
				}
			});

		$this->runTask();

		$this->assertNotNull($loggedSummary, 'Summary log entry must fire.');
		$this->assertSame(2, $loggedSummary['scanned']);
		$this->assertSame(2, $loggedSummary['expired']);
		$this->assertSame(1, $loggedSummary['deleted']);
		$this->assertSame(1, $loggedSummary['held'], 'A held row must be counted, not swallowed.');
	}

	/**
	 * FAILS CLOSED: a row whose object cannot be loaded cannot be asked about
	 * its hold, so it is left alone and reported under `unresolved`.
	 *
	 * Deleting it anyway is the failure this whole change is about: the row
	 * that could not answer is exactly the row that might be held.
	 *
	 * @return void
	 */
	public function testARowWhoseObjectCannotBeLoadedIsLeftAlone(): void {
		$register = $this->buildRegister(1, 42);
		$schema = $this->buildSchema(42, 'call_log', ['retention' => ['default' => 'P30D']]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->magicMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->wireRealHoldPredicate();

		$this->wireRowsFetch(
			[
				[
					'_uuid' => 'ghost-uuid',
					'_created' => (new \DateTimeImmutable())->modify('-90 days')->format('Y-m-d H:i:s'),
					'message' => 'ghost',
				],
			]
		);

		$this->objectService->expects($this->never())->method('deleteObject');

		$loggedSummary = null;
		$this->logger->method('info')
			->willReturnCallback(function (string $message, array $context) use (&$loggedSummary): void {
				if (str_contains($message, 'ArchivalRetentionTask] schema=') === true) {
					$loggedSummary = $context;
				}
			});

		$this->runTask();

		$this->assertNotNull($loggedSummary);
		$this->assertSame(1, $loggedSummary['expired']);
		$this->assertSame(0, $loggedSummary['deleted']);
		$this->assertSame(1, $loggedSummary['unresolved']);
	}

	/**
	 * AN ABSENT `_created` IS NOT DUE NOW. A row with no creation timestamp
	 * carries no computable expiry, so the sweep must leave it alone rather
	 * than treat the missing value as "expired at the epoch".
	 *
	 * @return void
	 */
	public function testARowWithoutACreatedTimestampIsNeverSwept(): void {
		$register = $this->buildRegister(1, 42);
		$schema = $this->buildSchema(42, 'call_log', ['retention' => ['default' => 'P30D']]);

		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);
		$this->wireRealHoldPredicate();

		$this->wireRowsFetch([['_uuid' => 'dateless-uuid', '_created' => null, 'message' => 'no date']]);

		$this->objectService->expects($this->never())->method('deleteObject');

		$loggedSummary = null;
		$this->logger->method('info')
			->willReturnCallback(function (string $message, array $context) use (&$loggedSummary): void {
				if (str_contains($message, 'ArchivalRetentionTask] schema=') === true) {
					$loggedSummary = $context;
				}
			});

		$this->runTask();

		$this->assertNotNull($loggedSummary);
		$this->assertSame(1, $loggedSummary['scanned']);
		$this->assertSame(0, $loggedSummary['expired']);
		$this->assertSame(0, $loggedSummary['deleted']);
	}
}
