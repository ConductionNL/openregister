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
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;

/**
 * Creates one task, suspends until it is terminal, and routes on the outcome.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The thirteenth dependency
 * is the task-form reader, which the node needs so a form declaration is
 * refused when the step is SAVED rather than when the performer meets it;
 * dropping a name here would move that failure onto the performer.
 * @SuppressWarnings(PHPMD.StaticAccess) FlowTaskBridge::outcomeBagFor is a
 * stateless helper over a value; a factory to call it would add a dependency
 * to say the same thing.
 */
class UserTaskNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The configuration boundary: validation and templating.
	 *
	 * @var UserTaskConfig
	 */
	private readonly UserTaskConfig $config;

	/**
	 * Constructor.
	 *
	 * @param FlowTaskBridge $bridge Creates and reads the node's task.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 * @param TaskFormReader $forms Reads and refuses the step's form declaration.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md
	 */
	public function __construct(
		private readonly FlowTaskBridge $bridge,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		TaskFormReader $forms,
	) {
		$this->config = new UserTaskConfig(l10n: $l10n, forms: $forms);

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
			'onTimeout',
			'onReject',
			'outcomes',
			'outcomeKey',
			'failOnReject',
			'heartbeatMinutes',
			'advance',
			...TaskFormReader::CONFIG_KEYS,
		];
	}//end configKeys()

	/**
	 * The fields this node is edited through, in the order the spec names them:
	 * what the task is, who may perform it, how urgent and when, and how the
	 * flow continues, and what the performer fills in.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	public function configForm(): array {
		return array_merge(
			$this->whatFields(),
			$this->whoFields(),
			$this->whenFields(),
			$this->continuationFields(),
			$this->formFields()
		);
	}//end configForm()

	/**
	 * Validate through the configuration boundary.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws \UnexpectedValueException When the config is refused.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function validateConfig(array $config): void {
		$this->config->validate(config: $config);

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

			throw $this->suspension(config: $config, items: $items);
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
			throw $this->suspension(config: $config, items: $items);
		}

		$bag = FlowTaskBridge::outcomeBagFor(task: $task);

		if ($bag['rejected'] === true && ($config['failOnReject'] ?? false) === true) {
			throw new FlowStop(
				reason: sprintf(
					'Rejected: %s',
					trim((string)($bag['comment'] ?? $this->config->renderedTitle(config: $config, items: $items)))
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

		$task = $this->bridge->createTask(
			data: $this->config->taskData(config: $config, items: $items, nodeId: $resume->nodeId(), nodeType: $this->getId()),
			runUuid: $runUuid,
			nodeId: $resume->nodeId(),
			actor: $this->actingIdentity(context: $context)
		);

		// Written ONCE. A heartbeat re-enters execute() and finds the uuid
		// held, so it never reaches this line again; askedAt therefore records
		// when somebody was first asked, not when the run last checked.
		$resume->merge(
			values: $this->config->slotValues(config: $config, items: $items, taskUuid: (string)$task->getUuid())
		);

	}//end createTask()

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
		$key = $this->config->outcomeKey(config: $config);

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
	 * The suspension this node parks on: a heartbeat, and a reason that names
	 * what is being waited for so a paused run explains itself.
	 *
	 * @param array $config The step configuration.
	 * @param array $items The input items.
	 *
	 * @return FlowSuspension The suspension to throw.
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
	 */
	private function suspension(array $config, array $items): FlowSuspension {
		$title = $this->config->renderedTitle(config: $config, items: $items);
		if ($title === '') {
			$title = 'a task';
		}

		return new FlowSuspension(
			resumeAt: $this->config->heartbeatAt(config: $config),
			reason: sprintf('waiting for a person: %s', $title)
		);
	}//end suspension()

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
	 * What the task is.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whatFields(): array {
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
				'key' => 'outcomes',
				'label' => $this->l10n->t('Possible outcomes'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'The answers the flow will route on, comma separated, like "approved, rejected". Recorded on the task for the inbox to offer.'
				),
			],
		];
	}//end whatFields()

	/**
	 * Who may perform it.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whoFields(): array {
		return [
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
				'help' => $this->l10n->t(
					'One of single-role, or-set, hierarchical, round-robin or least-loaded. Leave empty to let the pool claim.'
				),
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
				'help' => $this->l10n->t(
					'user, group, agent or worker. Defaults to user. An agent completes a task through the same verbs a person does.'
				),
			],
		];
	}//end whoFields()

	/**
	 * How urgent it is, and when.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whenFields(): array {
		return [
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
				'help' => $this->l10n->t(
					'When the task should be done: a date, a field like {{ deadline }}, or a relative time like "+3 days". Advisory.'
				),
			],
			[
				'key' => 'expiresAt',
				'label' => $this->l10n->t('Expires'),
				'type' => 'text',
				'help' => $this->l10n->t('When the task stops being doable. Same shapes as "Due". Must not lie before it.'),
			],
			[
				'key' => 'onTimeout',
				'label' => $this->l10n->t('On timeout'),
				'type' => 'text',
				'help' => $this->l10n->t('What happens when the task expires: skip, error or dead_letter. Empty means the deadline enforces nothing.'),
			],
			[
				'key' => 'onReject',
				'label' => $this->l10n->t('On rejection'),
				'type' => 'text',
				'help' => $this->l10n->t('Only dead_letter changes the record; skip and error are read by whatever resumes the flow.'),
			],
			[
				'key' => 'heartbeatMinutes',
				'label' => $this->l10n->t('Re-check every (minutes)'),
				'type' => 'number',
				'help' => $this->l10n->t(
					'Safety net for a wake that never arrives. Lower is not faster: a completed task wakes the run immediately either way.'
				),
			],
		];
	}//end whenFields()

	/**
	 * How the flow continues once answered.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function continuationFields(): array {
		return [
			[
				'key' => 'outcomeKey',
				'label' => $this->l10n->t('Field to store the answer in'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'The outcome is written onto every item under this field, so later steps can route on it. Defaults to "task".'
				),
			],
			[
				'key' => 'failOnReject',
				'label' => $this->l10n->t('Treat a rejection as a failure'),
				'type' => 'boolean',
				'help' => $this->l10n->t('Off by default: being told "no" is usually the flow working, not breaking. Route on the outcome instead.'),
			],
			[
				'key' => 'advance',
				'label' => $this->l10n->t('Continue after the answer'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'How far the run continues inside the request that completes the task: 0 leaves it to the background worker (default), '
					. 'a number runs that many steps, "all" runs to the next pause or the end.'
				),
			],
		];
	}//end continuationFields()

	/**
	 * What the performer fills in.
	 *
	 * Flat keys, like the rest of the vocabulary, so the server-driven config
	 * form draws them without an editor change. The field list is a
	 * constrained pick from the subject schema's properties, never a
	 * free-typed name: a misspelled field is refused when the step is saved,
	 * so the performer never meets a refusal they cannot act on.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	private function formFields(): array {
		return [
			[
				'key' => 'formKind',
				'label' => $this->l10n->t('Form'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'What the performer fills in besides the outcome: leave empty for none, "fields" for fields of the subject object, '
					. '"external" for a Nextcloud Forms form bound to the subject. Fields are validated by the subject schema; '
					. 'an external form is recorded as evidence and writes nothing to the object.'
				),
			],
			[
				'key' => 'formSchema',
				'label' => $this->l10n->t('Subject schema'),
				'type' => 'select',
				'optionsFrom' => '/apps/openregister/api/schemas',
				'help' => $this->l10n->t('The schema the fields belong to. Required for a field form.'),
			],
			[
				'key' => 'formAction',
				'label' => $this->l10n->t('Lifecycle action'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Inherit the fields a lifecycle transition of the subject schema declares, and apply that transition on completion. '
					. 'Leave empty to list fields instead.'
				),
			],
			[
				'key' => 'formFields',
				'label' => $this->l10n->t('Fields to ask for'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Properties of the subject schema the performer supplies, comma separated; add * after a name to make it required, '
					. 'like "reason*". A name the schema does not have is refused when the step is saved. Not combined with a lifecycle action.'
				),
			],
			[
				'key' => 'formId',
				'label' => $this->l10n->t('Nextcloud Forms form'),
				'type' => 'number',
				'help' => $this->l10n->t('The id of the Forms form linked to the subject object. Needs the Forms app.'),
			],
			[
				'key' => 'formRequireChecklist',
				'label' => $this->l10n->t('Require every checklist item checked'),
				'type' => 'boolean',
				'help' => $this->l10n->t('Refuse completion while a checklist item is unchecked, naming it. Checklist state is never part of the form values.'),
			],
		];
	}//end formFields()
}//end class
