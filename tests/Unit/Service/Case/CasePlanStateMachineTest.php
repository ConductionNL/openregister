<?php

/**
 * The one write path: is_terminal with state, the audit in the same
 * transaction, the stage-exit cascade with per-child audit rows, the
 * realisation created on entry and closed on exit, denials recorded, events
 * after commit, and a lost race refused.
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
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCA\OpenRegister\Service\Case\CaseBusinessStateWriter;
use OCA\OpenRegister\Service\Case\CasePlanStateMachine;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Coverage of CasePlanStateMachine and the events it emits.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanStateMachine
 * @covers \OCA\OpenRegister\Event\CaseItemTransitionedEvent
 * @covers \OCA\OpenRegister\Db\CaseItemAudit
 */
class CasePlanStateMachineTest extends TestCase {

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
	 * Writer.
	 *
	 * @var CaseBusinessStateWriter&MockObject
	 */
	private CaseBusinessStateWriter&MockObject $writer;

	/**
	 * Connection, for transaction assertions.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Dispatched events.
	 *
	 * @var array<int, CaseItemTransitionedEvent>
	 */
	private array $events = [];

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->items = new FakeCaseItemMapper($this);
		$this->audits = new RecordingAuditMapper($this);
		$this->realiser = $this->createMock(CaseRealisationService::class);
		$this->writer = $this->createMock(CaseBusinessStateWriter::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('inTransaction')->willReturn(false);
		$this->events = [];
	}//end setUp()

