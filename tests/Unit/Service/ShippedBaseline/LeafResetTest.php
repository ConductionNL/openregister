<?php

/**
 * LeafReset: a reset of one leaf walked down its path segments.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
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

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ShippedBaseline\LeafReset;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\LeafReset
 */
class LeafResetTest extends TestCase {

	public function testOnlyANullTargetRemovesTheLeaf(): void {
		$this->assertTrue((new LeafReset(['a'], null))->removes());
		$this->assertFalse((new LeafReset(['a'], ''))->removes());
		$this->assertFalse((new LeafReset(['a'], false))->removes());
		$this->assertFalse((new LeafReset(['a'], []))->removes());
	}

	public function testBelowDropsTheFirstSegmentAndKeepsTheTarget(): void {
		$reset = new LeafReset(['properties', 'age', 'title'], 'Age');

		$below = $reset->below();
		$this->assertSame(['age', 'title'], $below->segments);
		$this->assertSame('Age', $below->to);
		$this->assertSame(['title'], $below->below()->segments);
		$this->assertSame([], $below->below()->below()->below()->segments);
		$this->assertSame(['properties', 'age', 'title'], $reset->segments);
	}
}
