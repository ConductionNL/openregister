<?php

/**
 * RuleFlag: a dotted flag path walked one key at a time.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\RuleFlag;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\RuleFlag
 */
class RuleFlagTest extends TestCase {

	public function testWalksThePathKeepingTheSwitch(): void {
		$flag = new RuleFlag(['validations', 'age', 'enabled'], false);

		$this->assertSame('validations', $flag->key());
		$this->assertFalse($flag->isLast());
		$rest = $flag->rest();
		$this->assertSame(['age', 'enabled'], $rest->path);
		$this->assertFalse($rest->enabled);
		$this->assertSame('age', $rest->key());
		$last = $rest->rest();
		$this->assertSame('enabled', $last->key());
		$this->assertTrue($last->isLast());
		$this->assertSame(['validations', 'age', 'enabled'], $flag->path);
	}

	public function testAnEmptyPathIsLastWithAnEmptyKey(): void {
		$flag = new RuleFlag([], true);

		$this->assertSame('', $flag->key());
		$this->assertTrue($flag->isLast());
		$this->assertSame([], $flag->rest()->path);
		$this->assertTrue($flag->rest()->enabled);
	}

	public function testANumericSegmentIsReadAsAString(): void {
		$this->assertSame('3', (new RuleFlag([3, 'x'], true))->key());
	}
}
