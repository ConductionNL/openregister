<?php

/**
 * Puts a party OUTSIDE the instance into the graph: ask them through the
 * portal, and wait for the answer.
 *
 * THE THREE WAITERS, AND THE LINE BETWEEN THEM. Three nodes pause a run until
 * something outside it happens, and an author picks the wrong one unless the
 * division is stated once, as a set. A SIGNAL (`openregister.await-signal`)
 * is for a system that will call back: a payment provider, a webhook, a child
 * run. A USER TASK (`openregister.user-task`) is for a performer in the
 * organisation: somebody with an account, who has to be found, told, and
 * allowed to say no. A PORTAL TASK (this node) is for a party outside it: the
 * resident, applicant or supplier who authenticates at portaliq's edge and
 * will never hold a Nextcloud account. Using the wrong node fails visibly: a
 * user task for a resident has no performable candidate, and a portal task
 * for an employee never reaches an inbox.
 *
 * WHY A SEPARATE NODE AND NOT A MODE (design D-1). The performer resolution
 * (a party role on the case, not a uid), the delivery channel (the portal
 * seam, not a Nextcloud inbox) and the completion payload (an upload that
 * lands on the case) all differ from the user task's, and a mode flag would
 * make half of each node's config keys invalid depending on the flag. The
 * shared mechanics (suspension, terminality read, outcome placement,
 * cancellation propagation) are REUSED through {@see FlowTaskBridge}, not
 * copied.
 *
 * THE MATCH IS FROZEN (design D-3). The party role is resolved against the
 * case object ONCE, when the task is created, and the reference is stored on
 * the task and recorded in its audit. A later edit to the case's party data
 * moves nothing; correcting a wrong match is a cancel or a re-ask, each of
 * which is a new task with a new match and a new audit entry.
 *
 * 🔴 THE HEARTBEAT IS NEVER NULL, AND HERE THE WAITS ARE LONGER.
 * `FlowRunMapper::findAbandonedSignals()` reaps suspended runs with
 * `resume_at IS NULL` after fourteen days and FAILS them. A hersteltermijn is
 * measured in weeks. A portal task parked on null would hand the reaper the
 * exact runs this node exists to hold open. The thing that ends an unanswered
 * ask is `expires_at` enforcement in flow-business-timers, never the reaper.
 *
 * RE-ASK IS GRAPH RE-ENTRY (design D-6). When the flow routes back into this
 * node after its task went terminal and the node already continued past it,
 * the node creates a NEW task carrying a MANDATORY reason read from a
 * configured item field, increments the cycle, records the previous task's
 * uuid, and delivers again. Terminality of the slot task plus the
 * "passed" marker is what tells a re-ask from a heartbeat and from a
 * duplicate, so idempotence and looping use one mechanism.
 *
 * NO CLOCK LIVES HERE (design D-8). The node passes `dueAt` and `expiresAt`
 * through to the task; reminders, escalation and expiry enforcement are
 * flow-business-timers' rungs, consumed by a listener on the timer's fired
 * event, never computed in this class.
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
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskAuditEntry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;

/**
 * Matches the party, creates one external task, delivers it, suspends until
 * it is terminal, places the answer, and re-asks with a reason on re-entry.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowTaskBridge::outcomeBagFor is a
 * stateless helper over a value; a factory to call it would add a dependency
 * to say the same thing.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The node joins the graph
 * side (items, resume state, suspension, bridge, the three node interfaces)
 * to the portal side (party resolver, delivery seam); every import is one of
 * the two halves it exists to connect.
 */
class PortalTaskNode implements IFlowNode, PortalTaskNodeInterface, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {
	use TaskNodeTrait;

	/**
	 * The configuration boundary: validation and templating.
	 *
	 * @var PortalTaskConfig
	 */
	private readonly PortalTaskConfig $config;

	/**
	 * Creates and reads the node's task.
	 *
	 * @var FlowTaskBridge
	 */
	private readonly FlowTaskBridge $bridge;

	/**
	 * Resolves the party role against the case.
	 *
	 * @var PortalPartyResolver
	 */
	private readonly PortalPartyResolver $parties;

