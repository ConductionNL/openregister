<?php

/**
 * Golden: what an example set's task write sends to the task engine, as whom, when it adopts a task that holds
 * the key, and how it marks a finished task, pinned on the exact calls and record entry.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 */
class ExampleSetTasksWriteGoldenTest extends TestCase {
	/**
	 * Calls made on the engine, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The task writer over a recording engine.
	 *
	 * @param array<int, Task> $holders  What findByKey() finds.
	 * @param bool             $terminal Whether the imported task is terminal.
	 * @param bool             $fail     Whether the import throws.
	 *
	 * @return ExampleSetTasks
	 */
	private function tasks(array $holders=[], bool $terminal=false, bool $fail=false): ExampleSetTasks {
		$service = $this->createMock(TaskServiceInterface::class);
		$service->method('import')->willReturnCallback(function (array $data, ?string $actor, ?callable $mayRead=null, ?\DateTime $createdAt=null) use ($terminal, $fail): Task {
			$this->calls[] = 'import as '.$actor.' '.json_encode($data).' created '.($createdAt?->format('Y-m-d\TH:i:sP') ?? 'now');
			if ($fail === true) {
				throw new RuntimeException('the engine refused it');
			}

			$task = new Task();
			$task->setUuid('task-1');
			$task->setIsTerminal($terminal);
			return $task;
		});
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('findByKey')->willReturnCallback(function (string $key) use ($holders): array {
			$this->calls[] = 'findByKey '.$key;
			return $holders;
		});
		$mapper->method('update')->willReturnCallback(function (Task $task): Task {
			$this->calls[] = 'update completed '.$task->getCompletedAt()?->format('Y-m-d\TH:i:sP').' by '.$task->getCompletedBy();
			return $task;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
			TaskServiceInterface::class => $service,
			TaskMapper::class           => $mapper,
		});
		return new ExampleSetTasks(container: $container);
	}//end tasks()

	/**
	 * A new task is imported as its assignee, keyed by its app and key, without the fields the engine does not take.
	 *
	 * @return void
	 */
	public function testANewTaskIsImportedAsItsAssignee(): void {
		$data = ['title' => 'Call', 'objectUuid' => 'o-1', 'assignee' => 'alice', 'createdAt' => '2026-01-02T03:04:05+00:00', 'claimedAt' => null];
		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'task-1']], $this->tasks()->write(row: new ExampleSetRow(app: 'demo', key: 't1', data: $data)));
		$this->assertSame(
			[
				'findByKey example-set:demo/t1',
				'import as alice {"title":"Call","objectUuid":"o-1","assignee":"alice","key":"example-set:demo\/t1","appId":"demo"} created 2026-01-02T03:04:05+00:00',
			],
			$this->calls
		);
	}//end testANewTaskIsImportedAsItsAssignee()

	/**
	 * A task that already holds the declared key is adopted, not imported again.
	 *
	 * @return void
	 */
	public function testATaskThatHoldsTheKeyIsAdopted(): void {
		$held = new Task();
		$held->setUuid('held-9');
		$result = $this->tasks(holders: [$held])->write(row: new ExampleSetRow(app: 'demo', key: 't1', data: ['title' => 'Call', 'objectUuid' => 'o-1', 'taskKey' => 'onboarding.step1']));
		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'held-9']], $result);
		$this->assertSame(['findByKey onboarding.step1'], $this->calls);
	}//end testATaskThatHoldsTheKeyIsAdopted()

	/**
	 * An external task, and one without an assignee, is raised by the app; its upload rules become metadata.
	 *
	 * @return void
	 */
	public function testAnExternalOrUnassignedTaskIsRaisedByTheApp(): void {
		$tasks = $this->tasks();
		$tasks->write(row: new ExampleSetRow(app: 'demo', key: 'x', data: ['title' => 'Upload', 'objectUuid' => 'o-1', 'performerType' => 'external', 'assignee' => 'party:p', 'uploadRules' => ['required' => true]]));
		$tasks->write(row: new ExampleSetRow(app: 'demo', key: 'y', data: ['title' => 'Look', 'objectUuid' => 'o-1']));
		$this->assertSame(
			[
				'findByKey example-set:demo/x',
				'import as demo {"title":"Upload","objectUuid":"o-1","performerType":"external","assignee":"party:p","key":"example-set:demo\/x","appId":"demo","metadata":{"upload":{"required":true}}} created now',
				'findByKey example-set:demo/y',
				'import as demo {"title":"Look","objectUuid":"o-1","key":"example-set:demo\/y","appId":"demo"} created now',
			],
			$this->calls
		);
	}//end testAnExternalOrUnassignedTaskIsRaisedByTheApp()

	/**
	 * A finished task is imported as who finished it and marked completed then; a task that is not terminal is not.
	 *
	 * @return void
	 */
	public function testAFinishedTaskIsMarkedCompleted(): void {
		$data = ['title' => 'Done', 'objectUuid' => 'o-1', 'assignee' => 'alice', 'completedBy' => 'bob', 'completedAt' => '2026-01-05T10:00:00+00:00'];
		$this->tasks(terminal: true)->write(row: new ExampleSetRow(app: 'demo', key: 'd', data: $data));
		$this->tasks(terminal: false)->write(row: new ExampleSetRow(app: 'demo', key: 'e', data: $data));
		$this->assertSame(
			[
				'findByKey example-set:demo/d',
				'import as bob {"title":"Done","objectUuid":"o-1","assignee":"alice","key":"example-set:demo\/d","appId":"demo"} created now',
				'update completed 2026-01-05T10:00:00+00:00 by bob',
				'findByKey example-set:demo/e',
				'import as bob {"title":"Done","objectUuid":"o-1","assignee":"alice","key":"example-set:demo\/e","appId":"demo"} created now',
			],
			$this->calls
		);
	}//end testAFinishedTaskIsMarkedCompleted()

	/**
	 * An import the engine refuses is the write's error.
	 *
	 * @return void
	 */
	public function testARefusedImportIsTheError(): void {
		$this->assertSame(
			['error' => 'the engine refused it', 'entry' => null],
			$this->tasks(fail: true)->write(row: new ExampleSetRow(app: 'demo', key: 't', data: ['title' => 'Call', 'objectUuid' => 'o-1', 'assignee' => 'alice']))
		);
	}//end testARefusedImportIsTheError()
}//end class
