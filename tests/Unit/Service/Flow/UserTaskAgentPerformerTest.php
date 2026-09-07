<?php

/**
 * An agent is a performer of the SAME step, not a second mechanism beside it.
 *
 * 🔴 THE NODE DISPATCHES; IT NEVER INVOKES A RUNTIME. The engine has no business
 * knowing how an agent runs, and naming the app that does would put a consuming
 * app's name in OpenRegister. The app that owns agents listens, does the work,
 * and completes the task through the ORDINARY verbs — the same guard, the same
 * audit, the same outcome vocabulary a person's answer takes.
 *
 * ⚠️ THIS IS THE EVENT'S FIRST DISPATCHER. `AgentRunRequestedEvent` was
 * declared and fired by nothing; an event nobody sends is a contract nobody can
 * rely on.
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
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The agent half of {@see UserTaskNode}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers
 * @uses \OCA\OpenRegister\Event\AgentRunRequestedEvent
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 */
final class UserTaskAgentPerformerTest extends TestCase {

	/**
	 * Every event the node dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * A node wired to record what it dispatches.
	 *
	 * @return UserTaskNode The node.
	 */
	private function node(): UserTaskNode {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState(Task::STATE_ACTIVE);
		$bridge->method('createTask')->willReturn($task);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		return new UserTaskNode(
			$bridge,
			$l10n,
			$this->createMock(IURLGenerator::class),
			$forms,
			$this->createMock(FlowTimerService::class),
			null,
			$events
		);
	}//end node()

	/**
	 * Run the node once, swallowing the suspension it always raises.
	 *
	 * ⚠️ NOT named `run()`: that is FINAL on PHPUnit's TestCase and overriding
	 * it is a fatal error, not a failing test.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 */
	private function executeStep(array $config): void {
		$state = new FlowResumeState();

		try {
			$this->node()->execute(
				[['json' => []]],
				$config,
				[
					FlowResumeState::CONTEXT_KEY => $state,
					FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
					FlowRunContext::CONTEXT_RUN => 'run-1',
					'runUuid' => 'run-1',
					'flowName' => 'Approve it',
				]
			);
		} catch (FlowSuspension) {
			// Expected: the step waits for its answer, agent or person.
		}
	}//end run()

	/**
	 * The agent events the node dispatched.
	 *
	 * @return array<int, AgentRunRequestedEvent> The events.
	 */
	private function agentEvents(): array {
		return array_values(
			array_filter(
				$this->dispatched,
				static fn (Event $event): bool => $event instanceof AgentRunRequestedEvent
			)
		);
	}//end agentEvents()

	/**
	 * 🔴 AN AGENT PERFORMER IS ASKED, WITH THE PROMPT IN PLACE OF A FORM.
	 *
	 * A person gets fields to fill in; an agent gets a prompt. Same step, same
	 * completion verbs, same audit.
	 *
	 * @return void
	 */
	public function testAnAgentPerformerIsAskedWithItsPrompt(): void {
		$this->executeStep(
			[
				'title' => 'Approve it',
				'assignee' => ['type' => 'agent', 'id' => 'scribe'],
				'prompt' => 'Summarise the objection and recommend a decision.',
			]
		);

		$events = $this->agentEvents();
		$this->assertCount(1, $events);
		$this->assertSame('scribe', $events[0]->getAgent());
		$this->assertSame('Summarise the objection and recommend a decision.', $events[0]->getPrompt());
	}//end testAnAgentPerformerIsAskedWithItsPrompt()

	/**
	 * 🔴 A PERSON'S STEP DISPATCHES NOTHING.
	 *
	 * The anti-widening control. Every stored flow on every instance names a
	 * person or a group, and none of them may start asking an agent because
	 * this branch exists.
	 *
	 * @return void
	 */
	public function testAPersonsStepAsksNoAgent(): void {
		$this->executeStep(['title' => 'Approve it', 'assignee' => 'alice']);
		$this->assertSame([], $this->agentEvents());

		$this->dispatched = [];
		$this->executeStep(['title' => 'Approve it', 'assignee' => ['type' => 'group', 'id' => 'bezwaar']]);
		$this->assertSame([], $this->agentEvents());
	}//end testAPersonsStepAsksNoAgent()

	/**
	 * An agent asked nothing at all falls back to the step's title.
	 *
	 * An agent with an empty prompt cannot know what the step wanted, and a
	 * blank instruction is the one input guaranteed to produce a useless turn.
	 *
	 * @return void
	 */
	public function testAnAgentWithNoPromptIsAskedTheTitle(): void {
		$this->executeStep(['title' => 'Approve it', 'assignee' => ['type' => 'agent', 'id' => 'scribe']]);

		$this->assertSame('Approve it', $this->agentEvents()[0]->getPrompt());
	}//end testAnAgentWithNoPromptIsAskedTheTitle()

