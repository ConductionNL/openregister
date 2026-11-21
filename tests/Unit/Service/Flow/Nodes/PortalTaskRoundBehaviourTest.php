<?php

/**
 * One round of a portal task: it starts as cycle 1 with no node, party,
 * previous task or reason, and each wither returns a changed copy while the
 * round it came from stays as it was.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskRound;
use PHPUnit\Framework\TestCase;

/**
 * PortalTaskRound.
 */
class PortalTaskRoundBehaviourTest extends TestCase {

	/**
	 * @return array{string, int, string, ?string, ?string}
	 */
	private static function state(PortalTaskRound $round): array {
		return [$round->nodeId(), $round->cycle(), $round->partyReference(), $round->previousTaskUuid(), $round->reason()];
	}//end state()

	public function testAFreshRoundIsTheFirstCycleWithNothingElseSet(): void {
		$this->assertSame(['', 1, '', null, null], self::state(new PortalTaskRound([['id' => 1]], ['title' => 't'])));
	}//end testAFreshRoundIsTheFirstCycleWithNothingElseSet()

	public function testEachWitherChangesOnlyItsCopy(): void {
		$first = new PortalTaskRound([['id' => 1]], ['title' => 't']);

		$atNode = $first->atNode('node-7');
		$nextCycle = $atNode->inCycle(3, 'task-prev');
		$explained = $nextCycle->because('documents missing');
		$addressed = $explained->withParty('bsn:123');

		$this->assertSame(['', 1, '', null, null], self::state($first));
		$this->assertSame(['node-7', 1, '', null, null], self::state($atNode));
		$this->assertSame(['node-7', 3, '', 'task-prev', null], self::state($nextCycle));
		$this->assertSame(['node-7', 3, '', 'task-prev', 'documents missing'], self::state($explained));
		$this->assertSame(['node-7', 3, 'bsn:123', 'task-prev', 'documents missing'], self::state($addressed));
		$this->assertSame([[['id' => 1]], ['title' => 't']], [$addressed->items, $addressed->config]);
	}//end testEachWitherChangesOnlyItsCopy()
}//end class
