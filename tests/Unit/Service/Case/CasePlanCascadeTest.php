<?php

/**
 * The fixpoint: a milestone satisfies another item's sentry without a
 * further call; a stage with only optional children stays open; required
 * children complete their stage; a cycle hits the bound, fails naming it
 * and rolls back; repetition grows rows and the terminal-iff invariant
 * holds; a run's marking, status and log are byte-identical across an
 * evaluation; re-entrancy is skipped.
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
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Exception\CaseCascadeBoundException;
use OCA\OpenRegister\Service\Case\CaseAnchorReader;
use OCA\OpenRegister\Service\Case\CaseBusinessStateWriter;
use OCA\OpenRegister\Service\Case\CasePlanCascade;
use OCA\OpenRegister\Service\Case\CasePlanStateMachine;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Coverage of CasePlanCascade over the real machine, evaluator and tree.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanCascade
 * @covers \OCA\OpenRegister\Service\Case\CasePlanStateMachine
 * @covers \OCA\OpenRegister\Exception\CaseCascadeBoundException
 */
class CasePlanCascadeTest extends TestCase {

	/**
	 * Rows.
	 *
	 * @var FakeCaseItemMapper
	 */
	private FakeCaseItemMapper $items;

	/**
	 * Audit.
	 *
	 * @var RecordingAuditMapper
	 */
	private RecordingAuditMapper $audits;

	/**
	 * Realiser.
	 *
	 * @var CaseRealisationService&MockObject
	 */
	private CaseRealisationService&MockObject $realiser;

	/**
	 * Anchor.
	 *
	 * @var CaseAnchorReader&MockObject
	 */
	private CaseAnchorReader&MockObject $anchor;

	/**
	 * Connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Transaction nesting depth of the mocked connection.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->items = new FakeCaseItemMapper($this);
		$this->audits = new RecordingAuditMapper($this);
		$this->realiser = $this->createMock(CaseRealisationService::class);
		$this->realiser->method('realise')->willReturnCallback(
			static function (CaseItem $row): void {
				if ($row->getPlanItemType() === CaseItem::TYPE_HUMAN_TASK) {
					$row->setRealisationKind(CaseItem::REALISATION_TASK);
					$row->setRealisationUuid('task-for-' . (string)$row->getItemKey() . '-' . (int)$row->getRealisationCount());

					return;
				}

				$row->setRealisationKind(CaseItem::REALISATION_NONE);
			}
		);
		$this->anchor = $this->createMock(CaseAnchorReader::class);
		$this->anchor->method('read')->willReturn(['status' => 'open']);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('inTransaction')->willReturnCallback(fn (): bool => $this->depth > 0);
		$this->db->method('beginTransaction')->willReturnCallback(
			function (): void {
				$this->depth++;
			}
		);
		$this->db->method('commit')->willReturnCallback(
			function (): void {
				$this->depth--;
			}
		);
		$this->db->method('rollBack')->willReturnCallback(
			function (): void {
				$this->depth--;
			}
		);
		$this->depth = 0;
	}//end setUp()

	/**
	 * The cascade over the real machine.
	 *
	 * @return CasePlanCascade The cascade.
	 */
	private function cascade(): CasePlanCascade {
		$machine = new CasePlanStateMachine(
			items: $this->items,
			audits: $this->audits,
			table: new CasePlanTransitions(),
			realiser: $this->realiser,
			writer: $this->createMock(CaseBusinessStateWriter::class),
			db: $this->db,
			logger: new NullLogger()
		);

		return new CasePlanCascade(
			items: $this->items,
			machine: $machine,
			sentries: new CaseSentryEvaluator(catalog: new EventCatalogService()),
			realiser: $this->realiser,
			anchor: $this->anchor,
			db: $this->db,
			logger: new NullLogger()
		);
	}//end cascade()

