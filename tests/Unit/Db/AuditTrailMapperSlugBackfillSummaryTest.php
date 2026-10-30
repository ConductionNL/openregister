<?php

/**
 * Result summary of the slug backfill audit entry (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\TestCase;

/**
 * The entry is hash-chained and immutable, so what it stores is bounded.
 */
class AuditTrailMapperSlugBackfillSummaryTest extends TestCase {

	private AuditTrailMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->getMockBuilder(AuditTrailMapper::class)->disableOriginalConstructor()->onlyMethods(['insert'])->getMock();
	}//end setUp()

	/**
	 * Changes with extra keys that must not be copied.
	 *
	 * @param int $count How many.
	 *
	 * @return list<array> The changes.
	 */
	private function changes(int $count): array {
		$changes = [];
		for ($i = 1; $i <= $count; $i++) {
			$changes[] = ['uuid' => 'uuid-' . $i, 'old' => 'old-' . $i, 'new' => 'new-' . $i, 'title' => 'Jan de Vries'];
		}

		return $changes;
	}//end changes()

	public function testALargeRunStaysUnderTheCapAndCarriesTheTotal(): void {
		// Review finding r2routes-f6: 2,500 changes.
		$result = $this->mapper->slugBackfillResultSummary('fill', $this->changes(2500), ['assigned' => 2500]);

		$this->assertCount(AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES, $result['changes']);
		$this->assertSame(2500, $result['changesTotal']);
		$this->assertTrue($result['truncated']);
		$this->assertStringContainsString('first 1000 of 2500', $result['note']);
		$this->assertSame(['uuid' => 'uuid-1', 'old' => 'old-1', 'new' => 'new-1'], $result['changes'][0], 'only uuid, old and new are copied');
		$this->assertSame(['assigned' => 2500], $result['summary']);
	}//end testALargeRunStaysUnderTheCapAndCarriesTheTotal()

	public function testASmallRunIsListedInFull(): void {
		$result = $this->mapper->slugBackfillResultSummary('fill', $this->changes(3), []);

		$this->assertCount(3, $result['changes']);
		$this->assertSame(3, $result['changesTotal']);
		$this->assertFalse($result['truncated']);
		$this->assertArrayNotHasKey('note', $result);
	}//end testASmallRunIsListedInFull()

	public function testARunOfExactlyTheCapIsNotTruncated(): void {
		$result = $this->mapper->slugBackfillResultSummary('fill', $this->changes(AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES), []);

		$this->assertFalse($result['truncated']);
	}//end testARunOfExactlyTheCapIsNotTruncated()
}//end class
