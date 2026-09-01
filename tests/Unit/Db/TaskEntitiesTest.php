<?php

/**
 * The four task entities: field typing, hydration and serialisation.
 *
 * Entities are where a column name typo hides until production: a field the
 * mapper never hydrates, a JSON column typed as string, a serialised key
 * that drifts from the API contract. These tests pin the round trip for
 * Task, TaskAudit, TaskCandidate and TaskRelation, and the stored-vs-derived
 * boundary on Task (no overdue anywhere in the stored row).
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

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskCandidate;
use OCA\OpenRegister\Db\TaskRelation;
use PHPUnit\Framework\TestCase;

/**
 * Round trips for the task entities.
 *
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\TaskAudit
 * @covers \OCA\OpenRegister\Db\TaskCandidate
 * @covers \OCA\OpenRegister\Db\TaskRelation
 */
class TaskEntitiesTest extends TestCase {

	/**
	 * Every declared field hydrates and serialises under its API name; the
	 * stored key column is `taskKey` in PHP and `key` on the wire.
	 *
	 * @return void
	 */
	public function testTaskHydratesAndSerialisesEveryField(): void {
		$due = new DateTime('2026-09-04T17:00:00+02:00');
		$task = new Task();
		$task->hydrate(
			[
				'uuid' => 'u-1',
				'taskKey' => 'EXT-7',
				'title' => 'Controleer',
				'state' => Task::STATE_ENABLED,
				'isTerminal' => false,
				'performerType' => Task::PERFORMER_GROUP,
				'candidateGroups' => ['team'],
				'dueAt' => $due,
				'priority' => 'high',
				'objectUuid' => 'obj-1',
				'registerId' => 1,
				'schemaId' => 2,
				'checklist' => [['id' => 'c1', 'label' => 'Een', 'description' => null, 'checked' => false]],
				'metadata' => ['legacy' => true],
				'id' => 99,
				'notAField' => 'ignored',
			]
		);

		$row = $task->jsonSerialize();

		$this->assertSame('u-1', $row['uuid']);
		$this->assertSame('EXT-7', $row['key']);
		$this->assertSame(Task::STATE_ENABLED, $row['state']);
		$this->assertFalse($row['isTerminal']);
		$this->assertSame(['team'], $row['candidateGroups']);
		$this->assertSame($due->format('c'), $row['dueAt']);
		$this->assertSame(1, $row['registerId']);
		$this->assertSame('c1', $row['checklist'][0]['id']);
		$this->assertSame(['legacy' => true], $row['metadata']);
		// hydrate() never lets a payload set the row id.
		$this->assertNull($row['id']);
		$this->assertArrayNotHasKey('notAField', $row);
		// Nothing stored spells overdue.
		$this->assertArrayNotHasKey('overdue', $row);
	}//end testTaskHydratesAndSerialisesEveryField()

	/**
	 * Terminality reads the state set, and the entity's vocabularies are
	 * the six CMMN states and the open performer list.
	 *
	 * @return void
	 */
	public function testTaskTerminalityAndVocabularies(): void {
		$task = new Task();
		foreach (Task::STATES as $state) {
			$task->setState($state);
			$this->assertSame(in_array($state, Task::TERMINAL_STATES, true), $task->isInTerminalState());
		}

		$this->assertCount(6, Task::STATES);
		$this->assertSame(['user', 'group', 'agent', 'worker', 'external'], Task::PERFORMER_TYPES);
		$this->assertSame(['low', 'normal', 'high', 'urgent'], Task::PRIORITIES);
		$this->assertCount(5, Task::ROUTING_STRATEGIES);
	}//end testTaskTerminalityAndVocabularies()

	/**
	 * A JSON-typed column round-trips as an array, a datetime as a DateTime.
	 *
	 * @return void
	 */
	public function testTaskFieldTypes(): void {
		$task = new Task();
		$types = $task->getFieldTypes();

		$this->assertSame('json', $types['candidateUsers']);
		$this->assertSame('json', $types['checklist']);
		$this->assertSame('json', $types['templateSnapshot']);
		$this->assertSame('datetime', $types['dueAt']);
		$this->assertSame('datetime', $types['expiresAt']);
		$this->assertSame('boolean', $types['isTerminal']);
		$this->assertSame('integer', $types['definitionVersion']);
		$this->assertArrayNotHasKey('overdue', $types);
	}//end testTaskFieldTypes()

	/**
	 * An audit entry serialises actor, performer type, delegation and the
	 * authorized flag — the fields that make a denial distinguishable from a
	 * success and a delegate from the original performer.
	 *
	 * @return void
	 */
	public function testTaskAuditSerialises(): void {
		$entry = new TaskAudit();
		$entry->setTaskId(7);
		$entry->setAction('complete');
		$entry->setStateAfter(Task::STATE_COMPLETED);
		$entry->setActor('dora');
		$entry->setPerformerType(Task::PERFORMER_USER);
		$entry->setOnBehalfOf('alice');
		$entry->setMandate('Volmacht');
		$entry->setReason('ok');
		$entry->setAuthorized(false);
		$entry->setCreated(new DateTime('2026-09-01T10:00:00+00:00'));

		$row = $entry->jsonSerialize();

		$this->assertSame(7, $row['taskId']);
		$this->assertSame('complete', $row['action']);
		$this->assertSame('dora', $row['actor']);
		$this->assertSame('alice', $row['onBehalfOf']);
		$this->assertSame('Volmacht', $row['mandate']);
		$this->assertFalse($row['authorized']);
		$this->assertSame('2026-09-01T10:00:00+00:00', $row['created']);
		$this->assertSame('boolean', $entry->getFieldTypes()['authorized']);
	}//end testTaskAuditSerialises()

	/**
	 * A candidate index row carries kind and ref; the kinds are the three
	 * the inbox EXISTS matches.
	 *
	 * @return void
	 */
	public function testTaskCandidateSerialises(): void {
		$row = new TaskCandidate();
		$row->setTaskId(7);
		$row->setKind(TaskCandidate::KIND_ROLE);
		$row->setRef('fiatteur');

		$this->assertSame(['id' => null, 'taskId' => 7, 'kind' => 'role', 'ref' => 'fiatteur'], $row->jsonSerialize());
		$this->assertSame('user', TaskCandidate::KIND_USER);
		$this->assertSame('group', TaskCandidate::KIND_GROUP);
	}//end testTaskCandidateSerialises()

	/**
	 * A relation carries a free-text role and the related object's anchor.
	 *
	 * @return void
	 */
	public function testTaskRelationSerialises(): void {
		$row = new TaskRelation();
		$row->setTaskId(7);
		$row->setRole('contract');
		$row->setObjectUuid('obj-9');
		$row->setRegisterId(3);
		$row->setSchemaId(4);

		$this->assertSame(
			['id' => null, 'taskId' => 7, 'role' => 'contract', 'objectUuid' => 'obj-9', 'registerId' => 3, 'schemaId' => 4],
			$row->jsonSerialize()
		);
	}//end testTaskRelationSerialises()
}//end class
