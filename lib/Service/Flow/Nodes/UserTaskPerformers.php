<?php

/**
 * Who the step asks, and what asking them costs.
 *
 * Separate from {@see UserTaskNode} because it answers a different question.
 * The node orchestrates a task's lifecycle — create it, arm its clock, suspend,
 * resume, read the answer. This decides who the task is FOR, and it is the only
 * place that knows an agent is asked differently from a person.
 *
 * Both of its questions are asked at task-creation time, and both are asked
 * THERE for the same reason: it is the moment somebody actually has to be
 * found. A step naming a committee that has no members today may well have
 * members next week, so refusing to SAVE the flow over it would make the flow
 * unauthorable for a reason that has nothing to do with the flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
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

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Service\Flow\Principal\AgentPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use RuntimeException;

/**
 * Resolves and asks a user task's performers.
 */
class UserTaskPerformers {

	/**
	 * Constructor.
	 *
	 * @param UserTaskConfig                 $config     Reads the step's performer fields.
	 * @param PrincipalResolverRegistry|null $principals Resolves a reference to who it currently means.
	 * @param IEventDispatcher|null          $events     Where an agent's turn is asked for.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(
		private readonly UserTaskConfig $config,
		private readonly ?PrincipalResolverRegistry $principals = null,
		private readonly ?IEventDispatcher $events = null,
	) {

	}//end __construct()

	/**
	 * Refuse to raise a task nobody can perform.
	 *
	 * 🔴 THIS IS THE MEASURED DEFECT, INVERTED. A step naming a group that has
	 * no members — or that does not exist — created a task addressed to
	 * nobody. The run suspended, a heartbeat re-read it every few minutes, and
	 * NOTHING said anything: the task existed, the run was healthy, and the
	 * approval simply never happened. Silence was the defect.
	 *
	 * A loud step failure is strictly better even when the author chooses to
	 * continue past it, because it is subject to the flow's own `onError`
	 * policy — which is a decision the author gets to make, and silence is not.
	 *
	 * Only checked when the instance can resolve at all, and only for steps
	 * that name somebody: an unassigned step is deliberately open, and refusing
	 * one would close a door the spec holds open.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When every named performer resolves to nobody.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function refuseIfNobodyHoldsThem(array $config): void {
		if ($this->principals === null) {
			return;
		}

		$named = $this->config->performers(config: $config);
		if ($named === []) {
			return;
		}

		if ($this->principals->resolveAll(references: $named) !== []) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'openregister.user-task cannot raise a task: it asks %s, and nobody currently holds any of them. '
					. 'The step fails rather than creating a task addressed to nobody.',
				implode(', ', array_map(static fn ($r): string => (string)$r, $named))
			)
		);

	}//end refuseIfNobodyHoldsThem()

	/**
	 * Ask an agent performer to take its turn.
	 *
	 * 🔴 IT DISPATCHES; IT NEVER INVOKES A RUNTIME. The engine has no business
	 * knowing how an agent runs, and naming the app that does would put a
	 * consuming app's name in OpenRegister (gate-27, ADR-022). The app that
	 * owns agents listens, does the work, and completes the task through the
	 * ORDINARY verbs — the same guard, the same audit, the same outcome
	 * vocabulary a person's answer takes.
	 *
	 * That is also why the task is created FIRST and this is called second: the
	 * agent needs something to complete, and if nothing is listening the task
	 * simply sits there, reassignable to a person like any other.
	 *
	 * ⚠️ THIS IS THE EVENT'S FIRST DISPATCHER. `AgentRunRequestedEvent` was
	 * declared and never fired by anything — an event nobody sends is a
	 * contract nobody can rely on.
	 *
	 * @param array<string, mixed> $config  The step configuration.
	 * @param Task                 $task    The task just created.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::listFrom()` is
	 * a named constructor on a value object, which this rule cannot tell apart
	 * from a static call into a service.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function askAnyAgent(array $config, Task $task, array $context): void {
		if ($this->events === null) {
			return;
		}

		$agent = null;
		foreach (PrincipalReference::listFrom(value: ($config['assignee'] ?? null)) as $reference) {
			if ($reference->type === AgentPrincipalResolver::TYPE) {
				$agent = $reference;
				break;
			}
		}

		if ($agent === null) {
			return;
		}

		$this->events->dispatchTyped(
			new AgentRunRequestedEvent(
				subjectUuid: (string)$task->getUuid(),
				subjectRegister: trim((string)($config['subjectRegister'] ?? '')),
				subjectSchema: trim((string)($config['subjectSchema'] ?? '')),
				agent: $agent->id,
				skill: ($config['skill'] ?? null),
				// A person gets fields to fill in; an agent gets a prompt. An
				// agent asked nothing at all cannot know what the step wanted,
				// so the title stands in rather than an empty string.
				prompt: trim((string)($config['prompt'] ?? ($config['title'] ?? ''))),
				resultField: trim((string)($config['outcomeKey'] ?? '')),
				requiresApproval: (bool)($config['requiresApproval'] ?? false),
				mode: trim((string)($config['mode'] ?? 'task')),
				flowName: trim((string)($context['flowName'] ?? ''))
			)
		);

	}//end askAnyAgent()
}//end class
