<?php

/**
 * The candidate, relation and audit mappers, walked without a database —
 * and the FlowRunMapper override that announces run terminality.
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
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskRelation;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Side mappers and the terminal-run announcement.
 *
 * @covers \OCA\OpenRegister\Db\TaskCandidateMapper
 * @covers \OCA\OpenRegister\Db\TaskRelationMapper
 * @covers \OCA\OpenRegister\Db\TaskAuditMapper
 * @covers \OCA\OpenRegister\Db\TaskCandidate
 * @covers \OCA\OpenRegister\Db\TaskRelation
 * @covers \OCA\OpenRegister\Db\TaskAudit
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 * @covers \OCA\OpenRegister\Event\FlowRunTerminalEvent
 */
class TaskSideMappersTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * replaceForTask deletes the task's rows then inserts the new set; the
	 * read selects by task id.
	 *
	 * @return void
	 */
	public function testCandidateRowsAreReplacedWholesaleAndReadByTask(): void {
		$mapper = new TaskCandidateMapper(db: $this->connectionWith(rows: [['id' => 1, 'task_id' => 7, 'kind' => 'group', 'ref' => 'reviewers']]));

		$mapper->replaceForTask(taskId: 7, candidates: [['kind' => 'user', 'ref' => 'pat'], ['kind' => 'role', 'ref' => 'fiatteur']]);
		$this->assertTrue($this->saw('delete'));
		$this->assertTrue($this->saw('expr.eq', 'task_id'));
		$this->assertSame(2, count(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'insert')));

		$rows = $mapper->findForTask(taskId: 7);
		$this->assertCount(1, $rows);
		$this->assertSame('reviewers', $rows[0]->getRef());
	}//end testCandidateRowsAreReplacedWholesaleAndReadByTask()

	/**
	 * Relations read by task and by object, the latter optionally by role.
	 *
	 * @return void
	 */
	public function testRelationsReadByTaskAndByObject(): void {
		$mapper = new TaskRelationMapper(
			db: $this->connectionWith(rows: [['id' => 1, 'task_id' => 7, 'role' => 'contract', 'object_uuid' => 'obj-9', 'register_id' => 3, 'schema_id' => 4]])
		);

		$byTask = $mapper->findForTask(taskId: 7);
		$this->assertSame('contract', $byTask[0]->getRole());

		$this->calls = [];
		$byObject = $mapper->findByObject(objectUuid: 'obj-9', role: 'contract');
		$this->assertSame(3, $byObject[0]->getRegisterId());
		$this->assertTrue($this->saw('expr.eq', 'object_uuid'));
		$this->assertTrue($this->saw('expr.eq', 'role'));

		$this->calls = [];
		$mapper->findByObject(objectUuid: 'obj-9');
		$this->assertFalse($this->saw('expr.eq', 'role'), 'no role filter when none asked');

		$relation = new TaskRelation();
		$relation->setTaskId(7);
		$relation->setRole('evidence');
		$relation->setObjectUuid('obj-2');
		$this->assertSame(77, $mapper->insert(entity: $relation)->getId());
	}//end testRelationsReadByTaskAndByObject()

	/**
	 * APPEND-ONLY, ENFORCED: insert stamps created, findForTask reads oldest
	 * first, update and delete throw, a foreign entity is refused by name.
	 *
	 * @return void
	 */
	public function testTheAuditAppendsAndRefusesEverythingElse(): void {
		$mapper = new TaskAuditMapper(db: $this->connectionWith(rows: [['id' => 1, 'task_id' => 7, 'action' => 'claim', 'authorized' => 1]]));

		$entry = new TaskAudit();
		$entry->setTaskId(7);
		$entry->setAction('claim');
		$appended = $mapper->insert(entity: $entry);
		$this->assertNotNull($appended->getCreated());
		$this->assertSame(77, $appended->getId());

		$trail = $mapper->findForTask(taskId: 7);
		$this->assertSame('claim', $trail[0]->getAction());
		$this->assertTrue($this->saw('orderBy', 'id'));

		try {
			$mapper->update(entity: $entry);
			$this->fail('update() did not refuse.');
		} catch (LogicException $refused) {
			$this->assertStringContainsString('append-only', $refused->getMessage());
		}

		try {
			$mapper->delete(entity: $entry);
			$this->fail('delete() did not refuse.');
		} catch (LogicException $refused) {
			$this->assertStringContainsString('append-only', $refused->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$mapper->insert(entity: new Task());
	}//end testTheAuditAppendsAndRefusesEverythingElse()

	/**
	 * FlowRunMapper::update() announces a TERMINAL run and stays silent for a
	 * live one; a foreign entity is refused; no dispatcher means no event.
	 *
	 * @return void
	 */
	public function testFlowRunMapperAnnouncesTerminalityFromTheOneChokePoint(): void {
		$announced = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (FlowRunTerminalEvent $event) use (&$announced): void {
				$announced[] = $event->getRunUuid() . ':' . $event->getStatus();
			}
		);
		$mapper = new FlowRunMapper(db: $this->connectionWith(), dispatcher: $dispatcher);

		$done = new FlowRun();
		$done->setId(1);
		$done->setUuid('run-9');
		$done->setStatus(FlowRun::STATUS_STOPPED);
		$mapper->update(entity: $done);

		$live = new FlowRun();
		$live->setId(2);
		$live->setUuid('run-10');
		$live->setStatus(FlowRun::STATUS_SUSPENDED);
		$mapper->update(entity: $live);

		$this->assertSame(['run-9:stopped'], $announced);

		$silent = new FlowRunMapper(db: $this->connectionWith());
		$this->assertSame('run-9', $silent->update(entity: $done)->getUuid());

		$this->expectException(InvalidArgumentException::class);
		$mapper->update(entity: new Task());
	}//end testFlowRunMapperAnnouncesTerminalityFromTheOneChokePoint()
}//end class
