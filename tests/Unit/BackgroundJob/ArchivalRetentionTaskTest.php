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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArchivalRetentionTaskTest extends TestCase {
	private ArchivalRetentionTask $task;

	private IDBConnection&MockObject $db;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private MagicMapper&MockObject $magicMapper;
	private ObjectService&MockObject $objectService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->task = new ArchivalRetentionTask(
			$timeFactory,
			$this->db,
			$this->registerMapper,
			$this->schemaMapper,
			$this->magicMapper,
			$this->objectService,
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
}