	/**
	 * The machine over the fakes.
	 *
	 * @return CasePlanStateMachine The machine.
	 */
	private function machine(): CasePlanStateMachine {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
			}
		);

		return new CasePlanStateMachine(
			items: $this->items,
			audits: $this->audits,
			table: new CasePlanTransitions(),
			realiser: $this->realiser,
			writer: $this->writer,
			db: $this->db,
			logger: new NullLogger(),
			dispatcher: $dispatcher
		);
	}//end machine()

	/**
	 * Activation realises, stamps entered_at, writes is_terminal with state,
	 * audits in the transaction and announces after commit.
	 *
	 * @return void
	 */
	public function testActivationRealisesAuditsAndAnnouncesAfterCommit(): void {
		$item = CaseFixtures::row(id: 1, key: 'check', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$this->items->seed([$item]);
		$this->realiser->expects($this->once())->method('realise')->with($item, 'alice')->willReturnCallback(
			static function (CaseItem $row): void {
				$row->setRealisationKind(CaseItem::REALISATION_TASK);
				$row->setRealisationUuid('task-1');
			}
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$moved = $this->machine()->transition(item: $item, to: CaseItem::STATE_ACTIVE, cause: CaseItemAudit::CAUSE_SENTRY, causeRef: 'entry:default', actor: 'alice');

		$this->assertSame(CaseItem::STATE_ACTIVE, $moved->getState());
		$this->assertFalse($moved->getIsTerminal());
		$this->assertNotNull($moved->getEnteredAt());
		$this->assertSame('task-1', $moved->getRealisationUuid());
		$this->assertSame(['available->active (sentry)'], $this->audits->trail(1));
		$this->assertSame('entry:default', $this->audits->entries[0]->getCauseRef());
		$this->assertTrue($this->audits->entries[0]->getAuthorized());
		$this->assertCount(1, $this->events);
		$this->assertSame('available', $this->events[0]->getFromState());
		$this->assertNull($this->events[0]->getCatalogTrigger(), 'Active is not a catalog event.');
		$this->assertSame(['uuid' => CaseFixtures::OBJECT, 'register' => '1', 'schema' => '1'], $this->events[0]->getSubject());
		$this->assertSame('alice', $this->audits->entries[0]->getActor());
		$this->assertSame(1, $this->audits->entries[0]->jsonSerialize()['id']);
	}//end testActivationRealisesAuditsAndAnnouncesAfterCommit()

	/**
	 * Completing a milestone mirrors status; completing via the realisation
	 * does NOT terminate the realisation; a user completion of a task item does.
	 *
	 * @return void
	 */
	public function testCompletionMirrorsAMilestoneAndClosesARealisationOnlyWhenItDidNotCauseIt(): void {
		$milestone = CaseFixtures::row(id: 1, key: 'm', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$viaTask = CaseFixtures::row(id: 2, key: 't1', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$viaTask->setRealisationKind(CaseItem::REALISATION_TASK);
		$viaTask->setRealisationUuid('task-a');
		$byUser = CaseFixtures::row(id: 3, key: 't2', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$byUser->setRealisationKind(CaseItem::REALISATION_TASK);
		$byUser->setRealisationUuid('task-b');
		$this->items->seed([$milestone, $viaTask, $byUser]);

		$this->writer->expects($this->once())->method('mirrorStatus')->with($milestone);
		$this->realiser->expects($this->once())->method('terminate')->with($byUser, 'done by hand');
		$machine = $this->machine();

		$machine->transition(item: $milestone, to: CaseItem::STATE_COMPLETED, cause: CaseItemAudit::CAUSE_SENTRY, causeRef: 's', actor: null);
		$machine->transition(item: $viaTask, to: CaseItem::STATE_COMPLETED, cause: CaseItemAudit::CAUSE_REALISATION, causeRef: 'task-a', actor: null);
		$machine->transition(item: $byUser, to: CaseItem::STATE_COMPLETED, cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: 'alice', reason: 'done by hand');

		$this->assertTrue($milestone->getIsTerminal());
		$this->assertNotNull($milestone->getEnteredAt(), 'A milestone is entered when it completes.');
		$this->assertSame(CasePlanStateMachine::SYSTEM_ACTOR, $this->audits->entries[0]->getActor());
		$this->assertSame('case.item.completed', $this->events[0]->getCatalogTrigger());
		$this->assertCount(3, $this->events);
	}//end testCompletionMirrorsAMilestoneAndClosesARealisationOnlyWhenItDidNotCauseIt()

	/**
	 * Terminating a stage cascades: entered children terminated, unentered
	 * disabled (a milestone terminated), nested stages recursively, each with
	 * its own `cascade` audit row naming the parent; terminal children untouched.
	 *
	 * @return void
	 */
	public function testTerminatingAStageCascadesWithPerChildAudit(): void {
		$stage = CaseFixtures::row(id: 1, key: 'hearing', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$active = CaseFixtures::row(id: 2, key: 'invite', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE, parentId: 1);
		$active->setRealisationKind(CaseItem::REALISATION_TASK);
		$active->setRealisationUuid('task-1');
		$unentered = CaseFixtures::row(id: 3, key: 'report', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE, parentId: 1);
		$milestone = CaseFixtures::row(id: 4, key: 'heard', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE, parentId: 1);
		$done = CaseFixtures::row(id: 5, key: 'done', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_COMPLETED, parentId: 1);
		$inner = CaseFixtures::row(id: 6, key: 'inner', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE, parentId: 1);
		$innerChild = CaseFixtures::row(id: 7, key: 'deep', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ENABLED, parentId: 6);
		$this->items->seed([$stage, $active, $unentered, $milestone, $done, $inner, $innerChild]);
		$this->realiser->expects($this->once())->method('terminate')->with($active, $this->stringContains("Stage 'hearing' exited"));

		$this->machine()->transition(
			item: $stage,
			to: CaseItem::STATE_TERMINATED,
			cause: CaseItemAudit::CAUSE_USER,
			causeRef: null,
			actor: 'alice',
			reason: 'Bezwaar ingetrokken.',
			tree: new CasePlanTree(items: $this->items->findByObject(CaseFixtures::OBJECT))
		);

		$this->assertSame('Bezwaar ingetrokken.', $stage->getTerminatedReason());
		$this->assertSame(CaseItem::STATE_TERMINATED, $active->getState());
		$this->assertSame(CaseItem::STATE_DISABLED, $unentered->getState());
		$this->assertSame(CaseItem::STATE_TERMINATED, $milestone->getState(), 'A milestone has no disabled edge.');
		$this->assertSame(CaseItem::STATE_COMPLETED, $done->getState(), 'Terminal children are left alone.');
		$this->assertSame(CaseItem::STATE_TERMINATED, $inner->getState());
		$this->assertSame(CaseItem::STATE_TERMINATED, $innerChild->getState(), 'Nested stages cascade in turn.');

		foreach ([2, 3, 4, 6, 7] as $id) {
			$entries = $this->audits->findForItem($id);
			$this->assertCount(1, $entries, "child $id has exactly one audit row");
			$this->assertSame(CaseItemAudit::CAUSE_CASCADE, $entries[0]->getCause());
			$this->assertSame($id === 7 ? 'item-6' : 'item-1', $entries[0]->getCauseRef(), 'The cause_ref is the exited parent.');
			$this->assertTrue($this->items->rows[$id]->getIsTerminal());
		}

		$this->assertSame([], $this->audits->findForItem(5));
		$this->assertCount(6, $this->events, 'One event per transition, all after the one commit.');
	}//end testTerminatingAStageCascadesWithPerChildAudit()

	/**
	 * An illegal transition is refused before any write; a lost race rolls
	 * back and is refused naming the state.
	 *
	 * @return void
	 */
	public function testIllegalAndRacedTransitionsAreRefused(): void {
		$milestone = CaseFixtures::row(id: 1, key: 'm', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$raced = CaseFixtures::row(id: 2, key: 'r', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$this->items->seed([$milestone, $raced]);
		$machine = $this->machine();

		try {
			$machine->transition(item: $milestone, to: CaseItem::STATE_ACTIVE, cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: 'a');
			$this->fail('illegal');
		} catch (CaseTransitionException) {
			$this->assertSame(0, $this->items->updates, 'Refused before any write.');
			$this->assertSame([], $this->audits->entries);
		}

		$this->items->failUpdateFor['item-2'] = true;
		$this->db->expects($this->once())->method('rollBack');
		try {
			$machine->transition(item: $raced, to: CaseItem::STATE_COMPLETED, cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: 'a');
			$this->fail('raced');
		} catch (CaseTransitionException $lost) {
			$this->assertStringContainsString("moved concurrently out of 'active'", $lost->getMessage());
		}

		$this->assertSame([], $this->events, 'Nothing is announced after a rollback.');
	}//end testIllegalAndRacedTransitionsAreRefused()

	/**
	 * An audit-write failure unwinds the transition; a denial is recorded
	 * outside any transaction; creation is audited from an empty from-state.
	 *
	 * @return void
	 */
	public function testAuditFailureRollsBackAndDenialsAndCreationsAreRecorded(): void {
		$item = CaseFixtures::row(id: 1, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$this->items->seed([$item]);
		$machine = $this->machine();

		$this->audits->failNext = true;
		$this->db->expects($this->once())->method('rollBack');
		try {
			$machine->transition(item: $item, to: CaseItem::STATE_COMPLETED, cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: 'a');
			$this->fail('audit failure must propagate');
		} catch (RuntimeException $failure) {
			$this->assertSame('audit table unavailable', $failure->getMessage());
		}

		$machine->recordDenial(item: $item, to: CaseItem::STATE_ENABLED, actor: 'stranger', reason: 'denied');
		$this->assertFalse($this->audits->entries[0]->getAuthorized());
		$this->assertSame('stranger', $this->audits->entries[0]->getActor());
		$this->assertSame(CaseItem::STATE_ENABLED, $this->audits->entries[0]->getToState());

		$machine->recordDenial(item: new CaseItem(), to: 'x', actor: null, reason: 'unsaved: ignored');
		$this->audits->failNext = true;
		$machine->recordDenial(item: $item, to: 'x', actor: null, reason: 'logged, not thrown');
		$this->assertCount(1, $this->audits->entries);

		$machine->recordCreation(item: $item, cause: CaseItemAudit::CAUSE_IMPORT, causeRef: 'flow-1', actor: null);
		// The fake mapper has no real rollback, so the row kept the state the
		// failed transition wrote; what matters is the empty from-state.
		$this->assertStringEndsWith('(import)', $this->audits->trail(1)[1]);
		$this->assertSame('', $this->audits->entries[1]->getFromState());
		$this->assertSame(CasePlanStateMachine::SYSTEM_ACTOR, $this->audits->entries[1]->getActor());

		$machine->discardEvents();
		$machine->flushEvents();
		$this->assertSame([], $this->events);
	}//end testAuditFailureRollsBackAndDenialsAndCreationsAreRecorded()

	/**
	 * Nested in an outer transaction, events wait for the outer committer;
	 * a listener failure never unwinds the transition; no dispatcher is fine.
	 *
	 * @return void
	 */
	public function testNestedTransitionsDeferEventsAndListenerFailuresAreSwallowed(): void {
		$item = CaseFixtures::row(id: 1, key: 'x', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$this->items->seed([$item]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(true);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willThrowException(new RuntimeException('listener broke'));
		$machine = new CasePlanStateMachine(
			items: $this->items,
			audits: $this->audits,
			table: new CasePlanTransitions(),
			realiser: $this->realiser,
			writer: $this->writer,
			db: $db,
			logger: new NullLogger(),
			dispatcher: $dispatcher
		);

		$machine->transition(item: $item, to: CaseItem::STATE_COMPLETED, cause: CaseItemAudit::CAUSE_SENTRY, causeRef: 's', actor: null);
		$machine->flushEvents();
		$this->assertSame(CaseItem::STATE_COMPLETED, $item->getState());

		$silent = new CasePlanStateMachine(
			items: $this->items,
			audits: $this->audits,
			table: new CasePlanTransitions(),
			realiser: $this->realiser,
			writer: $this->writer,
			db: $this->db,
			logger: new NullLogger()
		);
		$other = CaseFixtures::row(id: 2, key: 'y', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$this->items->seed([$other]);
		$silent->transition(item: $other, to: CaseItem::STATE_TERMINATED, cause: CaseItemAudit::CAUSE_USER, causeRef: null, actor: 'a', reason: 'r');
		$this->assertSame('r', $other->getTerminatedReason());
	}//end testNestedTransitionsDeferEventsAndListenerFailuresAreSwallowed()
}//end class
