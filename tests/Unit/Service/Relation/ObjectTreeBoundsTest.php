<?php

/**
 * Unit test for ObjectTreeBounds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Service\Relation\ObjectTreeBounds;
use OCA\OpenRegister\Service\Relation\ObjectTreeService;
use PHPUnit\Framework\TestCase;

/**
 * Where the tree walk stops.
 */
class ObjectTreeBoundsTest extends TestCase {

	/**
	 * The depth is the declared bound unless a depth of at least 1 within it is asked for.
	 *
	 * @return void
	 */
	public function testEffectiveDepth(): void {
		$bounds = new ObjectTreeBounds();

		$this->assertSame([4, 1, 2, 4], [
			$bounds->effectiveDepth(asked: null, declared: 4),
			$bounds->effectiveDepth(asked: -3, declared: 4),
			$bounds->effectiveDepth(asked: 2, declared: 4),
			$bounds->effectiveDepth(asked: 9, declared: 4),
		]);
	}//end testEffectiveDepth()

	/**
	 * An unreadable level, an overflow and an empty level each end the walk; children carry it on.
	 *
	 * @return void
	 */
	public function testLevelStop(): void {
		$bounds = new ObjectTreeBounds();

		$this->assertSame(['done' => true, 'truncatedBy' => ObjectTreeService::TRUNCATED_UNREADABLE], $bounds->levelStop(read: null));
		$this->assertSame(['done' => true, 'truncatedBy' => ObjectTreeService::TRUNCATED_CAP], $bounds->levelStop(read: ['children' => ['a' => 'r'], 'overflow' => true]));
		$this->assertSame(['done' => true, 'truncatedBy' => null], $bounds->levelStop(read: ['children' => [], 'overflow' => false]));
		$this->assertNull($bounds->levelStop(read: ['children' => ['a' => 'r'], 'overflow' => false]));
	}//end testLevelStop()

	/**
	 * Seen rows still count against the budget before they are dropped.
	 *
	 * @return void
	 */
	public function testWithinBudget(): void {
		$bounds = new ObjectTreeBounds();

		$this->assertSame(
			['children' => ['b' => 'r'], 'overflow' => true],
			$bounds->withinBudget(rows: ['a' => 'r', 'b' => 'r', 'c' => 'r'], read: ['seen' => ['a' => true], 'budget' => 1])
		);
		$this->assertSame(['children' => [], 'overflow' => false], $bounds->withinBudget(rows: [], read: ['seen' => [], 'budget' => 0]));
	}//end testWithinBudget()

	/**
	 * A parent reference is a uuid, or a reference object carrying one.
	 *
	 * @return void
	 */
	public function testReference(): void {
		$bounds = new ObjectTreeBounds();

		$this->assertSame(
			['u', 'i', 'v', null, null, null],
			array_map(
				static fn (mixed $value): ?string => $bounds->reference(value: $value),
				[' u ', ['id' => 'i'], ['value' => 'v'], ' ', 7, ['other' => 'x']]
			)
		);
	}//end testReference()
}//end class