	/**
	 * The states of the plan, by key.
	 *
	 * @return array<string, string> key => state.
	 */
	private function states(): array {
		return (new CasePlanTree(items: $this->items->findByObject(CaseFixtures::OBJECT)))->stateMap();
	}//end states()

	/**
	 * Seed 1: the permit case. Creation evaluation enters intake and its
	 * check; the milestone waits; assessment waits. Completing the task then
	 * reaches the milestone, which admits assessment, which realises its
	 * decision item, and intake completes on its required children. One call.
	 *
	 * @return void
	 */
	public function testAMilestoneSatisfiesAnotherItemsSentryInOneEvaluation(): void {
		$intake = CaseFixtures::row(id: 1, key: 'intake', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE);
		$check = CaseFixtures::row(id: 2, key: 'check', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE, parentId: 1);
		$complete = CaseFixtures::row(id: 3, key: 'complete', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE, parentId: 1);
		$complete->setEntryCriteria([['id' => 'after-check', 'on' => ['event' => 'case.item.completed', 'item' => 'check']]]);
		$assessment = CaseFixtures::row(id: 4, key: 'assessment', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE);
		$assessment->setEntryCriteria([['id' => 'after-complete', 'on' => ['event' => 'case.item.completed', 'item' => 'complete']]]);
		$decide = CaseFixtures::row(id: 5, key: 'decide', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE, parentId: 4);
		$this->items->seed([$intake, $check, $complete, $assessment, $decide]);

		$outcomes = [];
		$this->realiser->method('terminalOutcome')->willReturnCallback(
			static function (CaseItem $row) use (&$outcomes): ?string {
				return ($outcomes[(string)$row->getRealisationUuid()] ?? null);
			}
		);

		$result = $this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
		$this->assertSame(
			['intake' => 'active', 'check' => 'active', 'complete' => 'available', 'assessment' => 'available', 'decide' => 'available'],
			$this->states()
		);
		$this->assertSame(2, $result['transitions']);
		$this->assertFalse($result['skipped']);

		// The task completes.
		$outcomes['task-for-check-1'] = CaseItem::STATE_COMPLETED;
		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);

