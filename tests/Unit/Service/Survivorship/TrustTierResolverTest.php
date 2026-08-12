<?php

/**
 * TrustTierResolver unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#7.1
 */

declare(strict_types=1);

namespace Unit\Service\Survivorship;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use PHPUnit\Framework\TestCase;

class TrustTierResolverTest extends TestCase {

	private TrustTierResolver $resolver;

	private DateTimeImmutable $asOf;

	protected function setUp(): void {
		$this->resolver = new TrustTierResolver();
		$this->asOf = new DateTimeImmutable('2026-06-01');
	}//end setUp()

	public function testMostRecentEffectiveRowWins(): void {
		$rows = [
			['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'crm', 'trustTier' => 'silver', 'effectiveFrom' => '2025-01-01'],
			['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'crm', 'trustTier' => 'gold', 'effectiveFrom' => '2026-01-01'],
		];

		$tier = $this->resolver->resolveTier('organisation', 'legalName', 'crm', $rows, $this->asOf);

		$this->assertSame('gold', $tier);
	}//end testMostRecentEffectiveRowWins()

	public function testFutureDatedRowIsIgnored(): void {
		$rows = [
			['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'crm', 'trustTier' => 'gold', 'effectiveFrom' => '2027-01-01'],
		];

		$tier = $this->resolver->resolveTier('organisation', 'legalName', 'crm', $rows, $this->asOf);

		$this->assertNull($tier);
	}//end testFutureDatedRowIsIgnored()

	public function testNoMatchingRowReturnsNull(): void {
		$rows = [
			['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'other', 'trustTier' => 'gold', 'effectiveFrom' => '2026-01-01'],
		];

		$tier = $this->resolver->resolveTier('organisation', 'legalName', 'crm', $rows, $this->asOf);

		$this->assertNull($tier);
	}//end testNoMatchingRowReturnsNull()

	public function testRowWithoutEffectiveFromIsAlwaysEffective(): void {
		$rows = [
			['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'crm', 'trustTier' => 'bronze'],
		];

		$tier = $this->resolver->resolveTier('organisation', 'legalName', 'crm', $rows, $this->asOf);

		$this->assertSame('bronze', $tier);
	}//end testRowWithoutEffectiveFromIsAlwaysEffective()

	public function testMalformedRowsAreSkippedNullSafe(): void {
		$rows = [
			'not-an-array',
			['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'crm'],
			['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'crm', 'trustTier' => 'gold', 'effectiveFrom' => 'not-a-date'],
		];

		// The unparseable effectiveFrom is treated as null (always effective),
		// so this row still resolves; nothing throws.
		$tier = $this->resolver->resolveTier('organisation', 'legalName', 'crm', $rows, $this->asOf);

		$this->assertSame('gold', $tier);
	}//end testMalformedRowsAreSkippedNullSafe()

	public function testApplyFreshnessDecayStepsOneTierDown(): void {
		$tierOrder = ['discard', 'bronze', 'silver', 'gold'];
		$anchor = new DateTimeImmutable('2026-02-01');
		// 120 days elapsed (2026-02-01 -> 2026-06-01), decay window 90.
		$result = $this->resolver->applyFreshnessDecay('gold', $tierOrder, 90, $anchor, $this->asOf);

		$this->assertSame('silver', $result);
	}//end testApplyFreshnessDecayStepsOneTierDown()

	public function testApplyFreshnessDecayWithinWindowUnchanged(): void {
		$tierOrder = ['discard', 'bronze', 'silver', 'gold'];
		$anchor = new DateTimeImmutable('2026-05-20');
		$result = $this->resolver->applyFreshnessDecay('gold', $tierOrder, 90, $anchor, $this->asOf);

		$this->assertSame('gold', $result);
	}//end testApplyFreshnessDecayWithinWindowUnchanged()

	public function testApplyFreshnessDecayNullSafe(): void {
		$tierOrder = ['discard', 'bronze', 'silver', 'gold'];

		// Null decay days -> unchanged.
		$this->assertSame('gold', $this->resolver->applyFreshnessDecay('gold', $tierOrder, null, new DateTimeImmutable('2020-01-01'), $this->asOf));
		// Null anchor -> unchanged.
		$this->assertSame('gold', $this->resolver->applyFreshnessDecay('gold', $tierOrder, 10, null, $this->asOf));
		// Non-positive decay days -> unchanged.
		$this->assertSame('gold', $this->resolver->applyFreshnessDecay('gold', $tierOrder, 0, new DateTimeImmutable('2020-01-01'), $this->asOf));
	}//end testApplyFreshnessDecayNullSafe()

	public function testStepDownAtWeakestTierStaysUnchanged(): void {
		$tierOrder = ['discard', 'bronze', 'silver', 'gold'];
		$this->assertSame('discard', $this->resolver->stepDown('discard', $tierOrder));
	}//end testStepDownAtWeakestTierStaysUnchanged()

	public function testStepDownUnknownTierStaysUnchanged(): void {
		$tierOrder = ['discard', 'bronze', 'silver', 'gold'];
		$this->assertSame('platinum', $this->resolver->stepDown('platinum', $tierOrder));
	}//end testStepDownUnknownTierStaysUnchanged()

	public function testTierRankUnknownTierIsNegativeOne(): void {
		$tierOrder = ['discard', 'bronze', 'silver', 'gold'];
		$this->assertSame(-1, $this->resolver->tierRank('platinum', $tierOrder));
		$this->assertSame(3, $this->resolver->tierRank('gold', $tierOrder));
		$this->assertSame(0, $this->resolver->tierRank('discard', $tierOrder));
	}//end testTierRankUnknownTierIsNegativeOne()
}//end class
