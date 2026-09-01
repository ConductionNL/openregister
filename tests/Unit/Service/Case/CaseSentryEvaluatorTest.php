<?php

/**
 * Sentry evaluation: AND within, OR across; an unevaluable if-part is FALSE;
 * an unknown event is refused at save time; a malformed sentry never fires;
 * plan-item on-parts read current state; object on-parts read the event.
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
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * Coverage of CaseSentryEvaluator, and of the three catalog entries it relies on.
 *
 * @covers \OCA\OpenRegister\Service\Case\CaseSentryEvaluator
 * @covers \OCA\OpenRegister\Service\Flow\EventCatalogService
 * @covers \OCA\OpenRegister\Exception\CaseValidationException
 */
class CaseSentryEvaluatorTest extends TestCase {

	/**
	 * The evaluator over the real catalog.
	 *
	 * @return CaseSentryEvaluator The evaluator.
	 */
	private function evaluator(): CaseSentryEvaluator {
		return new CaseSentryEvaluator(catalog: new EventCatalogService());
	}//end evaluator()

	/**
	 * The catalog carries the three plan-item events, additively.
	 *
	 * @return void
	 */
	public function testTheCatalogCarriesTheThreeCaseEvents(): void {
		$catalog = new EventCatalogService();
		$known = $catalog->knownTriggerIds();
		foreach (array_keys(CaseSentryEvaluator::ITEM_EVENTS) as $event) {
			$this->assertContains($event, $known);
			$this->assertSame([$event], $catalog->aliasesFor(dispatched: $event), 'A case event has no legacy alias.');
		}

		$this->assertSame(['object.created', 'created'], $catalog->aliasesFor(dispatched: 'created'), 'aliasesFor() is unchanged for existing entries.');
		$this->assertContains('object.transitioned', $known);
	}//end testTheCatalogCarriesTheThreeCaseEvents()

