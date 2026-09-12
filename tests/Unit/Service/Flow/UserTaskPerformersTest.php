<?php

/**
 * Who the step asks, and what asking them costs — tested directly.
 *
 * 🔴 THE MEASURED DEFECT, INVERTED. A step naming a group that has no members,
 * or that does not exist, created a task addressed to nobody. The run
 * suspended, a heartbeat re-read it every few minutes, and nothing said
 * anything: the task existed, the run was healthy, and the approval simply
 * never happened. Silence was the defect.
 *
 * ⚠️ TESTED HERE RATHER THAN ONLY THROUGH THE NODE. Reaching these two methods
 * through `UserTaskNode` means wiring a whole task lifecycle, and the node
 * fixtures that do it pass a null registry — so the interesting half of
 * `refuseIfNobodyHoldsThem` was never executed by anything.
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
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers;
use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see UserTaskPerformers}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Task\TaskForm
 * @uses \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 * @uses \OCA\OpenRegister\Event\AgentRunRequestedEvent
 * @uses \OCA\OpenRegister\Service\Task\TaskFormReader
 * @uses \OCA\OpenRegister\Db\Task
 */
final class UserTaskPerformersTest extends TestCase {

	/**
	 * Every event the performers dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * A resolver that answers for one type, with the holders it is given.
	 *
	 * @param string             $type    The type it answers for.
	 * @param array<int, string> $holders Who currently holds any id of that type.
	 *
	 * @return IPrincipalResolver The resolver.
	 */
	private function resolver(string $type, array $holders): IPrincipalResolver {
		return new class($type, $holders) implements IPrincipalResolver {

			/**
			 * Constructor.
			 *
			 * @param string             $type    The type.
			 * @param array<int, string> $holders The holders.
			 */
			public function __construct(
				private readonly string $type,
				private readonly array $holders,
			) {
			}

			/**
			 * The type this resolver answers for.
			 *
			 * @return string The type.
			 */
			public function type(): string {
				return $this->type;
			}

			/**
			 * Who holds it.
			 *
			 * @param string $id The reference id.
			 *
			 * @return array<int, string> The holders.
			 */
			public function resolve(string $id): array {
				return $this->holders;
			}
		};
	}//end resolver()

