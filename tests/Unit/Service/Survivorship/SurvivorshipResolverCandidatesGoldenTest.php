<?php

/**
 * Pins how SurvivorshipResolver collects candidates when one attribute of a record fails.
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
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipTrustLookup;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTuple;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver
 */
final class SurvivorshipResolverCandidatesGoldenTest extends TestCase {

	/**
	 * A trust lookup that fails for one attribute; every other answer is the real one.
	 *
	 * @return TrustTierResolver
	 */
	private function failingOn(string $attribute): TrustTierResolver {
		return new class($attribute) extends TrustTierResolver {
			/**
			 * @param string $failing The attribute whose lookup fails.
			 */
			public function __construct(private readonly string $failing) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function resolveTier(TrustTuple $tuple, array $trustRows, DateTimeImmutable $asOf): ?string {
				if ($tuple->attribute === $this->failing) {
					throw new RuntimeException('trust lookup failed');
				}

				return parent::resolveTier($tuple, $trustRows, $asOf);
			}
		};
	}//end failingOn()

	/**
	 * Attributes collected before the failure keep their candidate; the rest of
	 * that record is dropped, and the next record is still read.
	 *
	 * @return void
	 */
	public function testAFailureMidRecordKeepsWhatWasCollectedAndReadsTheNextRecord(): void {
		$config = [
			'tierOrder' => ['discard', 'bronze', 'silver', 'gold'],
			'defaultTier' => 'silver',
			'discardTier' => 'discard',
			'freshnessAnchorField' => 'lastUpdated',
		];
		$sources = [
			['sourceSystem' => 'brp', 'lastUpdated' => '2026-01-02T00:00:00+00:00', 'values' => ['name' => 'A', 'city' => 'X', 'zip' => '1']],
			['sourceSystem' => 'crm', 'lastUpdated' => '2026-01-01T00:00:00+00:00', 'values' => ['city' => 'Y', 'zip' => '2']],
		];

		$result = (new SurvivorshipResolver())->resolveGoldenRecord(
			$sources,
			$config,
			new SurvivorshipTrustLookup('person', [], $this->failingOn(attribute: 'city'), new DateTimeImmutable('2026-06-01T00:00:00+00:00'))
		);

		$this->assertSame(['name' => 'A'], $result['goldenRecord']);
		$this->assertSame(
			['name' => ['value' => 'A', 'sourceSystem' => 'brp', 'trustTier' => 'silver', 'lastUpdated' => '2026-01-02T00:00:00+00:00']],
			$result['attributeProvenance']
		);
	}//end testAFailureMidRecordKeepsWhatWasCollectedAndReadsTheNextRecord()

	/**
	 * With the lookup failing nowhere, both records compete and the newest wins a tie.
	 *
	 * @return void
	 */
	public function testWithoutAFailureBothRecordsCompete(): void {
		$config = [
			'tierOrder' => ['discard', 'bronze', 'silver', 'gold'],
			'defaultTier' => 'silver',
			'discardTier' => 'discard',
			'freshnessAnchorField' => 'lastUpdated',
		];
		$sources = [
			['sourceSystem' => 'brp', 'lastUpdated' => '2026-01-01T00:00:00+00:00', 'values' => ['city' => 'X', 'zip' => '']],
			['sourceSystem' => 'crm', 'lastUpdated' => '2026-01-03T00:00:00+00:00', 'values' => ['city' => 'Y', 'zip' => '2']],
			'not a record',
		];

		$result = (new SurvivorshipResolver())->resolveGoldenRecord(
			$sources,
			$config,
			new SurvivorshipTrustLookup('person', [], $this->failingOn(attribute: 'none'), new DateTimeImmutable('2026-06-01T00:00:00+00:00'))
		);

		$this->assertSame(['city' => 'Y', 'zip' => '2'], $result['goldenRecord']);
		$this->assertSame('crm', $result['attributeProvenance']['city']['sourceSystem']);
	}//end testWithoutAFailureBothRecordsCompete()
}//end class
