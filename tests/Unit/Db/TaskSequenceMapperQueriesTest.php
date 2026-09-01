<?php

/**
 * The sequence mapper's access paths: by uuid, the single running sequence
 * per anchor, and an anchor's history newest-first
 * (flow-approval-consolidation task 1.2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\TaskSequenceMapper
 * @covers \OCA\OpenRegister\Db\TaskSequence
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @uses \OCA\OpenRegister\Db\Task
 */
class TaskSequenceMapperQueriesTest extends TestCase {
	use FluentQueryBuilderTrait;

	private function row(string $uuid, string $status): array {
		return [
			'id' => 1,
			'uuid' => $uuid,
			'template_id' => 'tpl-1',
			'status' => $status,
			'anchor_object_uuid' => 'obj-1',
			'position_cursor' => 1,
		];
	}//end row()

	public function testFindRunningFiltersOnStatusAnchorAndTemplate(): void {
		$mapper = new TaskSequenceMapper(db: $this->connectionWith(rows: [$this->row('seq-1', 'running')]));

		$found = $mapper->findRunning(anchorObjectUuid: 'obj-1', templateId: 'tpl-1');

		self::assertSame('seq-1', $found->getUuid());
		$predicates = array_filter($this->calls, static fn (array $call): bool => $call[0] === 'expr.eq');
		$columns = array_map(static fn (array $call): mixed => $call[1], $predicates);
		self::assertContains('anchor_object_uuid', $columns);
		self::assertContains('template_id', $columns);
		self::assertContains('status', $columns, 'only the RUNNING sequence answers the gate');
	}//end testFindRunningFiltersOnStatusAnchorAndTemplate()

	public function testFindRunningWithNoRowIsNull(): void {
		$mapper = new TaskSequenceMapper(db: $this->connectionWith(rows: []));

		self::assertNull($mapper->findRunning(anchorObjectUuid: 'obj-1', templateId: 'tpl-1'));
	}//end testFindRunningWithNoRowIsNull()

	public function testFindNewestForAnchorOrdersByOpenTimeDescending(): void {
		$mapper = new TaskSequenceMapper(db: $this->connectionWith(rows: [$this->row('seq-2', 'rejected'), $this->row('seq-1', 'rejected')]));

		$newest = $mapper->findNewestForAnchor(anchorObjectUuid: 'obj-1', templateId: 'tpl-1');

		self::assertSame('seq-2', $newest->getUuid());
		$orderings = array_filter($this->calls, static fn (array $call): bool => in_array($call[0], ['orderBy', 'addOrderBy'], true));
		self::assertNotSame([], $orderings, 'history must be explicitly ordered, never id-lucky');
	}//end testFindNewestForAnchorOrdersByOpenTimeDescending()

	public function testATerminalStatusReadsAsTerminal(): void {
		$sequence = new TaskSequence();
		$sequence->setStatus(TaskSequence::STATUS_REJECTED);

		self::assertTrue($sequence->isTerminal());
		$sequence->setStatus(TaskSequence::STATUS_RUNNING);
		self::assertFalse($sequence->isTerminal());
		self::assertArrayHasKey('positionCursor', $sequence->jsonSerialize());
	}//end testATerminalStatusReadsAsTerminal()

	public function testFindBySequenceReadsInOrdinalOrder(): void {
		$mapper = new TaskMapper(db: $this->connectionWith(rows: []));

		$mapper->findBySequence(sequenceUuid: 'seq-1');

		$ordered = array_filter($this->calls, static fn (array $call): bool => $call[0] === 'orderBy' && $call[1] === 'sequence_position');
		self::assertNotSame([], $ordered, 'ordinal order is the only order a sequence has');
	}//end testFindBySequenceReadsInOrdinalOrder()
}//end class
