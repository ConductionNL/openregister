<?php

/**
 * The case mappers' builder code paths, over a fluent query-builder double:
 * the conditional update guards on the state read, the stuck-where reads
 * filter and page in the datastore, the audit mapper appends and refuses
 * update and delete.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use LogicException;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Db\Task;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Mapper coverage.
 *
 * @covers \OCA\OpenRegister\Db\CaseItemMapper
 * @covers \OCA\OpenRegister\Db\CaseItemAuditMapper
 * @covers \OCA\OpenRegister\Db\CaseItem
 * @covers \OCA\OpenRegister\Db\CaseItemAudit
 */
class CaseItemMapperQueriesTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * A stored plan-item row as the database returns it.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(): array {
		return [
			'id' => 7,
			'uuid' => 'c-7',
			'item_key' => 'intake',
			'object_uuid' => 'obj-1',
			'origin' => CaseItem::ORIGIN_DEFINED,
			'plan_item_type' => CaseItem::TYPE_STAGE,
			'state' => CaseItem::STATE_ACTIVE,
			'is_terminal' => 0,
			'required' => 1,
			'discretionary' => 0,
			'realisation_count' => 1,
			'entry_criteria' => '[{"id":"s1"}]',
			'created' => '2026-09-01 10:00:00',
		];
	}//end row()

	/**
	 * findByUuid maps one row, findByObject/findByRealisation map lists, and none throws.
	 *
	 * @return void
	 */
	public function testReadsMapRowsAndThrowOnNone(): void {
		$mapper = new CaseItemMapper(db: $this->connectionWith(rows: [$this->row()]));
		$item = $mapper->findByUuid(uuid: 'c-7');
		$this->assertSame('intake', $item->getItemKey());
		$this->assertSame([['id' => 's1']], $item->getEntryCriteria());
		$this->assertFalse($item->getIsTerminal());
		$this->assertTrue($this->saw('expr.eq', 'uuid'));

		$this->assertCount(1, $mapper->findByObject(objectUuid: 'obj-1'));
		$this->assertTrue($this->saw('expr.eq', 'object_uuid'));
		$this->assertTrue($this->saw('orderBy', 'position'));
		$this->assertCount(1, $mapper->findByRealisation(realisationUuid: 'task-1'));
		$this->assertTrue($this->saw('expr.eq', 'realisation_uuid'));

		$this->expectException(DoesNotExistException::class);
		(new CaseItemMapper(db: $this->connectionWith(rows: [])))->findByUuid(uuid: 'ghost');
	}//end testReadsMapRowsAndThrowOnNone()

	/**
	 * updateIfState writes only the changed fields under the state guard and
	 * reports whether the row was hit; an unsaved row is refused.
	 *
	 * @return void
	 */
	public function testUpdateIfStateGuardsOnTheStateRead(): void {
		$item = new CaseItem();
		$item->setId(7);
		$item->setUuid('c-7');
		$item->resetUpdatedFields();
		$item->setState(CaseItem::STATE_COMPLETED);
		$item->setIsTerminal(true);

		$mapper = new CaseItemMapper(db: $this->connectionWith(affectedRows: 1));
		$this->assertTrue($mapper->updateIfState(item: $item, expectedState: CaseItem::STATE_ACTIVE));
		$this->assertTrue($this->saw('set', 'state'));
		$this->assertTrue($this->saw('set', 'is_terminal'));
		$this->assertTrue($this->saw('set', 'updated'));
		$this->assertFalse($this->saw('set', 'name'), 'an untouched field is not written');
		$this->assertTrue($this->saw('expr.eq', 'state'));
		$this->assertTrue($this->saw('expr.eq', 'id'));

		$this->assertFalse((new CaseItemMapper(db: $this->connectionWith(affectedRows: 0)))->updateIfState(item: $item, expectedState: CaseItem::STATE_ACTIVE));

		$this->expectException(InvalidArgumentException::class);
		(new CaseItemMapper(db: $this->connectionWith()))->updateIfState(item: new CaseItem(), expectedState: 'x');
	}//end testUpdateIfStateGuardsOnTheStateRead()

	/**
	 * insert stamps uuid and created; update stamps updated; both refuse a foreign entity.
	 *
	 * @return void
	 */
	public function testInsertAndUpdateStampAndGuardTheEntityType(): void {
		$mapper = new CaseItemMapper(db: $this->connectionWith());
		$item = new CaseItem();
		$item->setObjectUuid('obj-1');
		$inserted = $mapper->insert($item);
		$this->assertNotEmpty($inserted->getUuid());
		$this->assertNotNull($inserted->getCreated());

		$item->setId(7);
		$this->assertNotNull($mapper->update($item)->getUpdated());

		try {
			$mapper->insert(new Task());
			$this->fail('foreign entity');
		} catch (InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(InvalidArgumentException::class);
		$mapper->update(new Task());
	}//end testInsertAndUpdateStampAndGuardTheEntityType()

	/**
	 * The stuck-where page and its total share one predicate, page in the
	 * datastore, and skip absent filters; counts read one scalar; delete is by object.
	 *
	 * @return void
	 */
	public function testStuckWhereReadsCountsAndDelete(): void {
		$mapper = new CaseItemMapper(db: $this->connectionWith(affectedRows: 3, rows: [$this->row()]));
		$page = $mapper->findByTypeAndState(type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE, limit: 10, offset: 20);
		$this->assertCount(1, $page);
		$this->assertTrue($this->saw('expr.eq', 'plan_item_type'));
		$this->assertTrue($this->saw('expr.eq', 'state'));
		$this->assertTrue($this->saw('setMaxResults', 10));
		$this->assertTrue($this->saw('setFirstResult', 20));

		$this->assertIsInt($mapper->countByTypeAndState(type: null, state: ' '));
		$this->assertIsInt($mapper->countOpenByObject(objectUuid: 'obj-1'));
		$this->assertTrue($this->saw('expr.eq', 'is_terminal'));
		$this->assertSame(3, $mapper->deleteByObject(objectUuid: 'obj-1'));
		$this->assertTrue($this->saw('delete', 'openregister_case_items'));
	}//end testStuckWhereReadsCountsAndDelete()

	/**
	 * The audit mapper appends with a stamp, reads by item and by items, and
	 * refuses update and delete.
	 *
	 * @return void
	 */
	public function testTheAuditIsAppendOnly(): void {
		$auditRow = ['id' => 1, 'case_item_id' => 7, 'from_state' => 'active', 'to_state' => 'completed', 'cause' => 'user', 'authorized' => 1, 'created' => '2026-09-01 10:00:00'];
		$mapper = new CaseItemAuditMapper(db: $this->connectionWith(rows: [$auditRow]));

		$entry = new CaseItemAudit();
		$entry->setCaseItemId(7);
		$entry->setCause(CaseItemAudit::CAUSE_USER);
		$this->assertNotNull($mapper->insert($entry)->getCreated());

		$this->assertCount(1, $mapper->findForItem(caseItemId: 7));
		$this->assertTrue($this->saw('expr.eq', 'case_item_id'));
		$this->assertSame([], $mapper->findForItems(caseItemIds: []));
		$this->assertCount(1, $mapper->findForItems(caseItemIds: [7, 8]));
		$this->assertTrue($this->saw('expr.in', 'case_item_id'));
		$this->assertSame('completed', $mapper->findForItem(caseItemId: 7)[0]->jsonSerialize()['toState']);

		try {
			$mapper->insert(new Task());
			$this->fail('foreign entity');
		} catch (InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		try {
			$mapper->update($entry);
			$this->fail('append-only');
		} catch (LogicException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(LogicException::class);
		$mapper->delete($entry);
	}//end testTheAuditIsAppendOnly()
}//end class
