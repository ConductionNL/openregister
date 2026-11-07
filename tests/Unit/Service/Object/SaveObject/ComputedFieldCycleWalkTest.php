<?php

/**
 * Unit tests for ComputedFieldCycleWalk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldCycleWalk;
use PHPUnit\Framework\TestCase;

/**
 * Cycles found in adjacency lists, deduplicated by their rotated signature.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldCycleWalk
 */
class ComputedFieldCycleWalkTest extends TestCase {

	public function testNoCycles(): void {
		$this->assertSame([], (new ComputedFieldCycleWalk([]))->cycles());
		$this->assertSame([], (new ComputedFieldCycleWalk(['a' => ['b'], 'b' => [], 'c' => ['a', 'missing']]))->cycles());
	}//end testNoCycles()

	public function testSelfLoopAndLongCycle(): void {
		$this->assertSame([['a', 'a']], (new ComputedFieldCycleWalk(['a' => ['a']]))->cycles());
		$this->assertSame(
			[['m', 'z', 'b', 'm']],
			(new ComputedFieldCycleWalk(['m' => ['z'], 'z' => ['b'], 'b' => ['m']]))->cycles()
		);
	}//end testSelfLoopAndLongCycle()

	public function testTheSameCycleFromAnotherEntryIsReportedOnce(): void {
		$walk = new ComputedFieldCycleWalk(['x' => ['a'], 'a' => ['b'], 'b' => ['a'], 'y' => ['b']]);
		$this->assertSame([['a', 'b', 'a']], $walk->cycles());
	}//end testTheSameCycleFromAnotherEntryIsReportedOnce()
}//end class
