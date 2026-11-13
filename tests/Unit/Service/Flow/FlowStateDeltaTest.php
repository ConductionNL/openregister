<?php

/**
 * Unit tests for FlowStateDelta: what a hop added to or changed in its items.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStateDelta;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowStateDelta
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItemOptions
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItemShape
 */
final class FlowStateDeltaTest extends TestCase {

	/**
	 * An item with data, optionally paired to an input index.
	 *
	 * @param array<string, mixed> $json The data.
	 * @param int|null             $from The input item it came from.
	 *
	 * @return array<string, mixed>
	 */
	private static function item(array $json, ?int $from=null): array {
		return FlowItems::item(json: $json, options: new FlowItemOptions(fromItemIndex: $from));
	}//end item()

	/**
	 * Added and changed keys are produced; unchanged and removed ones are not.
	 *
	 * @return void
	 */
	public function testOnlyAddedAndChangedKeysAreProduced(): void {
		$produced = FlowStateDelta::produced(
			itemsIn: [self::item(['status' => 'new', 'title' => 'T', 'gone' => 1, 'n' => 1])],
			itemsOut: [self::item(['status' => 'open', 'title' => 'T', 'assignee' => 'ann', 'n' => '1'])]
		);

		self::assertSame([['status' => 'open', 'assignee' => 'ann', 'n' => '1']], $produced);
	}//end testOnlyAddedAndChangedKeysAreProduced()

	/**
	 * A node that passes its items on unchanged produced nothing, per item.
	 *
	 * @return void
	 */
	public function testAnUnchangedPassProducesEmptyDeltas(): void {
		$items = [self::item(['a' => 1]), self::item(['b' => ['x' => 1]])];

		self::assertSame([[], []], FlowStateDelta::produced(itemsIn: $items, itemsOut: $items));
	}//end testAnUnchangedPassProducesEmptyDeltas()

	/**
	 * An output item is compared with the input item it names, not the one at its position.
	 *
	 * @return void
	 */
	public function testThePairedInputItemIsTheBaseline(): void {
		$produced = FlowStateDelta::produced(
			itemsIn: [self::item(['k' => 'first']), self::item(['k' => 'second'])],
			itemsOut: [self::item(['k' => 'second', 'tag' => 'x'], 1), self::item(['k' => 'first'], 0)]
		);

		self::assertSame([['tag' => 'x'], []], $produced);
	}//end testThePairedInputItemIsTheBaseline()

	/**
	 * An output item with no input counterpart produced all of its data.
	 *
	 * @return void
	 */
	public function testAnItemWithoutACounterpartProducedEverything(): void {
		$produced = FlowStateDelta::produced(
			itemsIn: [self::item(['a' => 1])],
			itemsOut: [self::item(['a' => 1]), self::item(['a' => 2, 'b' => 3])]
		);

		self::assertSame([[], ['a' => 2, 'b' => 3]], $produced);
	}//end testAnItemWithoutACounterpartProducedEverything()

	/**
	 * Only the items the run log samples are described.
	 *
	 * @return void
	 */
	public function testOnlyTheSampledItemsAreDescribed(): void {
		$out = [];
		for ($i = 0; $i < 8; $i++) {
			$out[] = self::item(['i' => $i]);
		}

		$produced = FlowStateDelta::produced(itemsIn: [], itemsOut: $out);

		self::assertCount(5, $produced);
		self::assertSame(['i' => 4], $produced[4]);
	}//end testOnlyTheSampledItemsAreDescribed()

	/**
	 * Items without a data member count as empty data.
	 *
	 * @return void
	 */
	public function testItemsWithoutDataCountAsEmpty(): void {
		self::assertSame(
			[[], ['x' => 1]],
			FlowStateDelta::produced(itemsIn: ['odd', ['json' => 'not-an-array']], itemsOut: [['binary' => []], self::item(['x' => 1])])
		);
	}//end testItemsWithoutDataCountAsEmpty()
}//end class
