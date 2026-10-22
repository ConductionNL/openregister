<?php

/**
 * ObjectSlugBackfillService unit tests (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
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
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the slug backfill: fill, reslug, dry run, duplicates.
 */
class ObjectSlugBackfillServiceTest extends TestCase {

	private const UUID_A = '00000000-0000-0000-0000-00000000000a';
	private const UUID_B = '00000000-0000-0000-0000-00000000000b';
	private const UUID_C = '00000000-0000-0000-0000-00000000000c';

	/** @var MagicMapper&MockObject */
	private MagicMapper $magicMapper;

	/** @var list<array{string, ?string}> Slug writes as [uuid, slug]. */
	private array $writes = [];

	/** @var list<array> Audit entries as the arguments of createSlugBackfillEntry. */
	private array $audits = [];

	/** @var list<string> Logged warnings. */
	private array $warnings = [];

	private Register $register;

	private ObjectSlugBackfillService $service;

	private AuditTrailMapper $auditTrailMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->magicMapper->method('updateSlugColumn')->willReturnCallback(
			function (Register $r, Schema $s, string $uuid, ?string $slug): int {
				$this->writes[] = [$uuid, $slug];
				return 1;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message): void {
				$this->warnings[] = (string)$message;
			}
		);

		$this->register = new Register();
		$this->register->setId(7);
		$this->register->setSlug('woo');
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register);

		$slugHandler = new ObjectSlugHandler(
			magicMapper: $this->magicMapper,
			hydrator: new MetadataHydrationHandler(
				logger: $this->createMock(LoggerInterface::class),
				cacheHandler: $this->createMock(CacheHandler::class)
			),
			registerMapper: $registerMapper,
			logger: $logger
		);

		$this->auditTrailMapper = $this->auditMapper();
		$this->service = new ObjectSlugBackfillService(
			magicMapper: $this->magicMapper,
			slugHandler: $slugHandler,
			cacheHandler: $this->createMock(CacheHandler::class),
			db: $this->createMock(IDBConnection::class),
			auditTrail: $this->auditTrailMapper,
			logger: $logger
		);
	}//end setUp()

	/**
	 * An audit mapper that records the entries a run writes.
	 *
	 * @return AuditTrailMapper&MockObject The mapper.
	 */
	private function auditMapper(): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createSlugBackfillEntry')->willReturnCallback(
			function (string $mode, array $changes, array $summary, ?int $register=null, ?int $schema=null): AuditTrail {
				$this->audits[] = ['mode' => $mode, 'changes' => $changes, 'summary' => $summary, 'register' => $register, 'schema' => $schema];
				return new AuditTrail();
			}
		);
		return $mapper;
	}//end auditMapper()

	/**
	 * A schema that builds slugs from `title`.
	 *
	 * @param array $configuration Configuration override.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $configuration=['objectSlugField' => 'title']): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('convenanten');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	/**
	 * A backfill row.
	 *
	 * @param int         $id    The row id.
	 * @param string      $uuid  The uuid.
	 * @param string      $title The source value.
	 * @param string|null $slug    The stored slug.
	 * @param string|null $created The `_created` value.
	 *
	 * @return array The row.
	 */
	private function row(int $id, string $uuid, string $title, ?string $slug=null, ?string $created=null): array {
		return ['id' => $id, 'uuid' => $uuid, 'slug' => $slug, 'created' => $created, 'object' => ['title' => $title]];
	}//end row()

	/**
	 * A page as findSlugBackfillPage() returns it.
	 *
	 * @param list<array> $rows    The convertible rows.
	 * @param int|null    $fetched Rows the query returned (default: count of $rows).
	 * @param int|null    $lastId  `_id` of the last row read (default: id of the last row).
	 *
	 * @return array{rows: list<array>, fetched: int, lastId: int} The page.
	 */
	private function page(array $rows, ?int $fetched=null, ?int $lastId=null): array {
		$last = 0;
		if ($rows !== []) {
			$last = (int)end($rows)['id'];
		}

		return ['rows' => $rows, 'fetched' => ($fetched ?? count($rows)), 'lastId' => ($lastId ?? $last)];
	}//end page()

	public function testASchemaWithoutSlugFieldIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->run($this->register, $this->schema(['objectNameField' => 'title']));
	}

	public function testEmptySlugsAreFilledOldestFirstAndATakenOneIsLogged(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['bulk-item' => [self::UUID_C]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello World'), $this->row(2, self::UUID_B, 'Bulk Item')]),
			$this->page([])
		);

		$result = $this->service->run($this->register, $this->schema());

		$this->assertSame([[self::UUID_A, 'hello-world']], $this->writes);
		$this->assertSame(1, $result['summary']['assigned']);
		$this->assertSame(1, $result['summary']['collisions']);
		$this->assertStringContainsString('gets no slug from the backfill', $this->warnings[0]);
	}

	/**
	 * Rows as the next run reads them: the plan of the previous run applied.
	 *
	 * @param list<array> $rows The rows the previous run read.
	 * @param array       $plan The plan that run returned.
	 *
	 * @return list<array> The rows after the writes.
	 */
	private function applyPlan(array $rows, array $plan): array {
		$new = [];
		foreach ($plan as $step) {
			$new[$step['uuid']] = $step['new'];
		}

		foreach ($rows as $index => $row) {
			if (array_key_exists($row['uuid'], $new) === true) {
				$rows[$index]['slug'] = $new[$row['uuid']];
			}
		}

		return $rows;
	}//end applyPlan()

	public function testASecondFillRunChangesNothing(): void {
		$rows = [$this->row(1, self::UUID_A, 'Hello World'), $this->row(2, self::UUID_B, 'Hello World'), $this->row(3, self::UUID_C, 'Other')];
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$taken = [];
		$this->magicMapper->method('findAllSlugs')->willReturnCallback(
			function () use (&$taken): array {
				return $taken;
			}
		);
		$served = $rows;
		$this->magicMapper->method('findSlugBackfillPage')->willReturnCallback(
			function (Register $r, Schema $s, int $afterId, int $pageSize, bool $onlyEmpty) use (&$served): array {
				$empty = array_values(array_filter($served, static fn (array $row): bool => $row['slug'] === null));
				return $this->page(($afterId === 0) ? $empty : []);
			}
		);

		$first = $this->service->run($this->register, $this->schema());
		$this->assertSame(2, $first['summary']['assigned'], 'run 1 assigns hello-world and other');
		$served = $this->applyPlan($rows, $first['plan']);
		$taken = ['hello-world' => [self::UUID_A], 'other' => [self::UUID_C]];

		$second = $this->service->run($this->register, $this->schema());

		$this->assertSame([], $second['plan']);
		$this->assertSame(0, $second['summary']['assigned']);
		$this->assertCount(2, $this->writes, 'run 2 wrote nothing more');
	}

	public function testASecondReslugRunChangesNothing(): void {
		$rows = [
			$this->row(1, self::UUID_A, 'Foo', 'old-a', '2020-01-01 00:00:00'),
			$this->row(2, self::UUID_B, 'Foo', 'foo', '2021-01-01 00:00:00'),
			$this->row(3, self::UUID_C, 'Renamed Title', 'old-title', '2022-01-01 00:00:00'),
		];
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$served = $rows;
		$this->magicMapper->method('findSlugBackfillPage')->willReturnCallback(
			function (Register $r, Schema $s, int $afterId) use (&$served): array {
				return $this->page(($afterId === 0) ? $served : []);
			}
		);

		$first = $this->service->run($this->register, $this->schema(), reslug: true);
		$this->assertSame([['uuid' => self::UUID_C, 'old' => 'old-title', 'new' => 'renamed-title']], $first['plan']);
		$served = $this->applyPlan($rows, $first['plan']);

		$second = $this->service->run($this->register, $this->schema(), reslug: true);

		$this->assertSame([], $second['plan']);
		$this->assertSame(0, $second['summary']['changed']);
		$this->assertCount(1, $this->writes, 'run 2 wrote nothing more');
	}

	public function testReslugKeepsTheCurrentSlugWhenTheNewOneIsHeldAndSaysSo(): void {
		// Review finding backfill-f4: A (older, slug old-a) now wants "foo", B holds "foo".
		// A keeps old-a, B keeps foo; nobody loses a slug and none is handed out twice.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Foo', 'old-a', '2020-01-01 00:00:00'),
				$this->row(2, self::UUID_B, 'Foo', 'foo', '2021-01-01 00:00:00'),
			]),
			$this->page([])
		);

		$result = $this->service->run($this->register, $this->schema(), reslug: true);

		$this->assertSame([], $this->writes);
		$this->assertSame([['uuid' => self::UUID_A, 'slug' => 'old-a', 'wanted' => 'foo', 'holder' => self::UUID_B]], $result['kept']);
		$this->assertSame(1, $result['summary']['kept']);
		$this->assertStringContainsString('keeps its slug "old-a"', $this->warnings[0]);
	}

	public function testReslugWritesTheRowThatVacatesASlugBeforeTheRowThatTakesIt(): void {
		// Review finding r2routes-f3: A wants the slug B holds today; B moves away to its
		// own new slug. B's write must come first, so no moment (and no failed or skipped
		// write) leaves both objects on `wanted`.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Wanted', 'old-a', '2020-01-01 00:00:00'),
				$this->row(2, self::UUID_B, 'Moved', 'wanted', '2021-01-01 00:00:00'),
			]),
			$this->page([])
		);

		$result = $this->service->run($this->register, $this->schema(), reslug: true);

		$this->assertSame(
			[
				['uuid' => self::UUID_B, 'old' => 'wanted', 'new' => 'moved'],
				['uuid' => self::UUID_A, 'old' => 'old-a', 'new' => 'wanted'],
			],
			$result['plan']
		);
		$this->assertSame([], $result['kept']);
	}

	public function testReslugOrdersAChainOfTakersAfterTheirHolders(): void {
		// A wants B's slug, B wants C's, C moves out: C, then B, then A.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Bee', 'old-a', '2020-01-01 00:00:00'),
				$this->row(2, self::UUID_B, 'Cee', 'bee', '2021-01-01 00:00:00'),
				$this->row(3, self::UUID_C, 'Elsewhere', 'cee', '2022-01-01 00:00:00'),
			]),
			$this->page([])
		);

		$result = $this->service->run($this->register, $this->schema(), reslug: true, dryRun: true);

		$this->assertSame([self::UUID_C, self::UUID_B, self::UUID_A], array_column($result['plan'], 'uuid'));
		$this->assertSame([[self::UUID_C, 'elsewhere'], [self::UUID_B, 'cee'], [self::UUID_A, 'bee']], array_map(static fn (array $s): array => [$s['uuid'], $s['new']], $result['plan']));
	}

	public function testAReverseChainOfTwoThousandRowsIsPlannedInLinearWork(): void {
		// Review finding r2routes-f10: row i holds `s<i>` and wants the slug of row i+1; only
		// the last one moves freely. A full re-scan per pass took one move per pass (2,000
		// passes over 2,000 rows, about two million decisions).
		$total = 2000;
		$rows = [];
		for ($i = 1; $i <= $total; $i++) {
			$wanted = ($i === $total) ? 'End' : 's' . ($i + 1);
			$rows[] = $this->row($i, sprintf('00000000-0000-0000-0000-%012d', $i), $wanted, 's' . $i, sprintf('2020-01-01 00:%02d:%02d', intdiv($i, 60), ($i % 60)));
		}

		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls($this->page($rows, 2000 - 1), $this->page([]));
		$decisions = 0;
		$countingHandler = new class (
			$this->magicMapper,
			new MetadataHydrationHandler(logger: $this->createMock(LoggerInterface::class), cacheHandler: $this->createMock(CacheHandler::class)),
			$this->createMock(RegisterMapper::class),
			$this->createMock(LoggerInterface::class),
			$decisions
		) extends ObjectSlugHandler {
			public function __construct(MagicMapper $m, MetadataHydrationHandler $h, RegisterMapper $r, LoggerInterface $l, public int &$decisions) {
				parent::__construct(magicMapper: $m, hydrator: $h, registerMapper: $r, logger: $l);
			}

			public function decide(string $candidate, Register $register, Schema $schema, string $objectUuid, callable $holderOf, string $path, bool $log=true, ?string $suffixed=null): ?string {
				$this->decisions++;
				return parent::decide($candidate, $register, $schema, $objectUuid, $holderOf, $path, $log, $suffixed);
			}
		};
		$service = new ObjectSlugBackfillService(
			magicMapper: $this->magicMapper,
			slugHandler: $countingHandler,
			cacheHandler: $this->createMock(CacheHandler::class),
			db: $this->createMock(IDBConnection::class),
			auditTrail: $this->auditTrailMapper,
			logger: $this->createMock(LoggerInterface::class)
		);

		$result = $service->run($this->register, $this->schema(), reslug: true, dryRun: true);

		$this->assertCount($total, $result['plan']);
		$this->assertSame('end', $result['plan'][0]['new'], 'the free row moves first, its taker after it');
		$this->assertSame('s2000', $result['plan'][1]['new']);
		$this->assertLessThan(4 * $total, $countingHandler->decisions, 'linear work, not rows times passes');
	}

	/**
	 * Reslug a ring: row i holds slug `s<i>` and wants the slug of the next row.
	 *
	 * @param int  $size   Rows in the ring.
	 * @param bool $suffix Whether objectSlugSuffix is on.
	 *
	 * @return array The result of a dry run.
	 */
	private function reslugRing(int $size, bool $suffix): array {
		$uuids = [self::UUID_A, self::UUID_B, self::UUID_C];
		$rows = [];
		for ($i = 0; $i < $size; $i++) {
			$wanted = 's' . (($i + 1) % $size);
			$rows[] = $this->row($i + 1, $uuids[$i], $wanted, 's' . $i, sprintf('2020-01-0%d 00:00:00', $i + 1));
		}

		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls($this->page($rows), $this->page([]));

		return $this->service->run($this->register, $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => $suffix]), reslug: true, dryRun: true);
	}//end reslugRing()

	/**
	 * @return array<string, array{0: int, 1: bool}> Ring size and suffix switch.
	 */
	public static function rings(): array {
		return [
			'swap, suffix on' => [2, true],
			'three-ring, suffix on' => [3, true],
			'swap, suffix off' => [2, false],
			'three-ring, suffix off' => [3, false],
		];
	}//end rings()

	/**
	 * Review finding pp-f1: no write order is safe for a cycle, so none of its rows is written.
	 *
	 * @dataProvider rings
	 */
	public function testARingOfRowsWantingEachOthersSlugsIsKeptNotWritten(int $size, bool $suffix): void {
		$result = $this->reslugRing($size, $suffix);

		$this->assertSame([], $result['plan'], 'no step of a cycle is planned, so none can be written first');
		$this->assertSame(0, $result['summary']['changed']);
		$this->assertSame($size, $result['summary']['unchanged']);
		$this->assertSame($size, $result['summary']['kept']);
		$this->assertCount($size, $result['kept']);
	}//end testARingOfRowsWantingEachOthersSlugsIsKeptNotWritten()

	public function testAnUnrelatedRowStillMovesBesideARing(): void {
		// The swap (suffix on) is kept; the unrelated row beside it is still written.
		$rows = [
			$this->row(1, self::UUID_A, 's1', 's0', '2020-01-01 00:00:00'),
			$this->row(2, self::UUID_B, 's0', 's1', '2020-01-02 00:00:00'),
			$this->row(3, self::UUID_C, 'Free Row', 'old-c', '2020-01-03 00:00:00'),
		];
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls($this->page($rows), $this->page([]));

		$result = $this->service->run($this->register, $this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]), reslug: true, dryRun: true);

		$this->assertSame([['uuid' => self::UUID_C, 'old' => 'old-c', 'new' => 'free-row']], $result['plan'], 'the unrelated row still moves');
		$this->assertSame(1, $result['summary']['changed']);
		$this->assertSame(2, $result['summary']['kept']);
	}//end testAnUnrelatedRowStillMovesBesideARing()

	public function testARunWritesOneAuditEntryWithTheOldAndNewSlugs(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Hello', 'k-1'),
				$this->row(2, self::UUID_B, 'World', 'k-2'),
			]),
			$this->page([])
		);

		$this->service->run($this->register, $this->schema(), reslug: true);

		$this->assertCount(1, $this->audits);
		$this->assertSame('reslug', $this->audits[0]['mode']);
		$this->assertSame(
			[
				['uuid' => self::UUID_A, 'old' => 'k-1', 'new' => 'hello'],
				['uuid' => self::UUID_B, 'old' => 'k-2', 'new' => 'world'],
			],
			$this->audits[0]['changes']
		);
		$this->assertSame(7, $this->audits[0]['register']);
		$this->assertSame(11, $this->audits[0]['schema']);
	}

	public function testADryRunWritesNoAuditEntry(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello')]),
			$this->page([])
		);

		$this->service->run($this->register, $this->schema(), dryRun: true);

		$this->assertSame([], $this->audits);
	}

	public function testARunThatChangesNothingWritesNoAuditEntry(): void {
		// Review finding r2routes-f9: nothing changed, nothing to record in the hash chain.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello', 'hello')]),
			$this->page([])
		);

		$result = $this->service->run($this->register, $this->schema(), reslug: true);

		$this->assertSame([], $result['plan']);
		$this->assertSame([], $this->audits);
	}

	public function testAnAppliedWriteIsAuditedButASkippedOneIsNot(): void {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$magicMapper->method('findAllSlugs')->willReturn([]);
		$magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello'), $this->row(2, self::UUID_B, 'World')]),
			$this->page([])
		);
		$magicMapper->method('updateSlugColumn')->willReturnCallback(
			static fn (Register $r, Schema $s, string $uuid): int => (($uuid === self::UUID_A) ? 1 : 0)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$service = new ObjectSlugBackfillService(
			magicMapper: $magicMapper,
			slugHandler: new ObjectSlugHandler(
				magicMapper: $magicMapper,
				hydrator: new MetadataHydrationHandler(
					logger: $this->createMock(LoggerInterface::class),
					cacheHandler: $this->createMock(CacheHandler::class)
				),
				registerMapper: $registerMapper,
				logger: $logger
			),
			cacheHandler: $this->createMock(CacheHandler::class),
			db: $this->createMock(IDBConnection::class),
			auditTrail: $this->auditMapper(),
			logger: $logger
		);

		$service->run($this->register, $this->schema());

		$this->assertSame([['uuid' => self::UUID_A, 'old' => null, 'new' => 'hello']], $this->audits[0]['changes']);
		// Review finding r2routes-f1: the entry carries the real skipped count, not the planning-time 0.
		$this->assertSame(1, $this->audits[0]['summary']['skipped']);
	}

	public function testExistingDuplicatesAreReportedAndLeftAlone(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn(['my-own' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['my-own' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturn($this->page([]));

		$result = $this->service->run($this->register, $this->schema());

		$this->assertSame(['my-own' => [self::UUID_A, self::UUID_B]], $result['duplicates']);
		$this->assertSame([], $this->writes);
		$this->assertStringContainsString('Duplicate slug "my-own"', $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_A . ', ' . self::UUID_B, $this->warnings[0]);
	}

	public function testReslugRecomputesFromTheSourceAndLeavesAnExistingDuplicateAlone(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn(['bulk-item' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Bulk Item', 'bulk-item'),
				$this->row(2, self::UUID_B, 'Bulk Item', 'bulk-item'),
				$this->row(3, self::UUID_C, 'Renamed Title', 'old-title'),
			]),
			$this->page([])
		);

		$result = $this->service->run($this->register, $this->schema(), reslug: true);

		// Both duplicates hold the slug they want, so neither moves; they stay reported by the duplicate report.
		$this->assertSame([[self::UUID_C, 'renamed-title']], $this->writes);
		$this->assertSame(1, $result['summary']['changed']);
		$this->assertSame(2, $result['summary']['unchanged']);
	}

	public function testReslugKeepsTheSlugOfAnObjectWithAnEmptySource(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, '', 'kept'),
				$this->row(2, self::UUID_B, 'Kept', null),
			]),
			$this->page([])
		);

		$this->service->run($this->register, $this->schema(), reslug: true);

		// A keeps "kept" and reserves it, so B (whose source slugifies to "kept") collides.
		$this->assertSame([], $this->writes);
	}

	public function testReslugWithSuffixKeepsAnExistingSuffixedSlug(): void {
		// Review finding: a second --reslug run must change nothing, also when
		// objects carry a suffix stamped on an earlier run.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Same', 'same'),
				$this->row(2, self::UUID_B, 'Same', 'same-20250101000000'),
			]),
			$this->page([])
		);

		$result = $this->service->run(
			$this->register,
			$this->schema(['objectSlugField' => 'title', 'objectSlugSuffix' => true]),
			reslug: true
		);

		$this->assertSame([], $this->writes);
		$this->assertSame(0, $result['summary']['changed']);
	}

	public function testOldestByCreatedWinsEvenWithAHigherId(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Same', null, '2026-05-01 00:00:00'),
				$this->row(2, self::UUID_B, 'Same', null, '2024-01-01 00:00:00'),
			]),
			$this->page([])
		);

		$this->service->run($this->register, $this->schema());

		// B was created first (an imported historical object), so B gets the slug.
		$this->assertSame([[self::UUID_B, 'same']], $this->writes);
	}

	public function testARowChangedDuringTheRunIsSkippedNotOverwritten(): void {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$magicMapper->method('findAllSlugs')->willReturn([]);
		$magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello World')]),
			$this->page([])
		);
		$expectedSeen = 'unset';
		$magicMapper->method('updateSlugColumn')->willReturnCallback(
			function (Register $r, Schema $s, string $uuid, ?string $slug, ?string $expected = null) use (&$expectedSeen): int {
				$expectedSeen = $expected;
				return 0;
			}
		);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register);
		$logger = $this->createMock(LoggerInterface::class);
		$slugHandler = new ObjectSlugHandler(
			magicMapper: $magicMapper,
			hydrator: new MetadataHydrationHandler(
				logger: $this->createMock(LoggerInterface::class),
				cacheHandler: $this->createMock(CacheHandler::class)
			),
			registerMapper: $registerMapper,
			logger: $logger
		);
		$service = new ObjectSlugBackfillService(
			magicMapper: $magicMapper,
			slugHandler: $slugHandler,
			cacheHandler: $this->createMock(CacheHandler::class),
			db: $this->createMock(IDBConnection::class),
			auditTrail: $this->auditMapper(),
			logger: $logger
		);

		$result = $service->run($this->register, $this->schema());

		$this->assertNull($expectedSeen, 'the write must be conditional on the row still having no slug');
		$this->assertSame(1, $result['summary']['skipped']);
	}

	public function testADryRunWritesAndLogsNothingButReturnsThePlan(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['bulk-item' => [self::UUID_C]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello World'), $this->row(2, self::UUID_B, 'Bulk Item')]),
			$this->page([])
		);

		$result = $this->service->run($this->register, $this->schema(), dryRun: true);

		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->warnings);
		$this->assertSame([['uuid' => self::UUID_A, 'old' => null, 'new' => 'hello-world']], $result['plan']);
	}

	public function testAFullPageWithAnUnconvertibleRowDoesNotEndTheScan(): void {
		// Page 1: 500 rows fetched, one of them (id 500) could not be converted, so only
		// 499 come back. Page 2 must still be read, from the last _id scanned.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$afterIds = [];
		$pages = [
			$this->page([$this->row(1, self::UUID_A, 'First Row')], 500, 500),
			$this->page([$this->row(501, self::UUID_B, 'Second Page Row')]),
		];
		$this->magicMapper->method('findSlugBackfillPage')->willReturnCallback(
			function (Register $r, Schema $s, int $afterId) use (&$afterIds, &$pages): array {
				$afterIds[] = $afterId;
				return array_shift($pages) ?? $this->page([]);
			}
		);

		$result = $this->service->run($this->register, $this->schema());

		$this->assertSame([0, 500], $afterIds, 'the cursor advances past the unconvertible row');
		$this->assertSame([[self::UUID_A, 'first-row'], [self::UUID_B, 'second-page-row']], $this->writes);
		$this->assertSame(2, $result['summary']['scanned']);
	}
}//end class
