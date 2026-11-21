<?php

/**
 * LeafReset walks one segment down a path and says whether it removes the leaf.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ShippedBaseline\LeafReset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A reset to null removes; any other value, including falsy ones, restores.
 */
#[CoversClass(LeafReset::class)]
class LeafResetTest extends TestCase {

	public function testNullTargetRemovesTheLeaf(): void {
		$this->assertTrue((new LeafReset(segments: ['a'], to: null))->removes());
	}//end testNullTargetRemovesTheLeaf()

	public function testFalsyNonNullTargetsDoNotRemove(): void {
		foreach ([false, 0, '', []] as $to) {
			$this->assertFalse((new LeafReset(segments: ['a'], to: $to))->removes());
		}
	}//end testFalsyNonNullTargetsDoNotRemove()

	public function testBelowDropsTheFirstSegmentAndKeepsTheTarget(): void {
		$reset = new LeafReset(segments: ['properties', 'title', 'type'], to: 'string');

		$below = $reset->below();

		$this->assertNotSame($reset, $below);
		$this->assertSame(['title', 'type'], $below->segments);
		$this->assertSame('string', $below->to);
		$this->assertSame(['properties', 'title', 'type'], $reset->segments);
	}//end testBelowDropsTheFirstSegmentAndKeepsTheTarget()

	public function testBelowTheLastSegmentLeavesNoSegments(): void {
		$below = (new LeafReset(segments: ['type'], to: null))->below();

		$this->assertSame([], $below->segments);
		$this->assertTrue($below->removes());
	}//end testBelowTheLastSegmentLeavesNoSegments()
}//end class
