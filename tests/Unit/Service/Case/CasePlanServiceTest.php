<?php

/**
 * The public surface: creation validates then authorizes then writes then
 * evaluates; reads are visibility-gated (404, not 403); enable and attach
 * deny before writing with the denial audited; ad-hoc items derive their
 * authorization; a result outside the set is refused naming it; deleting
 * the plan leaves the audit and the mirrored state; two overlapping
 * transitions on different items both succeed with their own audit rows.
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
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Exception\CaseTransitionException;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CaseAnchorReader;
use OCA\OpenRegister\Service\Case\CaseBusinessStateWriter;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanCascade;
use OCA\OpenRegister\Service\Case\CasePlanDefinition;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\CasePlanStateMachine;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Coverage of CasePlanService over the real machine, cascade, evaluator,
 * authorization and compiler, with the mappers, realiser and object access faked.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanService
 * @covers \OCA\OpenRegister\Service\Case\CasePlanCascade
 * @covers \OCA\OpenRegister\Service\Case\CasePlanStateMachine
 * @covers \OCA\OpenRegister\Service\Case\CasePlanAuthorizationService
 */
class CasePlanServiceTest extends TestCase {

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
	 * Writer.
	 *
	 * @var CaseBusinessStateWriter&MockObject
	 */
	private CaseBusinessStateWriter&MockObject $writer;

	/**
	 * Task outcomes by realisation uuid.
	 *
	 * @var array<string, string>
	 */
	private array $outcomes = [];