	/**
	 * A registry offering the given resolvers.
	 *
	 * @param array<int, IPrincipalResolver> $resolvers The resolvers.
	 *
	 * @return PrincipalResolverRegistry The registry.
	 */
	private function registry(array $resolvers): PrincipalResolverRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($resolvers): void {
				if (($event instanceof RegisterPrincipalResolversEvent) === false) {
					return;
				}

				foreach ($resolvers as $resolver) {
					$event->registerResolver($resolver);
				}
			}
		);

		return new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class));
	}//end registry()

	/**
	 * A form reader with no collaborators of consequence.
	 *
	 * ⚠️ The real reader, not a double: `TaskForm` is FINAL, so PHPUnit cannot
	 * generate a return value for `fromConfig()` and the auto-stub throws.
	 *
	 * @return TaskFormReader The reader.
	 */
	private function formReader(): TaskFormReader {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new TaskFormReader(
			$this->createMock(SchemaMapper::class),
			$this->createMock(TransitionEngine::class),
			$this->createMock(IAppManager::class),
			$l10n
		);
	}//end formReader()

	/**
	 * The performers, over a registry and a recording dispatcher.
	 *
	 * @param PrincipalResolverRegistry|null $registry    The registry, or none.
	 * @param boolean                        $withEvents  Whether a dispatcher is wired.
	 *
	 * @return UserTaskPerformers The subject.
	 */
	private function performers(?PrincipalResolverRegistry $registry, bool $withEvents = true): UserTaskPerformers {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$events = null;
		if ($withEvents === true) {
			$events = $this->createMock(IEventDispatcher::class);
			$events->method('dispatchTyped')->willReturnCallback(
				function (Event $event): void {
					$this->dispatched[] = $event;
				}
			);
		}

		return new UserTaskPerformers(
			config: new UserTaskConfig(l10n: $l10n, forms: $this->formReader()),
			principals: $registry,
			events: $events
		);
	}//end performers()

	/**
	 * The task an agent would be asked to complete.
	 *
	 * @return Task The task.
	 */
	private function aTask(): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setState(Task::STATE_ACTIVE);

		return $task;
	}//end aTask()

	/**
	 * The agent events dispatched so far.
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
	 * 🔴 A STEP WHOSE PERFORMERS RESOLVE TO NOBODY FAILS, LOUDLY.
	 *
	 * A loud step failure is strictly better than silence even when the author
	 * chooses to continue past it, because it is subject to the flow's own
	 * `onError` policy — which is a decision the author gets to make, and
	 * silence is not.
	 *
	 * @return void
	 */
	public function testAStepNobodyCanPerformIsRefusedNamingWhoItAsked(): void {
		$performers = $this->performers($this->registry([$this->resolver('group', [])]));

		try {
			$performers->refuseIfNobodyHoldsThem(['assignee' => ['type' => 'group', 'id' => 'bezwaar']]);
			$this->fail('a task addressed to nobody should be refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('bezwaar', $e->getMessage(), 'the refusal names who it asked');
			$this->assertStringContainsString('nobody', $e->getMessage());
		}
	}//end testAStepNobodyCanPerformIsRefusedNamingWhoItAsked()

	/**
	 * A performer somebody holds raises no objection.
	 *
	 * @return void
	 */
	public function testAPerformerSomebodyHoldsIsAccepted(): void {
		$this->expectNotToPerformAssertions();

		$this->performers($this->registry([$this->resolver('group', ['alice'])]))
			->refuseIfNobodyHoldsThem(['assignee' => ['type' => 'group', 'id' => 'bezwaar']]);
	}//end testAPerformerSomebodyHoldsIsAccepted()

	/**
	 * 🔑 AN UNASSIGNED STEP IS DELIBERATELY OPEN, and refusing one would close
	 * a door the spec holds open.
	 *
	 * @return void
	 */
	public function testAStepNamingNobodyAtAllIsNotRefusedHere(): void {
		$this->expectNotToPerformAssertions();

		$this->performers($this->registry([$this->resolver('group', [])]))
			->refuseIfNobodyHoldsThem(['title' => 'Approve it']);
	}//end testAStepNamingNobodyAtAllIsNotRefusedHere()

	/**
	 * 🔴 WITHOUT A REGISTRY NOTHING IS REFUSED.
	 *
	 * An instance that cannot resolve at all must not start failing every step
	 * that names a group: it has no basis for the claim that nobody holds it.
	 *
	 * @return void
	 */
	public function testWithoutARegistryNothingIsRefused(): void {
		$this->expectNotToPerformAssertions();

		$this->performers(null)
			->refuseIfNobodyHoldsThem(['assignee' => ['type' => 'group', 'id' => 'bezwaar']]);
	}//end testWithoutARegistryNothingIsRefused()

	/**
	 * Only one of several performers needs a holder.
	 *
	 * The step is performable as long as somebody can perform it, and refusing
	 * because one of three named groups is empty would be wrong.
	 *
	 * @return void
	 */
	public function testOneHolderAmongSeveralIsEnough(): void {
		$this->expectNotToPerformAssertions();

		$registry = $this->registry([$this->resolver('group', []), $this->resolver('user', ['alice'])]);

		$this->performers($registry)->refuseIfNobodyHoldsThem(
			['candidateGroups' => ['bezwaar'], 'candidateUsers' => ['alice']]
		);
	}//end testOneHolderAmongSeveralIsEnough()

	/**
	 * 🔴 AN AGENT PERFORMER IS ASKED, WITH A PROMPT IN PLACE OF A FORM.
	 *
	 * It dispatches; it never invokes a runtime. The app that owns agents
	 * listens, does the work, and completes the task through the ordinary
	 * verbs — the same guard, the same audit, the same outcome vocabulary a
	 * person's answer takes.
	 *
	 * @return void
	 */
	public function testAnAgentIsAskedWithItsPrompt(): void {
		$this->performers(null)->askAnyAgent(
			config: [
				'title' => 'Approve it',
				'assignee' => ['type' => 'agent', 'id' => 'scribe'],
				'prompt' => 'Summarise the objection.',
				'outcomeKey' => 'advice',
				'requiresApproval' => true,
			],
			task: $this->aTask(),
			context: ['flowName' => 'Bezwaar']
		);

		$events = $this->agentEvents();
		$this->assertCount(1, $events);
		$this->assertSame('scribe', $events[0]->getAgent());
		$this->assertSame('Summarise the objection.', $events[0]->getPrompt());
		$this->assertSame('task-1', $events[0]->getSubjectUuid(), 'the agent is given the task to complete');
	}//end testAnAgentIsAskedWithItsPrompt()

	/**
	 * An agent asked nothing at all falls back to the step's title.
	 *
	 * A blank instruction is the one input guaranteed to produce a useless turn.
	 *
	 * @return void
	 */
	public function testAnAgentWithNoPromptIsAskedTheTitle(): void {
		$this->performers(null)->askAnyAgent(
			config: ['title' => 'Approve it', 'assignee' => ['type' => 'agent', 'id' => 'scribe']],
			task: $this->aTask(),
			context: []
		);

		$this->assertSame('Approve it', $this->agentEvents()[0]->getPrompt());
	}//end testAnAgentWithNoPromptIsAskedTheTitle()

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
		$performers = $this->performers(null);

		$performers->askAnyAgent(
			config: ['title' => 'Approve it', 'assignee' => 'alice'],
			task: $this->aTask(),
			context: []
		);
		$performers->askAnyAgent(
			config: ['title' => 'Approve it', 'assignee' => ['type' => 'group', 'id' => 'bezwaar']],
			task: $this->aTask(),
			context: []
		);

		$this->assertSame([], $this->agentEvents());
	}//end testAPersonsStepAsksNoAgent()

	/**
	 * An agent among several performers is still the one asked.
	 *
	 * @return void
	 */
	public function testAnAgentAmongSeveralPerformersIsFound(): void {
		$this->performers(null)->askAnyAgent(
			config: [
				'title' => 'Approve it',
				'assignee' => [
					['type' => 'user', 'id' => 'alice'],
					['type' => 'agent', 'id' => 'scribe'],
				],
			],
			task: $this->aTask(),
			context: []
		);

		$this->assertSame('scribe', $this->agentEvents()[0]->getAgent());
	}//end testAnAgentAmongSeveralPerformersIsFound()

	/**
	 * 🔴 WITH NO DISPATCHER THE STEP STILL RAISES ITS TASK.
	 *
	 * The node degrades to "a task nobody automated is doing", which a person
	 * can still pick up. Refusing instead would make the whole step unusable on
	 * a container-less path.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherNothingIsAskedAndNothingFails(): void {
		$this->performers(null, withEvents: false)->askAnyAgent(
			config: ['title' => 'Approve it', 'assignee' => ['type' => 'agent', 'id' => 'scribe']],
			task: $this->aTask(),
			context: []
		);

		$this->assertSame([], $this->agentEvents());
	}//end testWithoutADispatcherNothingIsAskedAndNothingFails()

	/**
	 * The event carries the step's own subject and mode, not defaults.
	 *
	 * @return void
	 */
	public function testTheEventCarriesTheStepsOwnFields(): void {
		$this->performers(null)->askAnyAgent(
			config: [
				'title' => 'Approve it',
				'assignee' => ['type' => 'agent', 'id' => 'scribe'],
				'subjectRegister' => 'cases',
				'subjectSchema' => 'case',
				'mode' => 'chat',
				'skill' => 'summarise',
			],
			task: $this->aTask(),
			context: ['flowName' => 'Bezwaar']
		);

		$event = $this->agentEvents()[0];
		$this->assertSame('cases', $event->getSubjectRegister());
		$this->assertSame('case', $event->getSubjectSchema());
		$this->assertSame('Bezwaar', $event->getFlowName());
	}//end testTheEventCarriesTheStepsOwnFields()
}//end class
