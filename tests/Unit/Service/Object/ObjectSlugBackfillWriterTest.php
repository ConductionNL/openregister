<?php

/**
 * ObjectSlugBackfillWriter unit tests (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\SlugBackfillWriteException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillWriter;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the chunked write, the audit entry and the cache eviction of the backfill.
 */
class ObjectSlugBackfillWriterTest extends TestCase {

	/** @var MagicMapper&MockObject */
	private MagicMapper $magicMapper;

	/** @var CacheHandler&MockObject */
	private CacheHandler $cacheHandler;

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var list<array> Audit entries as the arguments of createSlugBackfillEntry. */
	private array $audits = [];

	private Register $register;

	private Schema $schema;

	private ObjectSlugBackfillWriter $writer;

	protected function setUp(): void {
		parent::setUp();

		$this->register = new Register();
		$this->register->setId(7);
		$this->register->setSlug('woo');
		$this->schema = new Schema();
		$this->schema->setId(11);
		$this->schema->setSlug('convenanten');

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->db = $this->createMock(IDBConnection::class);
		$auditTrail = $this->createMock(AuditTrailMapper::class);
		$auditTrail->method('createSlugBackfillEntry')->willReturnCallback(
			function (string $mode, array $changes, array $summary, ?int $register=null, ?int $schema=null): AuditTrail {
				$this->audits[] = ['mode' => $mode, 'changes' => $changes, 'summary' => $summary];
				return new AuditTrail();
			}
		);

		$this->writer = new ObjectSlugBackfillWriter(
			magicMapper: $this->magicMapper,
			cacheHandler: $this->cacheHandler,
			db: $this->db,
			auditTrail: $auditTrail,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A plan of $count writes.
	 *
	 * @param int $count How many steps.
	 *
	 * @return list<array{uuid: string, old: ?string, new: ?string}> The plan.
	 */
	private function plan(int $count): array {
		$plan = [];
		for ($i = 1; $i <= $count; $i++) {
			$plan[] = ['uuid' => sprintf('00000000-0000-0000-0000-%012d', $i), 'old' => null, 'new' => 'slug-' . $i];
		}

		return $plan;
	}//end plan()

	public function testAFailingSecondChunkKeepsTheFirstAuditsItAndEvictsTheCache(): void {
		// Review finding r2routes-f2: 500 rows per chunk, the 501st write throws.
		$calls = 0;
		$this->magicMapper->method('updateSlugColumn')->willReturnCallback(
			function () use (&$calls): int {
				$calls++;
				if ($calls === 501) {
					throw new RuntimeException('deadlock');
				}

				return 1;
			}
		);
		$this->db->expects($this->exactly(2))->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->once())->method('rollBack');
		$this->cacheHandler->expects($this->once())->method('invalidateForObjectChange');

		try {
			$this->writer->write($this->plan(600), $this->register, $this->schema, 'fill', ['changed' => 600, 'skipped' => 0]);
			$this->fail('the failing chunk must reach the caller');
		} catch (SlugBackfillWriteException $e) {
			$this->assertSame(500, $e->getApplied());
			$this->assertStringContainsString('deadlock', $e->getMessage());
			$this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
		}

		$this->assertCount(1, $this->audits);
		$this->assertCount(500, $this->audits[0]['changes'], 'the entry lists chunk 1 only');
	}//end testAFailingSecondChunkKeepsTheFirstAuditsItAndEvictsTheCache()

	public function testAFailingFirstChunkWritesNoEntryAndEvictsNothing(): void {
		$this->magicMapper->method('updateSlugColumn')->willThrowException(new RuntimeException('down'));
		$this->cacheHandler->expects($this->never())->method('invalidateForObjectChange');

		try {
			$this->writer->write($this->plan(3), $this->register, $this->schema, 'fill', ['skipped' => 0]);
			$this->fail('the failing chunk must reach the caller');
		} catch (SlugBackfillWriteException $e) {
			$this->assertSame(0, $e->getApplied());
		}

		$this->assertSame([], $this->audits);
	}//end testAFailingFirstChunkWritesNoEntryAndEvictsNothing()
}//end class
