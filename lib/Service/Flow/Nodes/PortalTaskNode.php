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
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
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
class PortalTaskNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The configuration boundary: validation and templating.
	 *
	 * @var PortalTaskConfig
	 */
	private readonly PortalTaskConfig $config;

	/**
	 * Constructor.
	 *
	 * @param FlowTaskBridge $bridge Creates and reads the node's task.
	 * @param PortalPartyResolver $parties Resolves the party role against the case.
	 * @param PortalTaskDeliveryService $delivery Records the ask for the portal seam.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
	 */
	public function __construct(
		private readonly FlowTaskBridge $bridge,
		private readonly PortalPartyResolver $parties,
		private readonly PortalTaskDeliveryService $delivery,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
		$this->config = new PortalTaskConfig(l10n: $l10n);

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
			'title',
			'description',
			'partyRole',
			'uploadRequired',
			'uploadMaxFiles',
			'uploadAcceptedTypes',
			'uploadMaxSizeMb',
			'outcomeKey',
			'reasonField',
			'dueAt',
			'expiresAt',
			'heartbeatMinutes',
			'advance',
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
			$this->whenFields(),
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
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying the outcome bag.
	 *
	 * @throws FlowSuspension While the task is not terminal.
	 * @throws RuntimeException When the node has no slot, its task is gone,
	 *                          the case names nobody, or a re-ask has no reason.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
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
			throw new RuntimeException('openregister.portal-task needs a node resume slot; without one every heartbeat would create a task.');
		}

		$taskUuid = trim((string)$resume->get(key: FlowTaskBridge::SLOT_TASK_UUID, default: ''));
		if ($taskUuid === '') {
			$this->ask(items: $items, config: $config, context: $context, resume: $resume, cycle: 1, previousTaskUuid: null, reason: null);

			throw $this->suspension(config: $config, items: $items);
		}

		$task = $this->bridge->taskOrNull(uuid: $taskUuid);
		if ($task === null) {
			throw new RuntimeException(sprintf('Task %s, which this step was waiting on, no longer exists.', $taskUuid));
		}

		if ($task->isInTerminalState() === false) {
			// A heartbeat wake, a reminder, a nudge: none is an answer. Suspend
			// again and do NOT touch the slot: askedAt stays what it was.
			throw $this->suspension(config: $config, items: $items);
		}

		if ($resume->get(key: PortalTaskConfig::SLOT_PASSED_AT, default: null) === null) {
			// The first pass over a terminal task: the answer travels on. Marked
			// ONCE, so the next firing of this node in this run is a re-entry.
			$resume->set(key: PortalTaskConfig::SLOT_PASSED_AT, value: (new DateTime())->format('c'));

			return $this->placeOutcome(items: $items, config: $config, task: $task);
		}

		// Re-entry: the graph routed back into this node after it continued.
		$reason = $this->config->reasonFrom(config: $config, items: $items);
		if ($reason === '') {
			throw new RuntimeException(
				sprintf(
					'openregister.portal-task re-entered after task %s ended, but the items carry no reason under "%s"; '
					. 'a party is not asked the same thing twice without an explanation.',
					$taskUuid,
					$this->config->reasonField(config: $config)
				)
			);
		}

		$cycle = ((int)$resume->get(key: PortalTaskConfig::SLOT_CYCLE, default: 1) + 1);
		$this->ask(items: $items, config: $config, context: $context, resume: $resume, cycle: $cycle, previousTaskUuid: $taskUuid, reason: $reason);

		throw $this->suspension(config: $config, items: $items);
	}//end execute()

	/**
	 * One ask: match the party on the case, create the external task, record
	 * the match in its audit, request delivery, and remember it in the slot.
	 *
	 * @param array $items The input items; the first is the representative.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 * @param FlowNodeResumeState $resume This node's slot.
	 * @param int $cycle This ask's cycle number.
	 * @param string|null $previousTaskUuid The previous cycle's task, on a re-ask.
	 * @param string|null $reason The re-ask reason, on a re-ask.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the run has no uuid, the item names no
	 *                          case, or the case names nobody for the role.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	private function ask(
		array $items,
		array $config,
		array $context,
		FlowNodeResumeState $resume,
		int $cycle,
		?string $previousTaskUuid,
		?string $reason,
	): void {
		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			throw new RuntimeException('openregister.portal-task cannot create a task outside a persisted run: the task must carry the run uuid.');
		}

		$caseUuid = $this->config->subjectObjectUuid(items: $items);
		if ($caseUuid === '') {
			throw new RuntimeException(
				'openregister.portal-task needs the item to be about a case object (@self.uuid); there is no case to match a party on.'
			);
		}

		// Matched ONCE, here. PortalPartyNotFoundException is a RuntimeException
		// naming the role and the case, and it fails the firing: an ask nobody
		// can perform must not be parked in a suspended run.
		$role = $this->config->partyRole(config: $config);
		$party = $this->parties->resolveFromObject(objectUuid: $caseUuid, role: $role);

		$actor = $this->actingIdentity(context: $context);
		$task = $this->bridge->createTask(
			data: $this->config->taskData(
				config: $config,
				items: $items,
				nodeId: $resume->nodeId(),
				nodeType: $this->getId(),
				partyReference: $party,
				cycle: $cycle,
				previousTaskUuid: $previousTaskUuid,
				reason: $reason
			),
			runUuid: $runUuid,
			nodeId: $resume->nodeId(),
			actor: $actor
		);

		$this->bridge->record(
			uuid: (string)$task->getUuid(),
			action: 'match',
			actor: $actor,
			reason: sprintf("Matched party role '%s' on case '%s' to '%s' (cycle %d).", $role, $caseUuid, $party, $cycle)
		);

		$kind = PortalTaskDelivery::KIND_ASK;
		if ($cycle > 1) {
			$kind = PortalTaskDelivery::KIND_RE_ASK;
		}

		// Never throws: a delivery that cannot be recorded leaves the task and
		// the suspension standing, and its state reads not-recorded.
		$this->delivery->request(task: $task, kind: $kind, message: $this->delivery->messageFor(task: $task, reason: $reason));

		$resume->merge(
			values: $this->config->slotValues(
				config: $config,
				items: $items,
				taskUuid: (string)$task->getUuid(),
				partyReference: $party,
				cycle: $cycle,
				previousTaskUuid: $previousTaskUuid
			)
		);

	}//end ask()

	/**
	 * Write the outcome bag onto every item, under the configured key.
	 *
	 * The bridge's bag, extended with what a portal answer carries: the
	 * submitted fields, the stored file references, the matched party, the
	 * cycle, and an explicit `expired` flag so an expiry-terminated ask is
	 * never read as an answer downstream.
	 *
	 * @param array $items The items to pass on.
	 * @param array $config The step configuration.
	 * @param Task $task The terminal task.
	 *
	 * @return array The items, each carrying the bag.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	private function placeOutcome(array $items, array $config, Task $task): array {
		$bag = FlowTaskBridge::outcomeBagFor(task: $task);
		$metadata = ($task->getMetadata() ?? []);
		$bag['answers'] = ($task->getResponses() ?? []);
		$bag['files'] = ($task->getEvidence() ?? []);
		$bag['party'] = $task->getAssignee();
		$bag['cycle'] = (int)($metadata['cycle'] ?? 1);
		$bag['reason'] = ($metadata['reaskReason'] ?? null);
		$bag['expired'] = ($bag['decided'] === false && str_starts_with((string)$bag['outcome'], 'expir') === true);

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
	 * The suspension this node parks on: a non-null heartbeat, and a reason
	 * that names what is being waited for.
	 *
	 * @param array $config The step configuration.
	 * @param array $items The input items.
	 *
	 * @return FlowSuspension The suspension to throw.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-suspension-is-heartbeat-safe-and-continues-on-task-terminality
	 */
	private function suspension(array $config, array $items): FlowSuspension {
		$title = $this->config->renderedTitle(config: $config, items: $items);
		if ($title === '') {
			$title = 'a portal task';
		}

		return new FlowSuspension(
			resumeAt: $this->config->heartbeatAt(config: $config),
			reason: sprintf('waiting for a party outside the organisation: %s', $title)
		);
	}//end suspension()

	/**
	 * The run's acting identity: who the task is requested by.
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
				'help' => $this->l10n->t('The task title, shown in the portal. Fields of the item can be used, like {{ name }}.'),
				'required' => true,
			],
			[
				'key' => 'description',
				'label' => $this->l10n->t('Details'),
				'type' => 'textarea',
				'help' => $this->l10n->t('What the party needs to know to do it. Templates work here too.'),
			],
		];
	}//end whatFields()

	/**
	 * Who on the case is asked.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whoFields(): array {
		return [
			[
				'key' => 'partyRole',
				'label' => $this->l10n->t('Party role on the case'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Which party on the case is asked, by role. Defaults to "initiator". The party is matched once, when the task is created, and a case that names nobody for the role fails the step.'
				),
			],
		];
	}//end whoFields()

	/**
	 * What they may hand in.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function uploadFields(): array {
		return [
			[
				'key' => 'uploadRequired',
				'label' => $this->l10n->t('Upload required'),
				'type' => 'boolean',
				'help' => $this->l10n->t('When on, the party must hand in at least one file. Every file lands on the case object.'),
			],
			[
				'key' => 'uploadMaxFiles',
				'label' => $this->l10n->t('Maximum number of files'),
				'type' => 'number',
				'help' => $this->l10n->t('How many files one answer may carry. Defaults to 1.'),
			],
			[
				'key' => 'uploadAcceptedTypes',
				'label' => $this->l10n->t('Accepted file types'),
				'type' => 'text',
				'help' => $this->l10n->t('Media types or extensions, comma separated, like "application/pdf, image/*, docx". Leave empty to accept any type.'),
			],
			[
				'key' => 'uploadMaxSizeMb',
				'label' => $this->l10n->t('Maximum file size (MB)'),
				'type' => 'number',
				'help' => $this->l10n->t('Per file. Leave empty for the instance default.'),
			],
		];
	}//end uploadFields()

	/**
	 * When.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function whenFields(): array {
		return [
			[
				'key' => 'dueAt',
				'label' => $this->l10n->t('Due'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'When the party should have answered: a date, a field like {{ deadline }}, or a relative time like "+14 days". Reminders are business timer rungs on this date.'
				),
			],
			[
				'key' => 'expiresAt',
				'label' => $this->l10n->t('Expires'),
				'type' => 'text',
				'help' => $this->l10n->t('When the ask stops being answerable. Same shapes as "Due". Expiry is enforced by the business timers, not by this step.'),
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
	 * How the flow continues once answered, and how it asks again.
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
					'The answer, the uploaded files and the matched party are written onto every item under this field. Defaults to "portalTask".'
				),
			],
			[
				'key' => 'reasonField',
				'label' => $this->l10n->t('Field carrying the reason to ask again'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'When the flow routes back into this step, the party is asked again with the reason read from this item field, like "review.comment". Without a reason the step fails rather than asking twice unexplained. Defaults to "reason".'
				),
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
}//end class
