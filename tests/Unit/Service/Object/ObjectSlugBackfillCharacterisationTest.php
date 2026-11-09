<?php

/**
 * ObjectSlugBackfillService characterisation tests.
 *
 * Pins the exact log lines, summaries, audit entries and cache evictions of
 * the slug backfill through its one public entry point, so a refactor of the
 * service, writer and ledger internals cannot change what a run does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\SlugBackfillWriteException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillLedger;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillWriter;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\BuildsObjectSlugHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of a slug backfill run.
 */
class ObjectSlugBackfillCharacterisationTest extends TestCase {
	use BuildsObjectSlugHandler;

	/** @var MagicMapper&MockObject */
	private MagicMapper $magicMapper;

	/** @var AuditTrailMapper&MockObject */
	private AuditTrailMapper $auditTrail;

	/** @var CacheHandler&MockObject */
	private CacheHandler $cacheHandler;

	/** @var list<array{string, string, array}> Every log call as [level, message, context]. */
	private array $logs = [];

	/** @var list<string> Every side effect, in order. */
	private array $events = [];

	private Register $register;

	private Schema $schema;

	private ObjectSlugBackfillService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->register = new Register();
		$this->register->setId(7);
		$this->register->setSlug('woo');
		$this->schema = new Schema();
		$this->schema->setId(11);
		$this->schema->setSlug('conv');
		$this->schema->setProperties(['title' => ['type' => 'string']]);
		$this->schema->setConfiguration(['objectSlugField' => 'title']);

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->auditTrail = $this->createMock(AuditTrailMapper::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->cacheHandler->method('invalidateForObjectChange')->willReturnCallback(
			function (...$args): void {
				$this->events[] = 'evict:' . json_encode($args);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context=[]) use ($level): void {
					$this->logs[] = [$level, (string)$message, $context];
				}
			);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->events[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->events[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->events[] = 'rollback';
		});

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register);

