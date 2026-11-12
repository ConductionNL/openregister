<?php

/**
 * Golden test for CasePlanTree.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for CasePlanTree::stageMayComplete() and ::stateMap().
 *
 * One fixed plan: stages whose children cover every combination of active,
 * required, optional, terminal and repeated rows. The answers were recorded
 * from the code as it stood before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanTree
 * @uses   \OCA\OpenRegister\Db\CaseItem
 */
class CasePlanTreeGoldenTest extends TestCase {

	/**
	 * One plan-item row.
	 *
	 * @param array<string, mixed> $fields id, key, state, and optionally parent, required, count, repetition.
	 *
	 * @return CaseItem
	 */
	private static function row(array $fields): CaseItem {
		$item = new CaseItem();
		$item->setId($fields['id']);
		$item->setItemKey($fields['key']);
		$item->setState($fields['state']);
		$item->setParentItemId($fields['parent'] ?? null);
		$item->setRequired($fields['required'] ?? true);
		$item->setRealisationCount($fields['count'] ?? 1);
		$item->setRepetition($fields['repetition'] ?? null);
		return $item;
	}//end row()

	/**
	 * The fixed plan, by id.
	 *
	 * @return array<int, CaseItem>
	 */
	private static function plan(): array {
		$active = CaseItem::STATE_ACTIVE;
		$done   = CaseItem::STATE_COMPLETED;
		$rows   = [
			// Stage 1: required done, optional open → may complete.
			['id' => 1, 'key' => 's1', 'state' => $active],
			['id' => 2, 'key' => 'a', 'state' => $done, 'parent' => 1],
			['id' => 3, 'key' => 'b', 'state' => CaseItem::STATE_ENABLED, 'parent' => 1, 'required' => false],
			// Stage 4: an optional ACTIVE child holds it open.
			['id' => 4, 'key' => 's4', 'state' => $active],
			['id' => 5, 'key' => 'c', 'state' => $done, 'parent' => 4],
			['id' => 6, 'key' => 'd', 'state' => $active, 'parent' => 4, 'required' => false],
			// Stage 7: only optional children → never trivially complete.
			['id' => 7, 'key' => 's7', 'state' => $active],
			['id' => 8, 'key' => 'e', 'state' => CaseItem::STATE_TERMINATED, 'parent' => 7, 'required' => false],
			// Stage 9: required child not terminal.
			['id' => 9, 'key' => 's9', 'state' => $active],
			['id' => 10, 'key' => 'f', 'state' => $done, 'parent' => 9],
			['id' => 11, 'key' => 'g', 'state' => CaseItem::STATE_AVAILABLE, 'parent' => 9],
			// Stage 12: required child repeats, max 3, latest realisation 2 → not exhausted.
			['id' => 12, 'key' => 's12', 'state' => $active],
			['id' => 13, 'key' => 'h', 'state' => $done, 'parent' => 12, 'repetition' => ['max' => 3]],
			['id' => 14, 'key' => 'h', 'state' => $done, 'parent' => 12, 'count' => 2, 'repetition' => ['max' => 3]],
			// Stage 15: required child repeats, max 2, latest realisation 2 → exhausted.
			['id' => 15, 'key' => 's15', 'state' => $active],
			['id' => 16, 'key' => 'i', 'state' => $done, 'parent' => 15, 'count' => 2, 'repetition' => ['max' => 2]],
			['id' => 17, 'key' => 'i', 'state' => $done, 'parent' => 15, 'repetition' => ['max' => 2]],
			// Stage 18: a repeated key with a later row still active elsewhere.
			['id' => 18, 'key' => 's18', 'state' => $active],
			['id' => 19, 'key' => 'j', 'state' => $done, 'parent' => 18],
			['id' => 20, 'key' => 'j', 'state' => $active, 'count' => 2],
			// Stage 21: no children at all.
			['id' => 21, 'key' => 's21', 'state' => CaseItem::STATE_AVAILABLE],
			// Stage 22: a required terminated child and an optional active one in the same stage, active last.
			['id' => 22, 'key' => 's22', 'state' => $active],
			['id' => 23, 'key' => 'k', 'state' => CaseItem::STATE_TERMINATED, 'parent' => 22],
			['id' => 24, 'key' => 'l', 'state' => CaseItem::STATE_ACTIVE, 'parent' => 22, 'required' => false],
			// Equal realisation counts: the later row wins the state map.
			['id' => 25, 'key' => 'm', 'state' => CaseItem::STATE_ENABLED, 'count' => 4],
			['id' => 26, 'key' => 'm', 'state' => CaseItem::STATE_DISABLED, 'count' => 4],
			['id' => 27, 'key' => 'm', 'state' => $done, 'count' => 3],
			['id' => 28, 'key' => '7', 'state' => $done],
		];
		$plan = [];
		foreach ($rows as $fields) {
			$plan[$fields['id']] = self::row($fields);
		}

		return $plan;
	}//end plan()

	public function testStageMayComplete(): void {
		$plan = self::plan();
		$tree = new CasePlanTree(array_values($plan));
		$expected = [
			1 => true,
			4 => false,
			7 => false,
			9 => false,
			12 => false,
			15 => true,
			18 => false,
			21 => false,
			22 => false,
			2 => false,
		];
		foreach ($expected as $id => $mayComplete) {
			$this->assertSame($mayComplete, $tree->stageMayComplete($plan[$id]), 'stage ' . $id);
		}
	}//end testStageMayComplete()

	public function testStateMap(): void {
		$tree = new CasePlanTree(array_values(self::plan()));
		$this->assertSame(
			[
				's1' => 'active',
				'a' => 'completed',
				'b' => 'enabled',
				's4' => 'active',
				'c' => 'completed',
				'd' => 'active',
				's7' => 'active',
				'e' => 'terminated',
				's9' => 'active',
				'f' => 'completed',
				'g' => 'available',
				's12' => 'active',
				'h' => 'completed',
				's15' => 'active',
				'i' => 'completed',
				's18' => 'active',
				'j' => 'active',
				's21' => 'available',
				's22' => 'active',
				'k' => 'terminated',
				'l' => 'active',
				'm' => 'disabled',
				'7' => 'completed',
			],
			$tree->stateMap()
		);
		$this->assertSame([], (new CasePlanTree([]))->stateMap());
	}//end testStateMap()
}//end class