		$this->assertSame(
			['intake' => 'completed', 'check' => 'completed', 'complete' => 'completed', 'assessment' => 'active', 'decide' => 'active'],
			$this->states()
		);
		$this->assertSame(['available->active (sentry)', 'active->completed (realisation)'], $this->audits->trail(2));
		$this->assertSame('task-for-check-1', $this->audits->findForItem(2)[1]->getCauseRef(), 'The task completion is the cause.');
		$this->assertSame('after-check', $this->audits->findForItem(3)[0]->getCauseRef(), 'The admitting sentry is named.');
		$this->assertSame('after-complete', $this->audits->findForItem(4)[0]->getCauseRef());
		$this->assertSame(CaseItemAudit::CAUSE_CASCADE, $this->audits->findForItem(1)[1]->getCause(), 'Stage completion by rule.');
		$this->assertSame('children', $this->audits->findForItem(1)[1]->getCauseRef());
	}//end testAMilestoneSatisfiesAnotherItemsSentryInOneEvaluation()

	/**
	 * A stage with only discretionary children stays active; enabling one
	 * starts it; an exit sentry terminates an item and closes its task.
	 *
	 * @return void
	 */
	public function testOptionalChildrenKeepAStageOpenAndEnabledItemsStart(): void {
		$stage = CaseFixtures::row(id: 1, key: 'assessment', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE);
		$advice = CaseFixtures::row(id: 2, key: 'advice', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE, parentId: 1, required: false, discretionary: true);
		$advice->setExitCriteria([['id' => 'withdrawn', 'if' => ['==' => [['var' => 'json.status'], 'ingetrokken']]]]);
		$this->items->seed([$stage, $advice]);
		$this->realiser->method('terminalOutcome')->willReturn(null);

		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
		$this->assertSame(['assessment' => 'active', 'advice' => 'available'], $this->states(), 'Discretionary: not auto-entered; stage stays open.');

		$advice->setState(CaseItem::STATE_ENABLED);
		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT, actor: 'alice');
		$this->assertSame('active', $this->states()['advice']);
		$this->assertSame('alice', $this->audits->findForItem(2)[0]->getActor());
		$this->assertSame('enable', $this->audits->findForItem(2)[0]->getCauseRef());

		$this->anchor = $this->createMock(CaseAnchorReader::class);
		$this->anchor->method('read')->willReturn(['status' => 'ingetrokken']);
		$this->realiser->expects($this->once())->method('terminate')->with($advice, "Exit criterion 'withdrawn' fired.");
		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
		$this->assertSame('terminated', $this->states()['advice']);
		$this->assertSame('assessment', array_search('active', $this->states(), true), 'Only optional children were ever there: the stage stays open.');
	}//end testOptionalChildrenKeepAStageOpenAndEnabledItemsStart()

	/**
	 * A cycle hits the bound: the failure names it, and the plan is rolled back.
	 *
	 * @return void
	 */
	public function testAnUnboundedCascadeFailsLoudlyAndRollsBack(): void {
		// Two repeating items that admit each other: a completes -> b enters and
		// (via the realiser) completes at once -> a's next realisation enters ...
		$first = CaseFixtures::row(id: 1, key: 'ping', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$first->setRepetition(['max' => 1000]);
		$second = CaseFixtures::row(id: 2, key: 'pong', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$second->setRepetition(['max' => 1000]);
		$this->items->seed([$first, $second]);
		$this->realiser->method('terminalOutcome')->willReturn(CaseItem::STATE_COMPLETED);

		try {
			$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
			$this->fail('must hit the bound');
		} catch (CaseCascadeBoundException $bound) {
			$this->assertStringContainsString((string)CasePlanCascade::MAX_CASCADE_DEPTH, $bound->getMessage());
			$this->assertStringContainsString('MAX_CASCADE_DEPTH', $bound->getMessage());
			$this->assertStringContainsString('rolled back', $bound->getMessage());
		}

		$this->assertSame(0, $this->depth, 'The outer transaction was rolled back, not committed.');
	}//end testAnUnboundedCascadeFailsLoudlyAndRollsBack()

	/**
	 * Repetition: a completed repeating row grows exactly one next row per
	 * pass, each realisation its own task; the item is terminal iff every
	 * realisation is terminal and the rule is exhausted.
	 *
	 * @return void
	 */
	public function testRepetitionGrowsRowsAndTheTerminalIffInvariantHolds(): void {
		$stage = CaseFixtures::row(id: 1, key: 's', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE);
		$docs = CaseFixtures::row(id: 2, key: 'docs', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE, parentId: 1);
		$docs->setRepetition(['max' => 2]);
		$docs->setCandidateGroups(['g']);
		$this->items->seed([$stage, $docs]);
		$outcomes = ['task-for-docs-1' => CaseItem::STATE_COMPLETED];
		$this->realiser->method('terminalOutcome')->willReturnCallback(
			static function (CaseItem $row) use (&$outcomes): ?string {
				return ($outcomes[(string)$row->getRealisationUuid()] ?? null);
			}
		);

		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
		$rows = (new CasePlanTree(items: $this->items->findByObject(CaseFixtures::OBJECT)))->rowsForKey(key: 'docs');
		$this->assertCount(2, $rows, 'One plan item, two realisation rows.');
		$this->assertSame([1, 2], [(int)$rows[0]->getRealisationCount(), (int)$rows[1]->getRealisationCount()]);
		$this->assertSame(['completed', 'active'], [$rows[0]->getState(), $rows[1]->getState()]);
		$this->assertSame(['g'], $rows[1]->getCandidateGroups(), 'The definition is carried onto the next realisation.');
		$this->assertSame('item-2', $this->audits->findForItem((int)$rows[1]->getId())[0]->getCauseRef(), 'Creation names the previous realisation.');
		$this->assertSame('active', $this->states()['s'], 'Stage waits: realisation 2 is active.');
		$this->assertFalse((new CasePlanTree(items: $this->items->findByObject(CaseFixtures::OBJECT)))->isItemTerminal(item: $rows[0]));

		$outcomes['task-for-docs-2'] = CaseItem::STATE_COMPLETED;
		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
		$tree = new CasePlanTree(items: $this->items->findByObject(CaseFixtures::OBJECT));
		$this->assertCount(2, $tree->rowsForKey(key: 'docs'), 'max 2: no third row.');
		$this->assertTrue($tree->isItemTerminal(item: $rows[0]));
		$this->assertSame('completed', $this->states()['s'], 'Now the stage completes.');
	}//end testRepetitionGrowsRowsAndTheTerminalIffInvariantHolds()

	/**
	 * A run realisation that stopped terminates its stage (with cascade), a
	 * missing realisation counts as terminated, and the run itself is never
	 * written: marking, status and log are byte-identical after evaluation.
	 *
	 * @return void
	 */
	public function testARunDrivesItsStageAndIsNeverWritten(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setMarking(['node-a' => 1]);
		$run->setLog([['step' => 'a']]);
		$run->resetUpdatedFields();
		$before = serialize($run);

		$stage = CaseFixtures::row(id: 1, key: 'auto', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$stage->setRealisationKind(CaseItem::REALISATION_RUN);
		$stage->setRealisationUuid('run-1');
		$child = CaseFixtures::row(id: 2, key: 'c', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE, parentId: 1);
		$child->setEntryCriteria([['id' => 'never', 'if' => ['==' => [['var' => 'json.nope'], 1]]]]);
		$this->items->seed([$stage, $child]);

		$this->realiser->method('terminalOutcome')->willReturn(null);
		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
		$this->assertSame('active', $this->states()['auto'], 'A run-bound stage is not completed by the children rule.');
		$this->assertSame($before, serialize($run));
		$this->assertSame([], $run->getUpdatedFields());

		$this->realiser = $this->createMock(CaseRealisationService::class);
		$this->realiser->method('terminalOutcome')->willReturn(CaseItem::STATE_TERMINATED);
		$this->cascade()->evaluate(objectUuid: CaseFixtures::OBJECT);
		$this->assertSame(['auto' => 'terminated', 'c' => 'terminated'], $this->states());
		$this->assertSame($before, serialize($run), 'Marking, status and log untouched.');
	}//end testARunDrivesItsStageAndIsNeverWritten()

	/**
	 * An empty plan is a no-op; re-entry during evaluation is skipped, not looped.
	 *
	 * @return void
	 */
	public function testEmptyPlansAndReentryAreNoOps(): void {
		$cascade = $this->cascade();
		$this->assertSame(['passes' => 1, 'transitions' => 0, 'skipped' => false], $cascade->evaluate(objectUuid: 'nothing-here'));

		$milestone = CaseFixtures::row(id: 1, key: 'm', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$this->items->seed([$milestone]);
		$reentered = null;
		$this->anchor = $this->createMock(CaseAnchorReader::class);
		$this->anchor->method('read')->willReturnCallback(
			function () use (&$reentered, &$cascade): array {
				// A write-through's object event arriving inside our own transaction.
				$reentered = $cascade->evaluate(objectUuid: CaseFixtures::OBJECT);

				return [];
			}
		);
		$cascade = $this->cascade();
		$result = $cascade->evaluate(objectUuid: CaseFixtures::OBJECT);
		$this->assertTrue($reentered['skipped']);
		$this->assertSame(1, $result['transitions']);
		$this->assertSame('completed', $this->states()['m']);
	}//end testEmptyPlansAndReentryAreNoOps()
}//end class
