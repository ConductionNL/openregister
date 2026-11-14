<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Confidentiality;

use OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * A confidentiality block is checked against the schema's own properties.
 *
 * @covers \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 */
final class ConfidentialityAnnotationValidatorTest extends TestCase {

	private const PROPERTIES = [
		'confidentialityTier' => ['type' => 'string'],
		'confidentialityGround' => ['type' => 'string'],
		'confidentialityReleaseUntil' => ['type' => 'string', 'format' => 'date-time'],
		'decidedOn' => ['type' => 'string', 'format' => 'date'],
	];

	/**
	 * A valid block with entries replaced.
	 *
	 * @param array<string, mixed> $change Entries to set.
	 *
	 * @return array<string, mixed>
	 */
	private static function block(array $change=[]): array {
		return array_merge(
			[
				'tiers' => ['public', 'confidential'],
				'tierProperty' => 'confidentialityTier',
				'groundProperty' => 'confidentialityGround',
				'releaseAtProperty' => 'confidentialityReleaseUntil',
				'clearance' => ['confidential' => 'raadsleden'],
			],
			$change
		);
	}

	/**
	 * The faults of a block.
	 *
	 * @param mixed $block The block.
	 *
	 * @return array<int, string>
	 */
	private static function faults(mixed $block): array {
		return (new ConfidentialityAnnotationValidator())->validate(block: $block, properties: self::PROPERTIES);
	}

	public function testAValidAnnotationIsAccepted(): void {
		$this->assertSame([], self::faults(self::block()));
		$minimal = self::block();
		unset($minimal['groundProperty'], $minimal['releaseAtProperty']);
		$this->assertSame([], self::faults($minimal));
	}

	public function testAnUndeclaredTierPropertyIsNamed(): void {
		$this->assertSame(
			['tierProperty "vertrouwelijkheid" is not a property of this schema'],
			self::faults(self::block(['tierProperty' => 'vertrouwelijkheid']))
		);
		$this->assertSame(['tierProperty must name a property'], self::faults(self::block(['tierProperty' => null])));
	}

	public function testAClearanceTierOutsideTiersIsNamed(): void {
		$this->assertSame(
			['clearance names tier "secret", which is not one of tiers'],
			self::faults(self::block(['clearance' => ['confidential' => 'raadsleden', 'secret' => 'college']]))
		);
	}

	public function testAReleasePropertyMustBeADateTime(): void {
		$this->assertSame(
			['releaseAtProperty "decidedOn" must be a property of format date-time'],
			self::faults(self::block(['releaseAtProperty' => 'decidedOn']))
		);
		$this->assertSame(
			['releaseAtProperty "openUntil" is not a property of this schema'],
			self::faults(self::block(['releaseAtProperty' => 'openUntil']))
		);
	}

	public function testAnUndeclaredGroundPropertyIsNamed(): void {
		$this->assertSame(
			['groundProperty "grond" is not a property of this schema'],
			self::faults(self::block(['groundProperty' => 'grond']))
		);
	}

	public function testTiersMustBeANonEmptyListOfDistinctNames(): void {
		$this->assertSame(['tiers must list at least one tier, least restrictive first'], self::faults(self::block(['tiers' => []])));
		$this->assertSame(['tiers must list at least one tier, least restrictive first'], self::faults(self::block(['tiers' => ['a' => 'public']])));
		$this->assertSame(['tiers names "public" twice'], self::faults(self::block(['tiers' => ['public', 'public', 'confidential']])));
		$this->assertSame(['tiers must be non-empty strings'], self::faults(self::block(['tiers' => ['public', ' ']])));
	}

	public function testClearanceIsRequiredUnlessThereIsOneTier(): void {
		$this->assertSame(['clearance must name the group required for at least one tier'], self::faults(self::block(['clearance' => []])));
		$this->assertSame([], self::faults(self::block(['tiers' => ['public'], 'clearance' => []])));
		$this->assertSame(['clearance must map tiers to group names'], self::faults(self::block(['clearance' => ['raadsleden']])));
		$this->assertSame(['clearance for tier "confidential" must be a group name'], self::faults(self::block(['clearance' => ['confidential' => '']])));
	}

	public function testANonObjectBlockIsRefused(): void {
		$this->assertSame(['must be an object with tiers, tierProperty and clearance'], self::faults('confidential'));
	}
}
