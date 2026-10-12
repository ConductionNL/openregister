<?php

/**
 * Unit tests for CasePlanEnsurer: convergent bring-over of plan items with
 * their recorded states and history.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanDefinition;
use OCA\OpenRegister\Service\Case\CasePlanEnsurer;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Real definition validator, real transition table, in-memory rows.
 */
class CasePlanEnsurerTest extends TestCase {

	private FakeCaseItemMapper $items;

	private RecordingAuditMapper $audits;

	/**
	 * Fresh tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->items = new FakeCaseItemMapper($this);
		$this->audits = new RecordingAuditMapper($this);
	}//end setUp()

	/**
	 * The ensurer under test.
	 *
	 * @return CasePlanEnsurer The ensurer.
	 */
	private function ensurer(): CasePlanEnsurer {
		$db = $this->createMock(IDBConnection::class);

		return new CasePlanEnsurer(
			items: $this->items,
			audits: $this->audits,
			definitions: new CasePlanDefinition(sentries: new CaseSentryEvaluator(catalog: new EventCatalogService())),
			transitions: new CasePlanTransitions(),
			db: $db
		);
	}//end ensurer()

	/**
	 * A migrated plan: a stage with a completed task, an active task and a
	 * reached milestone.
	 *
	 * @return array<string, mixed> The definition with recorded states.
	 */
	public static function migrated(): array {
		return [
			'settings' => ['authorization' => ['demo-behandelaars']],
			'items' => [
				[
					'key' => 'intake',
					'type' => CaseItem::TYPE_STAGE,
					'name' => 'Intake',
					'state' => CaseItem::STATE_ACTIVE,
					'children' => [
						['key' => 'check', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Check', 'state' => CaseItem::STATE_COMPLETED],
						['key' => 'beoordeling', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Beoordeling', 'state' => CaseItem::STATE_ACTIVE],
						['key' => 'volledig', 'type' => CaseItem::TYPE_MILESTONE, 'name' => 'Volledig', 'state' => CaseItem::STATE_COMPLETED],
					],
				],
				['key' => 'besluit', 'type' => CaseItem::TYPE_HUMAN_TASK, 'name' => 'Besluit'],
			],
		];
	}//end migrated()

	/**
	 * History as the source engine recorded it.
	 *
	 * @return array<int, array<string, string>> The entries.
	 */
	private static function history(): array {
		return [
			['item' => 'check', 'from' => 'available', 'to' => 'active', 'at' => '2026-09-01T10:00:00+00:00', 'actor' => 'alice'],
			['item' => 'check', 'from' => 'active', 'to' => 'completed', 'at' => '2026-09-02T10:00:00+00:00', 'actor' => 'alice'],
			['item' => 'volledig', 'from' => 'available', 'to' => 'completed', 'at' => '2026-09-02T10:00:01+00:00'],
		];
	}//end history()

	/**
	 * States by key of what the table holds.
	 *
	 * @return array<string, string> key => state.
	 */
	private function states(): array {
		$states = [];
		foreach ($this->items->findByObject(objectUuid: CaseFixtures::OBJECT) as $row) {
			$states[(string)$row->getItemKey()] = (string)$row->getState();
		}

		ksort($states);

		return $states;
	}//end states()

	/**
	 * A fresh bring-over carries states verbatim, nests under the right
	 * parent, creates no realisation, and imports history flagged as such
	 * with the original moments.
	 *
	 * @return void
	 */
	public function testCreatesItemsWithRecordedStatesAndImportsHistory(): void {
		$result = $this->ensurer()->ensure(
			objectUuid: CaseFixtures::OBJECT,
			registerId: 1,
			schemaId: 2,
			definition: self::migrated(),
			history: self::history(),
			actor: 'system:dossiq'
		);

		$this->assertSame(['intake', 'check', 'beoordeling', 'volledig', 'besluit'], $result['created']);
		$this->assertSame([], $result['existing']);
		$this->assertSame(['beoordeling' => 'active', 'besluit' => 'available', 'check' => 'completed', 'intake' => 'active', 'volledig' => 'completed'], $this->states());

		$byKey = [];
		foreach ($this->items->findByObject(objectUuid: CaseFixtures::OBJECT) as $row) {
			$byKey[(string)$row->getItemKey()] = $row;
		}

		$this->assertSame($byKey['intake']->getId(), $byKey['check']->getParentItemId());
		$this->assertNull($byKey['besluit']->getParentItemId());
		$this->assertTrue($byKey['check']->getIsTerminal());
		$this->assertFalse($byKey['beoordeling']->getIsTerminal());
		$this->assertNull($byKey['beoordeling']->getRealisationUuid(), 'An imported active item gets no new task.');
		$this->assertNotNull($byKey['beoordeling']->getEnteredAt());
		$this->assertNull($byKey['besluit']->getEnteredAt());
		$this->assertSame(2, $byKey['check']->getSchemaId());
		$this->assertSame(['authorization' => ['demo-behandelaars']], $byKey['check']->getPlanSettings());

		$checkTrail = $this->audits->findForItem((int)$byKey['check']->getId());
		$this->assertCount(3, $checkTrail);
		$this->assertSame(CaseItemAudit::CAUSE_IMPORT, $checkTrail[0]->getCause());
		$this->assertSame('ensure:system:dossiq', $checkTrail[0]->getCauseRef());
		$this->assertSame('system:dossiq', $checkTrail[0]->getActor());
		$this->assertFalse($checkTrail[0]->getImported());
		$this->assertTrue($checkTrail[1]->getImported());
		$this->assertSame('alice', $checkTrail[1]->getActor());
		$this->assertSame('2026-09-01T10:00:00+00:00', $checkTrail[1]->getCreated()->format('c'));
		$this->assertSame('completed', $checkTrail[2]->getToState());
		$this->assertSame('system:dossiq', $this->audits->findForItem((int)$byKey['volledig']->getId())[1]->getActor(), 'History without an actor names the importer.');
	}//end testCreatesItemsWithRecordedStatesAndImportsHistory()

	/**
	 * A half-written plan resumes: existing rows keep their state and gain
	 * no audit entry; only the missing ones are created, under the existing
	 * parent. A second run then appends nothing at all.
	 *
	 * @return void
	 */
	public function testHalfWrittenPlanResumesAndSecondRunChangesNothing(): void {
		$this->items->seed(
			[
				CaseFixtures::row(id: 10, key: 'intake', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE),
				CaseFixtures::row(id: 11, key: 'check', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE, parentId: 10),
			]
		);

		$first = $this->ensurer()->ensure(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: self::migrated(), history: self::history(), actor: 'system:dossiq');
		$this->assertSame(['beoordeling', 'volledig', 'besluit'], $first['created']);
		$this->assertSame(['intake', 'check'], $first['existing']);
		$this->assertSame('active', $this->states()['check'], 'An existing row keeps its state; the recorded one is never written over it.');
		$this->assertSame([], $this->audits->findForItem(11), 'No audit for a row this call did not create, history included.');

		$rows = $this->items->findByObject(objectUuid: CaseFixtures::OBJECT);
		foreach ($rows as $row) {
			if ($row->getItemKey() === 'beoordeling') {
				$this->assertSame(10, $row->getParentItemId());
			}
		}

		$auditCount = count($this->audits->entries);
		$second = $this->ensurer()->ensure(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: self::migrated(), history: self::history(), actor: 'system:dossiq');
		$this->assertSame([], $second['created']);
		$this->assertCount(5, $second['items']);
		$this->assertCount(5, $this->items->findByObject(objectUuid: CaseFixtures::OBJECT));
		$this->assertCount($auditCount, $this->audits->entries);
	}//end testHalfWrittenPlanResumesAndSecondRunChangesNothing()

	/**
	 * Refusals happen before anything is written: a state the type cannot
	 * reach, a state outside the six, history about an unknown item or with
	 * an unreadable moment, an existing key of another type, an anchor that
	 * names another schema.
	 *
	 * @return void
	 */
	public function testRefusesBeforeWriting(): void {
		$cases = [];

		$milestoneActive = self::migrated();
		$milestoneActive['items'][0]['children'][2]['state'] = CaseItem::STATE_ACTIVE;
		$cases["'volledig' (milestone) cannot carry state 'active'"] = [$milestoneActive, [], []];

		$unknownState = self::migrated();
		$unknownState['items'][1]['state'] = 'suspended';
		$cases["'besluit' (humanTask) cannot carry state 'suspended'"] = [$unknownState, [], []];

		$cases["names item 'ghost'"] = [self::migrated(), [['item' => 'ghost', 'to' => 'active', 'at' => '2026-09-01T10:00:00+00:00']], []];
		$cases['unreadable moment'] = [self::migrated(), [['item' => 'check', 'to' => 'active', 'at' => 'yesterday-ish']], []];
		$cases["to-state 'done'"] = [self::migrated(), [['item' => 'check', 'to' => 'done', 'at' => '2026-09-01T10:00:00+00:00']], []];
		$cases["'check' exists as a milestone"] = [self::migrated(), [], [CaseFixtures::row(id: 11, key: 'check', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE)]];

		$otherSchema = CaseFixtures::row(id: 12, key: 'intake', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$otherSchema->setSchemaId(9);
		$cases['anchored to register 1 schema 9'] = [self::migrated(), [], [$otherSchema]];

		foreach ($cases as $expected => [$definition, $history, $seed]) {
			$this->setUp();
			$this->items->seed($seed);
			$before = count($this->items->findByObject(objectUuid: CaseFixtures::OBJECT));
			try {
				$this->ensurer()->ensure(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: $definition, history: $history, actor: 'system:dossiq');
				$this->fail('expected a refusal: ' . $expected);
			} catch (CaseValidationException $refusal) {
				$this->assertStringContainsString($expected, $refusal->getMessage());
			}

			$this->assertCount($before, $this->items->findByObject(objectUuid: CaseFixtures::OBJECT), $expected);
			$this->assertSame([], $this->audits->entries, $expected);
		}
	}//end testRefusesBeforeWriting()

	/**
	 * A definition the validator refuses (an unknown event) is refused here
	 * too: ensure is not a side door around save-time validation.
	 *
	 * @return void
	 */
	public function testDefinitionValidationStillApplies(): void {
		$bad = self::migrated();
		$bad['items'][1]['entryCriteria'] = [['on' => ['event' => 'case.item.started']]];

		$this->expectException(CaseValidationException::class);
		$this->expectExceptionMessage("'case.item.started'");
		$this->ensurer()->ensure(objectUuid: CaseFixtures::OBJECT, registerId: 1, schemaId: 1, definition: $bad, history: [], actor: 'system:dossiq');
	}//end testDefinitionValidationStillApplies()
}//end class
