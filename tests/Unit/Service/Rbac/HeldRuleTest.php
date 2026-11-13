<?php

/**
 * A held rule exposes its match clause only for a role-less rule entry.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\HeldRule;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\HeldRule
 */
class HeldRuleTest extends TestCase {

	public function testARoleLessEntryGivesItsMatch(): void {
		$rule = new HeldRule(entry: ['group' => 'staff', 'match' => ['owner' => '$userId']], role: null);

		$this->assertSame(['owner' => '$userId'], $rule->matchClause());
	}

	public function testARoleLessEntryWithoutAMatchGivesNull(): void {
		$this->assertNull((new HeldRule(entry: ['group' => 'staff'], role: null))->matchClause());
	}

	public function testARoleRuleNeverMatches(): void {
		$rule = new HeldRule(entry: ['match' => ['owner' => '$userId']], role: 'editor');

		$this->assertNull($rule->matchClause());
		$this->assertSame('editor', $rule->role);
	}

	public function testAPlainGroupEntryGivesNull(): void {
		$rule = new HeldRule(entry: 'staff', role: null);

		$this->assertNull($rule->matchClause());
		$this->assertSame('staff', $rule->entry);
	}
}