	/**
	 * A milestone completing satisfies another item's on-part, read from state.
	 *
	 * @return void
	 */
	public function testAPlanItemOnPartReadsCurrentStateAndNamesTheSentry(): void {
		$milestone = CaseFixtures::row(id: 1, key: 'advice-received', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$item = CaseFixtures::row(id: 2, key: 'decide', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setEntryCriteria([['id' => 'after-advice', 'on' => ['event' => 'case.item.completed', 'item' => 'advice-received']]]);
		$tree = new CasePlanTree(items: [$milestone, $item]);

		$this->assertNull($this->evaluator()->entrySentry(item: $item, tree: $tree, object: []), 'Not yet.');

		$milestone->setState(CaseItem::STATE_COMPLETED);
		$this->assertSame('after-advice', $this->evaluator()->entrySentry(item: $item, tree: $tree, object: []), 'Now, without any event in hand.');
	}//end testAPlanItemOnPartReadsCurrentStateAndNamesTheSentry()

	/**
	 * AND within a sentry, OR across the criteria array.
	 *
	 * @return void
	 */
	public function testAndWithinOrAcross(): void {
		$done = CaseFixtures::row(id: 1, key: 'a', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_COMPLETED);
		$item = CaseFixtures::row(id: 2, key: 'b', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setEntryCriteria(
			[
				// on holds, if fails: this sentry does not fire.
				['id' => 'first', 'on' => ['event' => 'case.item.completed', 'item' => 'a'], 'if' => ['==' => [['var' => 'json.amount'], 999]]],
				// both hold: fires.
				['id' => 'second', 'on' => ['event' => 'case.item.completed', 'item' => 'a'], 'if' => ['>' => [['var' => 'json.amount'], 100]]],
			]
		);
		$tree = new CasePlanTree(items: [$done, $item]);

		$this->assertSame('second', $this->evaluator()->entrySentry(item: $item, tree: $tree, object: ['amount' => 500]));
		$this->assertNull($this->evaluator()->entrySentry(item: $item, tree: $tree, object: ['amount' => 50]), 'Both if-parts false: none fires.');
	}//end testAndWithinOrAcross()

	/**
	 * An if-part over a field the object does not have is FALSE, not vacuously true.
	 *
	 * @return void
	 */
	public function testAnUnevaluableIfPartBlocks(): void {
		$item = CaseFixtures::row(id: 1, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setEntryCriteria([['id' => 'guard', 'if' => ['==' => [['var' => 'json.missing.deep'], 'yes']]]]);
		$tree = new CasePlanTree(items: [$item]);

		$this->assertNull($this->evaluator()->entrySentry(item: $item, tree: $tree, object: ['other' => 1]));
		$this->assertSame('guard', $this->evaluator()->entrySentry(item: $item, tree: $tree, object: ['missing' => ['deep' => 'yes']]));
	}//end testAnUnevaluableIfPartBlocks()

	/**
	 * The if-part sees the `case` key: another item's state and the object.
	 *
	 * @return void
	 */
	public function testTheIfPartSeesTheCaseDocument(): void {
		$other = CaseFixtures::row(id: 1, key: 'intake', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$item = CaseFixtures::row(id: 2, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setExitCriteria([['id' => 'withdrawn', 'if' => ['and' => [['==' => [['var' => 'case.items.intake'], 'active']], ['==' => [['var' => 'case.object.status'], 'ingetrokken']]]]]]);
		$tree = new CasePlanTree(items: [$other, $item]);
		$evaluator = $this->evaluator();

		$this->assertSame('withdrawn', $evaluator->exitSentry(item: $item, tree: $tree, object: ['status' => 'ingetrokken']));
		$this->assertNull($evaluator->exitSentry(item: $item, tree: $tree, object: ['status' => 'open']));
		$data = $evaluator->dataFor(tree: $tree, object: ['status' => 'open'], event: 'object.updated', payload: ['k' => 1]);
		$this->assertSame(['intake' => 'active', 'x' => 'available'], $data['case']['items']);
		$this->assertSame('object.updated', $data['context']['event']);
		$this->assertSame(['status' => 'open'], $data['json']);
	}//end testTheIfPartSeesTheCaseDocument()

	/**
	 * Empty entry = default (parent active is the caller's check); empty exit = never.
	 *
	 * @return void
	 */
	public function testTheTwoDefaults(): void {
		$item = CaseFixtures::row(id: 1, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$tree = new CasePlanTree(items: [$item]);
		$this->assertSame(CaseSentryEvaluator::DEFAULT_ENTRY, $this->evaluator()->entrySentry(item: $item, tree: $tree, object: []));
		$this->assertNull($this->evaluator()->exitSentry(item: $item, tree: $tree, object: []));
	}//end testTheTwoDefaults()

	/**
	 * Malformed sentries never fire: neither part, an if-part naming no
	 * field, a non-object sentry, an on-part without an event, an item event
	 * without an item.
	 *
	 * @return void
	 */
	public function testAMalformedSentryNeverFires(): void {
		$done = CaseFixtures::row(id: 1, key: 'a', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_COMPLETED);
		$item = CaseFixtures::row(id: 2, key: 'b', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setExitCriteria(
			[
				['id' => 'empty'],
				['id' => 'literal', 'if' => true],
				['id' => 'no-field', 'if' => ['==' => [1, 1]]],
				['id' => 'list-literal', 'if' => [['var' => 'json.x'], 'yes']],
				'not-an-object',
				['id' => 'no-event', 'on' => ['item' => 'a']],
				['id' => 'no-item', 'on' => ['event' => 'case.item.completed']],
				['id' => 'on-not-object', 'on' => 'case.item.completed'],
			]
		);
		$tree = new CasePlanTree(items: [$done, $item]);

		$this->assertNull($this->evaluator()->exitSentry(item: $item, tree: $tree, object: ['x' => 1], event: 'object.updated'));
	}//end testAMalformedSentryNeverFires()

	/**
	 * An object on-part fires only for the event being handled, alias-aware,
	 * and a sentry without an id is named by position.
	 *
	 * @return void
	 */
	public function testAnObjectOnPartReadsTheEventBeingHandled(): void {
		$item = CaseFixtures::row(id: 1, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$item->setExitCriteria([['on' => ['event' => 'object.updated']]]);
		$tree = new CasePlanTree(items: [$item]);
		$evaluator = $this->evaluator();

		$this->assertNull($evaluator->exitSentry(item: $item, tree: $tree, object: []), 'No event in hand.');
		$this->assertNull($evaluator->exitSentry(item: $item, tree: $tree, object: [], event: 'object.transitioned'));
		$this->assertSame('sentry:1', $evaluator->exitSentry(item: $item, tree: $tree, object: [], event: 'updated'), 'The legacy alias counts.');
	}//end testAnObjectOnPartReadsTheEventBeingHandled()

	/**
	 * Save-time validation refuses what the editor should not accept.
	 *
	 * @return void
	 */
	public function testValidationRefusesAtSaveTime(): void {
		$evaluator = $this->evaluator();
		$evaluator->validateCriteria(criteria: null, where: 'x');
		$evaluator->validateCriteria(criteria: [], where: 'x');
		$evaluator->validateCriteria(criteria: [['on' => ['event' => 'object.transitioned']], ['if' => ['var' => 'json.a']], ['on' => ['event' => 'case.item.disabled', 'item' => 'k']]], where: 'x');
		$this->addToAssertionCount(1);

		$refusals = [
			'not a list' => 'not-a-list',
			'not an object' => ['sentry'],
			'neither part' => [['id' => 'bare']],
			'unknown event' => [['on' => ['event' => 'case.item.started']]],
			'on without event' => [['on' => ['item' => 'k']]],
			'item event without item' => [['on' => ['event' => 'case.item.completed']]],
			'invalid if' => [['if' => ['nonsuchoperator' => [1]]]],
		];
		foreach ($refusals as $label => $criteria) {
			try {
				$evaluator->validateCriteria(criteria: $criteria, where: "'k' entry");
				$this->fail("$label must be refused");
			} catch (CaseValidationException $refusal) {
				$this->assertNotSame('', $refusal->getMessage(), $label);
				if ($label === 'unknown event') {
					$this->assertStringContainsString("'case.item.started'", $refusal->getMessage(), 'The unknown event is named.');
				}
			}
		}
	}//end testValidationRefusesAtSaveTime()
}//end class
