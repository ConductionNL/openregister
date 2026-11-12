<?php

/**
 * A user override whose `match` clause cannot be read grants nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\GroupRuleEntries;
use OCA\OpenRegister\Service\Object\GroupRuleRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 */
class GroupRuleEntriesMalformedClauseTest extends TestCase {

	private function grants(mixed $clause): bool {
		$request = (new GroupRuleRequest(authorization: ['read' => []], action: 'read'))
			->forGroup(groupId: 'editors', userGroup: null)
			->forUser(userId: 'alice', objectOwner: null);

		return (new GroupRuleEntries($this->createStub(ConditionMatcher::class)))
			->grants(entry: ['user' => 'alice', 'match' => $clause], request: $request);
	}//end grants()

	/**
	 * @dataProvider malformedClauses
	 */
	public function testAMalformedClauseRefuses(mixed $clause): void {
		$this->assertFalse($this->grants($clause));
	}//end testAMalformedClauseRefuses()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedClauses(): array {
		return ['string' => ['x'], 'blank' => [''], 'number' => [7], 'zero' => [0], 'true' => [true], 'false' => [false]];
	}//end malformedClauses()

	public function testAnOverrideWithoutAClauseStillGrants(): void {
		$this->assertTrue($this->grants(null));
		$this->assertTrue($this->grants([]));
	}//end testAnOverrideWithoutAClauseStillGrants()
}//end class
