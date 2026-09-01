<?php

/**
 * Puts a person, or an agent, into the graph: ask them, and wait for the answer.
 *
 * THE DIVISION OF LABOUR WITH `openregister.await-signal`. Both nodes pause a
 * run until something outside it happens, and an author will pick the wrong one
 * unless the line is drawn once: a SIGNAL is for a system that will call back
 * (a payment provider, a webhook, a child run); a USER TASK is for a performer
 * who has to be found, told, and allowed to say no. This node creates a task
 * through the fleet-generic task service, so the question has an owner, sits
 * in an inbox, can be claimed, delegated and cancelled, and is answered only
 * through verbs that authorize the answerer. `await-signal` stays exactly as it
 * is for machine-to-machine work.
 *
 * THE NODE THAT SUSPENDS IS THE NODE THAT CREATES THE TASK. The obvious
 * composition (create a task, then await a signal) cannot work: a run holds one
 * awaiting slot PER NODE, so whatever resumes the run must name the node, and a
 * task created by an earlier step cannot know which node it will block. So the
 * task is created here, stamped with this run and this node, and the uuid is
 * kept in THIS node's resume slot. Two user-task nodes in one flow therefore
 * keep independent state; one's completion cannot continue the other.
 *
 * WHY IT READS THE TASK AND NOT THE SIGNAL SLOT. `context.signal` is ONE slot per
 * run, consumed by the walk it wakes. A flow with two approvals would have the
 * second read the answer given to the first. The task removes the race instead
 * of guarding it: each node's task is a row addressed by uuid, and terminality
 * is a property of that row. A resume POSTed at the run is therefore a nudge and
 * nothing more; it cannot answer for a performer.
 *
 * 🔴 THE HEARTBEAT MUST NOT CREATE A SECOND TASK. This node suspends with a
 * resume time as a safety net against a lost wake, which means it is re-entered
 * on a timer with the task still open. Creation is guarded on the resume slot,
 * and the creation time is written ONCE: a heartbeat that restamped it would
 * report every long-waiting task as minutes old, which is the reading that
 * stops anyone chasing it.
 *
 * 🔴 THE HEARTBEAT IS NEVER NULL. `FlowRunMapper::findAbandonedSignals()` reaps
 * suspended runs with `resume_at IS NULL` after fourteen days and FAILS them.
 * A user task parked on null would be the first thing that reaper ever caught,
 * and it would catch exactly the approvals a municipal case is made of. An
 * unanswered task is not an abandoned run; `expires_at` is the instrument for
 * that, and it belongs to the business timers.
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
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowAdvanceBudget;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use UnexpectedValueException;

/**
 * Creates one task, suspends until it is terminal, and routes on the outcome.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate, FlowAdvanceBudget
 * and FlowTaskBridge::outcomeBagFor are stateless helpers over values; a
 * factory to call them would add a dependency to say the same thing.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The node touches the task
 * entity's vocabulary, the engine's three exceptions, the resume slot and the
 * bridge; that is the surface of "put a task into a run", not accidental spread.
 */
class UserTaskNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * Minutes between heartbeats when the flow does not choose.
	 *
	 * Same reasoning as {@see AwaitSignalNode}: short enough that a lost wake
	 * is an inconvenience, long enough that a fortnight-long approval costs a
	 * few thousand no-op wakes. A completion wakes the run at once either way.
	 *
	 * @var int
	 */
	private const DEFAULT_HEARTBEAT_MINUTES = 15;

	/**
	 * The floor a configured heartbeat is clamped to: the stock cron period.
	 *
	 * @var int
	 */
	private const MIN_HEARTBEAT_MINUTES = 5;

	/**
	 * The item key the outcome lands under when the flow does not choose.
	 *
	 * `task`, not `signal`: a flow holding both wait nodes must not have them
	 * writing over each other's key by default.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTCOME_KEY = 'task';

	/**
	 * Constructor.
	 *
	 * @param FlowTaskBridge $bridge Creates and reads the node's task.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md
	 */
	public function __construct(
		private readonly FlowTaskBridge $bridge,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function getId(): string {
		return 'openregister.user-task';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Ask a person');
	}//end getDisplayName()

	/**
	 * Palette description, written as the other half of the await-signal pair.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-signal-node-keeps-machine-to-machine-work
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Ask a person or an agent to do something, and wait for their answer. For a system that will call back, use "Wait for an answer" instead.'
		);
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/user.svg');
	}//end getIcon()

	/**
	 * Asking somebody grants no privilege; the task service authorizes the answer.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a user-task step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function configKeys(): array {
		return [
			'title',
			'description',
			'assignee',
			'candidateUsers',
			'candidateGroups',
			'candidateRole',
			'routingStrategy',
			'routingFallback',
			'performerType',
			'priority',
			'dueAt',
			'expiresAt',
			'outcomes',
			'outcomeKey',
			'failOnReject',
			'heartbeatMinutes',
			'advance',
		];
	}//end configKeys()

	/**
	 * The fields this node is edited through.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'title',
				'label' => $this->l10n->t('What is being asked'),
				'type' => 'text',
				'help' => $this->l10n->t('The task title, shown in the inbox. Fields of the item can be used, like {{ name }}.'),
				'required' => true,
			],
			[
				'key' => 'description',
				'label' => $this->l10n->t('Details'),
				'type' => 'textarea',
				'help' => $this->l10n->t('What the performer needs to know to do it. Templates work here too.'),
			],
			[
				'key' => 'assignee',
				'label' => $this->l10n->t('Assign directly to'),
				'type' => 'text',
				'help' => $this->l10n->t('A user id. The task is created active for this person; leave empty to offer it to a pool instead.'),
			],
			[
				'key' => 'candidateUsers',
				'label' => $this->l10n->t('Candidate users'),
				'type' => 'text',
				'help' => $this->l10n->t('User ids, comma separated. Any of them may claim the task.'),
			],
			[
				'key' => 'candidateGroups',
				'label' => $this->l10n->t('Candidate groups'),
				'type' => 'text',
				'help' => $this->l10n->t('Group ids, comma separated. Any member may claim the task.'),
			],
			[
				'key' => 'candidateRole',
				'label' => $this->l10n->t('Candidate role'),
				'type' => 'text',
				'help' => $this->l10n->t('A role that resolves to a group of performers.'),
			],
			[
				'key' => 'routingStrategy',
				'label' => $this->l10n->t('How to pick a performer'),
				'type' => 'text',
				'help' => $this->l10n->t('One of single-role, or-set, hierarchical, round-robin or least-loaded. Leave empty to let the pool claim.'),
			],
			[
				'key' => 'routingFallback',
				'label' => $this->l10n->t('Fallback performer'),
				'type' => 'text',
				'help' => $this->l10n->t('Who gets the task when the strategy finds nobody.'),
			],
			[
				'key' => 'performerType',
				'label' => $this->l10n->t('Kind of performer'),
				'type' => 'text',
				'help' => $this->l10n->t('user, group, agent or worker. Defaults to user. An agent completes a task through the same verbs a person does.'),
			],
			[
				'key' => 'priority',
				'label' => $this->l10n->t('Priority'),
				'type' => 'text',
				'help' => $this->l10n->t('low, normal, high or urgent. Defaults to normal.'),
			],
			[
				'key' => 'dueAt',
				'label' => $this->l10n->t('Due'),
				'type' => 'text',
				'help' => $this->l10n->t('When the task should be done: a date, a field like {{ deadline }}, or a relative time like "+3 days". Advisory.'),
			],
			[
				'key' => 'expiresAt',
				'label' => $this->l10n->t('Expires'),
				'type' => 'text',
				'help' => $this->l10n->t('When the task stops being doable. Same shapes as "Due". Must not lie before it.'),
			],
			[
				'key' => 'outcomes',
				'label' => $this->l10n->t('Possible outcomes'),
				'type' => 'text',
				'help' => $this->l10n->t('The answers the flow will route on, comma separated, like "approved, rejected". Recorded on the task for the inbox to offer.'),
			],
			[
				'key' => 'outcomeKey',
				'label' => $this->l10n->t('Field to store the answer in'),
				'type' => 'text',
				'help' => $this->l10n->t('The outcome is written onto every item under this field, so later steps can route on it. Defaults to "task".'),
			],
			[
				'key' => 'failOnReject',
				'label' => $this->l10n->t('Treat a rejection as a failure'),
				'type' => 'boolean',
				'help' => $this->l10n->t('Off by default: being told "no" is usually the flow working, not breaking. Route on the outcome instead.'),
			],
			[
				'key' => 'heartbeatMinutes',
				'label' => $this->l10n->t('Re-check every (minutes)'),
				'type' => 'number',
				'help' => $this->l10n->t('Safety net for a wake that never arrives. Lower is not faster: a completed task wakes the run immediately either way.'),
			],
			[
				'key' => 'advance',
				'label' => $this->l10n->t('Continue after the answer'),
				'type' => 'text',
				'help' => $this->l10n->t('How far the run continues inside the request that completes the task: 0 leaves it to the background worker (default), a number runs that many steps, "all" runs to the next pause or the end.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a step that asks nothing, asks nobody, or carries an unreadable budget.
	 *
	 * The performer check is the one that must not be left to run time: a task
	 * nobody can be found for is not a task, and failing at run time would bury
	 * the mistake in a suspended run. The budget check is design D-4: `null`
	 * is refused by name so an author who asked for unlimited never silently
	 * gets zero.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the config is refused.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['title'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Say what is being asked, or nobody can do it.')
			);
		}

		if ($this->namesAPerformer(config: $config) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'No performer can be resolved: name an assignee, candidate users, candidate groups, a candidate role or a routing fallback.'
				)
			);
		}

		$this->refuseOutsideVocabulary(config: $config, key: 'performerType', vocabulary: Task::PERFORMER_TYPES);
		$this->refuseOutsideVocabulary(config: $config, key: 'priority', vocabulary: Task::PRIORITIES);
		$this->refuseOutsideVocabulary(config: $config, key: 'routingStrategy', vocabulary: Task::ROUTING_STRATEGIES);

		if (array_key_exists('outcomes', $config) === true && $this->listOf(value: $config['outcomes']) === []) {
			throw new UnexpectedValueException(
				$this->l10n->t('Possible outcomes must be a list of names, like "approved, rejected".')
			);
		}

		if (array_key_exists('heartbeatMinutes', $config) === true && is_numeric($config['heartbeatMinutes']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Re-check every (minutes) must be a number.')
			);
		}

		// Throws its own message, which names the value and states the spelling.
		FlowAdvanceBudget::fromConfig(config: $config);

	}//end validateConfig()

	/**
	 * Create the task on the first pass; suspend until it ends; route on the outcome.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying the outcome bag.
	 *
	 * @throws FlowSuspension While the task is not terminal.
	 * @throws FlowStop When rejected and the step asked to fail on rejection.
	 * @throws RuntimeException When the node has no resume slot, or its task is gone.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	public function execute(array $items, array $config, array $context): array {
		if ($items === []) {
			// An empty branch reaching this node is the normal case in a
			// priority-ordered graph. Nothing to ask about, nobody to ask, and
			// suspension is a RUN-level act this branch has no right to.
			return $items;
		}

		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === false) {
			// Without a slot there is nowhere to record that the task exists,
			// so every heartbeat would create another. A step that cannot be
			// made idempotent must not run at all.
			throw new RuntimeException('openregister.user-task needs a node resume slot; without one every heartbeat would create a task.');
		}

		$taskUuid = trim((string)$resume->get(key: FlowTaskBridge::SLOT_TASK_UUID, default: ''));
		if ($taskUuid === '') {
			$this->createTask(items: $items, config: $config, context: $context, resume: $resume);

			throw new FlowSuspension(
				resumeAt: $this->heartbeatAt(config: $config),
				reason: $this->waitingReason(config: $config, items: $items)
			);
		}

		$task = $this->bridge->taskOrNull(uuid: $taskUuid);
		if ($task === null) {
			// The row this run was waiting on is gone. Waiting further would
			// wait forever; carrying on would invent an answer. Fail the step
			// and let the author's onError policy decide.
			throw new RuntimeException(sprintf('Task %s, which this step was waiting on, no longer exists.', $taskUuid));
		}

		if ($task->isInTerminalState() === false) {
			// Claimed, reassigned, delegated, nudged: none of those is an
			// answer. Suspend again, and do NOT touch the slot: askedAt stays
			// what it was.
			throw new FlowSuspension(
				resumeAt: $this->heartbeatAt(config: $config),
				reason: $this->waitingReason(config: $config, items: $items)
			);
		}

		$bag = FlowTaskBridge::outcomeBagFor(task: $task);

		if ($bag['rejected'] === true && ($config['failOnReject'] ?? false) === true) {
			throw new FlowStop(
				reason: sprintf(
					'Rejected: %s',
					trim((string)($bag['comment'] ?? $this->renderedTitle(config: $config, items: $items)))
				),
				isError: true
			);
		}

		return $this->placeOutcome(items: $items, config: $config, bag: $bag);
	}//end execute()

	/**
	 * Create the one task and remember it in this node's slot.
	 *
	 * @param array $items The input items; the first is the representative.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 * @param FlowNodeResumeState $resume This node's slot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function createTask(array $items, array $config, array $context, FlowNodeResumeState $resume): void {
		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			throw new RuntimeException('openregister.user-task cannot create a task outside a persisted run: the task must carry the run uuid.');
		}

		$budget = FlowAdvanceBudget::fromConfig(config: $config);
		$assignee = trim((string)($config['assignee'] ?? ''));

		$task = $this->bridge->createTask(
			data: $this->taskData(items: $items, config: $config, resume: $resume),
			runUuid: $runUuid,
			nodeId: $resume->nodeId(),
			actor: $this->actingIdentity(context: $context)
		);

		// Written ONCE. A heartbeat re-enters execute() and finds the uuid
		// held, so it never reaches this line again; askedAt therefore records
		// when somebody was first asked, not when the run last checked.
		$resume->merge(
			values: [
				FlowTaskBridge::SLOT_TASK_UUID => (string)$task->getUuid(),
				FlowTaskBridge::SLOT_ASKED_AT => (new DateTime())->format('c'),
				FlowTaskBridge::SLOT_ADVANCE => $budget->toStored(),
				// Read by FlowRunAssignee, so a resume POSTed at the run by
				// somebody other than the assignee is refused at the door as
				// well as ignored here.
				'assignee' => $assignee,
				'title' => $this->renderedTitle(config: $config, items: $items),
			]
		);

	}//end createTask()

	/**
	 * The task fields, templated against the representative item.
	 *
	 * Everything here is passed THROUGH to the task builder, which validates
	 * it. The node adds no field of its own; the outcome vocabulary and the
	 * item key ride under `metadata`, which the task service carries and never
	 * interprets.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param FlowNodeResumeState $resume This node's slot, for its id.
	 *
	 * @return array<string, mixed> The creation payload.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function taskData(array $items, array $config, FlowNodeResumeState $resume): array {
		$json = $this->representativeJson(items: $items);
		$assignee = trim((string)($config['assignee'] ?? ''));

		$state = Task::STATE_ENABLED;
		if ($assignee !== '') {
			$state = Task::STATE_ACTIVE;
		}

		$data = [
			'title' => $this->renderedTitle(config: $config, items: $items),
			'description' => $this->renderedOrNull(value: ($config['description'] ?? null), json: $json),
			'state' => $state,
			'performerType' => trim((string)($config['performerType'] ?? Task::PERFORMER_USER)),
			'priority' => trim((string)($config['priority'] ?? 'normal')),
			'assignee' => $this->nullIfEmpty(value: $assignee),
			'candidateUsers' => $this->nullIfEmptyList(value: $this->listOf(value: ($config['candidateUsers'] ?? null))),
			'candidateGroups' => $this->nullIfEmptyList(value: $this->listOf(value: ($config['candidateGroups'] ?? null))),
			'candidateRole' => $this->nullIfEmpty(value: trim((string)($config['candidateRole'] ?? ''))),
			'routingStrategy' => $this->nullIfEmpty(value: trim((string)($config['routingStrategy'] ?? ''))),
			'routingFallback' => $this->nullIfEmpty(value: trim((string)($config['routingFallback'] ?? ''))),
			'dueAt' => $this->renderedOrNull(value: ($config['dueAt'] ?? null), json: $json),
			'expiresAt' => $this->renderedOrNull(value: ($config['expiresAt'] ?? null), json: $json),
			'metadata' => [
				'flowNodeType' => $this->getId(),
				'flowNode' => $resume->nodeId(),
				'outcomes' => $this->listOf(value: ($config['outcomes'] ?? null)),
				'outcomeKey' => $this->outcomeKey(config: $config),
			],
		];

		// Anchor the task to the object the item is about, when the item says
		// which one that is, so the inbox can show its subject.
		$self = (array)($json['@self'] ?? []);
		$objectUuid = trim((string)($self['uuid'] ?? ($json['uuid'] ?? ($self['id'] ?? ''))));
		if ($objectUuid !== '') {
			$data['objectUuid'] = $objectUuid;
			if (is_numeric($self['register'] ?? null) === true) {
				$data['registerId'] = (int)$self['register'];
			}

			if (is_numeric($self['schema'] ?? null) === true) {
				$data['schemaId'] = (int)$self['schema'];
			}
		}

		return $data;
	}//end taskData()

	/**
	 * Write the outcome bag onto every item, under the configured key.
	 *
	 * Into the item's record (`json`), not beside it: the steps that follow
	 * route per item and read `json.<key>`, and a Switch cannot branch on
	 * something only the run holds. Non-array items are left alone rather
	 * than failing the run.
	 *
	 * @param array $items The items to pass on.
	 * @param array $config The step configuration.
	 * @param array<string, mixed> $bag The outcome bag.
	 *
	 * @return array The items, each carrying the bag.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
	 */
	private function placeOutcome(array $items, array $config, array $bag): array {
		$key = $this->outcomeKey(config: $config);

		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$json = (array)($item[FlowItems::JSON] ?? []);
			$json[$key] = $bag;
			$item[FlowItems::JSON] = $json;
			$items[$index] = $item;
		}

		return $items;
	}//end placeOutcome()

	/**
	 * Whether the config names anybody who could perform the task.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return boolean True when at least one performer source is set.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	private function namesAPerformer(array $config): bool {
		foreach (['assignee', 'candidateRole', 'routingFallback'] as $key) {
			if (trim((string)($config[$key] ?? '')) !== '') {
				return true;
			}
		}

		foreach (['candidateUsers', 'candidateGroups'] as $key) {
			if ($this->listOf(value: ($config[$key] ?? null)) !== []) {
				return true;
			}
		}

		return false;
	}//end namesAPerformer()

	/**
	 * Refuse a set value outside a published vocabulary, naming both.
	 *
	 * @param array $config The step configuration.
	 * @param string $key The config key.
	 * @param array<int, string> $vocabulary The accepted values.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is set and not in the vocabulary.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	private function refuseOutsideVocabulary(array $config, string $key, array $vocabulary): void {
		$value = trim((string)($config[$key] ?? ''));
		if ($value === '' || in_array($value, $vocabulary, true) === true) {
			return;
		}

		throw new UnexpectedValueException(
			$this->l10n->t('%1$s "%2$s" is not one of %3$s.', [$key, $value, implode(', ', $vocabulary)])
		);
	}//end refuseOutsideVocabulary()

	/**
	 * A list from a list or a comma-separated string; empty for anything else.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<int, string> Trimmed, non-empty entries.
	 */
	private function listOf(mixed $value): array {
		if (is_string($value) === true) {
			$value = explode(',', $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		$list = [];
		foreach ($value as $entry) {
			if (is_scalar($entry) === false) {
				continue;
			}

			$entry = trim((string)$entry);
			if ($entry !== '') {
				$list[] = $entry;
			}
		}

		return $list;
	}//end listOf()

	/**
	 * The record of the representative item: the first array item's json.
	 *
	 * @param array $items The input items.
	 *
	 * @return array<string, mixed> The record, empty when there is none.
	 */
	private function representativeJson(array $items): array {
		foreach ($items as $item) {
			if (is_array($item) === true) {
				return (array)($item[FlowItems::JSON] ?? []);
			}
		}

		return [];
	}//end representativeJson()

	/**
	 * The title, templated.
	 *
	 * @param array $config The step configuration.
	 * @param array $items The input items.
	 *
	 * @return string The rendered title.
	 */
	private function renderedTitle(array $config, array $items): string {
		return trim((string)FlowValueTemplate::render(
			value: (string)($config['title'] ?? ''),
			json: $this->representativeJson(items: $items)
		));
	}//end renderedTitle()

	/**
	 * A templated string, or null when it renders to nothing.
	 *
	 * @param mixed $value The configured value.
	 * @param array $json The representative record.
	 *
	 * @return string|null The rendered string, or null.
	 */
	private function renderedOrNull(mixed $value, array $json): ?string {
		if ($value === null) {
			return null;
		}

		$rendered = FlowValueTemplate::render(value: $value, json: $json);
		if (is_scalar($rendered) === false) {
			return null;
		}

		return $this->nullIfEmpty(value: trim((string)$rendered));
	}//end renderedOrNull()

	/**
	 * The run's acting identity: who the task is requested by.
	 *
	 * The run's owner (`runAs`) first, the person who triggered it second.
	 * Null is passed through and refused by the task service by name, which
	 * is the loud failure an unattributed run should produce.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return string|null The uid, or null when the run has none.
	 */
	private function actingIdentity(array $context): ?string {
		foreach (['runAs', 'triggeredBy'] as $key) {
			$uid = trim((string)($context[$key] ?? ''));
			if ($uid !== '') {
				return $uid;
			}
		}

		return null;
	}//end actingIdentity()

	/**
	 * The item key the outcome is written under.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string The key.
	 */
	private function outcomeKey(array $config): string {
		$key = trim((string)($config['outcomeKey'] ?? ''));
		if ($key === '') {
			return self::DEFAULT_OUTCOME_KEY;
		}

		return $key;
	}//end outcomeKey()

	/**
	 * The suspension reason, so a paused run explains itself.
	 *
	 * @param array $config The step configuration.
	 * @param array $items The input items.
	 *
	 * @return string The reason.
	 */
	private function waitingReason(array $config, array $items): string {
		$title = $this->renderedTitle(config: $config, items: $items);
		if ($title === '') {
			$title = 'a task';
		}

		return sprintf('waiting for a person: %s', $title);
	}//end waitingReason()

	/**
	 * When to wake up and re-ask, absent a wake.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return DateTime The next heartbeat. Never null.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	private function heartbeatAt(array $config): DateTime {
		$minutes = (int)($config['heartbeatMinutes'] ?? self::DEFAULT_HEARTBEAT_MINUTES);
		if ($minutes < self::MIN_HEARTBEAT_MINUTES) {
			$minutes = self::MIN_HEARTBEAT_MINUTES;
		}

		return (new DateTime())->modify('+' . $minutes . ' minutes');
	}//end heartbeatAt()

	/**
	 * Null for an empty string.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The value, or null when empty.
	 */
	private function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()

	/**
	 * Null for an empty list.
	 *
	 * @param array<int, string> $value The list.
	 *
	 * @return array<int, string>|null The list, or null when empty.
	 */
	private function nullIfEmptyList(array $value): ?array {
		if ($value === []) {
			return null;
		}

		return $value;
	}//end nullIfEmptyList()
}//end class
