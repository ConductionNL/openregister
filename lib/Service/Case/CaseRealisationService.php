<?php

/**
 * Realisation, not execution: what an active plan item turns into.
 *
 * A plan item entering `active` does exactly one of three things (design
 * D-5): a `humanTask` creates a task through the task capability, a `stage`
 * bound to a flow queues a run through `FlowRunService::queue()` against the
 * flow's pinned published version, and a `milestone` does nothing because it
 * never is `active`. This class is the whole of that, plus the two reads the
 * one-directional coupling allows: "how did the realisation end" and "close
 * the realisation because the item was exited".
 *
 * The case layer never advances a marking, never queues a transition and
 * never alters a run's status. Dependency direction enforces it: this class
 * depends on `Service\Flow\FlowRunService`; nothing under `Service\Flow\`
 * depends on `Service\Case\`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskTrigger;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;

/**
 * Creates, reads and closes realisations.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */
class CaseRealisationService {
	/**
	 * The trigger name a stage-queued run carries.
	 */
	public const RUN_TRIGGER = 'case';

	/**
	 * Constructor.
	 *
	 * @param CaseRealisationTasks $tasks The task lifecycle and the task table.
	 * @param CaseRealisationRuns $runs The queue funnel and the run table.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly CaseRealisationTasks $tasks,
		private readonly CaseRealisationRuns $runs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Realise an item that is entering `active`: set `realisation_kind` and
	 * `realisation_uuid` on the (unsaved) row.
	 *
	 * @param CaseItem $item The item entering active.
	 * @param string $actor The identity the realisation is created by.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function realise(CaseItem $item, string $actor): void {
		if ($item->getPlanItemType() === CaseItem::TYPE_HUMAN_TASK) {
			$this->realiseAsTask(item: $item, actor: $actor);

			return;
		}

		$runnable = in_array($item->getPlanItemType(), [CaseItem::TYPE_STAGE, CaseItem::TYPE_PROCESS_TASK], true);
		if ($runnable === true && self::flowOf(item: $item) !== '') {
			$this->realiseAsRun(item: $item, actor: $actor);

			return;
		}

		$item->setRealisationKind(CaseItem::REALISATION_NONE);
		$item->setRealisationUuid(null);
	}//end realise()

	/**
	 * Whether an actor id names a Nextcloud account, as opposed to an audit label.
	 *
	 * Two kinds of label reach the plan engine: an in-process caller's `system:<app>` and the cascade's own
	 * {@see CasePlanStateMachine::SYSTEM_ACTOR}. Neither is an account, so neither may become a run's user.
	 *
	 * @param string|null $actor The actor id.
	 *
	 * @return bool True when the id is an account uid.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
	 */
	public function isAccountActor(?string $actor): bool {
		$id = trim((string)$actor);
		if ($id === '' || $id === CasePlanStateMachine::SYSTEM_ACTOR) {
			return false;
		}

		return str_starts_with($id, CasePlanService::SYSTEM_ACTOR_PREFIX) === false;
	}//end isAccountActor()

