<?php

/**
 * The lifecycle table, edge by edge.
 *
 * Every legal edge of every type passes; every edge absent from the table is
 * refused naming all four facts; a same-state "transition" is illegal; a
 * milestone has exactly two edges; and nothing leaves a terminal state.
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
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use PHPUnit\Framework\TestCase;

/**
 * Table-driven coverage of CasePlanTransitions.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanTransitions
 * @covers \OCA\OpenRegister\Exception\CaseTransitionException
 */
class CasePlanTransitionsTest extends TestCase {

	/**
	 * The legal edges, as the spec and the reference implementation state them.
	 *
	 * @return array<string, array{string, string, string}> type, from, to.
	 */
	public static function legalEdges(): array {
		$cases = [];
		foreach ([CaseItem::TYPE_STAGE, CaseItem::TYPE_HUMAN_TASK] as $type) {
			foreach ([
				[CaseItem::STATE_AVAILABLE, CaseItem::STATE_ENABLED],
				[CaseItem::STATE_AVAILABLE, CaseItem::STATE_ACTIVE],
				[CaseItem::STATE_AVAILABLE, CaseItem::STATE_DISABLED],
				[CaseItem::STATE_AVAILABLE, CaseItem::STATE_TERMINATED],
				[CaseItem::STATE_ENABLED, CaseItem::STATE_ACTIVE],
				[CaseItem::STATE_ENABLED, CaseItem::STATE_DISABLED],
				[CaseItem::STATE_ENABLED, CaseItem::STATE_TERMINATED],
				[CaseItem::STATE_ACTIVE, CaseItem::STATE_COMPLETED],
				[CaseItem::STATE_ACTIVE, CaseItem::STATE_TERMINATED],
			] as [$from, $to]) {
				$cases["$type $from -> $to"] = [$type, $from, $to];
			}
		}

		$cases['milestone available -> completed'] = [CaseItem::TYPE_MILESTONE, CaseItem::STATE_AVAILABLE, CaseItem::STATE_COMPLETED];
		$cases['milestone available -> terminated'] = [CaseItem::TYPE_MILESTONE, CaseItem::STATE_AVAILABLE, CaseItem::STATE_TERMINATED];

		return $cases;
	}//end legalEdges()

	/**
	 * Every edge the table names is legal.
	 *
	 * @dataProvider legalEdges
	 *
	 * @param string $type The type.
	 * @param string $from The from-state.
	 * @param string $to The to-state.
	 *
	 * @return void
	 */
	public function testLegalEdgesPass(string $type, string $from, string $to): void {
		$table = new CasePlanTransitions();
		$this->assertTrue($table->isLegal(type: $type, from: $from, to: $to));

		$item = $this->item(type: $type, state: $from);
		$table->assertLegal(item: $item, to: $to);
		$this->addToAssertionCount(1);
	}//end testLegalEdgesPass()

	/**
	 * Every other (type, from, to) triple is refused, including self-loops.
	 *
	 * @return void
	 */
	public function testEveryEdgeOutsideTheTableIsRefused(): void {
		$table = new CasePlanTransitions();
		$legal = [];
		foreach (self::legalEdges() as [$type, $from, $to]) {
			$legal["$type|$from|$to"] = true;
		}

		$refused = 0;
		foreach (CaseItem::TYPES as $type) {
			foreach (CaseItem::STATES as $from) {
				foreach (CaseItem::STATES as $to) {
					if (isset($legal["$type|$from|$to"]) === true) {
						continue;
					}

					$this->assertFalse($table->isLegal(type: $type, from: $from, to: $to), "$type $from -> $to must be illegal");
					$refused++;
				}
			}
		}

		// 3 types x 36 pairs = 108 triples, 20 legal.
		$this->assertSame(88, $refused);
	}//end testEveryEdgeOutsideTheTableIsRefused()

	/**
	 * A milestone has exactly the two edges and cannot become active.
	 *
	 * @return void
	 */
	public function testAMilestoneCannotBecomeActiveAndTheRefusalNamesAllFourFacts(): void {
		$table = new CasePlanTransitions();
		$this->assertSame(
			[CaseItem::STATE_COMPLETED, CaseItem::STATE_TERMINATED],
			$table->targetsFor(type: CaseItem::TYPE_MILESTONE, from: CaseItem::STATE_AVAILABLE)
		);

		$item = $this->item(type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		try {
			$table->assertLegal(item: $item, to: CaseItem::STATE_ACTIVE);
			$this->fail('A milestone must not become active.');
		} catch (CaseTransitionException $refusal) {
			$this->assertStringContainsString('ms-1', $refusal->getMessage());
			$this->assertStringContainsString('milestone', $refusal->getMessage());
			$this->assertStringContainsString("'available'", $refusal->getMessage());
			$this->assertStringContainsString("'active'", $refusal->getMessage());
		}

		$this->assertSame(CaseItem::STATE_AVAILABLE, $item->getState(), 'The item is unchanged.');
	}//end testAMilestoneCannotBecomeActiveAndTheRefusalNamesAllFourFacts()

	/**
	 * Nothing leaves a terminal state, for any type; the refusal names the state.
	 *
	 * @return void
	 */
	public function testATerminalStateAcceptsNothingFurther(): void {
		$table = new CasePlanTransitions();
		foreach (CaseItem::TYPES as $type) {
			foreach (CaseItem::TERMINAL_STATES as $terminal) {
				$this->assertTrue($table->isTerminal(state: $terminal));
				$this->assertSame([], $table->targetsFor(type: $type, from: $terminal));
				try {
					$table->assertLegal(item: $this->item(type: $type, state: $terminal), to: CaseItem::STATE_ACTIVE);
					$this->fail("$type must not leave $terminal");
				} catch (CaseTransitionException $refusal) {
					$this->assertStringContainsString("'$terminal'", $refusal->getMessage());
				}
			}
		}

		$this->assertFalse($table->isTerminal(state: CaseItem::STATE_ACTIVE));
	}//end testATerminalStateAcceptsNothingFurther()

	/**
	 * A same-state transition is illegal, and an unknown type has no edges.
	 *
	 * @return void
	 */
	public function testSelfLoopsAndUnknownTypesHaveNoEdges(): void {
		$table = new CasePlanTransitions();
		foreach (CaseItem::STATES as $state) {
			$this->assertFalse($table->isLegal(type: CaseItem::TYPE_STAGE, from: $state, to: $state));
		}

		$this->assertSame([], $table->targetsFor(type: 'processTask', from: CaseItem::STATE_AVAILABLE));
	}//end testSelfLoopsAndUnknownTypesHaveNoEdges()

	/**
	 * A row of a type in a state.
	 *
	 * @param string $type The type.
	 * @param string $state The state.
	 *
	 * @return CaseItem The row.
	 */
	private function item(string $type, string $state): CaseItem {
		$item = new CaseItem();
		$item->setUuid('ms-1');
		$item->setPlanItemType($type);
		$item->setState($state);

		return $item;
	}//end item()
}//end class
