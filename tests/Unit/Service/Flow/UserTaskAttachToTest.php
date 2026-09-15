<?php

/**
 * Hanging a task on an object the run declared earlier.
 *
 * 🔴 AN UNHELD ROLE FAILS THE STEP AND CREATES NO TASK. A task attached to
 * nothing looks fine in every list and is exactly the task an author believed
 * was attached to the case, so the refusal has to land BEFORE the task exists.
 *
 * 🔑 THE DECLARED SUBJECT OVERRIDES THE ITEM'S OWN ANCHOR, which is the point
 * of the field. Without `attachTo` the task hangs on whatever record the step
 * was raised from; with it, the task hangs on the object the AUTHOR named,
 * which may be one no item in this stream carries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-user-task-node/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\FlowRunSubjects;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Tests for the user-task node's `attachTo`.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunSubjects
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowAdvanceBudget
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Task\TaskForm
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Db\Task
 */
final class UserTaskAttachToTest extends TestCase {

	/**
	 * The data the bridge was asked to create a task from, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $created = null;

	/**
	 * The run the recorder reads.
	 *
	 * @var FlowRun|null
	 */
	private ?FlowRun $run = null;

	/**
	 * A node whose recorder reads the given run.
	 *
	 * @param FlowRun $run The run, carrying whatever it has declared.
	 *
	 * @return UserTaskNode The node.
	 */
	private function node(FlowRun $run): UserTaskNode {
		$this->run = $run;

		$bridge = $this->createMock(FlowTaskBridge::class);
		$bridge->method('createTask')->willReturnCallback(
			function (array $data): Task {
				$this->created = $data;

				$task = new Task();
				$task->setUuid('task-1');
				$task->setState(Task::STATE_ACTIVE);

				return $task;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('findByUuid')->willReturnCallback(fn (): FlowRun => $this->run);

		return new UserTaskNode(
			$bridge,
			$l10n,
			$this->createMock(IURLGenerator::class),
			$forms,
			$this->createMock(FlowTimerService::class),
			null,
			null,
			new FlowRunSubjectRecorder(
				$runs,
				new FlowRunSubjects(),
				$this->createMock(LoggerInterface::class)
			)
		);
	}//end node()

	/**
	 * A run holding a case under the role `case`.
	 *
	 * @return FlowRun The run.
	 */
	private function aRunHoldingACase(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		(new FlowRunSubjects())->record(
			run: $run,
			role: 'case',
			uuid: 'obj-case',
			register: '3',
			schema: '7'
		);

		return $run;
	}//end aRunHoldingACase()

	/**
	 * Run one step, swallowing the suspension it always raises.
	 *
	 * ⚠️ NOT named `run()`: that is FINAL on PHPUnit's TestCase, and overriding
	 * it is a fatal error rather than a failing test.
	 *
	 * @param FlowRun              $run    The run.
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 */
	private function executeStep(FlowRun $run, array $config): void {
		$state = new FlowResumeState();

		try {
			$this->node($run)->execute(
				[['json' => ['uuid' => 'obj-item', '@self' => ['uuid' => 'obj-item']]]],
				$config,
				[
					FlowResumeState::CONTEXT_KEY => $state,
					FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
					FlowRunContext::CONTEXT_RUN => 'run-1',
					'runUuid' => 'run-1',
					'runAs' => 'alice',
				]
			);
		} catch (FlowSuspension) {
			// Expected: the step waits for its answer.
		}
	}//end executeStep()

	/**
	 * 🔴 THE TASK CARRIES THE CASE'S UUID, REGISTER AND SCHEMA.
	 *
	 * Through the task row's existing object fields, not a new one, so the
	 * subject-anchored inbox read, the case sidebar and the portal visibility
	 * rule all keep working with no change.
	 *
	 * @return void
	 */
	public function testAttachingPutsTheTaskOnTheDeclaredSubject(): void {
		$this->executeStep(
			$this->aRunHoldingACase(),
			['title' => 'Approve it', 'assignee' => 'alice', 'attachTo' => 'case']
		);

		$this->assertSame('obj-case', $this->created['objectUuid']);
		$this->assertSame(3, $this->created['registerId']);
		$this->assertSame(7, $this->created['schemaId']);
	}//end testAttachingPutsTheTaskOnTheDeclaredSubject()

	/**
	 * 🔑 THE DECLARED SUBJECT OVERRIDES THE ITEM'S OWN ANCHOR.
	 *
	 * The item carries `obj-item`, and the author said `case`. Without the
	 * override the task would hang on whatever the stream happened to carry,
	 * which is the anchor the author was replacing.
	 *
	 * @return void
	 */
	public function testTheDeclaredSubjectBeatsTheItemsOwnAnchor(): void {
		$this->executeStep(
			$this->aRunHoldingACase(),
			['title' => 'Approve it', 'assignee' => 'alice', 'attachTo' => 'case']
		);

		$this->assertNotSame('obj-item', $this->created['objectUuid']);
	}//end testTheDeclaredSubjectBeatsTheItemsOwnAnchor()

	/**
	 * A step naming no `attachTo` keeps the item's own anchor, as before.
	 *
	 * @return void
	 */
	public function testWithoutAttachToTheItemsAnchorStands(): void {
		$this->executeStep(
			$this->aRunHoldingACase(),
			['title' => 'Approve it', 'assignee' => 'alice']
		);

		$this->assertSame('obj-item', $this->created['objectUuid']);
	}//end testWithoutAttachToTheItemsAnchorStands()

	/**
	 * 🔴 AN UNHELD ROLE FAILS THE STEP AND CREATES NO TASK.
	 *
	 * The refusal names the role that was asked for and the roles the run does
	 * hold, which is the whole reason no role vocabulary has to be registered
	 * anywhere.
	 *
	 * @return void
	 */
	public function testAnUnheldRoleFailsTheStepAndCreatesNoTask(): void {
		try {
			$this->executeStep(
				$this->aRunHoldingACase(),
				['title' => 'Approve it', 'assignee' => 'alice', 'attachTo' => 'besluit']
			);
			$this->fail('attaching to a role the run never recorded should fail the step');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('besluit', $e->getMessage());
			$this->assertStringContainsString('case', $e->getMessage(), 'the refusal names what IS held');
		}

		$this->assertNull($this->created, 'no task may be created when the attachment cannot be made');
	}//end testAnUnheldRoleFailsTheStepAndCreatesNoTask()

	/**
	 * A run holding nothing at all still refuses, rather than attaching to none.
	 *
	 * @return void
	 */
	public function testARunHoldingNothingRefusesToo(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');

		try {
			$this->executeStep($run, ['title' => 'Approve it', 'assignee' => 'alice', 'attachTo' => 'case']);
			$this->fail('a run holding nothing should refuse');
		} catch (UnexpectedValueException) {
			$this->addToAssertionCount(1);
		}

		$this->assertNull($this->created);
	}//end testARunHoldingNothingRefusesToo()

	/**
	 * `attachTo` is a key the node declares, so the editor's field edits
	 * something the node actually reads.
	 *
	 * @return void
	 */
	public function testTheNodeDeclaresTheKey(): void {
		$this->assertContains('attachTo', $this->node($this->aRunHoldingACase())->configKeys());
	}//end testTheNodeDeclaresTheKey()
}//end class
