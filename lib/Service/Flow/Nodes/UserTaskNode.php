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
 * @spec openspec/specs/flow-user-task-node/spec.md
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
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
class UserTaskNode implements IFlowNode, UserTaskNodeInterface, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {
	use TaskNodeTrait;

	/**
	 * The configuration boundary: validation and templating.
	 *
	 * @var UserTaskConfig
	 */
	private readonly UserTaskConfig $config;

	/**
	 * Who the step asks, and what asking them costs.
	 *
	 * @var UserTaskPerformers
	 */
	private readonly UserTaskPerformers $performers;

	/**
	 * Creates and reads the node's task.
	 *
	 * @var FlowTaskBridge
	 */
	private readonly FlowTaskBridge $bridge;

	/**
	 * Arms the task's business timer.
	 *
	 * @var FlowTimerService
	 */
	private readonly FlowTimerService $timers;

	/**
	 * Resolves `attachTo` to the object the run declared under that role.
	 *
	 * @var FlowRunSubjectRecorder|null
	 */
	private readonly ?FlowRunSubjectRecorder $subjects;

	/**
	 * Translations.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Resolves the palette icon.
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urls;

	/**
	 * Constructor.
	 *
	 * @param UserTaskServices     $services     The task bridge, form reader and
	 *                                          business timers.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 * @param UserTaskHooks        $hooks        The principal resolvers the config
	 *                                          reader refuses unknown performer
	 *                                          types with, the dispatcher an agent
	 *                                          performer's turn is ASKED FOR on,
	 *                                          and the recorder `attachTo`
	 *                                          resolves against.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md
	 */
	public function __construct(
		UserTaskServices $services,
		FlowNodePresentation $presentation,
		UserTaskHooks $hooks=new UserTaskHooks(),
	) {
		$this->bridge     = $services->bridge;
		$this->timers     = $services->timers;
		$this->subjects   = $hooks->subjects;
		$this->l10n       = $presentation->l10n;
		$this->urls       = $presentation->urls;
		$this->config     = new UserTaskConfig(l10n: $this->l10n, forms: $services->forms);
		$this->performers = new UserTaskPerformers(config: $this->config, hooks: $hooks);

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function getId(): string {
		return 'openregister.user-task';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function getDisplayName(): string {
		// "or group" because that is what the step has always done and never
		// said: the guard resolved a bare name as a uid OR a group, so half
		// the fleet's approvals are addressed to a committee under a label
		// that named one person.
		return $this->l10n->t('Ask a person or group');
	}//end getDisplayName()

	/**
	 * Palette description, written as the other half of the await-signal pair.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-signal-node-keeps-machine-to-machine-work
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Ask a person, a group or an agent to do something, and wait for the answer. For a system that will call back, use "Wait for an answer" instead.'
		);
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
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
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a user-task step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function configKeys(): array {
		return [
			// `candidates` is one field expressing what three did, and able to mix
			// types. `prompt` is WHAT an agent performer is asked, in place of a
			// form: a person gets fields to fill in, an agent gets a prompt.
			'title', 'description', 'assignee', 'candidates', 'prompt',
			'candidateUsers', 'candidateGroups', 'candidateRole', 'routingStrategy', 'routingFallback', 'performerType',
			'priority', 'dueAt', 'expiresAt', 'onTimeout', 'onReject',
			'outcomes', 'outcomeKey', 'failOnReject', 'heartbeatMinutes', 'advance',
			// The business-timer half. Absent from a node, nothing is armed and
			// the node behaves exactly as it did before these existed.
			'sla', 'calendar', 'ladder', 'escalationRules',
			// `attachTo` names WHICH declared subject of the run this task hangs
			// on. Empty leaves the task anchored to the item it was raised from.
			'attachTo', 'purpose', 'legalEffect', 'onExpiry',
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
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	public function configForm(): array {
		return array_merge(
			$this->whatFields(),
			$this->attachFields(),
			$this->whoFields(),
			$this->legacyCandidateFields(),
			$this->routingFields(),
			$this->whenFields(),
			$this->timeoutFields(),
			$this->continuationFields(),
			$this->formFields(),
			$this->formSchemaFields(),
			$this->formContentFields(),
			$this->externalFormFields()
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
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-node-describes-its-own-form-served-from-the-node-catalog
	 */
	public function validateConfig(array $config): void {
		$this->performers->refuseUnknownTypes(config: $config, l10n: $this->l10n);
		$this->config->validate(config: $config);

	}//end validateConfig()

	/**
	 * Create the task on the first pass; suspend until it ends; route on the outcome.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each carrying the outcome bag.
	 *
	 * @throws FlowSuspension While the task is not terminal.
	 * @throws FlowStop When rejected and the step asked to fail on rejection.
	 * @throws RuntimeException When the node has no resume slot, or its task is gone.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 * @spec openspec/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-wake-re-reads-the-awaited-task-and-applies-a-terminal-outcome
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		if ($items === []) {
			// An empty branch reaching this node is the normal case in a
			// priority-ordered graph. Nothing to ask about, nobody to ask, and
			// suspension is a RUN-level act this branch has no right to.
			return $items;
		}

		$resume = $this->resumeSlot(context: $context);

		return $this->pass(step: new UserTaskStep(items: $items, config: $config, context: $context, nodeId: $resume->nodeId()), resume: $resume);
	}//end execute()

	/**
	 * The node's resume slot, which a user task cannot run without.
	 *
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return FlowNodeResumeState The slot.
	 *
	 * @throws RuntimeException When the run gave the node no slot.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function resumeSlot(array $context): FlowNodeResumeState {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === false) {
			// Without a slot there is nowhere to record that the task exists,
			// so every heartbeat would create another. A step that cannot be
			// made idempotent must not run at all.
			throw new RuntimeException('openregister.user-task needs a node resume slot; without one every heartbeat would create a task.');
		}

		return $resume;
	}//end resumeSlot()

	/**
	 * Create the task on the first pass, or route on the outcome of the one in the slot.
	 *
	 * @param UserTaskStep        $step   The step's items, configuration, context and node.
	 * @param FlowNodeResumeState $resume The node's slot.
	 *
	 * @return array The items, each carrying the outcome bag.
	 *
	 * @throws FlowSuspension While the task is not terminal.
	 * @throws FlowStop When rejected and the step asked to fail on rejection.
	 * @throws RuntimeException When its task is gone.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function pass(UserTaskStep $step, FlowNodeResumeState $resume): array {
		$taskUuid = trim((string)$resume->get(key: FlowTaskBridge::SLOT_TASK_UUID, default: ''));
		if ($taskUuid === '') {
			$this->createTask(step: $step, resume: $resume);

			throw $this->suspension(config: $step->config, items: $step->items);
		}

		return $this->answerFromTask(step: $step, taskUuid: $taskUuid);
	}//end pass()

	/**
	 * Read the task this step is waiting on: suspend again until it is terminal, then place its outcome.
	 *
	 * @param UserTaskStep $step     The items, configuration and run context.
	 * @param string       $taskUuid The task held in this node's slot.
	 *
	 * @return array The items, each carrying the outcome.
	 *
	 * @throws RuntimeException When the task no longer exists.
	 * @throws FlowSuspension   While the task is not terminal.
	 * @throws FlowStop         On a rejection the flow opted to stop on.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function answerFromTask(UserTaskStep $step, string $taskUuid): array {
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
			throw $this->suspension(config: $step->config, items: $step->items);
		}

		// A terminal read with no signal in hand means the completion's wake
		// never arrived — refused by the assignee guard, or lost — and the
		// heartbeat is what recovered it. Recorded on the task's audit,
		// attributed to its completer, BEFORE the outcome is applied below:
		// the applying is identical on both paths, which is the contract.
		if (array_key_exists(FlowRunServiceInterface::SIGNAL_CONTEXT_KEY, $step->context) === false) {
			$this->bridge->recordHeartbeatRecovery(task: $task);
		}

		$bag = FlowTaskBridge::outcomeBagFor(task: $task);

		$this->refuseIfRejected(step: $step, bag: $bag);

		return $this->placeOutcome(step: $step, bag: $bag);
	}//end answerFromTask()

	/**
	 * Stop the run on a rejection, when the flow opted in with `failOnReject`.
	 *
	 * @param UserTaskStep         $step The items and configuration.
	 * @param array<string, mixed> $bag  The outcome bag.
	 *
	 * @return void
	 *
	 * @throws FlowStop On an opted-in rejection.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function refuseIfRejected(UserTaskStep $step, array $bag): void {
		if ($bag['rejected'] === true && ($step->config['failOnReject'] ?? false) === true) {
			throw new FlowStop(
				reason: sprintf(
					'Rejected: %s',
					trim((string)($bag['comment'] ?? $this->config->renderedTitle(config: $step->config, items: $step->items)))
				),
				isError: true
			);
		}

	}//end refuseIfRejected()

	/**
	 * Create the one task and remember it in this node's slot.
	 *
	 * @param UserTaskStep        $step   The items (the first is the representative),
	 *                                    configuration, run context and node id.
	 * @param FlowNodeResumeState $resume This node's slot.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
	 */
	private function createTask(UserTaskStep $step, FlowNodeResumeState $resume): void {
		$context = $step->context;
		$config  = $step->config;
		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			throw new RuntimeException('openregister.user-task cannot create a task outside a persisted run: the task must carry the run uuid.');
		}

		$this->performers->refuseIfNobodyHoldsThem(config: $config);

		$data = $this->config->taskData(step: $step, nodeType: $this->getId());

		// Who the performers resolved to NOW, kept as evidence in `metadata`
		// only: the answer guard resolves again when somebody answers, and must.
		$evidence = $this->performers->resolutionEvidence(config: $config);
		if ($evidence !== null) {
			$data['metadata']['performersAtCreation'] = $evidence;
		}

		// 🔴 BEFORE THE TASK EXISTS, AND IT THROWS. `attachTo` naming a role the
		// run never recorded fails the step and creates NOTHING: a task attached
		// to nothing looks fine in every list and is exactly the task an author
		// believed was attached to the case. The refusal names the roles the run
		// does hold, which is what makes an unregistered role vocabulary
		// workable.
		//
		// 🔑 IT OVERRIDES THE ITEM'S OWN ANCHOR, which is the point. Without
		// `attachTo` the task hangs on whatever record the step was raised from;
		// with it, the task hangs on the object the AUTHOR named, which may be
		// one no item in this stream carries.
		$data = array_merge(
			$data,
			($this->subjects?->anchorFor(context: $context, role: (string)($config['attachTo'] ?? '')) ?? [])
		);

		$task = $this->bridge->createTask(
			data: $data,
			runUuid: $runUuid,
			nodeId: $resume->nodeId(),
			actor: $this->actingIdentity(context: $context)
		);

		// ARM THE DEADLINE, if this node declares one. Nothing else in the
		// engine did: `FlowTimerService::arm()` had no production caller at
		// all, so a node could describe an SLA and no clock ever started.
		//
		// Here rather than anywhere else because this is where the task's uuid
		// first exists, and the timer is bound to the TASK — the same
		// (subjectType: 'task', subjectUuid) pair that
		// FlowTimerSubjectTerminalListener already cancels on completion. Arm
		// somewhere the uuid is not yet known and the two halves cannot meet.
		$this->performers->askAnyAgent(step: $step, task: $task);

		$this->armDeadline(
			step: $step,
			binding: [
				'subjectType' => 'task',
				'subjectUuid' => (string)$task->getUuid(),
				'runUuid' => $runUuid,
				'nodeId' => $resume->nodeId(),
				'appId' => 'openregister',
			]
		);

		// Written ONCE. A heartbeat re-enters execute() and finds the uuid
		// held, so it never reaches this line again; askedAt therefore records
		// when somebody was first asked, not when the run last checked.
		$resume->merge(
			values: $this->config->slotValues(step: $step, taskUuid: (string)$task->getUuid())
		);

	}//end createTask()

	/**
	 * Arm a business timer for the task this node just created.
	 *
	 * NO-OP UNLESS THE NODE DECLARES AN SLA. A flow that never mentioned one
	 * behaves exactly as before, which is what makes this safe to add to an
	 * engine every app shares.
	 *
	 * 🔴 A FAILURE HERE IS NOT SWALLOWED. If an SLA is declared and cannot be
	 * armed, the task has no deadline, and for a `wettelijk` term that is a
	 * legal defect rather than something to log and carry on from. Letting it
	 * propagate is also safe for existing flows precisely because they do not
	 * reach this branch.
	 *
	 * Bound to the TASK, matching what FlowTimerSubjectTerminalListener already
	 * cancels: `subjectType: 'task'` with the task's uuid. The run and node are
	 * recorded as provenance, which is what the timer model asks for — a timer
	 * is subject-bound, and its run is optional context.
	 *
	 * @param UserTaskStep         $step    The node configuration and execution context.
	 * @param array<string, mixed> $binding The timer's subject (the task just created) and provenance (run, node, app).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	private function armDeadline(UserTaskStep $step, array $binding): void {
		$config = $step->config;
		if ($this->declaresSla(config: $config) === false) {
			return;
		}

		$timerConfig = $binding + ['title' => $this->timerTitle(config: $config), 'sla' => $config['sla']]
			+ $this->optionalTimerKeys(config: $config);

		$this->timers->arm(config: $timerConfig, act: FlowTimerAct::of(actor: $this->actingIdentity(context: $step->context)));

	}//end armDeadline()

	/**
	 * Whether the node declares an SLA at all: absent, null, '' and [] do not.
	 *
	 * @param array<string, mixed> $config The node configuration.
	 *
	 * @return bool True when a deadline should be armed.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	private function declaresSla(array $config): bool {
		$sla = ($config['sla'] ?? null);
		return $sla !== null && $sla !== '' && $sla !== [];
	}//end declaresSla()

	/**
	 * The timer's title: the node's trimmed title, or null when it has none.
	 *
	 * @param array<string, mixed> $config The node configuration.
	 *
	 * @return string|null The title.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	private function timerTitle(array $config): ?string {
		$title = trim((string)($config['title'] ?? ''));
		if ($title === '') {
			return null;
		}

		return $title;
	}//end timerTitle()

	/**
	 * The optional timer keys the author actually set.
	 *
	 * Only forward what the author actually set: `build()` applies its own
	 * defaults, and passing an explicit null would override them.
	 *
	 * @param array<string, mixed> $config The node configuration.
	 *
	 * @return array<string, mixed> The keys that are present, not null and not ''.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	private function optionalTimerKeys(array $config): array {
		$set = [];
		foreach (['calendar', 'ladder', 'escalationRules', 'purpose', 'legalEffect', 'onExpiry', 'organisation'] as $key) {
			if (array_key_exists($key, $config) === true && $config[$key] !== null && $config[$key] !== '') {
				$set[$key] = $config[$key];
			}
		}

		return $set;
	}//end optionalTimerKeys()

	/**
	 * Write the outcome bag onto every item, under the configured key.
	 *
	 * Into the item's record (`json`), not beside it: the steps that follow
	 * route per item and read `json.<key>`, and a Switch cannot branch on
	 * something only the run holds. Non-array items are left alone rather
	 * than failing the run.
	 *
	 * @param UserTaskStep         $step The items to pass on and the step configuration.
	 * @param array<string, mixed> $bag  The outcome bag.
	 *
	 * @return array The items, each carrying the bag.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
	 */
	private function placeOutcome(UserTaskStep $step, array $bag): array {
		return (new EveryItemField(key: $this->config->outcomeKey(config: $step->config), value: $bag))->placedOn(items: $step->items);
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
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-run-continues-on-task-terminality-never-on-a-nudge
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
	 * What the task is.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whatFields(): array {
		return [
			$this->requiredField(field: new NodeFormField(
				key: 'title', label: $this->l10n->t('What is being asked'), type: 'text', help: $this->l10n->t(
				'The task title, shown in the inbox. Fields of the item can be used, like {{ name }}.'
			))),
			$this->field(field: new NodeFormField(key: 'description', label: $this->l10n->t('Details'), type: 'textarea', help: $this->l10n->t(
				'What the performer needs to know to do it. Templates work here too.'
			))),
			$this->field(field: new NodeFormField(key: 'outcomes', label: $this->l10n->t('Possible outcomes'), type: 'text', help: $this->l10n->t(
				'The answers the flow will route on, comma separated, like "approved, rejected". Recorded on the task for the inbox to offer.'
			))),
		];
	}//end whatFields()

	/**
	 * Where the task is hung: the object it shows on.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function attachFields(): array {
		return [
			$this->field(field: new NodeFormField(key: 'attachTo', label: $this->l10n->t('Hang this task on'), type: 'text', help: $this->l10n->t(
				'The name an earlier step recorded its object under, such as "case". The task then shows on that object. '
				.'Leave it empty and the task stays with the item this step received. '
				.'A name no earlier step recorded fails the step rather than making a task attached to nothing.'
			))),
		];
	}//end attachFields()

	/**
	 * Who may perform it.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whoFields(): array {
		return [
			// 🔑 `principal`, not `text`. The field always accepted a
			// person OR a group and could only ever say one of them, so
			// authors typed a committee's name into a box labelled for a
			// user and nothing recorded which they meant.
			$this->field(field: new NodeFormField(
				key: 'assignee', label: $this->l10n->t('Assign directly to'), type: 'principal', help: $this->l10n->t(
				'A person, a group, or an agent. The task is created active for them; leave empty to offer it to a pool instead.'
			))),
			$this->field(field: new NodeFormField(key: 'candidates', label: $this->l10n->t('Candidates'), type: 'principal', help: $this->l10n->t(
				'Anybody who may claim the task. People and groups may be mixed.'
			))),
			$this->field(field: new NodeFormField(key: 'prompt', label: $this->l10n->t('Prompt for an agent'), type: 'textarea', help: $this->l10n->t(
				'What an agent performer is asked, in place of a form. Ignored when a person is asked.'
			))),
		];
	}//end whoFields()

	/**
	 * The candidate fields kept for flows written before Candidates existed.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function legacyCandidateFields(): array {
		return [
			$this->field(field: new NodeFormField(
				key: 'candidateUsers', label: $this->l10n->t('Candidate users'), type: 'principal', help: $this->l10n->t(
				'Kept for flows written before Candidates existed. Any of them may claim the task.'
			))),
			$this->field(field: new NodeFormField(
				key: 'candidateGroups', label: $this->l10n->t('Candidate groups'), type: 'principal', help: $this->l10n->t(
				'Kept for flows written before Candidates existed. Any member may claim the task.'
			))),
			$this->field(field: new NodeFormField(
				key: 'candidateRole', label: $this->l10n->t('Candidate role'), type: 'principal', help: $this->l10n->t(
				'A role that resolves to a group of performers.'
			))),
		];
	}//end legacyCandidateFields()

	/**
	 * How a performer is picked from the pool, and who gets it when nobody is.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function routingFields(): array {
		return [
			$this->field(field: new NodeFormField(
				key: 'routingStrategy', label: $this->l10n->t('How to pick a performer'), type: 'text', help: $this->l10n->t(
				'One of single-role, or-set, hierarchical, round-robin or least-loaded. Leave empty to let the pool claim.'
			))),
			$this->field(field: new NodeFormField(
				key: 'routingFallback', label: $this->l10n->t('Fallback performer'), type: 'principal', help: $this->l10n->t(
				'Who gets the task when the strategy finds nobody.'
			))),
		];
	}//end routingFields()

	/**
	 * How urgent it is, and when.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whenFields(): array {
		return [
			$this->field(field: new NodeFormField(key: 'priority', label: $this->l10n->t('Priority'), type: 'text', help: $this->l10n->t(
				'low, normal, high or urgent. Defaults to normal.'
			))),
			$this->field(field: new NodeFormField(key: 'dueAt', label: $this->l10n->t('Due'), type: 'text', help: $this->l10n->t(
				'When the task should be done: a date, a field like {{ deadline }}, or a relative time like "+3 days". Advisory.'
			))),
			$this->field(field: new NodeFormField(key: 'expiresAt', label: $this->l10n->t('Expires'), type: 'text', help: $this->l10n->t(
				'When the task stops being doable. Same shapes as "Due". Must not lie before it.'
			))),
		];
	}//end whenFields()

	/**
	 * What a deadline or a rejection does, and how often the run re-checks.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function timeoutFields(): array {
		return $this->timeoutFieldsWith(onTimeoutHelp: $this->l10n->t(
			'What happens when the task expires: skip, error or dead_letter. Empty means the deadline enforces nothing.'
		));
	}//end timeoutFields()

	/**
	 * How the flow continues once answered.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function continuationFields(): array {
		return [
			$this->field(field: new NodeFormField(
				key: 'outcomeKey', label: $this->l10n->t('Field to store the answer in'), type: 'text', help: $this->l10n->t(
				'The outcome is written onto every item under this field, so later steps can route on it. Defaults to "task".'
			))),
			$this->field(field: new NodeFormField(
				key: 'failOnReject', label: $this->l10n->t('Treat a rejection as a failure'), type: 'boolean', help: $this->l10n->t(
				'Off by default: being told "no" is usually the flow working, not breaking. Route on the outcome instead.'
			))),
			$this->field(field: new NodeFormField(
				key: 'advance', label: $this->l10n->t('Continue after the answer'), type: 'text', help: $this->l10n->t(
				'How far the run continues inside the request that completes the task: 0 leaves it to the background worker (default), '
				. 'a number runs that many steps, "all" runs to the next pause or the end.'
			))),
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
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
	 */
	private function formFields(): array {
		return [
			$this->field(field: new NodeFormField(key: 'formKind', label: $this->l10n->t('Form'), type: 'text', help: $this->l10n->t(
				'What the performer fills in besides the outcome: leave empty for none, "fields" for fields of the subject object, '
				. '"external" for a Nextcloud Forms form bound to the subject. Fields are validated by the subject schema; '
				. 'an external form is recorded as evidence and writes nothing to the object.'
			))),
		];
	}//end formFields()

	/**
	 * The schema whose fields a field form asks for.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function formSchemaFields(): array {
		return [
			[
				'key' => 'formSchema',
				'label' => $this->l10n->t('Subject schema'),
				'type' => 'select',
				'optionsFrom' => '/apps/openregister/api/schemas',
				'help' => $this->l10n->t('The schema the fields belong to. Required for a field form.'),
			],
		];
	}//end formSchemaFields()

	/**
	 * Which fields of the subject the performer supplies.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function formContentFields(): array {
		return [
			$this->field(field: new NodeFormField(key: 'formAction', label: $this->l10n->t('Lifecycle action'), type: 'text', help: $this->l10n->t(
				'Inherit the fields a lifecycle transition of the subject schema declares, and apply that transition on completion. '
				. 'Leave empty to list fields instead.'
			))),
			$this->field(field: new NodeFormField(key: 'formFields', label: $this->l10n->t('Fields to ask for'), type: 'text', help: $this->l10n->t(
				'Properties of the subject schema the performer supplies, comma separated; add * after a name to make it required, '
				. 'like "reason*". A name the schema does not have is refused when the step is saved. Not combined with a lifecycle action.'
			))),
		];
	}//end formContentFields()

	/**
	 * The Nextcloud Forms form and its checklist rule.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function externalFormFields(): array {
		return [
			$this->field(field: new NodeFormField(key: 'formId', label: $this->l10n->t('Nextcloud Forms form'), type: 'number', help: $this->l10n->t(
				'The id of the Forms form linked to the subject object. Needs the Forms app.'
			))),
			$this->field(field: new NodeFormField(
				key: 'formRequireChecklist',
				label: $this->l10n->t('Require every checklist item checked'),
				type: 'boolean',
				help: $this->l10n->t(
					'Refuse completion while a checklist item is unchecked, naming it. Checklist state is never part of the form values.'
				)
			)),
		];
	}//end externalFormFields()

	/**
	 * What kind of step this is. A person is asked, and the run waits.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_USER_TASK;

	}//end getKind()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_HUMAN;

	}//end getCategory()
}//end class
