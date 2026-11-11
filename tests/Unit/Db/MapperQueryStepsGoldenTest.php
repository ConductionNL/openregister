<?php

/**
 * Characterisation of three mapper queries: RecordTemplateMapper::findCandidates() (owned,
 * public or shared templates, narrowed to a register and schema), ObjectReadStateMapper::
 * unreadAmong() and CaseItemMapper::updateIfState(), pinned call for call.
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
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCA\OpenRegister\Db\RecordTemplateMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Golden queries of three mapper methods.
 *
 * @covers \OCA\OpenRegister\Db\RecordTemplateMapper
 * @covers \OCA\OpenRegister\Db\ObjectReadStateMapper
 * @covers \OCA\OpenRegister\Db\CaseItemMapper
 */
final class MapperQueryStepsGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * Template candidates: owned, public or shared, newest first, narrowed when a scope is given.
	 *
	 * @return void
	 */
	public function testTemplateCandidates(): void {
		$mapper = new RecordTemplateMapper($this->recordingDb());

		$mapper->findCandidates('anna');
		$mapper->findCandidates('anna', ListQuery::unbounded(filters: ['register' => 'reg', 'schema' => 'sch']));
		$mapper->findCandidates('anna', ListQuery::unbounded(filters: ['register' => '', 'schema' => 'sch']));

		$reach = 'select(*) from(openregister_record_templates) where(orX(eq(owner, :p%d), eq(is_public, :p%d), isNotNull(shared_with))) orderBy(created, DESC)';
		$this->assertSame(sprintf($reach, 0, 1) . ' executeQuery()', $this->sql(0));
		$this->assertSame(sprintf($reach, 2, 3) . ' andWhere(eq(register, :p4)) andWhere(eq(schema, :p5)) executeQuery()', $this->sql(1));
		$this->assertSame(sprintf($reach, 6, 7) . ' andWhere(eq(schema, :p8)) executeQuery()', $this->sql(2));
		$this->assertSame(
			[
				['anna', IQueryBuilder::PARAM_STR], [true, IQueryBuilder::PARAM_BOOL],
				['anna', IQueryBuilder::PARAM_STR], [true, IQueryBuilder::PARAM_BOOL], ['reg', IQueryBuilder::PARAM_STR], ['sch', IQueryBuilder::PARAM_STR],
				['anna', IQueryBuilder::PARAM_STR], [true, IQueryBuilder::PARAM_BOOL], ['sch', IQueryBuilder::PARAM_STR],
			],
			array_values($this->params)
		);
	}//end testTemplateCandidates()

	/**
	 * Unread objects: the asked uuids without a read row for the user, in the asked order.
	 *
	 * @return void
	 */
	public function testUnreadAmong(): void {
		$this->results[] = [['object_uuid' => 'b'], ['object_uuid' => null], ['object_uuid' => 'd']];
		$mapper = new ObjectReadStateMapper($this->recordingDb());

		$this->assertSame(['a', 'c'], $mapper->unreadAmong('anna', ['a', 'b', 'c', 'd']));
		$this->assertSame(
			'select(object_uuid) from(openregister_object_read_state) where(eq(user_id, :p0)) andWhere(in(object_uuid, :p1)) executeQuery()',
			$this->sql()
		);
		$this->assertSame([['anna', IQueryBuilder::PARAM_STR], [['a', 'b', 'c', 'd'], IQueryBuilder::PARAM_STR_ARRAY]], array_values($this->params));
	}//end testUnreadAmong()

	/**
	 * A guarded state update writes the changed fields, then matches the id and the expected state.
	 *
	 * @return void
	 */
	public function testUpdateIfState(): void {
		$item = new CaseItem();
		$item->setId(7);
		$item->resetUpdatedFields();
		$item->setState(CaseItem::STATE_COMPLETED);
		$item->setIsTerminal(true);
		$mapper = new CaseItemMapper($this->recordingDb());

		$this->assertTrue($mapper->updateIfState($item, CaseItem::STATE_ACTIVE));
		$this->assertSame(
			'update(openregister_case_items) set(state, :p0) set(is_terminal, :p1) set(updated, :p2) where(eq(id, :p3)) andWhere(eq(state, :p4)) executeStatement()',
			$this->sql()
		);
		$this->assertSame([CaseItem::STATE_COMPLETED, IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertSame([7, IQueryBuilder::PARAM_INT], $this->params[':p3']);
		$this->assertSame([CaseItem::STATE_ACTIVE, IQueryBuilder::PARAM_STR], $this->params[':p4']);

		$this->affected = 0;
		$this->assertFalse($mapper->updateIfState($item, CaseItem::STATE_ACTIVE));
	}//end testUpdateIfState()

	/**
	 * An unsaved item is refused before any query.
	 *
	 * @return void
	 */
	public function testUpdateIfStateRefusesAnUnsavedItem(): void {
		$mapper = new CaseItemMapper($this->recordingDb());

		try {
			$mapper->updateIfState(new CaseItem(), 'x');
			$this->fail('An unsaved item was written.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('A plan item must be persisted before it can be transitioned.', $e->getMessage());
		}

		$this->assertSame([], $this->queries);
	}//end testUpdateIfStateRefusesAnUnsavedItem()
}//end class