	/**
	 * Fresh collaborators. Groups: alice in demo-behandelaars, bob in
	 * demo-beslissers, boss is admin, `ghost` does not exist.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->items = new FakeCaseItemMapper($this);
		$this->audits = new RecordingAuditMapper($this);
		$this->outcomes = [];
		$this->realiser = $this->createMock(CaseRealisationService::class);
		$this->realiser->method('realise')->willReturnCallback(
			static function (CaseItem $row): void {
				$row->setRealisationKind(CaseItem::REALISATION_NONE);
				if ($row->getPlanItemType() === CaseItem::TYPE_HUMAN_TASK) {
					$row->setRealisationKind(CaseItem::REALISATION_TASK);
					$row->setRealisationUuid('task-for-' . (string)$row->getItemKey());
				}
			}
		);
		$this->realiser->method('terminalOutcome')->willReturnCallback(
			fn (CaseItem $row): ?string => ($this->outcomes[(string)$row->getRealisationUuid()] ?? null)
		);
		$this->anchor = $this->createMock(CaseAnchorReader::class);
		$this->anchor->method('read')->willReturn(['status' => 'open']);
		$this->anchor->method('mayRead')->willReturn(true);
		$this->writer = $this->createMock(CaseBusinessStateWriter::class);
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return CasePlanService The service.
	 */
	private function service(): CasePlanService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'boss');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => ($uid === 'alice' && $gid === 'demo-behandelaars') || ($uid === 'bob' && $gid === 'demo-beslissers')
		);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid !== 'ghost');
		$sentries = new CaseSentryEvaluator(catalog: new EventCatalogService());
		$machine = new CasePlanStateMachine(
			items: $this->items,
			audits: $this->audits,
			table: new CasePlanTransitions(),
			realiser: $this->realiser,
			writer: $this->writer,
			db: $db,
			logger: new NullLogger()
		);

		return new CasePlanService(
			items: $this->items,
			audits: $this->audits,
			machine: $machine,
			cascade: new CasePlanCascade(items: $this->items, machine: $machine, sentries: $sentries, realiser: $this->realiser, anchor: $this->anchor, db: $db, logger: new NullLogger()),
			sentries: $sentries,
			authorization: new CasePlanAuthorizationService(groupManager: $groups),
			anchor: $this->anchor,
			writer: $this->writer,
			definitions: new CasePlanDefinition(sentries: $sentries),
			db: $db,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * The plan's states by key.
	 *
	 * @param array<string, mixed> $plan A getPlan() result.
	 *
	 * @return array<string, string> key => state.
	 */
	private static function states(array $plan): array {
		$states = [];
		foreach ($plan['items'] as $item) {
			$states[$item['key']] = $item['state'];
		}

		ksort($states);

		return $states;
	}//end states()

	/**
	 * Creating the permit plan enters intake and realises the check, without
	 * any run; reading it needs no run uuid; a stranger who cannot read the
	 * object gets "no such plan".
	 *
	 * @return void
	 */
	public function testCreateEntersThePlanAndReadsAreVisibilityGated(): void {
		$plan = $this->service()->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');

		$expected = ['intake' => 'active', 'completeness-check' => 'active', 'application-complete' => 'available', 'assessment' => 'available', 'external-advice' => 'available', 'decide' => 'available', 'run-stage' => 'available'];
		ksort($expected);
		$this->assertSame($expected, self::states($plan));
		$this->assertSame(['demo-behandelaars'], $plan['settings']['authorization']);
		$origins = array_column($plan['items'], 'origin', 'key');
		$this->assertSame(CaseItem::ORIGIN_DISCRETIONARY, $origins['external-advice']);
		$this->assertSame(CaseItem::ORIGIN_DEFINED, $origins['decide']);
		$this->assertSame('->available (import)', $this->audits->trail(1)[0]);
		$this->assertNotEmpty($plan['audit']);
		foreach ($plan['items'] as $item) {
			$this->assertNull($item['flowUuid'], 'No run, no flow: the plan stands on the object alone.');
		}

		try {
			$this->service()->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');
			$this->fail('one plan per object');
		} catch (CaseValidationException $refusal) {
			$this->assertStringContainsString('already has a case plan', $refusal->getMessage());
		}

		$this->anchor = $this->createMock(CaseAnchorReader::class);
		$this->anchor->method('mayRead')->willReturn(false);
		try {
			$this->service()->getPlan(objectUuid: CaseFixtures::OBJECT, uid: 'stranger');
			$this->fail('invisible reads as absent');
		} catch (DoesNotExistException) {
			$this->addToAssertionCount(1);
		}

		$this->assertCount(7, $this->service()->getPlan(objectUuid: CaseFixtures::OBJECT, uid: 'boss')['items'], 'An administrator sees it regardless.');
		$this->expectException(DoesNotExistException::class);
		$this->service()->getPlan(objectUuid: 'no-plan', uid: 'boss');
	}//end testCreateEntersThePlanAndReadsAreVisibilityGated()

	/**
	 * Creation is refused at the boundary before anything is written: an
	 * unknown event, and a caller who could not administer the plan.
	 *
	 * @return void
	 */
	public function testCreationRefusesBeforeWriting(): void {
		$bad = CasePlanDefinitionTest::permitDefinition();
		$bad['items'][0]['entryCriteria'] = [['on' => ['event' => 'case.item.started']]];
		try {
			$this->service()->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: $bad, uid: 'alice');
			$this->fail('unknown event');
		} catch (CaseValidationException $refusal) {
			$this->assertStringContainsString("'case.item.started'", $refusal->getMessage());
		}

		try {
			$this->service()->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'bob');
			$this->fail('bob does not hold the root authorization');
		} catch (CaseAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame([], $this->items->rows, 'No plan item was created.');
		$this->assertSame([], $this->audits->entries);
	}//end testCreationRefusesBeforeWriting()

	/**
	 * Enable: a non-member is denied with the denial audited and nothing
	 * else written; an unresolvable role denies; a member enables, the item
	 * starts, and the enableable query reflects it.
	 *
	 * @return void
	 */
	public function testEnableIsAuthorizedFailClosedAndAudited(): void {
		$service = $this->service();
		$service->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');
		// Reach assessment: the check completes.
		$this->outcomes['task-for-completeness-check'] = CaseItem::STATE_COMPLETED;
		$service->evaluate(objectUuid: CaseFixtures::OBJECT, uid: 'alice');
		$this->assertSame('active', self::states($service->getPlan(objectUuid: CaseFixtures::OBJECT, uid: 'alice'))['assessment']);

		$advice = $this->items->findByUuid('item-5');
		$this->assertSame('external-advice', $advice->getItemKey());
		$enableable = $service->enableableItems(objectUuid: CaseFixtures::OBJECT, uid: 'alice');
		$this->assertSame(['external-advice'], array_column($enableable, 'key'));

		$before = count($this->audits->entries);
		try {
			$service->enableDiscretionary(itemUuid: 'item-5', uid: 'alice');
			$this->fail('alice is a behandelaar, the item wants beslissers');
		} catch (CaseAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame(CaseItem::STATE_AVAILABLE, $advice->getState());
		$this->assertCount($before + 1, $this->audits->entries, 'Exactly the denial was recorded.');
		$denial = $this->audits->entries[$before];
		$this->assertFalse($denial->getAuthorized());
		$this->assertSame('alice', $denial->getActor());
		$this->assertSame(CaseItem::STATE_ENABLED, $denial->getToState());

		$advice->setAuthorizationRules(['role:ghost']);
		try {
			$service->enableDiscretionary(itemUuid: 'item-5', uid: 'bob');
			$this->fail('indeterminate role');
		} catch (CaseAccessDeniedException $refusal) {
			$this->assertStringContainsString("'ghost'", $refusal->getMessage());
		}

		$advice->setAuthorizationRules(['demo-beslissers']);
		$enabled = $service->enableDiscretionary(itemUuid: 'item-5', uid: 'bob');
		$this->assertSame(CaseItem::STATE_ACTIVE, $enabled->getState(), 'Enabled, then started by the evaluation.');
		$this->assertSame('task-for-external-advice', $enabled->getRealisationUuid());
		$authorized = array_values(array_filter($this->audits->findForItem(5), static fn (CaseItemAudit $entry): bool => $entry->getAuthorized() === true));
		$this->assertSame(
			['->available (import)', 'available->enabled (user)', 'enabled->active (user)'],
			array_map(static fn (CaseItemAudit $entry): string => sprintf('%s->%s (%s)', (string)$entry->getFromState(), (string)$entry->getToState(), (string)$entry->getCause()), $authorized)
		);
		$this->assertCount(2, array_filter($this->audits->findForItem(5), static fn (CaseItemAudit $entry): bool => $entry->getAuthorized() === false), 'Both denials are on record.');
		$this->assertSame([], $service->enableableItems(objectUuid: CaseFixtures::OBJECT, uid: 'alice'));

		// A non-discretionary item is refused as not enableable, once the
		// caller (alice holds the root rules) is past authorization.
		$this->expectException(CaseValidationException::class);
		$service->enableDiscretionary(itemUuid: 'item-6', uid: 'alice');
	}//end testEnableIsAuthorizedFailClosedAndAudited()

	/**
	 * Attach: an ad-hoc item under the active stage is created, entered and
	 * realised, inheriting the stage's authorization; a stranger is refused
	 * with the denial on the parent; a self-declared authorization is refused;
	 * a non-active parent is refused; no definition changes.
	 *
	 * @return void
	 */
	public function testAttachAdHocDerivesAuthorizationAndEntersAtOnce(): void {
		$service = $this->service();
		$service->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');
		$definitionRows = count($this->items->rows);

		try {
			$service->attachAdHoc(objectUuid: CaseFixtures::OBJECT, data: ['key' => 'visit', 'type' => 'humanTask', 'parent' => 'intake'], uid: 'stranger');
			$this->fail('stranger');
		} catch (CaseAccessDeniedException) {
			$last = end($this->audits->entries);
			$this->assertFalse($last->getAuthorized());
			$this->assertSame(1, $last->getCaseItemId(), 'The denial is recorded on the parent stage.');
		}

		try {
			$service->attachAdHoc(objectUuid: CaseFixtures::OBJECT, data: ['key' => 'visit', 'type' => 'humanTask', 'parent' => 'intake', 'authorization' => []], uid: 'alice');
			$this->fail('cannot declare itself unguarded');
		} catch (CaseValidationException $refusal) {
			$this->assertStringContainsString('cannot declare its own authorization', $refusal->getMessage());
		}

		foreach ([['parent' => 'assessment'], ['parent' => 'completeness-check'], ['parent' => 'nope'], ['key' => 'intake']] as $bad) {
			try {
				$service->attachAdHoc(objectUuid: CaseFixtures::OBJECT, data: array_merge(['key' => 'visit', 'type' => 'humanTask', 'parent' => 'intake'], $bad), uid: 'alice');
				$this->fail('refused: ' . json_encode($bad));
			} catch (CaseValidationException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->assertCount($definitionRows, $this->items->rows, 'Nothing was written by the refusals.');

		$visit = $service->attachAdHoc(objectUuid: CaseFixtures::OBJECT, data: ['key' => 'visit', 'type' => 'humanTask', 'name' => 'Locatiebezoek', 'parent' => 'intake', 'required' => false, 'candidateUsers' => ['alice']], uid: 'alice');
		$this->assertSame(CaseItem::ORIGIN_ADHOC, $visit->getOrigin());
		$this->assertNull($visit->getDefinitionItemKey());
		$this->assertNull($visit->getAuthorizationRules(), 'Derived, not declared.');
		$this->assertSame(1, $visit->getParentItemId());
		$this->assertSame(CaseItem::STATE_ACTIVE, $visit->getState(), 'Entered and realised at once.');
		$this->assertSame('task-for-visit', $visit->getRealisationUuid());
		$this->assertSame(['->available (user)', 'available->active (sentry)'], $this->audits->trail((int)$visit->getId()));
		$this->assertCount($definitionRows + 1, $this->items->rows);

		// A root-level ad-hoc item derives from the plan root.
		$rootLevel = $service->attachAdHoc(objectUuid: CaseFixtures::OBJECT, data: ['key' => 'note', 'type' => 'milestone', 'name' => 'Notitie'], uid: 'alice');
		$this->assertNull($rootLevel->getParentItemId());
		$this->assertSame(CaseItem::STATE_COMPLETED, $rootLevel->getState(), 'A root milestone with no criteria completes at once.');

		$this->expectException(DoesNotExistException::class);
		$service->attachAdHoc(objectUuid: 'no-plan', data: ['key' => 'x', 'type' => 'milestone'], uid: 'alice');
	}//end testAttachAdHocDerivesAuthorizationAndEntersAtOnce()

	/**
	 * Two overlapping transitions on different items both succeed with their
	 * own audit rows; a user transition is authorized; an illegal one is refused.
	 *
	 * @return void
	 */
	public function testTransitionsOnDifferentItemsDoNotClobberEachOther(): void {
		$service = $this->service();
		$service->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');
		$visitA = $service->attachAdHoc(objectUuid: CaseFixtures::OBJECT, data: ['key' => 'a', 'type' => 'humanTask', 'parent' => 'intake', 'required' => false], uid: 'alice');
		$visitB = $service->attachAdHoc(objectUuid: CaseFixtures::OBJECT, data: ['key' => 'b', 'type' => 'humanTask', 'parent' => 'intake', 'required' => false], uid: 'alice');

		// Both read as active, then both complete "at the same time": each
		// conditional update sees its own row still active.
		$this->realiser->expects($this->exactly(2))->method('terminate');
		$doneA = $service->transition(itemUuid: (string)$visitA->getUuid(), to: CaseItem::STATE_COMPLETED, uid: 'alice', reason: 'done a');
		$doneB = $service->transition(itemUuid: (string)$visitB->getUuid(), to: CaseItem::STATE_COMPLETED, uid: 'alice', reason: 'done b');
		$this->assertSame(CaseItem::STATE_COMPLETED, $doneA->getState());
		$this->assertSame(CaseItem::STATE_COMPLETED, $doneB->getState());
		$this->assertSame('active->completed (user)', end($this->audits->trail((int)$visitA->getId())));
		$this->assertSame('active->completed (user)', end($this->audits->trail((int)$visitB->getId())));
		$this->assertSame('done b', $this->audits->findForItem((int)$visitB->getId())[2]->getReason());

		try {
			$service->transition(itemUuid: 'item-3', to: CaseItem::STATE_ACTIVE, uid: 'alice');
			$this->fail('a milestone cannot become active');
		} catch (CaseTransitionException) {
			$this->addToAssertionCount(1);
		}

		try {
			$service->transition(itemUuid: 'item-3', to: 'paused', uid: 'alice');
			$this->fail('not a state');
		} catch (CaseValidationException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(CaseAccessDeniedException::class);
		$service->transition(itemUuid: 'item-3', to: CaseItem::STATE_COMPLETED, uid: 'stranger');
	}//end testTransitionsOnDifferentItemsDoNotClobberEachOther()

	/**
	 * Completing the case: a result outside the set is refused naming the set;
	 * an open required root item refuses; otherwise the result is mirrored.
	 * Deleting leaves the audit and mirrors nothing.
	 *
	 * @return void
	 */
	public function testCompleteCaseConstrainsTheResultAndDeleteLeavesTheAudit(): void {
		$service = $this->service();
		$service->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');

		try {
			$service->completeCase(objectUuid: CaseFixtures::OBJECT, result: 'misschien', uid: 'alice');
			$this->fail('outside the set');
		} catch (CaseValidationException $refusal) {
			$this->assertStringContainsString('[verleend, geweigerd]', $refusal->getMessage());
		}

		try {
			$service->completeCase(objectUuid: CaseFixtures::OBJECT, result: 'verleend', uid: 'alice');
			$this->fail('intake is still open');
		} catch (CaseValidationException $refusal) {
			$this->assertStringContainsString("'intake'", $refusal->getMessage());
		}

		// Finish the work: check, then decide.
		$this->outcomes['task-for-completeness-check'] = CaseItem::STATE_COMPLETED;
		$this->outcomes['task-for-decide'] = CaseItem::STATE_COMPLETED;
		$service->evaluate(objectUuid: CaseFixtures::OBJECT, uid: 'alice');
		$this->writer->expects($this->once())->method('mirrorResult')->with($this->anything(), 'verleend')->willReturn(true);
		$finished = $service->completeCase(objectUuid: CaseFixtures::OBJECT, result: 'verleend', uid: 'alice');
		$this->assertSame('verleend', $finished['result']);
		$this->assertSame('completed', self::states($finished)['assessment']);
		$this->assertSame('available', self::states($finished)['run-stage'], 'Optional and never admitted: not in the way.');

		try {
			$service->completeCase(objectUuid: CaseFixtures::OBJECT, result: 'verleend', uid: 'bob');
			$this->fail('bob may not administer');
		} catch (CaseAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$auditRows = count($this->audits->entries);
		$this->assertSame(7, $service->deletePlan(objectUuid: CaseFixtures::OBJECT, uid: 'alice'));
		$this->assertCount($auditRows, $this->audits->entries, 'The audit outlives the rows.');
		$this->writer->expects($this->never())->method('mirrorStatus');

		foreach (['completeCase', 'deletePlan'] as $verb) {
			try {
				$verb === 'completeCase'
					? $service->completeCase(objectUuid: 'nope', result: 'x', uid: 'boss')
					: $service->deletePlan(objectUuid: 'nope', uid: 'boss');
				$this->fail("$verb on no plan");
			} catch (DoesNotExistException) {
				$this->addToAssertionCount(1);
			}
		}
	}//end testCompleteCaseConstrainsTheResultAndDeleteLeavesTheAudit()

	/**
	 * The listener entry points: a task ending evaluates the plans it
	 * realised; an object event evaluates only objects with a live plan; the
	 * cross-case listing is an administrator's read with a datastore total.
	 *
	 * @return void
	 */
	public function testEventEntryPointsAndTheStuckQuery(): void {
		$service = $this->service();
		$service->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');

		$this->outcomes['task-for-completeness-check'] = CaseItem::STATE_COMPLETED;
		$service->onRealisationTerminal(taskUuid: 'task-for-completeness-check');
		$this->assertSame('completed', self::states($service->getPlan(objectUuid: CaseFixtures::OBJECT, uid: 'alice'))['completeness-check']);
		$service->onRealisationTerminal(taskUuid: 'unknown-task');

		// An object on-part: run-stage waits for json.automate; the event brings it.
		$this->anchor = $this->createMock(CaseAnchorReader::class);
		$this->anchor->method('read')->willReturn(['automate' => true]);
		$this->anchor->method('mayRead')->willReturn(true);
		$service = $this->service();
		$service->onObjectEvent(objectUuid: 'object-without-plan', event: 'object.updated', payload: []);
		$service->onObjectEvent(objectUuid: CaseFixtures::OBJECT, event: 'object.updated', payload: ['automate' => true]);
		$this->assertSame('active', self::states($service->getPlan(objectUuid: CaseFixtures::OBJECT, uid: 'alice'))['run-stage']);

		$page = $service->findStuck(type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE, limit: 1, offset: 0, uid: 'boss');
		$this->assertSame(1, $page['total'], 'decide is the one active human item.');
		$this->assertSame('decide', $page['results'][0]['key']);
		$this->assertSame(1, $page['limit']);

		$this->expectException(CaseAccessDeniedException::class);
		$service->findStuck(type: null, state: null, limit: 25, offset: 0, uid: 'alice');
	}//end testEventEntryPointsAndTheStuckQuery()

	/**
	 * A failing event-driven evaluation is logged, never rethrown into the event.
	 *
	 * @return void
	 */
	public function testEventDrivenEvaluationFailuresAreSwallowed(): void {
		$service = $this->service();
		$service->createPlan(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: CasePlanDefinitionTest::permitDefinition(), uid: 'alice');
		$this->audits->failNext = true;
		$this->outcomes['task-for-completeness-check'] = CaseItem::STATE_COMPLETED;
		$service->onRealisationTerminal(taskUuid: 'task-for-completeness-check');
		// The in-memory fake has no rollback, so the row itself is not the
		// evidence; the audit is: the failed append left no completion entry,
		// and the failure never reached the caller.
		$completions = array_filter($this->audits->findForItem(2), static fn (CaseItemAudit $entry): bool => $entry->getToState() === CaseItem::STATE_COMPLETED);
		$this->assertSame([], $completions);
	}//end testEventDrivenEvaluationFailuresAreSwallowed()
}//end class
