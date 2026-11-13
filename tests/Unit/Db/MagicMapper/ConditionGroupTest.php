<?php

/**
 * Unit tests for ConditionGroup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\ConditionGroup;
use OCA\OpenRegister\Db\MagicMapper\ConditionLeaf;
use PHPUnit\Framework\TestCase;

/**
 * A condition group lists every leaf beneath it, depth first and in order.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\ConditionGroup
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ConditionLeaf
 */
class ConditionGroupTest extends TestCase {
	/**
	 * Leaves of nested groups come back flattened, in document order.
	 *
	 * @return void
	 */
	public function testLeavesAreFlattenedInOrder(): void {
		$a = new ConditionLeaf(field: 'status', operator: 'eq', value: 'open', path: '_where.0');
		$b = new ConditionLeaf(field: 'amount', operator: 'gt', value: 10, path: '_where.1._or.0');
		$c = new ConditionLeaf(field: 'amount', operator: 'lt', value: 2, path: '_where.1._or.1._and.0');
		$d = new ConditionLeaf(field: 'owner', operator: 'eq', value: 'x', path: '_where.2');

		$group = new ConditionGroup(
			type: ConditionGroup::TYPE_AND,
			children: [
				$a,
				new ConditionGroup(
					type: ConditionGroup::TYPE_OR,
					children: [$b, new ConditionGroup(type: ConditionGroup::TYPE_AND, children: [$c], path: '_where.1._or.1')],
					path: '_where.1'
				),
				$d,
			],
			path: '_where'
		);

		$this->assertSame([$a, $b, $c, $d], $group->leaves());
		$this->assertSame(['AND', '_where', 3], [$group->type, $group->path, count($group->children)]);
	}//end testLeavesAreFlattenedInOrder()

	/**
	 * An empty group, or a group of empty groups, has no leaves.
	 *
	 * @return void
	 */
	public function testEmptyGroupsHaveNoLeaves(): void {
		$group = new ConditionGroup(
			type: ConditionGroup::TYPE_OR,
			children: [new ConditionGroup(type: ConditionGroup::TYPE_AND, children: [], path: '_or.0')],
			path: '_or'
		);

		$this->assertSame([], $group->leaves());
	}//end testEmptyGroupsHaveNoLeaves()
}//end class
