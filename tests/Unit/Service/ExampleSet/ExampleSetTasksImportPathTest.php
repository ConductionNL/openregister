<?php

/**
 * Tests for ExampleSetTasks: the fill-time refusal (run and claim), the import path
 * (actor, completion, waiting run, claim audit) and adopting a task that holds the key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks;
use OCA\OpenRegister\Service\ExampleSet\FilledRow;
use OCA\OpenRegister\Service\Task\TaskImport;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
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
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetRunSlot
 * @uses   \OCA\OpenRegister\Service\ExampleSet\FilledRow
 * @uses   \OCA\OpenRegister\Service\Task\TaskImport
 * @uses   \OCA\OpenRegister\Service\Task\TaskState
 * @uses   \OCA\OpenRegister\Db\Task
 * @uses   \OCA\OpenRegister\Db\TaskAudit
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class ExampleSetTasksImportPathTest extends TestCase {

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
	 * Flow run mapper behind the container.
	 *
	 * @var FlowRunMapperInterface&MockObject
	 */
	private FlowRunMapperInterface&MockObject $runs;

	/**
	 * Task audit mapper behind the container.
	 *
	 * @var TaskAuditMapperInterface&MockObject
	 */
	private TaskAuditMapperInterface&MockObject $audits;

	/**
	 * Class under test.
	 *
	 * @var ExampleSetTasks
	 */
	private ExampleSetTasks $tasks;

	/**
	 * Wire the container to the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper  = $this->createMock(TaskMapper::class);
		$this->service = $this->createMock(TaskServiceInterface::class);
		$this->runs    = $this->createMock(FlowRunMapperInterface::class);
		$this->audits  = $this->createMock(TaskAuditMapperInterface::class);
		$container     = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[TaskMapper::class, $this->mapper],
				[TaskServiceInterface::class, $this->service],
				[FlowRunMapperInterface::class, $this->runs],
				[TaskAuditMapperInterface::class, $this->audits],
			]
		);
		$this->tasks = new ExampleSetTasks($container);
	}

	/**
	 * A task entity.
	 *
	 * @param string $uuid     The uuid.
	 * @param bool   $terminal Whether its state is terminal.
	 *
	 * @return Task
	 */
	private function task(string $uuid, bool $terminal=false): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid($uuid);
		$task->setState('claimed');
		$task->setPerformerType('internal');
		$task->setIsTerminal($terminal);
		return $task;
	}

	/**
	 * Call the protected fill-time refusal.
	 *
	 * @param array<string, mixed> $data    The filled fields.
	 * @param array<int, string>   $users   The set's accounts.
	 * @param array<string, mixed> $context The fill context.
	 *
	 * @return string
	 */
	private function refusalIn(array $data, array $users, array $context=[]): string {
		$method = new ReflectionMethod(ExampleSetTasks::class, 'refusalIn');
		return $method->invoke($this->tasks, new FilledRow(data: $data, users: $users), $context);
	}

	/**
	 * The plain refusal goes first, before the run or the claim are looked at.
	 *
	 * @return void
	 */
	public function testRefusalInAsksThePlainRefusalFirst(): void {
		$this->assertSame(
			'a task needs a title',
			$this->refusalIn(['objectUuid' => 'o', 'runUuid' => 'r'], [])
		);
	}

	/**
	 * A run without its step is refused by the flow runs.
	 *
	 * @return void
	 */
	public function testRefusalInRefusesARunWithoutItsStep(): void {
		$this->assertSame(
			'runUuid and nodeId go together: the run the task waits in, and the step',
			$this->refusalIn(['title' => 't', 'objectUuid' => 'o', 'runUuid' => 'run-1'], [])
		);
	}

	/**
	 * A claim by someone other than the assignee is refused.
	 *
	 * @return void
	 */
	public function testRefusalInRefusesAClaimByAnotherAccount(): void {
		$this->assertSame(
			'claimedBy is the assignee: "bob" is not',
			$this->refusalIn(['title' => 't', 'objectUuid' => 'o', 'assignee' => 'alice', 'claimedBy' => 'bob'], ['alice', 'bob'])
		);
	}

	/**
	 * A claimedAt without claimedBy is refused, and the claim moment is read from claimedAt.
	 *
	 * @return void
	 */
	public function testRefusalInRefusesAClaimMomentWithoutAClaimer(): void {
		$this->assertSame(
			'claimedAt needs claimedBy',
			$this->refusalIn(['title' => 't', 'objectUuid' => 'o', 'claimedAt' => '2020-01-01T10:00:00Z'], [])
		);
	}

	/**
	 * A consistent task with a past claim passes.
	 *
	 * @return void
	 */
	public function testRefusalInAcceptsAConsistentClaim(): void {
		$this->assertSame(
			'',
			$this->refusalIn(['title' => 't', 'objectUuid' => 'o', 'assignee' => 'alice', 'claimedBy' => 'alice', 'claimedAt' => '2020-01-01T10:00:00Z'], ['alice'])
		);
	}

	/**
	 * A task that already holds the key is adopted without importing.
	 *
	 * @return void
	 */
	public function testATaskHoldingTheKeyIsAdopted(): void {
		$this->mapper->expects($this->once())->method('findByKey')->with('example-set:procest/open')->willReturn([$this->task('held'), $this->task('other')]);
		$this->service->expects($this->never())->method('importTask');

		$this->assertSame(
			['error' => '', 'entry' => ['uuid' => 'held']],
			$this->tasks->write(new ExampleSetRow('procest', 'open', ['title' => 't', 'objectUuid' => 'o']))
		);
	}

	/**
	 * A finished task is raised by who finished it, marked completed, put in its run's slot and its claim audited.
	 *
	 * @return void
	 */
	public function testAFinishedClaimedTaskInARunIsWrittenThroughEveryStep(): void {
		$task = $this->task('new-task', true);
		$run  = new FlowRun();
		$run->setContext(['resumeState' => ['other' => ['taskUuid' => 'x']], 'keep' => 1]);

		$this->mapper->method('findByKey')->willReturn([]);
		$this->service->expects($this->once())->method('importTask')
			->with($this->callback(static fn (TaskImport $i): bool => $i->actor === 'carol'
				&& $i->data['key'] === 'example-set:procest/done' && $i->data['appId'] === 'procest'
				&& isset($i->data['completedAt'], $i->data['claimedBy'], $i->data['claimedAt']) === false
				&& $i->data['runUuid'] === 'run-1' && $i->data['nodeId'] === 'review'))
			->willReturn($task);
		$this->mapper->expects($this->once())->method('update')->with($task)->willReturn($task);
		$this->runs->expects($this->once())->method('findByUuid')->with('run-1')->willReturn($run);
		$this->runs->expects($this->once())->method('update')->with($run)->willReturn($run);
		$this->audits->expects($this->once())->method('insert')
			->with($this->callback(static fn (TaskAudit $a): bool => $a->getTaskId() === 7 && $a->getAction() === 'claim'
				&& $a->getActor() === 'alice' && $a->getStateAfter() === 'claimed' && $a->getPerformerType() === 'internal'
				&& $a->getCreated()->format('Y-m-d H:i') === '2020-01-01 10:00'))
			->willReturnArgument(0);

		$result = $this->tasks->write(
			new ExampleSetRow(
				'procest',
				'done',
				[
					'title'       => 't',
					'objectUuid'  => 'o',
					'assignee'    => 'alice',
					'completedBy' => 'carol',
					'completedAt' => '2020-01-02T00:00:00Z',
					'runUuid'     => 'run-1',
					'nodeId'      => 'review',
					'claimedBy'   => 'alice',
					'claimedAt'   => '2020-01-01T10:00:00Z',
				]
			)
		);

		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'new-task']], $result);
		$this->assertSame('carol', $task->getCompletedBy());
		$this->assertSame('2020-01-02', $task->getCompletedAt()->format('Y-m-d'));
		$context = $run->getContext();
		$this->assertSame(1, $context['keep']);
		$this->assertSame(['taskUuid' => 'x'], $context['resumeState']['other']);
		$this->assertSame('new-task', $context['resumeState']['review']['taskUuid']);
		$this->assertNotNull($run->getResumeAt());
	}

	/**
	 * A task with no run and no claim touches neither the runs nor the audit.
	 *
	 * @return void
	 */
	public function testATaskWithoutRunOrClaimTouchesNeither(): void {
		$this->mapper->method('findByKey')->willReturn([]);
		$this->service->expects($this->once())->method('importTask')
			->with($this->callback(static fn (TaskImport $i): bool => $i->actor === 'alice'))
			->willReturn($this->task('u'));
		$this->runs->expects($this->never())->method('findByUuid');
		$this->audits->expects($this->never())->method('insert');

		$this->assertSame(
			['error' => '', 'entry' => ['uuid' => 'u']],
			$this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o', 'assignee' => 'alice']))
		);
	}

	/**
	 * A key that cannot be looked up counts as held by none: the task is imported, raised by the app.
	 *
	 * @return void
	 */
	public function testAnUnreadableKeyIsImportedAsTheApp(): void {
		$this->mapper->expects($this->once())->method('findByKey')->with('example-set:app/k')->willThrowException(new RuntimeException('db'));
		$this->service->expects($this->once())->method('importTask')
			->with($this->callback(static fn (TaskImport $i): bool => $i->actor === 'app'))
			->willReturn($this->task('fresh'));

		$this->assertSame(
			['error' => '', 'entry' => ['uuid' => 'fresh']],
			$this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o']))
		);
	}

	/**
	 * A run that cannot be found after the import is the row's error.
	 *
	 * @return void
	 */
	public function testAFailureAfterTheImportIsTheError(): void {
		$this->mapper->method('findByKey')->willReturn([]);
		$this->service->method('importTask')->willReturn($this->task('u'));
		$this->runs->method('findByUuid')->willThrowException(new RuntimeException('no such run'));

		$this->assertSame(
			['error' => 'no such run', 'entry' => null],
			$this->tasks->write(new ExampleSetRow('app', 'k', ['title' => 't', 'objectUuid' => 'o', 'runUuid' => 'r', 'nodeId' => 'n']))
		);
	}
}
