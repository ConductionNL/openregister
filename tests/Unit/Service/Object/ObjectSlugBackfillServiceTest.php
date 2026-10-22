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

	/** @var list<string> Logged warnings. */
	private array $warnings = [];

	private Register $register;

	private ObjectSlugBackfillService $service;

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

		$this->service = new ObjectSlugBackfillService(
			magicMapper: $this->magicMapper,
			slugHandler: $slugHandler,
			cacheHandler: $this->createMock(CacheHandler::class),
			db: $this->createMock(IDBConnection::class),
			logger: $logger
		);
	}//end setUp()

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

	public function testASchemaWithoutSlugFieldIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->run($this->register, $this->schema(['objectNameField' => 'title']));
	}

	public function testEmptySlugsAreFilledOldestFirstAndATakenOneIsLogged(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['bulk-item' => [self::UUID_C]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			[$this->row(1, self::UUID_A, 'Hello World'), $this->row(2, self::UUID_B, 'Bulk Item')],
			[]
		);

		$result = $this->service->run($this->register, $this->schema());

		$this->assertSame([[self::UUID_A, 'hello-world']], $this->writes);
		$this->assertSame(1, $result['summary']['assigned']);
		$this->assertSame(1, $result['summary']['collisions']);
		$this->assertStringContainsString('gets no slug from the backfill', $this->warnings[0]);
	}

	public function testASecondRunChangesNothing(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['hello-world' => [self::UUID_A]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturn([]);

		$result = $this->service->run($this->register, $this->schema());

		$this->assertSame([], $this->writes);
		$this->assertSame(0, $result['summary']['scanned']);
	}

	public function testExistingDuplicatesAreReportedAndLeftAlone(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn(['my-own' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['my-own' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturn([]);

		$result = $this->service->run($this->register, $this->schema());

		$this->assertSame(['my-own' => [self::UUID_A, self::UUID_B]], $result['duplicates']);
		$this->assertSame([], $this->writes);
		$this->assertStringContainsString('Duplicate slug "my-own"', $this->warnings[0]);
		$this->assertStringContainsString(self::UUID_A . ', ' . self::UUID_B, $this->warnings[0]);
	}

	public function testReslugRecomputesFromTheSourceAndResolvesADuplicateOldestFirst(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn(['bulk-item' => [self::UUID_A, self::UUID_B]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			[
				$this->row(1, self::UUID_A, 'Bulk Item', 'bulk-item'),
				$this->row(2, self::UUID_B, 'Bulk Item', 'bulk-item'),
				$this->row(3, self::UUID_C, 'Renamed Title', 'old-title'),
			],
			[]
		);

		$result = $this->service->run($this->register, $this->schema(), reslug: true);

		$this->assertSame([[self::UUID_B, null], [self::UUID_C, 'renamed-title']], $this->writes);
		$this->assertSame(2, $result['summary']['changed']);
		$this->assertSame(1, $result['summary']['unchanged']);
	}

	public function testReslugKeepsTheSlugOfAnObjectWithAnEmptySource(): void {
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			[
				$this->row(1, self::UUID_A, '', 'kept'),
				$this->row(2, self::UUID_B, 'Kept', null),
			],
			[]
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
			[
				$this->row(1, self::UUID_A, 'Same', 'same'),
				$this->row(2, self::UUID_B, 'Same', 'same-20250101000000'),
			],
			[]
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
			[
				$this->row(1, self::UUID_A, 'Same', null, '2026-05-01 00:00:00'),
				$this->row(2, self::UUID_B, 'Same', null, '2024-01-01 00:00:00'),
			],
			[]
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
			[$this->row(1, self::UUID_A, 'Hello World')],
			[]
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
			[$this->row(1, self::UUID_A, 'Hello World'), $this->row(2, self::UUID_B, 'Bulk Item')],
			[]
		);

		$result = $this->service->run($this->register, $this->schema(), dryRun: true);

		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->warnings);
		$this->assertSame([['uuid' => self::UUID_A, 'old' => null, 'new' => 'hello-world']], $result['plan']);
	}
}//end class
