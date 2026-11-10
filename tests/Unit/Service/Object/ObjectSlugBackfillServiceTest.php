<?php

/**
 * ObjectSlugBackfillService unit tests (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\ObjectSlugBackfillRequest;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\BuildsObjectSlugHandler;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\SlugBackfill;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillLedger;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillWriter;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the slug backfill: fill, dry run, duplicates, audit.
 */
class ObjectSlugBackfillServiceTest extends TestCase {
	use BuildsObjectSlugHandler;


	private const UUID_A = '00000000-0000-0000-0000-00000000000a';
	private const UUID_B = '00000000-0000-0000-0000-00000000000b';
	private const UUID_C = '00000000-0000-0000-0000-00000000000c';

	/** @var MagicMapper&MockObject */
	private MagicMapper $magicMapper;

	/** @var list<array{string, ?string}> Slug writes as [uuid, slug]. */
	private array $writes = [];

	/** @var list<array> Audit entries as the fields passed to recordSlugBackfill. */
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
			function (RegisterSchema $table, string $uuid, ?string $slug): int {
				[$r, $s] = [$table->register, $table->schema];
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

		$slugHandler = $this->buildSlugHandler(
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
			writer: new ObjectSlugBackfillWriter(
				magicMapper: $this->magicMapper,
				db: $this->createMock(IDBConnection::class),
				ledger: new ObjectSlugBackfillLedger(cacheHandler: $this->createMock(CacheHandler::class), audit: new \OCA\OpenRegister\Service\Object\AuditRecording(trail: $this->auditTrailMapper, logger: $logger)),
				logger: $logger
			),
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
		$mapper->method('recordSlugBackfill')->willReturnCallback(
			function (SlugBackfill $backfill): AuditTrail {
				$target = $backfill->target;
				$this->audits[] = ['changes' => $backfill->changes, 'summary' => $backfill->summary, 'register' => $target->register, 'schema' => $target->schema];
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

		$this->service->run(
			new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(['objectNameField' => 'title']), dryRun: false)
		);
	}

	public function testEmptySlugsAreFilledOldestFirstAndATakenOneIsLogged(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['bulk-item' => [self::UUID_C]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello World'), $this->row(2, self::UUID_B, 'Bulk Item')]),
			$this->page([])
		);

		$result = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame([[self::UUID_A, 'hello-world']], $this->writes);
		$this->assertSame(1, $result['summary']['assigned']);
		$this->assertSame(1, $result['summary']['collisions']);
		$this->assertStringContainsString('gets no slug from the backfill', $this->warnings[0]);
	}

	public function testAMixedCaseExistingSlugBlocksTheLowerCaseCandidate(): void {
		// Review finding (Robert, 30-10): bulk save folds case, the backfill must too,
		// or a case-insensitive collation ends up with two holders of one slug.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['Hello-World' => [self::UUID_C]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello World')]),
			$this->page([])
		);

		$result = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame([], $this->writes);
		$this->assertSame(0, $result['summary']['assigned']);
		$this->assertSame(1, $result['summary']['collisions']);
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
			function (Register $r, Schema $s, int $afterId) use (&$served): array {
				$empty = array_values(array_filter($served, static fn (array $row): bool => $row['slug'] === null));
				return $this->page(($afterId === 0) ? $empty : []);
			}
		);

		$first = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));
		$this->assertSame(2, $first['summary']['assigned'], 'run 1 assigns hello-world and other');
		$served = $this->applyPlan($rows, $first['plan']);
		$taken = ['hello-world' => [self::UUID_A], 'other' => [self::UUID_C]];

		$second = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame([], $second['plan']);
		$this->assertSame(0, $second['summary']['assigned']);
		$this->assertCount(2, $this->writes, 'run 2 wrote nothing more');
	}

	public function testARunWritesOneAuditEntryWithTheOldAndNewSlugs(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([
				$this->row(1, self::UUID_A, 'Hello'),
				$this->row(2, self::UUID_B, 'World'),
			]),
			$this->page([])
		);

		$this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertCount(1, $this->audits);
		$this->assertSame(
			[
				['uuid' => self::UUID_A, 'old' => null, 'new' => 'hello'],
				['uuid' => self::UUID_B, 'old' => null, 'new' => 'world'],
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

		$this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: true));

		$this->assertSame([], $this->audits);
	}

	public function testARunThatChangesNothingWritesNoAuditEntry(): void {
		// Review finding r2routes-f9: nothing changed, nothing to record in the hash chain.
		// The only row without a slug wants one that is taken, so the plan is empty.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['hello' => [self::UUID_B]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello')]),
			$this->page([])
		);

		$result = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

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
			static fn (RegisterSchema $table, string $uuid): int => (($uuid === self::UUID_A) ? 1 : 0)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$service = new ObjectSlugBackfillService(
			magicMapper: $magicMapper,
			slugHandler: $this->buildSlugHandler(
				magicMapper: $magicMapper,
				hydrator: new MetadataHydrationHandler(
					logger: $this->createMock(LoggerInterface::class),
					cacheHandler: $this->createMock(CacheHandler::class)
				),
				registerMapper: $registerMapper,
				logger: $logger
			),
			writer: new ObjectSlugBackfillWriter(
				magicMapper: $magicMapper,
				db: $this->createMock(IDBConnection::class),
				ledger: new ObjectSlugBackfillLedger(cacheHandler: $this->createMock(CacheHandler::class), audit: new \OCA\OpenRegister\Service\Object\AuditRecording(trail: $this->auditMapper(), logger: $logger)),
				logger: $logger
			),
			logger: $logger
		);

		$service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame([['uuid' => self::UUID_A, 'old' => null, 'new' => 'hello']], $this->audits[0]['changes']);
		// Review finding r2routes-f1: the entry carries the real skipped count, not the planning-time 0.
		$this->assertSame(1, $this->audits[0]['summary']['skipped']);
	}

	public function testExistingDuplicatesAreReportedAndLeftAlone(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn(['my-own' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['my-own' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturn($this->page([]));

		$result = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame(['my-own' => [self::UUID_A, self::UUID_B]], $result['duplicates']);
		$this->assertSame([], $this->writes);
		$this->assertStringContainsString('Duplicate slug "my-own"', $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_A . ', ' . self::UUID_B, $this->warnings[0]);
	}

	public function testASwitchedSlugFieldLeavesSetSlugsAlone(): void {
		// Robert Zondervan (2026-10-23): no rewrite flag. After switching the slug field,
		// only objects without a slug are read; a set slug is never recomputed.
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['k-1' => [self::UUID_A]]);
		$pages = [$this->page([$this->row(2, self::UUID_B, 'Hello World')]), $this->page([])];
		$this->magicMapper->method('findSlugBackfillPage')->willReturnCallback(
			function () use (&$pages): array {
				return array_shift($pages) ?? $this->page([]);
			}
		);

		$result = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame([[self::UUID_B, 'hello-world']], $this->writes, 'only the object without a slug is written');
		$this->assertSame(['scanned' => 1, 'assigned' => 1, 'collisions' => 0, 'unchanged' => 0, 'duplicates' => 0, 'skipped' => 0], $result['summary']);
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

		$this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

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
		// The write only applies while the row has no slug; 0 rows = a save got there first.
		$magicMapper->method('updateSlugColumn')->willReturn(0);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($this->register);
		$logger = $this->createMock(LoggerInterface::class);
		$slugHandler = $this->buildSlugHandler(
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
			writer: new ObjectSlugBackfillWriter(
				magicMapper: $magicMapper,
				db: $this->createMock(IDBConnection::class),
				ledger: new ObjectSlugBackfillLedger(cacheHandler: $this->createMock(CacheHandler::class), audit: new \OCA\OpenRegister\Service\Object\AuditRecording(trail: $this->auditMapper(), logger: $logger)),
				logger: $logger
			),
			logger: $logger
		);

		$result = $service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame(1, $result['summary']['skipped']);
	}

	public function testADryRunWritesAndLogsNothingButReturnsThePlan(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['bulk-item' => [self::UUID_C]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			$this->page([$this->row(1, self::UUID_A, 'Hello World'), $this->row(2, self::UUID_B, 'Bulk Item')]),
			$this->page([])
		);

		$result = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: true));

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

		$result = $this->service->run(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema(), dryRun: false));

		$this->assertSame([0, 500], $afterIds, 'the cursor advances past the unconvertible row');
		$this->assertSame([[self::UUID_A, 'first-row'], [self::UUID_B, 'second-page-row']], $this->writes);
		$this->assertSame(2, $result['summary']['scanned']);
	}
}//end class
