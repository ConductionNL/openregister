<?php

/**
 * Pins the branch outcomes of the survivorship resolver, trust-tier resolver and annotation validator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Survivorship;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\SourceFreshness;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTuple;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver
 * @covers \OCA\OpenRegister\Service\Survivorship\TrustTierResolver
 * @covers \OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator
 */
final class SurvivorshipBranchesGoldenTest extends TestCase {

	/**
	 * Fixed reference instant (no clock).
	 *
	 * @return DateTimeImmutable
	 */
	private function asOf(): DateTimeImmutable {
		return new DateTimeImmutable('2026-06-01T00:00:00+00:00');
	}//end asOf()

	/**
	 * Run the resolver.
	 *
	 * @param array<int, mixed>    $sources   Source records.
	 * @param array<string, mixed> $config    Config.
	 * @param array<int, mixed>    $trustRows Trust rows.
	 * @param mixed                $overrides Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve(array $sources, array $config=[], array $trustRows=[], mixed $overrides=null): array {
		return (new SurvivorshipResolver())->resolveGoldenRecord(
			'org',
			$sources,
			$config,
			$trustRows,
			new TrustTierResolver(),
			$this->asOf(),
			$overrides
		);
	}//end resolve()

	/**
	 * Withdrawn, non-array and value-less records are skipped; mappedAttributes is a fallback.
	 *
	 * @return void
	 */
	public function testRecordFilteringBranches(): void {
		$result = $this->resolve(
			[
				'not-a-record',
				['sourceSystem' => 'a', 'withdrawn' => true, 'values' => ['name' => 'withdrawn']],
				['sourceSystem' => 'b', 'values' => 'scalar'],
				['sourceSystem' => 'c', 'mappedAttributes' => ['name' => 'mapped', 'blank' => '  ', 'none' => null, 7 => 'numeric']],
				['sourceSystem' => 'd', 'withdrawn' => 'yes', 'values' => ['city' => 'Utrecht']],
			]
		);

		$this->assertSame(['name' => 'mapped', '7' => 'numeric', 'city' => 'Utrecht'], $result['goldenRecord']);
		$this->assertSame(
			['value' => 'mapped', 'sourceSystem' => 'c', 'trustTier' => 'bronze', 'lastUpdated' => ''],
			$result['attributeProvenance']['name']
		);
	}//end testRecordFilteringBranches()