		$this->service = new ObjectSlugBackfillService(
			magicMapper: $this->magicMapper,
			slugHandler: $this->buildSlugHandler(
				magicMapper: $this->magicMapper,
				hydrator: new MetadataHydrationHandler(
					logger: $this->createMock(LoggerInterface::class),
					cacheHandler: $this->createMock(CacheHandler::class)
				),
				registerMapper: $registerMapper,
				logger: $logger
			),
			writer: new ObjectSlugBackfillWriter(
				magicMapper: $this->magicMapper,
				db: $db,
				ledger: new ObjectSlugBackfillLedger(cacheHandler: $this->cacheHandler, auditTrail: $this->auditTrail, logger: $logger),
				logger: $logger
			),
			logger: $logger
		);
	}//end setUp()

	/**
	 * One page holding the given rows, then the end of the table.
	 *
	 * @param list<array> $rows The rows.
	 *
	 * @return void
	 */
	private function rows(array $rows): void {
		$last = 0;
		if ($rows !== []) {
			$last = (int)end($rows)['id'];
		}

		$this->magicMapper->method('findSlugBackfillPage')->willReturn(['rows' => $rows, 'fetched' => count($rows), 'lastId' => $last]);
	}//end rows()

	/**
	 * A row without a slug.
	 *
	 * @param int    $id    The row id.
	 * @param string $title The title the candidate is built from ('' gives none).
	 *
	 * @return array The row.
	 */
	private function row(int $id, string $title): array {
		return ['id' => $id, 'uuid' => 'u-' . $id, 'slug' => null, 'created' => null, 'object' => ['title' => $title]];
	}//end row()

	/**
	 * Record each slug write; the given uuids touch no row.
	 *
	 * @param list<string> $untouched Uuids whose update reports zero rows.
	 *
	 * @return void
	 */
	private function writes(array $untouched=[]): void {
		$this->magicMapper->method('updateSlugColumn')->willReturnCallback(
			function (Register $r, Schema $s, string $uuid, string $slug) use ($untouched): int {
				$this->events[] = 'update:' . $uuid . '=' . $slug;
				return (in_array($uuid, $untouched, true) === true) ? 0 : 1;
			}
		);
	}//end writes()

	public function testTheRefusalMessageForASchemaWithoutSlugField(): void {
		$this->schema->setConfiguration([]);

		try {
			$this->service->run($this->register, $this->schema);
			$this->fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Schema conv has no objectSlugField; there is nothing to backfill', $e->getMessage());
		}
	}

	public function testAMixedRunPinsSummaryPlanLogsAuditAndEviction(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn(['dup' => ['u-8', 'u-9']]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['Taken' => ['u-9']]);
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => ['fresh' => ['u-other']]]);
		$this->rows([$this->row(1, 'Fresh'), $this->row(2, 'Taken'), $this->row(3, ''), $this->row(4, 'Moved'), $this->row(5, 'Kept')]);
		$this->writes(untouched: ['u-4']);
		$this->auditTrail->method('createSlugBackfillEntry')->willReturnCallback(
			function (...$args): AuditTrail {
				$this->events[] = 'audit:' . json_encode($args);
				return new AuditTrail();
			}
		);

		$result = $this->service->run($this->register, $this->schema);

		$this->assertSame(['dup' => ['u-8', 'u-9']], $result['duplicates']);
		$this->assertSame(
			[
				['uuid' => 'u-1', 'old' => null, 'new' => 'fresh'],
				['uuid' => 'u-4', 'old' => null, 'new' => 'moved'],
				['uuid' => 'u-5', 'old' => null, 'new' => 'kept'],
			],
			$result['plan']
		);
		$this->assertSame(['scanned' => 5, 'assigned' => 3, 'collisions' => 1, 'unchanged' => 1, 'duplicates' => 1, 'skipped' => 2], $result['summary']);
		$this->assertSame(
			[
				'warning',
				'[ObjectSlug] Duplicate slug "dup" in register woo (7) / schema conv (11) held by objects u-8, u-9',
				['app' => 'openregister', 'path' => 'backfill', 'registerId' => 7, 'schemaId' => 11, 'slug' => 'dup', 'uuids' => ['u-8', 'u-9']],
			],
			$this->logs[0]
		);
		$skips = array_values(array_filter($this->logs, static fn (array $log): bool => str_contains($log[1], 'Backfill skipped')));
		$this->assertSame(
			[
				['warning', '[ObjectSlug] Backfill skipped object u-1 in register woo / schema conv: slug "fresh" was taken by another object while the backfill ran', ['app' => 'openregister', 'path' => 'backfill', 'objectUuid' => 'u-1']],
				['warning', '[ObjectSlug] Backfill skipped object u-4 in register woo / schema conv: its slug changed while the backfill ran', ['app' => 'openregister', 'path' => 'backfill', 'objectUuid' => 'u-4']],
			],
			$skips
		);
		$this->assertSame(
			[
				'begin',
				'update:u-4=moved',
				'update:u-5=kept',
				'commit',
				'audit:' . json_encode([[['uuid' => 'u-5', 'old' => null, 'new' => 'kept']], ['scanned' => 5, 'assigned' => 3, 'collisions' => 1, 'unchanged' => 1, 'duplicates' => 1, 'skipped' => 2], 7, 11]),
				'evict:' . json_encode([null, 'bulk_update', 7, 11]),
			],
			$this->events
		);
	}

	public function testADryRunPlansWithoutWritingOrLoggingCollisions(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['taken' => ['u-9']]);
		$this->magicMapper->expects($this->never())->method('updateSlugColumn');
		$this->auditTrail->expects($this->never())->method('createSlugBackfillEntry');
		$this->rows([$this->row(1, 'Taken'), $this->row(2, 'Free')]);

		$result = $this->service->run($this->register, $this->schema, true);

		$this->assertSame([['uuid' => 'u-2', 'old' => null, 'new' => 'free']], $result['plan']);
		$this->assertSame(['scanned' => 2, 'assigned' => 1, 'collisions' => 1, 'unchanged' => 0, 'duplicates' => 0, 'skipped' => 0], $result['summary']);
		$this->assertSame([], $this->logs);
		$this->assertSame([], $this->events);
	}

	public function testAFailedAuditAndEvictionAreLoggedNotThrown(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => []]);
		$this->rows([$this->row(1, 'One')]);
		$this->writes();
		$this->auditTrail->method('createSlugBackfillEntry')->willThrowException(new RuntimeException('db down'));
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->cacheHandler->method('invalidateForObjectChange')->willThrowException(new RuntimeException('cache down'));
		$this->rebuildWithCache();

		$result = $this->service->run($this->register, $this->schema);

		$this->assertSame(0, $result['summary']['skipped']);
		$this->assertSame(
			[
				['error', '[ObjectSlug] Could not write the audit entry of the slug backfill (register 7, schema 11): db down; changes: [{"uuid":"u-1","old":null,"new":"one"}]', ['app' => 'openregister', 'path' => 'backfill']],
				['error', '[ObjectSlug] Could not evict the cache after the slug backfill (register 7, schema 11): cache down', ['app' => 'openregister', 'path' => 'backfill']],
			],
			$this->logs
		);
	}

	public function testAFailingChunkIsRolledBackAndReportedWithTheCounts(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => []]);
		$this->rows([$this->row(1, 'One')]);
		$this->magicMapper->method('updateSlugColumn')->willThrowException(new RuntimeException('write failed'));
		$this->auditTrail->expects($this->never())->method('createSlugBackfillEntry');

		try {
			$this->service->run($this->register, $this->schema);
			$this->fail('expected the write failure');
		} catch (SlugBackfillWriteException $e) {
			$this->assertSame('write failed', $e->getPrevious()?->getMessage());
		}

		$this->assertSame(['begin', 'rollback'], $this->events);
	}

	public function testALargeRunLogsTheChangeListInParts(): void {
		$rows = [];
		for ($i = 1; $i <= 1001; $i++) {
			$rows[] = $this->row($i, 'item ' . $i);
		}

		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugState')->willReturn(['holders' => []]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			['rows' => array_slice($rows, 0, 500), 'fetched' => 500, 'lastId' => 500],
			['rows' => array_slice($rows, 500, 500), 'fetched' => 500, 'lastId' => 1000],
			['rows' => array_slice($rows, 1000), 'fetched' => 1, 'lastId' => 1001]
		);
		$this->magicMapper->method('updateSlugColumn')->willReturn(1);
		$this->auditTrail->method('createSlugBackfillEntry')->willReturn(new AuditTrail());

		$this->service->run($this->register, $this->schema);

		$parts = array_values(array_filter($this->logs, static fn (array $log): bool => str_contains($log[1], 'Slug backfill changes')));
		$this->assertCount(2, $parts);
		$this->assertSame('warning', $parts[1][0]);
		$this->assertSame(
			'[ObjectSlug] Slug backfill changes (register 7, schema 11, part 2 of 2): [{"uuid":"u-1001","old":null,"new":"item-1001"}]',
			$parts[1][1]
		);
		$this->assertSame(['app' => 'openregister', 'path' => 'backfill'], $parts[1][2]);
		$this->assertStringStartsWith('[ObjectSlug] Slug backfill changes (register 7, schema 11, part 1 of 2): [{"uuid":"u-1",', $parts[0][1]);
	}

	/**
	 * Rebuild the service after the cache mock was replaced.
	 *
	 * @return void
	 */
	private function rebuildWithCache(): void {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context=[]) use ($level): void {
					$this->logs[] = [$level, (string)$message, $context];
				}
			);
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register);
		$this->service = new ObjectSlugBackfillService(
			magicMapper: $this->magicMapper,
			slugHandler: $this->buildSlugHandler(
				magicMapper: $this->magicMapper,
				hydrator: new MetadataHydrationHandler(
					logger: $this->createMock(LoggerInterface::class),
					cacheHandler: $this->createMock(CacheHandler::class)
				),
				registerMapper: $registerMapper,
				logger: $logger
			),
			writer: new ObjectSlugBackfillWriter(
				magicMapper: $this->magicMapper,
				db: $this->createMock(IDBConnection::class),
				ledger: new ObjectSlugBackfillLedger(cacheHandler: $this->cacheHandler, auditTrail: $this->auditTrail, logger: $logger),
				logger: $logger
			),
			logger: $logger
		);
	}//end rebuildWithCache()
}//end class
