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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, reads and closes realisations.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
 */
class CaseRealisationService {

	/**
	 * The trigger name a stage-queued run carries.
	 */
	public const RUN_TRIGGER = 'case';

	/**
	 * Constructor.
	 *
	 * @param TaskService $tasks The task lifecycle (its trusted creation path).
	 * @param TaskMapper $taskRows The task table, for the terminal-outcome read.
	 * @param FlowRunService $runs The one queue funnel.
	 * @param FlowRunMapper $runRows The run table, for the terminal-outcome read.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly TaskService $tasks,
		private readonly TaskMapper $taskRows,
		private readonly FlowRunService $runs,
		private readonly FlowRunMapper $runRows,
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function realise(CaseItem $item, string $actor): void {
		if ($item->getPlanItemType() === CaseItem::TYPE_HUMAN_TASK) {
			$task = $this->tasks->import(data: $this->taskDataFor(item: $item, actor: $actor), actor: $actor);
			$item->setRealisationKind(CaseItem::REALISATION_TASK);
			$item->setRealisationUuid($task->getUuid());

			return;
		}

		$flow = trim((string)($item->getPlanSettings()['flows'][(string)$item->getItemKey()] ?? ''));
		if ($item->getPlanItemType() === CaseItem::TYPE_STAGE && $flow !== '') {
			$run = $this->runs->queue(
				flowId: $flow,
				subject: [
					'uuid' => (string)$item->getObjectUuid(),
					'register' => (string)$item->getRegisterId(),
					'schema' => (string)$item->getSchemaId(),
				],
				trigger: self::RUN_TRIGGER,
				context: ['caseItem' => (string)$item->getUuid()],
				user: $actor
			);
			$item->setRealisationKind(CaseItem::REALISATION_RUN);
			$item->setRealisationUuid($run->getUuid());

			return;
		}

		$item->setRealisationKind(CaseItem::REALISATION_NONE);
		$item->setRealisationUuid(null);
	}//end realise()

	/**
	 * How a realisation ended, if it has: the outcome that drives the item.
	 *
	 * @param CaseItem $item An active item with a realisation.
	 *
	 * @return string|null `completed` | `terminated` when the realisation is
	 *                     terminal; null while it is open, absent, or unreadable.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function terminalOutcome(CaseItem $item): ?string {
		$uuid = trim((string)$item->getRealisationUuid());
		if ($uuid === '') {
			return null;
		}

		try {
			if ($item->getRealisationKind() === CaseItem::REALISATION_TASK) {
				$task = $this->taskRows->findByUuid(uuid: $uuid);

				return $this->outcomeOfTask(task: $task);
			}

			if ($item->getRealisationKind() === CaseItem::REALISATION_RUN) {
				$run = $this->runRows->findByUuid(uuid: $uuid);

				return $this->outcomeOfRun(run: $run);
			}
		} catch (DoesNotExistException) {
			// A realisation that no longer exists cannot complete anything;
			// it is terminated work.
			return CaseItem::STATE_TERMINATED;
		}

		return null;
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function terminate(CaseItem $item, string $reason): void {
		$uuid = trim((string)$item->getRealisationUuid());
		if ($uuid === '') {
			return;
		}

		$source = sprintf('case-item:%s', (string)$item->getUuid());
		if ($item->getRealisationKind() === CaseItem::REALISATION_TASK) {
			try {
				$this->tasks->terminateAsMoot(uuid: $uuid, reason: $reason, source: $source);
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function taskDataFor(CaseItem $item, string $actor): array {
		$users = ($item->getCandidateUsers() ?? []);
		$groups = ($item->getCandidateGroups() ?? []);
		$role = trim((string)$item->getCandidateRole());
		$pooled = ($users !== [] || $groups !== [] || $role !== '');

		$performerType = Task::PERFORMER_USER;
		if ($users === [] && $groups !== []) {
			$performerType = Task::PERFORMER_GROUP;
		}

		$state = Task::STATE_AVAILABLE;
		if ($pooled === true) {
			$state = Task::STATE_ENABLED;
		}

		$data = [
			'title' => (string)($item->getName() ?? $item->getItemKey()),
			'description' => $item->getDescription(),
			'state' => $state,
			'performerType' => $performerType,
			'requester' => (string)($item->getCreatedBy() ?? $actor),
			'objectUuid' => $item->getObjectUuid(),
			'registerId' => $item->getRegisterId(),
			'schemaId' => $item->getSchemaId(),
			'appId' => 'openregister',
			'metadata' => [
				'caseItem' => $item->getUuid(),
				'caseItemKey' => $item->getItemKey(),
				'realisationCount' => $item->getRealisationCount(),
				'doorlooptijd' => $item->getDoorlooptijd(),
				'servicenorm' => $item->getServicenorm(),
			],
		];

		if ($users !== []) {
			$data['candidateUsers'] = array_values($users);
		}

		if ($groups !== []) {
			$data['candidateGroups'] = array_values($groups);
		}

		if ($role !== '') {
			$data['candidateRole'] = $role;
		}

		if ($item->getDueAt() !== null) {
			$data['dueAt'] = $item->getDueAt()->format('c');
		}

		if ($item->getExpiresAt() !== null) {
			$data['expiresAt'] = $item->getExpiresAt()->format('c');
		}

		return $data;
	}//end taskDataFor()

	/**
	 * A task's terminal outcome as a plan-item state.
	 *
	 * @param Task $task The task.
	 *
	 * @return string|null `completed` | `terminated` | null while open.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
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