	/**
	 * Discard tier, default tier, explicit tiers and the date tie-break.
	 *
	 * @return void
	 */
	public function testTierAndTieBreakBranches(): void {
		$config = ['freshnessAnchorField' => 'updated'];
		$rows   = [
			'junk',
			['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'crm', 'trustTier' => 'gold'],
			['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'junk', 'trustTier' => 'discard'],
			['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'unknown', 'trustTier' => 'platinum'],
		];
		$result = $this->resolve(
			[
				['sourceSystem' => 'junk', 'values' => ['name' => 'J', 'city' => 'J-city']],
				['sourceSystem' => 'unknown', 'values' => ['name' => 'U']],
				['sourceSystem' => 'old', 'updated' => '2025-01-01', 'values' => ['city' => 'Old']],
				['sourceSystem' => 'nodate', 'values' => ['city' => 'NoDate']],
				['sourceSystem' => 'new', 'updated' => '2026-01-01', 'values' => ['city' => 'New']],
				['sourceSystem' => 'bad', 'updated' => 'not a date', 'values' => ['city' => 'Bad']],
				['sourceSystem' => 'crm', 'updated' => '2020-01-01', 'values' => ['name' => 'CRM']],
			],
			$config,
			$rows
		);

		$this->assertSame('CRM', $result['goldenRecord']['name']);
		$this->assertSame('gold', $result['attributeProvenance']['name']['trustTier']);
		$this->assertSame('New', $result['goldenRecord']['city']);
		$this->assertSame('2026-01-01T00:00:00+00:00', $result['attributeProvenance']['city']['lastUpdated']);

		// Equal ranks without any dates: the first candidate stays.
		$tie = $this->resolve(
			[
				['sourceSystem' => 'x', 'values' => ['name' => 'first']],
				['sourceSystem' => 'y', 'values' => ['name' => 'second']],
			]
		);
		$this->assertSame('first', $tie['goldenRecord']['name']);

		// Unknown tiers only: nothing wins.
		$none = $this->resolve([['sourceSystem' => 'unknown', 'values' => ['name' => 'U']]], [], $rows);
		$this->assertSame([], $none['goldenRecord']);
	}//end testTierAndTieBreakBranches()

	/**
	 * Freshness decay reads the first numeric decay window of a matching row.
	 *
	 * @return void
	 */
	public function testDecayWindowBranches(): void {
		$config = ['freshnessAnchorField' => 'updated'];
		$rows   = [
			['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'crm', 'trustTier' => 'gold', 'freshnessDecayDays' => 'x'],
			['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'crm', 'trustTier' => 'gold', 'freshnessDecayDays' => '10'],
			['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'erp', 'trustTier' => 'silver', 'freshnessDecayDays' => 10],
			['entityType' => 'other', 'attribute' => 'name', 'sourceSystem' => 'crm', 'freshnessDecayDays' => 1],
		];
		$stale  = $this->resolve(
			[
				['sourceSystem' => 'crm', 'updated' => '2026-05-01', 'values' => ['name' => 'CRM']],
				['sourceSystem' => 'erp', 'updated' => '2026-05-30', 'values' => ['name' => 'ERP']],
			],
			$config,
			$rows
		);
		// The crm source decays gold -> silver; erp is fresh silver and more recent.
		$this->assertSame('ERP', $stale['goldenRecord']['name']);
		$this->assertSame('silver', $stale['attributeProvenance']['name']['trustTier']);

		$fresh = $this->resolve(
			[
				['sourceSystem' => 'crm', 'updated' => '2026-05-25', 'values' => ['name' => 'CRM']],
				['sourceSystem' => 'erp', 'updated' => '2026-05-30', 'values' => ['name' => 'ERP']],
			],
			$config,
			$rows
		);
		$this->assertSame('CRM', $fresh['goldenRecord']['name']);
		$this->assertSame('gold', $fresh['attributeProvenance']['name']['trustTier']);
	}//end testDecayWindowBranches()

	/**
	 * Malformed and valid tierOrder values.
	 *
	 * @return void
	 */
	public function testTierOrderBranches(): void {
		$rows    = [['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'a', 'trustTier' => 'top']];
		$sources = [
			['sourceSystem' => 'a', 'values' => ['name' => 'A']],
			['sourceSystem' => 'b', 'values' => ['name' => 'B']],
		];

		// Declared order with junk entries is cleaned; "top" outranks "low".
		$custom = $this->resolve($sources, ['tierOrder' => ['', 5, 'low', 'top'], 'defaultTier' => 'low'], $rows);
		$this->assertSame('A', $custom['goldenRecord']['name']);

		// Non-array, empty and all-junk orders fall back to the default ("top" is unknown => b wins at bronze).
		foreach (['gold', [], ['', 3]] as $order) {
			$fallback = $this->resolve($sources, ['tierOrder' => $order], $rows);
			$this->assertSame('B', $fallback['goldenRecord']['name']);
		}
	}//end testTierOrderBranches()

	/**
	 * Override normalisation branches.
	 *
	 * @return void
	 */
	public function testOverrideBranches(): void {
		$result = $this->resolve(
			[['sourceSystem' => 'a', 'values' => ['name' => 'A']]],
			[],
			[],
			[
				0 => 'numeric key',
				'' => 'empty key',
				'noValue' => ['overriddenBy' => 'u'],
				'blankValue' => ['value' => ' '],
				'badRationale' => ['value' => 'v', 'rationale' => new \stdClass()],
				'full' => ['value' => 'F', 'overriddenBy' => 'admin', 'rationale' => 42],
				'bare' => 'B',
				'bareBlank' => '',
				'bareNull' => null,
				'name' => ['value' => 'Overridden'],
			]
		);

		$this->assertSame(['name' => 'Overridden', 'full' => 'F', 'bare' => 'B'], $result['goldenRecord']);
		$this->assertSame(
			['value' => 'F', 'override' => true, 'overriddenBy' => 'admin', 'rationale' => '42'],
			$result['attributeProvenance']['full']
		);
		$this->assertSame(
			['value' => 'B', 'override' => true, 'overriddenBy' => '', 'rationale' => null],
			$result['attributeProvenance']['bare']
		);
		$this->assertSame(
			['value' => 'Overridden', 'override' => true, 'overriddenBy' => '', 'rationale' => null],
			$result['attributeProvenance']['name']
		);

		$this->assertSame(['name' => 'A'], $this->resolve([['sourceSystem' => 'a', 'values' => ['name' => 'A']]], [], [], 'junk')['goldenRecord']);
	}//end testOverrideBranches()

	/**
	 * TrustTierResolver: effectiveFrom ranking, future rows, ties and empty tiers.
	 *
	 * @return void
	 */
	public function testResolveTierBranches(): void {
		$resolver = new TrustTierResolver();
		$base     = ['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'crm'];
		$rows     = [
			'junk',
			$base + ['trustTier' => 'bronze'],
			$base + ['trustTier' => 'silver', 'effectiveFrom' => '2026-01-01'],
			$base + ['trustTier' => 'gold', 'effectiveFrom' => '2027-01-01'],
			$base + ['trustTier' => '', 'effectiveFrom' => '2026-03-01'],
			$base + ['trustTier' => 'tin', 'effectiveFrom' => 'garbage'],
			['entityType' => 'org', 'attribute' => 'name', 'sourceSystem' => 'erp', 'trustTier' => 'gold'],
		];
		$this->assertSame('silver', $resolver->resolveTier(new TrustTuple('org', 'name', 'crm'), $rows, $this->asOf()));
		$this->assertNull($resolver->resolveTier(new TrustTuple('org', 'name', 'none'), $rows, $this->asOf()));

		// Equal ranks: the later row wins.
		$ties = [$base + ['trustTier' => 'one'], $base + ['trustTier' => 'two']];
		$this->assertSame('two', $resolver->resolveTier(new TrustTuple('org', 'name', 'crm'), $ties, $this->asOf()));
		$dated = [$base + ['trustTier' => 'one', 'effectiveFrom' => '2026-01-01'], $base + ['trustTier' => 'two', 'effectiveFrom' => '2026-01-01']];
		$this->assertSame('two', $resolver->resolveTier(new TrustTuple('org', 'name', 'crm'), $dated, $this->asOf()));
		// Exactly asOf is effective.
		$exact = [$base + ['trustTier' => 'now', 'effectiveFrom' => '2026-06-01T00:00:00+00:00']];
		$this->assertSame('now', $resolver->resolveTier(new TrustTuple('org', 'name', 'crm'), $exact, $this->asOf()));
	}//end testResolveTierBranches()

	/**
	 * TrustTierResolver::applyFreshnessDecay branches.
	 *
	 * @return void
	 */
	public function testFreshnessDecayBranches(): void {
		$resolver = new TrustTierResolver();
		$order    = ['discard', 'bronze', 'silver', 'gold'];
		$asOf     = $this->asOf();
		$anchor   = new DateTimeImmutable('2026-05-22T00:00:00+00:00');

		$this->assertSame('gold', $resolver->applyFreshnessDecay('gold', $order, new SourceFreshness(null, $anchor, $asOf)));
		$this->assertSame('gold', $resolver->applyFreshnessDecay('gold', $order, new SourceFreshness(0, $anchor, $asOf)));
		$this->assertSame('gold', $resolver->applyFreshnessDecay('gold', $order, new SourceFreshness(-3, $anchor, $asOf)));
		$this->assertSame('gold', $resolver->applyFreshnessDecay('gold', $order, new SourceFreshness(5, null, $asOf)));
		$this->assertSame('gold', $resolver->applyFreshnessDecay('gold', $order, new SourceFreshness(10, $anchor, $asOf)));
		$this->assertSame('silver', $resolver->applyFreshnessDecay('gold', $order, new SourceFreshness(9, $anchor, $asOf)));
		$this->assertSame('discard', $resolver->applyFreshnessDecay('discard', $order, new SourceFreshness(1, $anchor, $asOf)));
		$this->assertSame('odd', $resolver->applyFreshnessDecay('odd', $order, new SourceFreshness(1, $anchor, $asOf)));
	}//end testFreshnessDecayBranches()

	/**
	 * Validator: sourceLink and trustLookup branches.
	 *
	 * @return void
	 */
	public function testValidatorBranches(): void {
		$validator = new SurvivorshipAnnotationValidator();
		$codes     = static function (array $annotation) use ($validator): array {
			return array_column($validator->validate(['x-openregister-survivorship' => $annotation]), 'code');
		};

		$this->assertSame([], $codes(['sourceLinkField' => 'src']));
		$this->assertSame(['survivorship.source-link-not-object'], $codes(['sourceLinkField' => 'src', 'sourceLink' => 'x']));
		$this->assertSame([], $codes(['sourceLinkField' => 'src', 'sourceLink' => ['mode' => 'embedded']]));
		$this->assertSame([], $codes(['sourceLinkField' => 'src', 'sourceLink' => []]));
		$this->assertSame([], $codes(['sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => 's', 'referenceField' => 'r']]));
		$this->assertSame(
			['survivorship.source-link-reverse-fk-incomplete', 'survivorship.missing-source-link-field'],
			$codes(['sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => 's']])
		);
		$this->assertSame(
			['survivorship.source-link-reverse-fk-incomplete'],
			$codes(['sourceLinkField' => 'src', 'sourceLink' => ['mode' => 'reverseFk', 'referenceField' => 'r']])
		);

		$this->assertSame(['survivorship.trust-lookup-not-object'], $codes(['sourceLinkField' => 'src', 'trustLookup' => 'x']));
		$this->assertSame([], $codes(['sourceLinkField' => 'src', 'trustLookup' => []]));
		$this->assertSame([], $codes(['sourceLinkField' => 'src', 'trustLookup' => ['keys' => ['a']]]));
		$this->assertSame(['survivorship.trust-lookup-keys-invalid'], $codes(['sourceLinkField' => 'src', 'trustLookup' => ['keys' => []]]));
		$this->assertSame(['survivorship.trust-lookup-keys-invalid'], $codes(['sourceLinkField' => 'src', 'trustLookup' => ['keys' => 'a']]));
	}//end testValidatorBranches()
}//end class
