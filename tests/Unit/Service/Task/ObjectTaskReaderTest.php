<?php

/**
 * ObjectTaskReader: a consuming app reads the tasks on its object, with who
 * decided each, on whose behalf and under which mandate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-task-reader/specs/flow-tasks/spec.md#requirement-a-consuming-app-reads-the-tasks-on-its-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectTaskReaderInterface;
use OCA\OpenRegister\Contract\ObjectTaskRecord;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\ObjectTaskReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The published read: a delegated decision, a direct one, an open step, and no tasks.
 *
 * @covers \OCA\OpenRegister\Service\Task\ObjectTaskReader
 * @covers \OCA\OpenRegister\Contract\ObjectTaskRecord
 * @uses \OCA\OpenRegister\Db\Task
 */
class ObjectTaskReaderTest extends TestCase {

	private TaskMapper&MockObject $mapper;

	private ObjectTaskReader $reader;

	/**
	 * A reader over a mocked task store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(TaskMapper::class);
		$this->reader = new ObjectTaskReader(tasks: $this->mapper);
	}//end setUp()

	/**
	 * One task row.
	 *
	 * @param array<string,mixed> $fields Setter name (without "set") => value.
	 *
	 * @return Task
	 */
	private static function task(array $fields): Task {
		$task = new Task();
		foreach ($fields as $name => $value) {
			$task->{'set'.$name}($value);
		}

		return $task;
	}//end task()

	/**
	 * A delegated and a direct decision keep their decider, delegation and mandate.
	 *
	 * @return void
	 */
	public function testDecidedStepsCarryTheirDeciderAndDelegation(): void {
		$this->mapper->expects($this->once())
			->method('findByObjectUuid')
			->with('po-040')
			->willReturn(
				[
					self::task(fields: ['Uuid' => 't-1', 'Title' => 'Team lead approval', 'WorkflowStepId' => 'teamleider', 'State' => 'completed', 'IsTerminal' => true, 'Outcome' => 'approved', 'Assignee' => 'karin', 'CompletedBy' => 'karin', 'OnBehalfOf' => 'jan', 'Mandate' => 'Absence of the team lead, mandate 2026-07', 'CompletedAt' => new DateTime('2026-11-20T10:15:00+00:00'), 'Created' => new DateTime('2026-11-19T09:00:00+00:00')]),
					self::task(fields: ['Uuid' => 't-2', 'Title' => 'Facility manager approval', 'WorkflowStepId' => 'facility_manager', 'State' => 'completed', 'IsTerminal' => true, 'Outcome' => 'approved', 'Assignee' => 'pieter', 'CompletedBy' => 'pieter', 'CompletedAt' => new DateTime('2026-11-21T08:00:00+00:00')]),
				]
			);

		$records = $this->reader->tasksOnObject(objectUuid: 'po-040');

		$this->assertCount(2, $records);
		$this->assertContainsOnlyInstancesOf(ObjectTaskRecord::class, $records);
		$this->assertSame('karin', $records[0]->decidedBy);
		$this->assertSame('jan', $records[0]->onBehalfOf);
		$this->assertSame('Absence of the team lead, mandate 2026-07', $records[0]->mandate);
		$this->assertSame('approved', $records[0]->outcome);
		$this->assertSame('teamleider', $records[0]->workflowStepId);
		$this->assertTrue($records[0]->isTerminal);
		$this->assertInstanceOf(DateTimeImmutable::class, $records[0]->decidedAt);
		$this->assertSame('2026-11-20T10:15:00+00:00', $records[0]->decidedAt->format(DATE_ATOM));
		$this->assertSame('2026-11-19T09:00:00+00:00', $records[0]->createdAt?->format(DATE_ATOM));

		$this->assertSame('pieter', $records[1]->decidedBy);
		$this->assertNull($records[1]->onBehalfOf);
		$this->assertNull($records[1]->mandate);
	}//end testDecidedStepsCarryTheirDeciderAndDelegation()

	/**
	 * An open step has no decider or decision moment yet.
	 *
	 * @return void
	 */
	public function testAnOpenStepHasNoDecisionYet(): void {
		$this->mapper->method('findByObjectUuid')->willReturn(
			[self::task(fields: ['Uuid' => 't-3', 'Title' => 'Procurement manager approval', 'State' => 'active', 'IsTerminal' => false, 'Assignee' => 'lars'])]
		);

		$record = $this->reader->tasksOnObject(objectUuid: 'po-041')[0];

		$this->assertFalse($record->isTerminal);
		$this->assertSame('active', $record->state);
		$this->assertSame('lars', $record->assignee);
		$this->assertNull($record->decidedBy);
		$this->assertNull($record->decidedAt);
		$this->assertNull($record->outcome);
	}//end testAnOpenStepHasNoDecisionYet()

	/**
	 * An object without tasks, or a blank uuid, answers an empty list; a blank uuid asks nothing.
	 *
	 * @return void
	 */
	public function testNoTasksIsAnEmptyList(): void {
		$this->mapper->expects($this->once())->method('findByObjectUuid')->with('po-042')->willReturn([]);

		$this->assertSame([], $this->reader->tasksOnObject(objectUuid: 'po-042'));
		$this->assertSame([], $this->reader->tasksOnObject(objectUuid: '  '));
	}//end testNoTasksIsAnEmptyList()

	/**
	 * The reader is the published contract.
	 *
	 * @return void
	 */
	public function testItIsThePublishedContract(): void {
		$this->assertInstanceOf(ObjectTaskReaderInterface::class, $this->reader);
	}//end testItIsThePublishedContract()
}//end class
