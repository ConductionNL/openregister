<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Confidentiality;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Confidentiality\ConfidentialityRule;
use PHPUnit\Framework\TestCase;

/**
 * The read rule a confidentiality block derives: clearance or release.
 *
 * @covers \OCA\OpenRegister\Service\Confidentiality\ConfidentialityRule
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 */
final class ConfidentialityRuleTest extends TestCase {

	private const PROPERTIES = [
		'confidentialityTier' => ['type' => 'string'],
		'confidentialityGround' => ['type' => 'string'],
		'confidentialityReleaseUntil' => ['type' => 'string', 'format' => 'date-time'],
	];

	private const BESLUIT = [
		'tiers' => ['public', 'confidential'],
		'tierProperty' => 'confidentialityTier',
		'groundProperty' => 'confidentialityGround',
		'releaseAtProperty' => 'confidentialityReleaseUntil',
		'clearance' => ['confidential' => 'raadsleden'],
	];

	/**
	 * The current moment the tests read against.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-06-01T12:00:00+00:00');
	}

	/**
	 * The rule of the besluit block, with entries replaced.
	 *
	 * @param array<string, mixed> $change Entries to set.
	 *
	 * @return ConfidentialityRule
	 */
	private static function rule(array $change=[]): ConfidentialityRule {
		return ConfidentialityRule::fromBlock(block: array_merge(self::BESLUIT, $change), properties: self::PROPERTIES);
	}

	public function testReadIsDeniedBelowClearanceAndAllowedWithIt(): void {
		$besluit = ['confidentialityTier' => 'confidential'];
		$this->assertFalse(self::rule()->admits($besluit, ['staff'], self::now()), 'pieter, outside raadsleden');
		$this->assertTrue(self::rule()->admits($besluit, ['staff', 'raadsleden'], self::now()), 'jan, in raadsleden');
		$this->assertTrue(self::rule()->admits(['confidentialityTier' => 'public'], [], self::now()), 'the first tier needs no group');
	}

	public function testAPassedReleaseAdmitsEveryoneAndAFutureOneDoesNot(): void {
		$released = ['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2026-01-01T00:00:00+00:00'];
		$embargoed = ['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2027-01-01T00:00:00+00:00'];
		$this->assertTrue(self::rule()->admits($released, [], self::now()));
		$this->assertFalse(self::rule()->admits($embargoed, [], self::now()));
		$this->assertFalse(self::rule()->admits(['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => 'not a date'], [], self::now()));
		$this->assertTrue(self::rule()->admits(['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2026-06-01T12:00:00+00:00'], [], self::now()), 'the release moment itself');
	}

	public function testATierWithoutClearanceInheritsTheNextLessRestrictiveOne(): void {
		$rule = ConfidentialityRule::fromBlock(
			block: ['tiers' => ['public', 'internal', 'confidential', 'secret'], 'tierProperty' => 'confidentialityTier', 'clearance' => ['confidential' => 'raadsleden']],
			properties: self::PROPERTIES
		);
		$this->assertNull($rule->requiredGroup('public'));
		$this->assertNull($rule->requiredGroup('internal'));
		$this->assertSame('raadsleden', $rule->requiredGroup('confidential'));
		$this->assertSame('raadsleden', $rule->requiredGroup('secret'));
		$this->assertTrue($rule->admits(['confidentialityTier' => 'internal'], [], self::now()));
		$this->assertFalse($rule->admits(['confidentialityTier' => 'secret'], ['college'], self::now()));
	}

	public function testAnUnsetOrUnknownTierCountsAsTheLeastRestrictive(): void {
		$this->assertTrue(self::rule()->admits([], [], self::now()));
		$this->assertTrue(self::rule()->admits(['confidentialityTier' => ''], [], self::now()));
		$this->assertTrue(self::rule()->admits(['confidentialityTier' => 'geheim'], [], self::now()));
		$this->assertNull(self::rule()->requiredGroup('geheim'));
	}

	public function testAFaultyBlockFailsClosed(): void {
		$rule = self::rule(['clearance' => ['secret' => 'college']]);
		$this->assertTrue($rule->failsClosed());
		$this->assertSame(['clearance names tier "secret", which is not one of tiers'], $rule->errors());
		$this->assertFalse($rule->admits(['confidentialityTier' => 'public'], ['college', 'raadsleden'], self::now()), 'a tier is hidden');
		$this->assertFalse($rule->admits(['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2020-01-01T00:00:00+00:00'], [], self::now()), 'a release does not open it');
		$this->assertTrue($rule->admits([], [], self::now()), 'an object without a tier stays readable');
		$this->assertSame(
			[
				['group' => 'public', 'match' => ['confidentialityTier' => ['$exists' => false]]],
				['group' => 'public', 'match' => ['confidentialityTier' => ['$eq' => '']]],
			],
			$rule->readRules()
		);
	}

	public function testABlockWithoutAUsableTierPropertyHidesEverything(): void {
		$rule = ConfidentialityRule::fromBlock(block: ['tiers' => ['public']], properties: self::PROPERTIES);
		$this->assertTrue($rule->failsClosed());
		$this->assertFalse($rule->admits([], ['admin'], self::now()));
		$this->assertSame([], $rule->readRules());
	}

	public function testTheReadRulesTakeTheAuthoredShapeAndUseNow(): void {
		$this->assertFalse(self::rule()->failsClosed());
		$this->assertSame(
			[
				['group' => 'public', 'match' => ['confidentialityTier' => ['$exists' => false]]],
				['group' => 'public', 'match' => ['confidentialityTier' => ['$nin' => ['public', 'confidential']]]],
				['group' => 'public', 'match' => ['confidentialityTier' => ['$in' => ['public']]]],
				['group' => 'raadsleden', 'match' => ['confidentialityTier' => ['$in' => ['confidential']]]],
				['group' => 'public', 'match' => ['confidentialityReleaseUntil' => ['$lte' => '$now']]],
			],
			self::rule()->readRules()
		);
	}

	public function testOneGroupClearingSeveralTiersGetsOneRule(): void {
		$rule = ConfidentialityRule::fromBlock(
			block: ['tiers' => ['public', 'confidential', 'secret'], 'tierProperty' => 'confidentialityTier', 'clearance' => ['confidential' => 'raadsleden']],
			properties: self::PROPERTIES
		);
		$this->assertContains(['group' => 'raadsleden', 'match' => ['confidentialityTier' => ['$in' => ['confidential', 'secret']]]], $rule->readRules());
		$this->assertCount(4, $rule->readRules(), 'no release rule without a releaseAtProperty');
	}

	public function testTheRenderMirrorDescribesTierGroundAndRelease(): void {
		$this->assertSame(
			['tier' => 'confidential', 'ground' => 'Gemeentewet art. 25', 'releaseAt' => null, 'released' => false],
			self::rule()->describe(['confidentialityTier' => 'confidential', 'confidentialityGround' => 'Gemeentewet art. 25'], self::now())
		);
		$this->assertSame(
			['tier' => 'confidential', 'ground' => null, 'releaseAt' => '2026-01-01T00:00:00+00:00', 'released' => true],
			self::rule()->describe(['confidentialityTier' => 'confidential', 'confidentialityReleaseUntil' => '2026-01-01T00:00:00+00:00'], self::now())
		);
		$this->assertSame('public', self::rule()->describe([], self::now())['tier']);
		$this->assertNull(self::rule(['clearance' => ['x' => 'y']])->describe(['confidentialityTier' => 'confidential'], self::now())['tier']);
	}
}
