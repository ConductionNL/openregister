<?php

/**
 * ExampleSetTasks validates a fragment task row, writes it once per key through
 * the task service, and reports and reverts what it wrote.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks;
use OCA\OpenRegister\Service\Task\TaskImport;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetExternalTask
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskChecklist
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetDateValue
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskClaim
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 * @uses   \OCA\OpenRegister\Service\Task\TaskState
 * @uses   \OCA\OpenRegister\Db\Task
 */
class ExampleSetTasksBehaviourTest extends TestCase {

	/**
	 * Task mapper behind the container.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $mapper;

	/**
	 * Task service behind the container.
	 *
	 * @var TaskServiceInterface&MockObject
	 */
	private TaskServiceInterface&MockObject $service;

	/**
	 * Class under test.
	 *
	 * @var ExampleSetTasks
	 */
	private ExampleSetTasks $tasks;

	/**
	 * Wire the container to the mapper and service mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper  = $this->createMock(TaskMapper::class);
		$this->service = $this->createMock(TaskServiceInterface::class);
		$container     = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[TaskMapper::class, $this->mapper],
				[TaskServiceInterface::class, $this->service],
			]
		);
		$this->tasks = new ExampleSetTasks($container);
	}

	/**
	 * A task with the given uuid and terminal flag.
	 *
	 * @param string $uuid     The uuid.
	 * @param bool   $terminal Whether the state is terminal.
	 *
	 * @return Task
	 */
	private function task(string $uuid, bool $terminal=false): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setIsTerminal($terminal);
		return $task;
	}

	/**
	 * Part and noun name the rows this engine writes.
	 *
	 * @return void
	 */
	public function testPartAndNoun(): void {
		$this->assertSame(['tasks', 'task'], [$this->tasks->part(), $this->tasks->noun()]);
	}

	/**
	 * A minimal well-formed task is accepted.
	 *
	 * @return void
	 */
	public function testMinimalTaskIsAccepted(): void {
		$this->assertSame('', $this->tasks->refusal(['title' => 'Check', 'objectUuid' => 'o-1', 'assignee' => 'alice'], ['alice']));
	}

	/**
	 * An unknown field is refused and named.
	 *
	 * @return void
	 */
	public function testUnknownFieldIsRefused(): void {
		$this->assertSame(
			'field "colour", "size" is not a task field a set may write',
			$this->tasks->refusal(['title' => 'x', 'objectUuid' => 'o', 'colour' => 1, 'size' => 2], [])
		);
	}

	/**
	 * Title and objectUuid are both required and must be non-blank strings.
	 *
	 * @return void
	 */
	public function testTitleAndObjectAreNeeded(): void {
		$this->assertSame('a task needs a title', $this->tasks->refusal(['objectUuid' => 'o'], []));
		$this->assertSame('a task needs a title', $this->tasks->refusal(['title' => ' ', 'objectUuid' => 'o'], []));
		$this->assertSame('a task needs the object it is on (objectUuid)', $this->tasks->refusal(['title' => 't'], []));
		$this->assertSame('a task needs the object it is on (objectUuid)', $this->tasks->refusal(['title' => 't', 'objectUuid' => 5], []));
	}

	/**
	 * Every person field must be an account the set knows.
	 *
	 * @return void
	 */
	public function testUnknownPeopleAreRefused(): void {
		foreach (['assignee', 'completedBy', 'requester', 'claimedBy'] as $field) {
			$this->assertSame(
				$field.' "mallory" is not an account the set creates or adopts',
				$this->tasks->refusal(['title' => 't', 'objectUuid' => 'o', $field => 'mallory'], ['alice']),
				$field
			);
		}
	}

	/**
	 * An external task's assignee is a party, not an account, so it is not checked as one.
	 *
	 * @return void
	 */
	public function testExternalAssigneeIsNotCheckedAsAccount(): void {
		$this->assertSame(
			'',
			$this->tasks->refusal(['title' => 't', 'objectUuid' => 'o', 'performerType' => 'external', 'assignee' => 'party:abc'], [])
		);
		$this->assertSame(
			'requester "bob" is not an account the set creates or adopts',
			$this->tasks->refusal(['title' => 't', 'objectUuid' => 'o', 'performerType' => 'external', 'assignee' => 'party:abc', 'requester' => 'bob'], [])
		);
	}

	/**
	 * External-task rules are applied after the people check.
	 *
	 * @return void
	 */
	public function testExternalTaskRuleIsApplied(): void {
		$this->assertSame(
			'uploadRules apply to an external task only (performerType: external)',
			$this->tasks->refusal(['title' => 't', 'objectUuid' => 'o', 'uploadRules' => []], [])
		);
	}

	/**
	 * An unknown state is refused; a known one passes.
	 *
	 * @return void
	 */
	public function testStateIsNormalised(): void {
		$this->assertNotSame('', $this->tasks->refusal(['title' => 't', 'objectUuid' => 'o', 'state' => 'bogus-state'], []));
		$this->assertSame('', $this->tasks->refusal(['title' => 't', 'objectUuid' => 'o', 'state' => 'available'], []));
	}

	/**
	 * Date and checklist refusals come through, in that order.
	 *
	 * @return void
	 */
	public function testDateThenChecklistRefusal(): void {
		$base = ['title' => 't', 'objectUuid' => 'o'];
		$this->assertSame('dueAt "not-a-date" is not a date', $this->tasks->refusal($base + ['dueAt' => 'not-a-date', 'checklist' => 'no'], []));
		$this->assertSame('checklist must be a list of items', $this->tasks->refusal($base + ['checklist' => 'no'], []));
	}

	/**
	 * A key already held by a task is not written again; the holder is reported.
	 *
	 * @return void
	 */
	public function testHeldKeyIsNotWrittenAgain(): void {
		$this->mapper->expects($this->once())->method('findByKey')->with('example-set:app/k1')->willReturn([$this->task('held-uuid')]);
		$this->service->expects($this->never())->method('importTask');

		$this->assertSame(
			['error' => '', 'entry' => ['uuid' => 'held-uuid']],
			$this->tasks->write(new ExampleSetRow('app', 'k1', ['title' => 't', 'objectUuid' => 'o']))
		);
	}

	/**
	 * A fresh row imports through the service with the derived key, app and actor.
	 *
	 * @return void
	 */
	public function testFreshRowIsImported(): void {
		$this->mapper->method('findByKey')->willReturn([]);
		$this->mapper->expects($this->never())->method('update');
		$this->service->expects($this->once())->method('importTask')
			->with(
				$this->callback(static fn (TaskImport $i) => $i->data === ['title' => 't', 'objectUuid' => 'o', 'assignee' => 'alice', 'key' => 'custom-key', 'appId' => 'app']
					&& $i->actor === 'alice' && $i->mayReadBlocker === null && $i->createdAt?->format('Y-m-d') === '2020-01-01')
			)
			->willReturn($this->task('new-uuid'));

		$this->assertSame(
			['error' => '', 'entry' => ['uuid' => 'new-uuid']],
			$this->tasks->write(new ExampleSetRow('app', 'k1', ['title' => 't', 'objectUuid' => 'o', 'assignee' => 'alice', 'taskKey' => 'custom-key', 'createdAt' => '2020-01-01']))
		);
	}

	/**
	 * A lookup failure on the key counts as not held, and an unassigned task is written as the app.
	 *
	 * @return void
	 */
	public function testKeyLookupFailureStillImportsAsApp(): void {
		$this->mapper->method('findByKey')->willThrowException(new RuntimeException('db'));
		$this->service->expects($this->once())->method('importTask')
			->with($this->callback(static fn (TaskImport $i) => $i->actor === 'app' && $i->mayReadBlocker === null && $i->createdAt === null))
			->willReturn($this->task('u'));

		$this->assertSame('u', $this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o']))['entry']['uuid']);
	}

	/**
	 * An external task is written as the app, with its upload rules as metadata.
	 *
	 * @return void
	 */
	public function testExternalTaskIsImportedAsAppWithUploadMetadata(): void {
		$this->mapper->method('findByKey')->willReturn([]);
		$this->service->expects($this->once())->method('importTask')
			->with(
				$this->callback(
					static fn (TaskImport $i) => $i->data['metadata'] === ['upload' => ['required' => true]] && isset($i->data['uploadRules']) === false
						&& $i->actor === 'app' && $i->mayReadBlocker === null && $i->createdAt === null
				)
			)
			->willReturn($this->task('u'));

		$this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o', 'performerType' => 'external', 'assignee' => 'party:x', 'uploadRules' => ['required' => true]]));
	}

	/**
	 * An import failure is reported as the row's error, with no entry.
	 *
	 * @return void
	 */
	public function testImportFailureIsReported(): void {
		$this->mapper->method('findByKey')->willReturn([]);
		$this->service->method('importTask')->willThrowException(new RuntimeException('refused'));

		$this->assertSame(
			['error' => 'refused', 'entry' => null],
			$this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o']))
		);
	}

	/**
	 * A terminal task with completedAt gets its completion moment and actor written back.
	 *
	 * @return void
	 */
	public function testTerminalTaskCompletionIsWrittenBack(): void {
		$task = $this->task('u', true);
		$this->mapper->method('findByKey')->willReturn([]);
		$this->service->method('importTask')->willReturn($task);
		$this->mapper->expects($this->once())->method('update')->with($task)->willReturn($task);

		$this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o', 'assignee' => 'alice', 'completedAt' => '2020-01-02']));

		$this->assertSame('2020-01-02', $task->getCompletedAt()->format('Y-m-d'));
		$this->assertSame('alice', $task->getCompletedBy());
	}

	/**
	 * A non-terminal task is not marked complete even with completedAt.
	 *
	 * @return void
	 */
	public function testOpenTaskIsNotCompleted(): void {
		$this->mapper->method('findByKey')->willReturn([]);
		$this->service->method('importTask')->willReturn($this->task('u', false));
		$this->mapper->expects($this->never())->method('update');

		$this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o', 'completedAt' => '2020-01-02']));
	}

	/**
	 * A task is lost when the mapper can no longer find it.
	 *
	 * @return void
	 */
	public function testLost(): void {
		$this->mapper->method('findByUuid')->willReturnCallback(
			fn (string $uuid) => $uuid === 'here' ? $this->task('here') : throw new DoesNotExistException('gone')
		);
		$this->assertFalse($this->tasks->lost(['uuid' => 'here']));
		$this->assertTrue($this->tasks->lost(['uuid' => 'gone']));
	}

	/**
	 * Revert deletes the task it finds.
	 *
	 * @return void
	 */
	public function testRevertDeletes(): void {
		$task = $this->task('u');
		$this->mapper->method('findByUuid')->with('u')->willReturn($task);
		$this->mapper->expects($this->once())->method('delete')->with($task)->willReturn($task);
		$this->assertSame('removed', $this->tasks->revert(['uuid' => 'u']));
	}

	/**
	 * Reverting a task that is already gone counts as removed, without a delete.
	 *
	 * @return void
	 */
	public function testRevertOfMissingTaskIsRemoved(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->mapper->expects($this->never())->method('delete');
		$this->assertSame('removed', $this->tasks->revert(['uuid' => 'u']));
	}

	/**
	 * A failed delete is reported as failed.
	 *
	 * @return void
	 */
	public function testRevertDeleteFailureIsFailed(): void {
		$this->mapper->method('findByUuid')->willReturn($this->task('u'));
		$this->mapper->method('delete')->willThrowException(new RuntimeException('locked'));
		$this->assertSame('failed', $this->tasks->revert(['uuid' => 'u']));
	}
}
