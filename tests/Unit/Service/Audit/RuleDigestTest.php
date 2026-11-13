<?php

/**
 * Unit tests for RuleDigest (read-audit-entries-name-the-rule task 1.2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Service\Audit\RuleDigest;
use PHPUnit\Framework\TestCase;

/**
 * The digest is a 64-hex SHA-256 of the rule's canonical JSON: the key order a
 * rule is serialised in does not change it, a changed rule does, and the order
 * of a list inside the rule is part of its identity.
 *
 * @covers \OCA\OpenRegister\Service\Audit\RuleDigest
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md
 */
class RuleDigestTest extends TestCase {

	public function testTheDigestIsSixtyFourHexCharacters(): void {
		$digest = RuleDigest::of(['group' => 'readers', 'match' => ['status' => 'published']]);

		$this->assertSame(64, strlen($digest));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
	}//end testTheDigestIsSixtyFourHexCharacters()

	public function testKeyOrderDoesNotChangeTheDigest(): void {
		$one = RuleDigest::of(['group' => 'readers', 'match' => ['status' => 'published', 'kind' => 'note']]);
		$two = RuleDigest::of(['match' => ['kind' => 'note', 'status' => 'published'], 'group' => 'readers']);

		$this->assertSame($one, $two);
	}//end testKeyOrderDoesNotChangeTheDigest()

	public function testAChangedRuleChangesTheDigest(): void {
		$base = RuleDigest::of(['group' => 'readers', 'match' => ['status' => 'published']]);

		$this->assertNotSame($base, RuleDigest::of(['group' => 'writers', 'match' => ['status' => 'published']]));
		$this->assertNotSame($base, RuleDigest::of(['group' => 'readers', 'match' => ['status' => 'draft']]));
		$this->assertNotSame($base, RuleDigest::of(['group' => 'readers', 'match' => ['status' => 'published', 'extra' => true]]));
	}//end testAChangedRuleChangesTheDigest()

	public function testTheOrderOfAListInsideTheRuleMatters(): void {
		$one = RuleDigest::of(['group' => ['a', 'b'], 'match' => []]);
		$two = RuleDigest::of(['group' => ['b', 'a'], 'match' => []]);

		$this->assertNotSame($one, $two);
	}//end testTheOrderOfAListInsideTheRuleMatters()

	public function testNestedKeyOrderDoesNotChangeTheDigest(): void {
		$one = RuleDigest::of(['match' => ['and' => [['a' => 1, 'b' => 2]]]]);
		$two = RuleDigest::of(['match' => ['and' => [['b' => 2, 'a' => 1]]]]);

		$this->assertSame($one, $two);
	}//end testNestedKeyOrderDoesNotChangeTheDigest()

	public function testTheSameRuleIsStableAcrossCalls(): void {
		$rule = ['group' => 'readers', 'match' => ['status' => 'published']];

		$this->assertSame(RuleDigest::of($rule), RuleDigest::of($rule));
	}//end testTheSameRuleIsStableAcrossCalls()

	public function testAnEmptyRuleHasADigest(): void {
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', RuleDigest::of([]));
		$this->assertNotSame(RuleDigest::of([]), RuleDigest::of(['group' => 'readers']));
	}//end testAnEmptyRuleHasADigest()
}//end class
