<?php

/**
 * RuleMatch: the ids of a decision table's matched rules and each rule's outputs by name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Dmn\RuleMatch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Dmn\RuleMatch
 */
class RuleMatchTest extends TestCase {

	public function testRuleIdsFallBackToTheirPosition(): void {
		$match = new RuleMatch([['id' => 'r-high'], ['outputEntries' => []], 5 => ['id' => 9]], []);

		$this->assertSame(['r-high', '1', '9'], $match->ids());
	}

	public function testNoRulesHaveNoIds(): void {
		$this->assertSame([], (new RuleMatch([], [['name' => 'x']]))->ids());
	}

	public function testOutputsAreNamedByTheDeclaredOutputAtTheSamePosition(): void {
		$match = new RuleMatch([], [['name' => 'fee'], ['name' => 'band'], ['name' => 'note']]);

		$this->assertSame(
			['fee' => 12.5, 'band' => 'B', 'note' => null],
			$match->outputsOf(['outputEntries' => [12.5, 'B']])
		);
		$this->assertSame(['fee' => null, 'band' => null, 'note' => null], $match->outputsOf([]));
	}
}
