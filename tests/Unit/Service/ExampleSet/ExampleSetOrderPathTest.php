<?php

/**
 * ExampleSetOrderPath walks a dependency path, remembering every step it has seen.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetOrderPath;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrderPath
 */
class ExampleSetOrderPathTest extends TestCase {
	/**
	 * A step moves on, keeps the target and adds the step to what was seen.
	 *
	 * @return void
	 */
	public function testStepAdvancesAndRemembers(): void {
		$path = new ExampleSetOrderPath(from: 'a', target: 'z', seen: ['a' => true]);
		$next = $path->step(next: 'b');

		$this->assertSame('b', $next->from);
		$this->assertSame('z', $next->target);
		$this->assertSame(['a' => true, 'b' => true], $next->seen);
		$this->assertSame('a', $path->from);
		$this->assertSame(['a' => true], $path->seen);
	}

	/**
	 * Steps accumulate along the path and a revisit does not duplicate.
	 *
	 * @return void
	 */
	public function testStepsAccumulate(): void {
		$path = (new ExampleSetOrderPath(from: 'a', target: 'z'))->step(next: 'b')->step(next: 'c')->step(next: 'b');
		$this->assertSame(['b' => true, 'c' => true], $path->seen);
		$this->assertSame('b', $path->from);
	}
}
