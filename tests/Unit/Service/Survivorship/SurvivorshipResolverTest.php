<?php

/**
 * SurvivorshipResolver unit tests.
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
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#7.2
 */

declare(strict_types=1);

namespace Unit\Service\Survivorship;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use PHPUnit\Framework\TestCase;

class SurvivorshipResolverTest extends TestCase
{

    private SurvivorshipResolver $resolver;

    private TrustTierResolver $trustResolver;

    private DateTimeImmutable $asOf;

    private array $baseConfig;

    protected function setUp(): void
    {
        $this->resolver      = new SurvivorshipResolver();
        $this->trustResolver = new TrustTierResolver();
        $this->asOf          = new DateTimeImmutable('2026-06-01');
        $this->baseConfig    = [
            'sourceLinkField'      => 'sources',
            'goldenRecordField'    => 'goldenRecord',
            'provenanceField'      => 'attributeProvenance',
            'tierOrder'            => ['discard', 'bronze', 'silver', 'gold'],
            'defaultTier'          => 'bronze',
            'discardTier'          => 'discard',
            'freshnessAnchorField' => 'lastUpdated',
        ];
    }//end setUp()

    public function testHigherTierWinsTheAttribute(): void
    {
        $sources   = [
            ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Silver Co']],
            ['sourceSystem' => 'registry', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Gold Co']],
        ];
        $trustRows = [
            ['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'crm', 'trustTier' => 'silver', 'effectiveFrom' => '2026-01-01'],
            ['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'registry', 'trustTier' => 'gold', 'effectiveFrom' => '2026-01-01'],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, $trustRows, $this->trustResolver, $this->asOf);

        $this->assertSame('Gold Co', $result['goldenRecord']['legalName']);
        $this->assertSame('gold', $result['attributeProvenance']['legalName']['trustTier']);
        $this->assertSame('registry', $result['attributeProvenance']['legalName']['sourceSystem']);
    }//end testHigherTierWinsTheAttribute()

    public function testDiscardTierValueIsNeverSelected(): void
    {
        $sources   = [
            ['sourceSystem' => 'stale-import', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Bad Co']],
        ];
        $trustRows = [
            ['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'stale-import', 'trustTier' => 'discard', 'effectiveFrom' => '2026-01-01'],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, $trustRows, $this->trustResolver, $this->asOf);

        $this->assertArrayNotHasKey('legalName', $result['goldenRecord']);
    }//end testDiscardTierValueIsNeverSelected()

    public function testUncontestedSourceWithNoTrustRowUsesDefaultTier(): void
    {
        $sources = [
            ['sourceSystem' => 'unknown-source', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Lone Co']],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, [], $this->trustResolver, $this->asOf);

        $this->assertSame('Lone Co', $result['goldenRecord']['legalName']);
        $this->assertSame('bronze', $result['attributeProvenance']['legalName']['trustTier']);
    }//end testUncontestedSourceWithNoTrustRowUsesDefaultTier()

    public function testWithdrawnAndEmptySourcesAreExcluded(): void
    {
        $sources = [
            ['sourceSystem' => 'crm', 'withdrawn' => true, 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Withdrawn Co']],
            ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => '']],
            ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => null]],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, [], $this->trustResolver, $this->asOf);

        $this->assertSame([], $result['goldenRecord']);
    }//end testWithdrawnAndEmptySourcesAreExcluded()

    public function testTieBrokenByMostRecentDateNotLexical(): void
    {
        // Same tier (both default bronze); dates in different (but both
        // parseable) formats where a lexical string compare disagrees with
        // the chronological order: "2026-1-9" > "2026-01-10" lexically (the
        // char '1' beats '0' at the differing position), but chronologically
        // 2026-01-10 is one day LATER than 2026-01-09. A lexical tie-break
        // would therefore wrongly pick A; the date-correct resolver must pick B.
        $sources = [
            ['sourceSystem' => 'a', 'lastUpdated' => '2026-1-9', 'values' => ['phone' => 'A-number']],
            ['sourceSystem' => 'b', 'lastUpdated' => '2026-01-10', 'values' => ['phone' => 'B-number']],
        ];

        $this->assertTrue('2026-1-9' > '2026-01-10', 'Precondition: lexical compare must disagree with chronological order.');

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, [], $this->trustResolver, $this->asOf);

        $this->assertSame('B-number', $result['goldenRecord']['phone']);
    }//end testTieBrokenByMostRecentDateNotLexical()

    public function testTieBrokenByMostRecentDateSimpleIsoCase(): void
    {
        $sources = [
            ['sourceSystem' => 'a', 'lastUpdated' => '2026-01-09', 'values' => ['phone' => 'A-number']],
            ['sourceSystem' => 'b', 'lastUpdated' => '2026-01-10', 'values' => ['phone' => 'B-number']],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, [], $this->trustResolver, $this->asOf);

        $this->assertSame('B-number', $result['goldenRecord']['phone']);
    }//end testTieBrokenByMostRecentDateSimpleIsoCase()

    public function testMalformedSourceIsSkippedNeverThrows(): void
    {
        $sources = [
            'not-an-array',
            ['sourceSystem' => 'crm', 'values' => 'not-an-array-either'],
            ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Good Co']],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, [], $this->trustResolver, $this->asOf);

        $this->assertSame('Good Co', $result['goldenRecord']['legalName']);
    }//end testMalformedSourceIsSkippedNeverThrows()

    public function testFreshnessDecayStepsWinnerDownAndLosesToGenuineGold(): void
    {
        $config    = $this->baseConfig;
        $sources   = [
            // stale-gold decays to silver after 90 days: anchor 120 days before asOf.
            ['sourceSystem' => 'stale-gold-source', 'lastUpdated' => '2026-02-01', 'values' => ['legalName' => 'Stale Gold Co']],
            ['sourceSystem' => 'fresh-gold-source', 'lastUpdated' => '2026-05-25', 'values' => ['legalName' => 'Fresh Gold Co']],
        ];
        $trustRows = [
            ['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'stale-gold-source', 'trustTier' => 'gold', 'freshnessDecayDays' => 90, 'effectiveFrom' => '2026-01-01'],
            ['entityType' => 'organisation', 'attribute' => 'legalName', 'sourceSystem' => 'fresh-gold-source', 'trustTier' => 'gold', 'freshnessDecayDays' => 90, 'effectiveFrom' => '2026-01-01'],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $config, $trustRows, $this->trustResolver, $this->asOf);

        $this->assertSame('Fresh Gold Co', $result['goldenRecord']['legalName']);
        $this->assertSame('gold', $result['attributeProvenance']['legalName']['trustTier']);
    }//end testFreshnessDecayStepsWinnerDownAndLosesToGenuineGold()

    public function testEmptySourceListYieldsEmptyGoldenRecord(): void
    {
        $result = $this->resolver->resolveGoldenRecord('organisation', [], $this->baseConfig, [], $this->trustResolver, $this->asOf);

        $this->assertSame([], $result['goldenRecord']);
        $this->assertSame([], $result['attributeProvenance']);
    }//end testEmptySourceListYieldsEmptyGoldenRecord()

    public function testTierOrderDefaultsWhenAbsent(): void
    {
        $config = $this->baseConfig;
        unset($config['tierOrder'], $config['defaultTier'], $config['discardTier']);

        $sources = [
            ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Co']],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $config, [], $this->trustResolver, $this->asOf);

        $this->assertSame('Co', $result['goldenRecord']['legalName']);
        $this->assertSame('bronze', $result['attributeProvenance']['legalName']['trustTier']);
    }//end testTierOrderDefaultsWhenAbsent()

    public function testMappedAttributesKeyIsAlsoSupported(): void
    {
        // pipelinq-style key name for the values block.
        $sources = [
            ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'mappedAttributes' => ['legalName' => 'Legacy Co']],
        ];

        $result = $this->resolver->resolveGoldenRecord('organisation', $sources, $this->baseConfig, [], $this->trustResolver, $this->asOf);

        $this->assertSame('Legacy Co', $result['goldenRecord']['legalName']);
    }//end testMappedAttributesKeyIsAlsoSupported()
}//end class
