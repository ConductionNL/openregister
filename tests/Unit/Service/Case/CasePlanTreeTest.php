<?php

/**
 * The structural reads over one plan: children, ancestors, the completion
 * rule with its `$mandatoryFound` guard, repetition exhaustion, the state map.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use PHPUnit\Framework\TestCase;

/**
 * Structural coverage of CasePlanTree.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanTree
 * @covers \OCA\OpenRegister\Db\CaseItem
 */
class CasePlanTreeTest extends TestCase {

	/**
	 * A stage with only optional children does NOT complete.
	 *
	 * @return void
	 */
	public function testAStageWithOnlyOptionalChildrenMayNotComplete(): void {
		$stage = CaseFixtures::row(id: 1, key: 'assessment', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$optional = CaseFixtures::row(id: 2, key: 'advice', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE, parentId: 1, required: false, discretionary: true);
		$tree = new CasePlanTree(items: [$stage, $optional]);

		$this->assertFalse($tree->stageMayComplete(stage: $stage), 'No required child found: not complete, never trivially complete.');
	}//end testAStageWithOnlyOptionalChildrenMayNotComplete()

	/**
	 * A stage completes when every required child is terminal and none is active.
	 *
	 * @return void
	 */
	public function testAStageCompletesOnItsRequiredChildrenAlone(): void {
		$stage = CaseFixtures::row(id: 1, key: 's', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$required = CaseFixtures::row(id: 2, key: 'r', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_COMPLETED, parentId: 1);
		$optionalOpen = CaseFixtures::row(id: 3, key: 'o', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ENABLED, parentId: 1, required: false);
		$tree = new CasePlanTree(items: [$stage, $required, $optionalOpen]);
		$this->assertTrue($tree->stageMayComplete(stage: $stage), 'An enabled optional child does not hold the stage open.');

		$optionalOpen->setState(CaseItem::STATE_ACTIVE);
		$this->assertFalse($tree->stageMayComplete(stage: $stage), 'An ACTIVE child of any kind does.');

		$optionalOpen->setState(CaseItem::STATE_COMPLETED);
		$required->setState(CaseItem::STATE_ACTIVE);
		$this->assertFalse($tree->stageMayComplete(stage: $stage), 'An open required child does.');
	}//end testAStageCompletesOnItsRequiredChildrenAlone()

	/**
	 * A repeating item is terminal only when exhausted AND every realisation is terminal.
	 *
	 * @return void
	 */
	public function testARepeatingItemIsTerminalIffExhaustedAndAllRealisationsTerminal(): void {
		$first = CaseFixtures::row(id: 1, key: 'docs', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_COMPLETED);
		$first->setRepetition(['max' => 2]);
		$second = CaseFixtures::row(id: 2, key: 'docs', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$second->setRepetition(['max' => 2]);
		$second->setRealisationCount(2);

		$tree = new CasePlanTree(items: [$first, $second]);
		$this->assertFalse($tree->isItemTerminal(item: $first), 'Realisation 2 is active: the item is not terminal.');
		$this->assertFalse($tree->repetitionExhausted(item: $first), 'One of two done: not exhausted.');

		$second->setState(CaseItem::STATE_COMPLETED);
		$this->assertTrue($tree->repetitionExhausted(item: $second));
		$this->assertTrue($tree->isItemTerminal(item: $first), 'Both terminal and max reached.');

		$terminated = CaseFixtures::row(id: 3, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_TERMINATED);
		$terminated->setRepetition(['max' => 5]);
		$this->assertTrue((new CasePlanTree(items: [$terminated]))->repetitionExhausted(item: $terminated), 'A terminated item does not repeat.');
		$this->assertSame(['docs' => CaseItem::STATE_COMPLETED], $tree->stateMap(), 'The state map reports the latest realisation.');
	}//end testARepeatingItemIsTerminalIffExhaustedAndAllRealisationsTerminal()

	/**
	 * Children, descendants, ancestors, parent-active and settings.
	 *
	 * @return void
	 */
	public function testStructureReads(): void {
		$root = CaseFixtures::row(id: 1, key: 'root', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$root->setPlanSettings(['authorization' => ['g']]);
		$inner = CaseFixtures::row(id: 2, key: 'inner', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE, parentId: 1);
		$leaf = CaseFixtures::row(id: 3, key: 'leaf', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE, parentId: 2);
		$orphan = CaseFixtures::row(id: 4, key: 'orphan', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE, parentId: 99);
		$tree = new CasePlanTree(items: [$root, $inner, $leaf, $orphan]);

		$this->assertSame([$root], $tree->children(parentId: null));
		$this->assertSame([$inner, $leaf], $tree->descendants(parentId: 1));
		$this->assertSame([$inner, $root], $tree->ancestors(item: $leaf));
		$this->assertTrue($tree->isParentActive(item: $root), 'A root has no parent to wait for.');
		$this->assertTrue($tree->isParentActive(item: $inner));
		$this->assertFalse($tree->isParentActive(item: $leaf), 'inner is available, not active.');
		$this->assertFalse($tree->isParentActive(item: $orphan), 'A dangling parent id is not "no parent".');
		$this->assertSame(['authorization' => ['g']], $tree->settings());
		$this->assertNull($tree->byId(id: null));
		$this->assertSame($leaf, $tree->byId(id: 3));
		$this->assertTrue($tree->keyHasState(key: 'root', state: CaseItem::STATE_ACTIVE));
		$this->assertFalse($tree->keyHasState(key: 'nope', state: CaseItem::STATE_ACTIVE));
		$this->assertTrue($leaf->isEntered() === false && $root->isEntered() === true);

		$serialised = $root->hydrate(['name' => 'Root', 'id' => 77, 'unknown' => 1])->jsonSerialize();
		$this->assertSame('Root', $serialised['name']);
		$this->assertSame(1, $serialised['id'], 'hydrate() never sets id.');
		$this->assertSame(['authorization' => ['g']], $serialised['planSettings']);
	}//end testStructureReads()
}//end class