	/**
	 * Records the ask for the portal seam.
	 *
	 * @var PortalTaskDeliveryService
	 */
	private readonly PortalTaskDeliveryService $delivery;

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
	 * @param PortalTaskServices   $services     The task bridge, party resolver and delivery seam.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function __construct(
		PortalTaskServices $services,
		FlowNodePresentation $presentation,
	) {
		$this->bridge   = $services->bridge;
		$this->parties  = $services->parties;
		$this->delivery = $services->delivery;
		$this->l10n     = $presentation->l10n;
		$this->urls     = $presentation->urls;
		$this->config   = new PortalTaskConfig(l10n: $this->l10n);

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function getId(): string {
		return 'openregister.portal-task';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Ask a party outside the organisation');
	}//end getDisplayName()

	/**
	 * Palette description, written as the third of the waiter set.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Ask the applicant or another party on the case, through the portal, and wait for their answer or upload. '
			. 'For someone in the organisation use "Ask a person"; for a system that will call back use "Wait for an answer".'
		);
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/share.svg');
	}//end getIcon()

	/**
	 * Asking somebody grants no privilege; the seam authorizes the answer.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a portal-task step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function configKeys(): array {
		return [
			'title', 'description', 'partyRole',
			'uploadRequired', 'uploadMaxFiles', 'uploadAcceptedTypes', 'uploadMaxSizeMb',
			'outcomeKey', 'reasonField', 'dueAt', 'expiresAt', 'onTimeout', 'onReject', 'heartbeatMinutes', 'advance',
		];
	}//end configKeys()

	/**
	 * The fields this node is edited through: what is asked, who on the case
	 * is asked, what they may hand in, when, and how the flow continues.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function configForm(): array {
		return array_merge(
			$this->whatFields(),
			$this->whoFields(),
			$this->uploadFields(),
			$this->uploadLimitFields(),
			$this->whenFields(),
			$this->timeoutFields(),
			$this->continuationFields()
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
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function validateConfig(array $config): void {
		$this->config->validate(config: $config);

	}//end validateConfig()

	/**
	 * Match, create and deliver on the first pass; suspend until the task
	 * ends; place the answer once; re-ask with a reason on re-entry.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each carrying the outcome bag.
	 *
	 * @throws FlowSuspension While the task is not terminal.
	 * @throws RuntimeException When the node has no slot, its task is gone,
	 *                          the case names nobody, or a re-ask has no reason.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
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

		return $this->pass(round: new PortalTaskRound(items: $items, config: $config), context: $context);
	}//end execute()

	/**
	 * One pass over a non-empty branch: ask, wait, place the answer, or re-ask.
	 *
	 * @param PortalTaskRound      $round   The ask: its items and configuration.
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return array The items, each carrying the outcome bag.
	 *
	 * @throws FlowSuspension While the task is not terminal.
	 * @throws RuntimeException When the node has no slot, its task is gone,
	 *                          the case names nobody, or a re-ask has no reason.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	private function pass(PortalTaskRound $round, array $context): array {
		$resume = $this->resumeSlot(context: $context);
		$round = $round->atNode(nodeId: $resume->nodeId());
		if ($this->taskUuidIn(resume: $resume) === '') {
			$resume->merge(values: $this->ask(round: $round, context: $context));

			throw $this->suspension(round: $round);
		}

		if ($resume->get(key: PortalTaskConfig::SLOT_PASSED_AT, default: null) === null) {
			return $this->firstPass(round: $round, context: $context);
		}

		throw $this->reAsk(round: $round, context: $context);
	}//end pass()

	/**
	 * The node's resume slot, which a portal task cannot run without.
	 *
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return FlowNodeResumeState The slot.
	 *
	 * @throws RuntimeException When the run gave the node no slot.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	private function resumeSlot(array $context): FlowNodeResumeState {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === false) {
			throw new RuntimeException('openregister.portal-task needs a node resume slot; without one every heartbeat would create a task.');
		}

		return $resume;
	}//end resumeSlot()

	/**
	 * The task uuid this node's slot holds, empty before the first ask.
	 *
	 * @param FlowNodeResumeState $resume The node's slot.
	 *
	 * @return string The task uuid, or ''.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	private function taskUuidIn(FlowNodeResumeState $resume): string {
		return trim((string)$resume->get(key: FlowTaskBridge::SLOT_TASK_UUID, default: ''));
	}//end taskUuidIn()

	/**
	 * The first pass over a terminal task: the answer travels on.
	 *
	 * @param PortalTaskRound      $round   The ask, at this node.
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return array The items, each carrying the outcome bag.
	 *
	 * @throws FlowSuspension   While the task is not terminal.
	 * @throws RuntimeException When the task is gone.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	private function firstPass(PortalTaskRound $round, array $context): array {
		$resume = $this->resumeSlot(context: $context);
		$task = $this->awaitedTask(round: $round, taskUuid: $this->taskUuidIn(resume: $resume));
		$this->recordRecoveryUnlessSignalled(task: $task, context: $context);

		// Marked ONCE, so the next firing of this node in this run is a re-entry.
		$resume->set(key: PortalTaskConfig::SLOT_PASSED_AT, value: (new DateTime())->format('c'));

		return $this->placeOutcome(round: $round, task: $task);
	}//end firstPass()

	/**
	 * Re-entry: the graph routed back into this node after it continued, so
	 * the party is asked again, with a reason, in the next cycle.
	 *
	 * @param PortalTaskRound      $round   The ask, at this node.
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return FlowSuspension The suspension to throw once the new task is asked.
	 *
	 * @throws FlowSuspension   While the previous task is not terminal.
	 * @throws RuntimeException When the task is gone or the items carry no reason.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	private function reAsk(PortalTaskRound $round, array $context): FlowSuspension {
		$resume = $this->resumeSlot(context: $context);
		$taskUuid = $this->taskUuidIn(resume: $resume);
		$this->awaitedTask(round: $round, taskUuid: $taskUuid);
		$reason = $this->reasonForReAsk(round: $round, taskUuid: $taskUuid);

		$cycle = ((int)$resume->get(key: PortalTaskConfig::SLOT_CYCLE, default: 1) + 1);
		$round = $round->inCycle(cycle: $cycle, previousTaskUuid: $taskUuid)->because(reason: $reason);
		$resume->merge(values: $this->ask(round: $round, context: $context));

		return $this->suspension(round: $round);
	}//end reAsk()

	/**
	 * The task this step is waiting on, once it is terminal.
	 *
	 * @param PortalTaskRound $round    The ask: its items and configuration.
	 * @param string          $taskUuid The task held in this node's slot.
	 *
	 * @return Task The terminal task.
	 *
	 * @throws RuntimeException When the task no longer exists.
	 * @throws FlowSuspension   While the task is not terminal.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	private function awaitedTask(PortalTaskRound $round, string $taskUuid): Task {
		$task = $this->bridge->taskOrNull(uuid: $taskUuid);
		if ($task === null) {
			throw new RuntimeException(sprintf('Task %s, which this step was waiting on, no longer exists.', $taskUuid));
		}

		if ($task->isInTerminalState() === false) {
			// A heartbeat wake, a reminder, a nudge: none is an answer. Suspend
			// again and do NOT touch the slot: askedAt stays what it was.
			throw $this->suspension(round: $round);
		}

		return $task;
	}//end awaitedTask()

	/**
	 * Audit a heartbeat-recovered answer on the task.
	 *
	 * A terminal read with no signal in hand means the completion's wake never
	 * arrived — refused or lost — and the heartbeat is what recovered it.
	 * Recorded before the outcome is applied, which is identical on both
	 * paths; the user-task node makes the same call.
	 *
	 * @param Task                 $task    The terminal task.
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	private function recordRecoveryUnlessSignalled(Task $task, array $context): void {
		if (array_key_exists(FlowRunServiceInterface::SIGNAL_CONTEXT_KEY, $context) === false) {
			$this->bridge->recordHeartbeatRecovery(task: $task);
		}

	}//end recordRecoveryUnlessSignalled()

	/**
	 * The reason a re-entered step asks the party again.
	 *
	 * @param PortalTaskRound $round    The ask: its items and configuration.
	 * @param string          $taskUuid The task that already ended.
	 *
	 * @return string The non-empty reason.
	 *
	 * @throws RuntimeException When the items carry no reason.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-re-ask-creates-a-new-task-carrying-a-mandatory-reason
	 */
	private function reasonForReAsk(PortalTaskRound $round, string $taskUuid): string {
		$reason = $this->config->reasonFrom(config: $round->config, items: $round->items);
		if ($reason === '') {
			throw new RuntimeException(
				sprintf(
					'openregister.portal-task re-entered after task %s ended, but the items carry no reason under "%s"; '
					. 'a party is not asked the same thing twice without an explanation.',
					$taskUuid,
					$this->config->reasonField(config: $round->config)
				)
			);
		}

		return $reason;
	}//end reasonForReAsk()

	/**
	 * One ask: match the party on the case, create the external task, record
	 * the match in its audit, and request delivery.
	 *
	 * @param PortalTaskRound $round The ask: items, configuration, node, cycle
	 *                               and, on a re-ask, previous task and reason.
	 * @param array $context Run-level metadata.
	 *
	 * @return array<string, mixed> The values this node's slot remembers.
	 *
	 * @throws RuntimeException When the run has no uuid, the item names no
	 *                          case, or the case names nobody for the role.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	private function ask(PortalTaskRound $round, array $context): array {
		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			throw new RuntimeException('openregister.portal-task cannot create a task outside a persisted run: the task must carry the run uuid.');
		}

		$caseUuid = $this->config->subjectObjectUuid(items: $round->items);
		if ($caseUuid === '') {
			throw new RuntimeException(
				'openregister.portal-task needs the item to be about a case object (@self.uuid); there is no case to match a party on.'
			);
		}

		// Matched ONCE, here. PortalPartyNotFoundException is a RuntimeException
		// naming the role and the case, and it fails the firing: an ask nobody
		// can perform must not be parked in a suspended run.
		$role = $this->config->partyRole(config: $round->config);
		$round = $round->withParty(partyReference: $this->parties->resolveFromObject(objectUuid: $caseUuid, role: $role));

		$actor = $this->actingIdentity(context: $context);
		$task = $this->bridge->createTask(
			data: $this->config->taskData(round: $round, nodeType: $this->getId()),
			runUuid: $runUuid,
			nodeId: $round->nodeId(),
			actor: $actor
		);

		$this->bridge->record(
			entry: new TaskAuditEntry(
				uuid: (string)$task->getUuid(),
				action: 'match',
				actor: $actor,
				reason: sprintf("Matched party role '%s' on case '%s' to '%s' (cycle %d).", $role, $caseUuid, $round->partyReference(), $round->cycle())
			)
		);

		$this->deliver(round: $round, task: $task);

		return $this->config->slotValues(round: $round, taskUuid: (string)$task->getUuid());
	}//end ask()

	/**
	 * Request delivery of a new task: an ask on the first cycle, a re-ask after.
	 *
	 * Never throws: a delivery that cannot be recorded leaves the task and the
	 * suspension standing, and its state reads not-recorded.
	 *
	 * @param PortalTaskRound $round The ask.
	 * @param Task $task The created task.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	private function deliver(PortalTaskRound $round, Task $task): void {
		$kind = PortalTaskDelivery::KIND_ASK;
		if ($round->cycle() > 1) {
			$kind = PortalTaskDelivery::KIND_RE_ASK;
		}

		$this->delivery->request(task: $task, kind: $kind, message: $this->delivery->messageFor(task: $task, reason: $round->reason()));
	}//end deliver()

	/**
	 * Write the outcome bag onto every item, under the configured key.
	 *
	 * The bridge's bag, extended with what a portal answer carries: the
	 * submitted fields, the stored file references, the matched party, the
	 * cycle, and an explicit `expired` flag so an expiry-terminated ask is
	 * never read as an answer downstream.
	 *
	 * @param PortalTaskRound $round The ask: the items to pass on and the configuration.
	 * @param Task            $task  The terminal task.
	 *
	 * @return array The items, each carrying the bag.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	private function placeOutcome(PortalTaskRound $round, Task $task): array {
		$bag = FlowTaskBridge::outcomeBagFor(task: $task);
		$metadata = ($task->getMetadata() ?? []);
		// `answers` now comes from the bridge, which is where it always
		// belonged: this node placing it and the user-task node not placing
		// it is exactly how a form's values could be stored and never read.
		$bag['files'] = ($task->getEvidence() ?? []);
		$bag['party'] = $task->getAssignee();
		$bag['cycle'] = (int)($metadata['cycle'] ?? 1);
		$bag['reason'] = ($metadata['reaskReason'] ?? null);
		$bag['expired'] = ($bag['decided'] === false && str_starts_with((string)$bag['outcome'], 'expir') === true);

		return (new EveryItemField(key: $this->config->outcomeKey(config: $round->config), value: $bag))->placedOn(items: $round->items);
	}//end placeOutcome()

	/**
	 * The suspension this node parks on: a non-null heartbeat, and a reason
	 * that names what is being waited for.
	 *
	 * @param PortalTaskRound $round The ask: its items and configuration.
	 *
	 * @return FlowSuspension The suspension to throw.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	private function suspension(PortalTaskRound $round): FlowSuspension {
		$title = $this->config->renderedTitle(config: $round->config, items: $round->items);
		if ($title === '') {
			$title = 'a portal task';
		}

		return new FlowSuspension(
			resumeAt: $this->config->heartbeatAt(config: $round->config),
			reason: sprintf('waiting for a party outside the organisation: %s', $title)
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
				'The task title, shown in the portal. Fields of the item can be used, like {{ name }}.'
			))),
			$this->field(field: new NodeFormField(key: 'description', label: $this->l10n->t('Details'), type: 'textarea', help: $this->l10n->t(
				'What the party needs to know to do it. Templates work here too.'
			))),
		];
	}//end whatFields()

	/**
	 * Who on the case is asked.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whoFields(): array {
		return [
			$this->field(field: new NodeFormField(
				key: 'partyRole', label: $this->l10n->t('Party role on the case'), type: 'text', help: $this->l10n->t(
				'Which party on the case is asked, by role. Defaults to "initiator". The party is matched once, when the task is created, '
				. 'and a case that names nobody for the role fails the step.'
			))),
		];
	}//end whoFields()

	/**
	 * What they may hand in.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function uploadFields(): array {
		return [
			$this->field(field: new NodeFormField(
				key: 'uploadRequired', label: $this->l10n->t('Upload required'), type: 'boolean', help: $this->l10n->t(
				'When on, the party must hand in at least one file. Every file lands on the case object.'
			))),
			$this->field(field: new NodeFormField(
				key: 'uploadMaxFiles', label: $this->l10n->t('Maximum number of files'), type: 'number', help: $this->l10n->t(
				'How many files one answer may carry. Defaults to 1.'
			))),
		];
	}//end uploadFields()

	/**
	 * Which file types and sizes an answer may carry.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function uploadLimitFields(): array {
		return [
			$this->field(field: new NodeFormField(
				key: 'uploadAcceptedTypes', label: $this->l10n->t('Accepted file types'), type: 'text', help: $this->l10n->t(
				'Media types or extensions, comma separated, like "application/pdf, image/*, docx". Leave empty to accept any type.'
			))),
			$this->field(field: new NodeFormField(
				key: 'uploadMaxSizeMb', label: $this->l10n->t('Maximum file size (MB)'), type: 'number', help: $this->l10n->t(
				'Per file. Leave empty for the instance default.'
			))),
		];
	}//end uploadLimitFields()

	/**
	 * When.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whenFields(): array {
		return [
			$this->field(field: new NodeFormField(key: 'dueAt', label: $this->l10n->t('Due'), type: 'text', help: $this->l10n->t(
				'When the party should have answered: a date, a field like {{ deadline }}, or a relative time like "+14 days". '
				. 'Reminders are business timer rungs on this date.'
			))),
			$this->field(field: new NodeFormField(key: 'expiresAt', label: $this->l10n->t('Expires'), type: 'text', help: $this->l10n->t(
				'When the ask stops being answerable. Same shapes as "Due". Expiry is enforced by the business timers, not by this step.'
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
			'What happens when the ask expires: skip, error or dead_letter. Empty means the deadline enforces nothing.'
		));
	}//end timeoutFields()

	/**
	 * How the flow continues once answered, and how it asks again.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function continuationFields(): array {
		return [
			$this->field(field: new NodeFormField(
				key: 'outcomeKey', label: $this->l10n->t('Field to store the answer in'), type: 'text', help: $this->l10n->t(
				'The answer, the uploaded files and the matched party are written onto every item under this field. Defaults to "portalTask".'
			))),
			$this->field(field: new NodeFormField(
				key: 'reasonField', label: $this->l10n->t('Field carrying the reason to ask again'), type: 'text', help: $this->l10n->t(
				'When the flow routes back into this step, the party is asked again with the reason read from this item field, like "review.comment". '
				. 'Without a reason the step fails rather than asking twice unexplained. Defaults to "reason".'
			))),
			$this->field(field: new NodeFormField(
				key: 'advance', label: $this->l10n->t('Continue after the answer'), type: 'text', help: $this->l10n->t(
				'How far the run continues inside the request that completes the task: 0 leaves it to the background worker (default), '
				. 'a number runs that many steps, "all" runs to the next pause or the end.'
			))),
		];
	}//end continuationFields()

	/**
	 * What kind of step this is. A person is asked through the portal.
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