	/**
	 * How a realisation ended, if it has: the outcome that drives the item.
	 *
	 * @param CaseItem $item An active item with a realisation.
	 *
	 * @return string|null `completed` | `terminated` when the realisation is
	 *                     terminal; null while it is open, absent, or unreadable.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function terminalOutcome(CaseItem $item): ?string {
		$uuid = trim((string)$item->getRealisationUuid());
		if ($uuid === '') {
			return null;
		}

		try {
			return $this->outcomeOf(item: $item, uuid: $uuid);
		} catch (DoesNotExistException) {
			// A realisation that no longer exists cannot complete anything;
			// it is terminated work.
			return CaseItem::STATE_TERMINATED;
		}
	}//end terminalOutcome()

	/**
	 * Close an open realisation because its item was exited or cascaded.
	 *
	 * The ONLY write the plan item ever makes to its realisation. A task is
	 * terminated as moot with the reason; a run has no public stop verb, so
	 * its terminal status stays the engine's decision and the omission is
	 * logged rather than faked.
	 *
	 * @param CaseItem $item The exited item.
	 * @param string $reason Why, recorded on the task and its audit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function terminate(CaseItem $item, string $reason): void {
		$uuid = trim((string)$item->getRealisationUuid());
		if ($uuid === '') {
			return;
		}

		if ($item->getRealisationKind() === CaseItem::REALISATION_TASK) {
			$this->terminateTask(item: $item, reason: $reason);

			return;
		}

		if ($item->getRealisationKind() === CaseItem::REALISATION_RUN) {
			$this->logger->info(
				sprintf('[CaseRealisationService] Plan item %s exited while run %s is open; the run keeps the engine\'s status.', (string)$item->getUuid(), $uuid)
			);
		}
	}//end terminate()

	/**
	 * The task a human plan item becomes, in TaskBuilder's vocabulary.
	 *
	 * Carries the anchor triple, the candidates, the deadline values and the
	 * carried zaaktype terms (as metadata, for flow-business-timers). The
	 * task starts `enabled` (pooled) when it has candidates and `available`
	 * otherwise; it is never born assigned by the case layer.
	 *
	 * @param CaseItem $item The human plan item.
	 * @param string $actor The creating identity.
	 *
	 * @return array<string, mixed> The task data.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function taskDataFor(CaseItem $item, string $actor): array {
		$data = [
			'title' => (string)($item->getName() ?? $item->getItemKey()),
			'description' => $item->getDescription(),
			'state' => $this->initialState(item: $item),
			'performerType' => $this->performerType(item: $item),
			'requester' => (string)($item->getCreatedBy() ?? $actor),
			'objectUuid' => $item->getObjectUuid(),
			'registerId' => $item->getRegisterId(),
			'schemaId' => $item->getSchemaId(),
			'appId' => 'openregister',
			'metadata' => self::taskMetadata(item: $item),
		];

		return $data + self::candidatesOf(item: $item) + self::deadlinesOf(item: $item);
	}//end taskDataFor()

	/**
	 * A task with candidates starts pooled (`enabled`); one without starts
	 * `available`. The case layer never assigns.
	 *
	 * @param CaseItem $item The human plan item.
	 *
	 * @return string The initial task state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function initialState(CaseItem $item): string {
		$role = trim((string)$item->getCandidateRole());
		if (($item->getCandidateUsers() ?? []) !== [] || ($item->getCandidateGroups() ?? []) !== [] || $role !== '') {
			return Task::STATE_ENABLED;
		}

		return Task::STATE_AVAILABLE;
	}//end initialState()

	/**
	 * Groups-only candidates make a group task; anything else a user task.
	 *
	 * @param CaseItem $item The human plan item.
	 *
	 * @return string The performer type.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function performerType(CaseItem $item): string {
		if (($item->getCandidateUsers() ?? []) === [] && ($item->getCandidateGroups() ?? []) !== []) {
			return Task::PERFORMER_GROUP;
		}

		return Task::PERFORMER_USER;
	}//end performerType()

	/**
	 * Create the task a human item is realised by, through the trusted creation path.
	 *
	 * @param CaseItem $item The human item entering active.
	 * @param string $actor The identity the task is created by.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function realiseAsTask(CaseItem $item, string $actor): void {
		$task = $this->tasks->tasks->import(data: $this->taskDataFor(item: $item, actor: $actor), actor: $actor);
		$item->setRealisationKind(CaseItem::REALISATION_TASK);
		$item->setRealisationUuid($task->getUuid());
	}//end realiseAsTask()

	/**
	 * Queue the run a flow-bound stage or process task is realised by.
	 *
	 * `system:<app>` and the cascade's own `case-plan` are labels for the
	 * audit, not accounts. As the run's user either would become its
	 * `runAs`, and every scoped step would be refused ("not a user
	 * account"), so the run is queued without a caller. FlowRunAttribution
	 * then lets the `case` trigger answer from the identity the flow's
	 * schedule trigger declares (validated and delegation-checked at save
	 * time); a flow that declares none is refused as unattributed, loudly,
	 * rather than run as a label.
	 *
	 * @param CaseItem $item The item entering active; the plan's settings name its flow.
	 * @param string $actor The identity the realisation is created by.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function realiseAsRun(CaseItem $item, string $actor): void {
		$run = $this->runs->runs->queueRun(
			flowId: self::flowOf(item: $item),
			options: new FlowRunQueueOptions(
				subject: [
					'uuid' => (string)$item->getObjectUuid(),
					'register' => (string)$item->getRegisterId(),
					'schema' => (string)$item->getSchemaId(),
				],
				trigger: self::RUN_TRIGGER,
				context: ['caseItem' => (string)$item->getUuid()],
				user: $this->runUser(actor: $actor)
			)
		);
		$item->setRealisationKind(CaseItem::REALISATION_RUN);
		$item->setRealisationUuid($run->getUuid());
	}//end realiseAsRun()

	/**
	 * The flow the plan's settings name for this item, '' when none.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return string The flow id, trimmed.
	 */
	private static function flowOf(CaseItem $item): string {
		return trim((string)($item->getPlanSettings()['flows'][(string)$item->getItemKey()] ?? ''));
	}//end flowOf()

	/**
	 * The user a run is queued for: the actor when it is an account, nobody for an audit label.
	 *
	 * @param string $actor The acting identity.
	 *
	 * @return string|null The uid, or null.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
	 */
	private function runUser(string $actor): ?string {
		if ($this->isAccountActor(actor: $actor) === true) {
			return $actor;
		}

		return null;
	}//end runUser()