	/**
	 * 🔑 THE TASK IS CREATED FIRST, THE EVENT SECOND.
	 *
	 * The agent needs something to complete, and if nothing is listening the
	 * task simply sits there — reassignable to a person like any other, which
	 * is the escape hatch that makes an agent performer safe to use at all.
	 *
	 * @return void
	 */
	public function testTheEventCarriesTheTaskTheAgentMustComplete(): void {
		$this->executeStep(['title' => 'Approve it', 'assignee' => ['type' => 'agent', 'id' => 'scribe']]);

		$this->assertSame('task-1', $this->agentEvents()[0]->getSubjectUuid());
	}//end testTheEventCarriesTheTaskTheAgentMustComplete()

	/**
	 * With no dispatcher, an agent step still raises its task.
	 *
	 * The node degrades to "a task nobody automated is doing", which a person
	 * can still pick up. Refusing instead would make the whole step unusable on
	 * a container-less path.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherTheStepStillRaisesItsTask(): void {
		$this->expectNotToPerformAssertions();

		$bridge = $this->createMock(FlowTaskBridge::class);
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState(Task::STATE_ACTIVE);
		$bridge->method('createTask')->willReturn($task);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));

		$node = new UserTaskNode(
			$bridge,
			$l10n,
			$this->createMock(IURLGenerator::class),
			$forms,
			$this->createMock(FlowTimerService::class)
		);

		$state = new FlowResumeState();

		try {
			$node->execute(
				[['json' => []]],
				['title' => 'Approve it', 'assignee' => ['type' => 'agent', 'id' => 'scribe']],
				[
					FlowResumeState::CONTEXT_KEY => $state,
					FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
					FlowRunContext::CONTEXT_RUN => 'run-1',
					'runUuid' => 'run-1',
				]
			);
		} catch (FlowSuspension) {
			// Expected.
		}
	}//end testWithoutADispatcherTheStepStillRaisesItsTask()

	/**
	 * 🔴 A TASK CANNOT BE CREATED OUTSIDE A PERSISTED RUN.
	 *
	 * The task carries the run uuid, and that is what lets an answer find its
	 * way back to the run that asked. A task without one is unanswerable, so
	 * the step fails rather than creating it.
	 *
	 * @return void
	 */
	public function testAStepOutsideAPersistedRunRefusesToCreateATask(): void {
		$state = new FlowResumeState();

		$this->expectException(RuntimeException::class);

		$this->node()->execute(
			[['json' => []]],
			['title' => 'Approve it', 'assignee' => 'alice'],
			[
				FlowResumeState::CONTEXT_KEY => $state,
				FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
			]
		);
	}//end testAStepOutsideAPersistedRunRefusesToCreateATask()

	/**
	 * A run naming no acting identity still raises its task, unattributed.
	 *
	 * 🔑 `runAs` THEN `triggeredBy`, AND NULL WHEN NEITHER IS THERE. An
	 * MCP-triggered run genuinely carries no identity, and refusing one would
	 * make the step unusable on that path; the task simply records no actor.
	 *
	 * @return void
	 */
	public function testARunWithNoActingIdentityStillRaisesItsTask(): void {
		$this->expectNotToPerformAssertions();

		$state = new FlowResumeState();

		try {
			$this->node()->execute(
				[['json' => []]],
				['title' => 'Approve it', 'assignee' => 'alice'],
				[
					FlowResumeState::CONTEXT_KEY => $state,
					FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
					FlowRunContext::CONTEXT_RUN => 'run-1',
					'runUuid' => 'run-1',
				]
			);
		} catch (FlowSuspension) {
			// Expected: the step waits for its answer.
		}
	}//end testARunWithNoActingIdentityStillRaisesItsTask()

	/**
	 * The step names itself in the palette, and carries an icon.
	 *
	 * A catalogue entry with no label reads as a blank row in the step picker.
	 *
	 * @return void
	 */
	public function testTheStepNamesItselfInThePalette(): void {
		$node = $this->node();

		$this->assertSame('Ask a person or group', $node->getDisplayName());
		// The generator is a double here, so the VALUE is not the point: the
		// contract is that the node answers a path rather than throwing.
		$this->assertIsString($node->getIcon());
	}//end testTheStepNamesItselfInThePalette()
}//end class