	/**
	 * How the realisation of an item ended, read from its own table.
	 *
	 * @param CaseItem $item An active item with a realisation.
	 * @param string $uuid The realisation's uuid.
	 *
	 * @return string|null `completed` | `terminated`, or null while open or when the item realises nothing.
	 *
	 * @throws DoesNotExistException When the realisation no longer exists.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function outcomeOf(CaseItem $item, string $uuid): ?string {
		if ($item->getRealisationKind() === CaseItem::REALISATION_TASK) {
			return $this->outcomeOfTask(task: $this->tasks->taskRows->findByUuid(uuid: $uuid));
		}

		if ($item->getRealisationKind() === CaseItem::REALISATION_RUN) {
			return $this->outcomeOfRun(run: $this->runs->runRows->findByUuid(uuid: $uuid));
		}

		return null;
	}//end outcomeOf()

	/**
	 * Terminate a task realisation as moot; one that is gone needs nothing.
	 *
	 * @param CaseItem $item The exited item, realised by a task.
	 * @param string $reason Why, recorded on the task and its audit.
	 *
	 * @return void
	 *
	 * @throws Throwable Any other failure, after logging it.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function terminateTask(CaseItem $item, string $reason): void {
		$uuid = trim((string)$item->getRealisationUuid());
		try {
			$trigger = new TaskTrigger(source: sprintf('case-item:%s', (string)$item->getUuid()), reason: $reason);
			$this->tasks->tasks->terminateAsMoot(uuid: $uuid, trigger: $trigger);
		} catch (DoesNotExistException) {
			// Nothing left to terminate.
			return;
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[CaseRealisationService] Could not terminate a task realisation: ' . $failure->getMessage(),
				['item' => $item->getUuid(), 'task' => $uuid]
			);
			throw $failure;
		}
	}//end terminateTask()

	/**
	 * The carried terms of a human item, as task metadata.
	 *
	 * @param CaseItem $item The human plan item.
	 *
	 * @return array<string, mixed> The metadata.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private static function taskMetadata(CaseItem $item): array {
		return [
			'caseItem' => $item->getUuid(),
			'caseItemKey' => $item->getItemKey(),
			'realisationCount' => $item->getRealisationCount(),
			'doorlooptijd' => $item->getDoorlooptijd(),
			'servicenorm' => $item->getServicenorm(),
		];
	}//end taskMetadata()

	/**
	 * The candidates a task carries, each only when there is one.
	 *
	 * @param CaseItem $item The human plan item.
	 *
	 * @return array<string, mixed> candidateUsers, candidateGroups and candidateRole, as present.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private static function candidatesOf(CaseItem $item): array {
		$candidates = [
			'candidateUsers' => array_values($item->getCandidateUsers() ?? []),
			'candidateGroups' => array_values($item->getCandidateGroups() ?? []),
			'candidateRole' => trim((string)$item->getCandidateRole()),
		];

		return array_filter($candidates, static fn (array|string $value): bool => $value !== [] && $value !== '');
	}//end candidatesOf()

	/**
	 * The deadlines a task carries, each only when set.
	 *
	 * @param CaseItem $item The human plan item.
	 *
	 * @return array<string, string> dueAt and expiresAt, as ISO 8601.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private static function deadlinesOf(CaseItem $item): array {
		$deadlines = ['dueAt' => $item->getDueAt()?->format('c'), 'expiresAt' => $item->getExpiresAt()?->format('c')];

		return array_filter($deadlines, static fn (?string $value): bool => $value !== null);
	}//end deadlinesOf()

	/**
	 * A task's terminal outcome as a plan-item state.
	 *
	 * @param Task $task The task.
	 *
	 * @return string|null `completed` | `terminated` | null while open.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function outcomeOfTask(Task $task): ?string {
		if ($task->isInTerminalState() === false) {
			return null;
		}

		if ($task->getState() === Task::STATE_COMPLETED) {
			return CaseItem::STATE_COMPLETED;
		}

		return CaseItem::STATE_TERMINATED;
	}//end outcomeOfTask()

	/**
	 * A run's terminal outcome as a plan-item state.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return string|null `completed` | `terminated` | null while active.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	private function outcomeOfRun(FlowRun $run): ?string {
		$status = (string)$run->getStatus();
		if (in_array($status, FlowRun::TERMINAL, true) === false) {
			return null;
		}

		if ($status === FlowRun::STATUS_COMPLETED) {
			return CaseItem::STATE_COMPLETED;
		}

		return CaseItem::STATE_TERMINATED;
	}//end outcomeOfRun()
}//end class
