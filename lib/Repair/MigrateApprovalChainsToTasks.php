<?php

/**
 * MigrateApprovalChainsToTasks: the one-way data migration of the approval
 * consolidation (flow-approval-consolidation design, Migration Plan 2-8).
 *
 * Every approval chain becomes a task template (derived, deterministic);
 * every `(chain, object)` step set becomes ONE task sequence; every step
 * becomes a task at its own ordinal with its role as candidate group, its
 * requester, and its original creation time. Terminal steps migrate their
 * decision into the task audit, attributed to the ORIGINAL decider with the
 * original comment and time, marked as migrated. Reconciliation columns are
 * written on both sides (`legacy_step_id` on the task,
 * `migrated_task_uuid` on the kept step row), and every stage is guarded on
 * them so a second run changes nothing.
 *
 * Tasks are inserted through the mappers directly, NOT through
 * `TaskService`: the service's verbs announce terminality after commit, and
 * a migration replaying history must not re-fire sequence advancement,
 * auto-advance transitions or timer cancellation for decisions that
 * happened months ago. No event leaves this step.
 *
 * The verification at the end FAILS LOUDLY, naming the chain, the object
 * and the step: a partially migrated database must never report success.
 * The legacy tables are NOT dropped, and the cutover timestamp lands in app
 * config under `approval_consolidation_cutover`, where an operator deciding
 * about a rollback will find it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Converts chains to templates, step sets to sequences and steps to tasks.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the codebase's uuid idiom.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The migration touches both
 * worlds by definition: the legacy tables it reads and every store a task is
 * made of.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The branching IS the
 * contract: per-status mapping, per-stage idempotency guards and a five-part
 * verification, each required by the spec to exist separately.
 */
class MigrateApprovalChainsToTasks implements IRepairStep {

	/**
	 * The acting identity recorded on migration-created audit entries.
	 *
	 * @var string
	 */
	public const ACTOR = 'migration:flow-approval-consolidation';

	/**
	 * The app-config key carrying the cutover timestamp.
	 *
	 * @var string
	 */
	public const CUTOVER_CONFIG_KEY = 'approval_consolidation_cutover';

	/**
	 * Deterministic template-id namespace. MUST equal the one
	 * {@see \OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller}
	 * derives from, so the gate finds the sequences this step opens.
	 *
	 * @var string
	 */
	private const TEMPLATE_ID_NS = 'or-approval-template';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Reads the legacy tables and writes the reconciliation column.
	 * @param TaskBuilder $builder Validates and builds each migrated task.
	 * @param TaskMapper $tasks Inserts tasks (insert dispatches nothing).
	 * @param TaskCandidateMapper $candidates Maintains the candidate index.
	 * @param TaskAuditMapper $audits Appends the migrated decision history.
	 * @param TaskSequenceMapper $sequences Inserts one sequence per (chain, object).
	 * @param IAppConfig $config Records the cutover timestamp.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly TaskBuilder $builder,
		private readonly TaskMapper $tasks,
		private readonly TaskCandidateMapper $candidates,
		private readonly TaskAuditMapper $audits,
		private readonly TaskSequenceMapper $sequences,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step's name, shown by `occ maintenance:repair`.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Migrate OpenRegister approval chains and steps onto task sequences';
	}//end getName()

	/**
	 * Run the migration: chains to templates, steps to tasks, then verify.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the verification cannot reconcile.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function run(IOutput $output): void {
		try {
			$chains = $this->rows(sql: 'SELECT * FROM `*PREFIX*openregister_approval_chains`');
		} catch (Throwable $absent) {
			// A fresh install may not carry the legacy tables at repair time.
			$output->info('No legacy approval tables found; nothing to migrate.');

			return;
		}

		$migrated = 0;
		foreach ($chains as $chain) {
			$migrated += $this->migrateChain(chain: $chain);
		}

		$this->verify();

		if ($migrated > 0 && $this->config->getValueString('openregister', self::CUTOVER_CONFIG_KEY, '') === '') {
			$this->config->setValueString('openregister', self::CUTOVER_CONFIG_KEY, (new DateTime())->format('c'));
		}

		$output->info(sprintf('Approval migration verified; %d step(s) migrated onto tasks this run.', $migrated));
	}//end run()

	/**
	 * Migrate every (object) step set of one chain.
	 *
	 * @param array<string, mixed> $chain The legacy chain row.
	 *
	 * @return int How many steps were migrated this run.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	private function migrateChain(array $chain): int {
		$steps = $this->rows(
			sql: 'SELECT * FROM `*PREFIX*openregister_approval_steps` WHERE `chain_id` = ? ORDER BY `object_uuid`, `step_order`',
			params: [(int)$chain['id']]
		);

		$byObject = [];
		foreach ($steps as $step) {
			$byObject[(string)$step['object_uuid']][] = $step;
		}

		$template = $this->templateFor(chain: $chain);
		$migrated = 0;
		foreach ($byObject as $objectUuid => $objectSteps) {
			$migrated += $this->migrateStepSet(
				chain: $chain,
				template: $template,
				objectUuid: (string)$objectUuid,
				steps: $objectSteps
			);
		}

		return $migrated;
	}//end migrateChain()

	/**
	 * Migrate one (chain, object) step set onto one sequence.
	 *
	 * Idempotent per step: a step carrying `migrated_task_uuid` is never
	 * touched again, and the sequence is recovered from an already-migrated
	 * sibling rather than opened a second time.
	 *
	 * @param array<string, mixed> $chain The legacy chain row.
	 * @param array<string, mixed> $template The derived template.
	 * @param string $objectUuid The object the approval is about.
	 * @param array<int, array<string, mixed>> $steps The object's step rows, ordinal order.
	 *
	 * @return int How many steps were migrated this run.
	 */
	private function migrateStepSet(array $chain, array $template, string $objectUuid, array $steps): int {
		$pending = array_filter($steps, static fn (array $step): bool => (string)$step['status'] === 'pending');
		$unmigrated = array_filter($steps, static fn (array $step): bool => trim((string)($step['migrated_task_uuid'] ?? '')) === '');
		if ($unmigrated === []) {
			return 0;
		}

		$sequence = $this->sequenceFor(
			chain: $chain,
			template: $template,
			objectUuid: $objectUuid,
			steps: $steps,
			pendingOrdinal: $this->firstOrdinal(steps: $pending)
		);

		$count = 0;
		foreach ($unmigrated as $step) {
			$this->migrateStep(sequence: $sequence, step: $step, chainName: (string)$chain['name']);
			$count++;
		}

		return $count;
	}//end migrateStepSet()

	/**
	 * Recover or open the sequence for a step set.
	 *
	 * @param array<string, mixed> $chain The legacy chain row.
	 * @param array<string, mixed> $template The derived template.
	 * @param string $objectUuid The anchor.
	 * @param array<int, array<string, mixed>> $steps All the set's step rows.
	 * @param int|null $pendingOrdinal The ordinal that was pending, when any.
	 *
	 * @return TaskSequence The sequence the set's tasks belong to.
	 */
	private function sequenceFor(array $chain, array $template, string $objectUuid, array $steps, ?int $pendingOrdinal): TaskSequence {
		foreach ($steps as $step) {
			$taskUuid = trim((string)($step['migrated_task_uuid'] ?? ''));
			if ($taskUuid === '') {
				continue;
			}

			try {
				$existing = $this->tasks->findByUuid(uuid: $taskUuid);
				$sequenceUuid = trim((string)$existing->getSequenceUuid());
				if ($sequenceUuid !== '') {
					return $this->sequences->findByUuid(uuid: $sequenceUuid);
				}
			} catch (Throwable $gone) {
				$this->logger->warning(
					'[MigrateApprovalChainsToTasks] Reconciliation points at a missing task or sequence: ' . $gone->getMessage()
				);
			}
		}

		[$status, $outcome] = $this->sequenceOutcome(steps: $steps);
		$requester = $this->firstRequester(steps: $steps);

		$sequence = new TaskSequence();
		$sequence->setUuid(Uuid::v4()->toRfc4122());
		$sequence->setTemplateId((string)$template['templateId']);
		$sequence->setTemplateVersion((int)$template['templateVersion']);
		$sequence->setTemplateSnapshot($template);
		$sequence->setAnchorObjectUuid($objectUuid);
		$schemaId = ($chain['schema_id'] ?? null);
		if ($schemaId !== null) {
			$schemaId = (int)$schemaId;
		}

		$sequence->setSchemaId($schemaId);
		$sequence->setChainKey((string)$chain['name']);
		$sequence->setRequesterId($requester);
		$sequence->setPositionCursor($pendingOrdinal ?? (int)($steps[0]['step_order'] ?? 1));
		$sequence->setStatus($status);
		$sequence->setOutcome($outcome);
		$sequence->setOpenedAt($this->parseDbDate(value: ($steps[0]['created'] ?? null)) ?? new DateTime());
		if ($status !== TaskSequence::STATUS_RUNNING) {
			$sequence->setClosedAt(new DateTime());
		}

		return $this->sequences->insert($sequence);
	}//end sequenceFor()

	/**
	 * The (status, outcome) a migrated step set closes with.
	 *
	 * Rejected wins, then fully approved, then running while anything is
	 * open; a set with nothing open and nothing approved is closed as
	 * terminated, never left running (Migration Plan step 5).
	 *
	 * @param array<int, array<string, mixed>> $steps The set's step rows.
	 *
	 * @return array{0: string, 1: string|null} Status and outcome.
	 */
	private function sequenceOutcome(array $steps): array {
		$statuses = array_map(static fn (array $step): string => (string)$step['status'], $steps);
		if (in_array('rejected', $statuses, true) === true) {
			return [TaskSequence::STATUS_REJECTED, 'rejected'];
		}

		if ($statuses !== [] && array_diff($statuses, ['approved']) === []) {
			return [TaskSequence::STATUS_COMPLETED, 'approved'];
		}

		if (in_array('pending', $statuses, true) === false && in_array('waiting', $statuses, true) === false) {
			return [TaskSequence::STATUS_TERMINATED, 'terminated'];
		}

		return [TaskSequence::STATUS_RUNNING, null];
	}//end sequenceOutcome()

	/**
	 * The first recorded requester in a step set, or null.
	 *
	 * @param array<int, array<string, mixed>> $steps The set's step rows.
	 *
	 * @return string|null The requester uid.
	 */
	private function firstRequester(array $steps): ?string {
		foreach ($steps as $step) {
			$candidate = trim((string)($step['requester_id'] ?? ''));
			if ($candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end firstRequester()

	/**
	 * Convert ONE step row into a task, its candidates and its audit.
	 *
	 * @param TaskSequence $sequence The owning sequence.
	 * @param array<string, mixed> $step The legacy step row.
	 * @param string $chainName The chain's name, for the task title.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-a-decided-approval-keeps-its-decision-its-actor-and-its-comment
	 */
	private function migrateStep(TaskSequence $sequence, array $step, string $chainName): void {
		$status = (string)$step['status'];
		$role = trim((string)$step['role']);
		$candidateGroups = null;
		if ($role !== '') {
			$candidateGroups = [$role];
		}

		$stateByStatus = [
			'pending' => Task::STATE_ENABLED,
			'waiting' => Task::STATE_AVAILABLE,
			'approved' => 'approved',
			'rejected' => 'rejected',
		];

		$task = $this->builder->fromData(
			data: [
				'title' => sprintf('Approve %s (step %d)', $chainName, (int)$step['step_order']),
				'description' => sprintf(
					'Approval step %d for %s on object %s, migrated from the retired approval chain engine.',
					(int)$step['step_order'],
					$chainName,
					(string)$step['object_uuid']
				),
				'state' => ($stateByStatus[$status] ?? Task::STATE_AVAILABLE),
				'performerType' => Task::PERFORMER_GROUP,
				'candidateGroups' => $candidateGroups,
				'routingStrategy' => 'single-role',
				'requester' => $sequence->getRequesterId(),
				'objectUuid' => (string)$step['object_uuid'],
				'schemaId' => $sequence->getSchemaId(),
				'templateId' => $sequence->getTemplateId(),
				'templateVersion' => $sequence->getTemplateVersion(),
				'sequenceUuid' => $sequence->getUuid(),
				'sequencePosition' => (int)$step['step_order'],
				'legacyStepId' => (int)$step['id'],
			],
			actor: self::ACTOR
		);

		// Provenance the builder does not take from boundary data: the
		// ORIGINAL creation time, decider and decision time survive.
		$task->setCreated($this->parseDbDate(value: ($step['created'] ?? null)));
		if ($task->isInTerminalState() === true) {
			$task->setCompletedBy($this->nullableString(value: ($step['decided_by'] ?? null)));
			$task->setCompletedAt($this->parseDbDate(value: ($step['decided_at'] ?? null)));
			$task->setComment($this->nullableString(value: ($step['comment'] ?? null)));
		}

		$persisted = $this->tasks->insert($task);
		$this->indexCandidates(task: $persisted);

		$this->appendAudit(
			task: $persisted,
			action: 'create',
			stateAfter: (string)$persisted->getState(),
			actor: self::ACTOR,
			reason: sprintf('Migrated from approval step %d.', (int)$step['id']),
			created: $persisted->getCreated()
		);

		if ($persisted->isInTerminalState() === true) {
			$decisionActor = self::ACTOR;
			if ((string)($step['decided_by'] ?? '') !== '') {
				$decisionActor = (string)$step['decided_by'];
			}

			$this->appendAudit(
				task: $persisted,
				action: 'complete',
				stateAfter: (string)$persisted->getState(),
				actor: $decisionActor,
				reason: trim(sprintf('[migrated decision] %s', (string)($step['comment'] ?? ''))),
				created: $this->parseDbDate(value: ($step['decided_at'] ?? null))
			);
		}

		$update = $this->db->getQueryBuilder();
		$update->update('openregister_approval_steps')
			->set('migrated_task_uuid', $update->createNamedParameter((string)$persisted->getUuid()))
			->where($update->expr()->eq('id', $update->createNamedParameter((int)$step['id'])));
		$update->executeStatement();
	}//end migrateStep()

	/**
	 * Verify the migration reconciles, or STOP naming the failure.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming the chain, the object and the step.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	private function verify(): void {
		$steps = $this->rows(sql: 'SELECT * FROM `*PREFIX*openregister_approval_steps`');

		$enabledExpected = 0;
		foreach ($steps as $step) {
			$enabledExpected += $this->verifyStep(step: $step);
		}

		$this->verifySingleRunningSequences();
		$this->verifyEnabledCount(expected: $enabledExpected);
	}//end verify()

	/**
	 * Verify ONE step's reconciliation, or STOP naming it.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 *
	 * @return int One when the step was `pending` (it must be the enabled
	 *             task), zero otherwise.
	 *
	 * @throws RuntimeException Naming the chain, the object and the step.
	 */
	private function verifyStep(array $step): int {
		$status = (string)$step['status'];
		$where = sprintf(
			'chain %d, object %s, step %d',
			(int)$step['chain_id'],
			(string)$step['object_uuid'],
			(int)$step['id']
		);

		$taskUuid = trim((string)($step['migrated_task_uuid'] ?? ''));
		if ($taskUuid === '') {
			throw new RuntimeException(
				'Approval migration failed: no migrated task recorded for ' . $where . '. The migration did NOT succeed.'
			);
		}

		try {
			$task = $this->tasks->findByUuid(uuid: $taskUuid);
		} catch (Throwable $gone) {
			throw new RuntimeException(
				'Approval migration failed: migrated task ' . $taskUuid . ' for ' . $where . ' does not exist. The migration did NOT succeed.'
			);
		}

		if ((int)$task->getSequencePosition() !== (int)$step['step_order']) {
			throw new RuntimeException(sprintf(
				'Approval migration failed: task %s sits at ordinal %d while its step holds %d (%s). The migration did NOT succeed.',
				$taskUuid,
				(int)$task->getSequencePosition(),
				(int)$step['step_order'],
				$where
			));
		}

		$openStep = ($status === 'pending' || $status === 'waiting');
		if ($openStep === true && $task->isInTerminalState() === true) {
			throw new RuntimeException(
				'Approval migration failed: non-terminal ' . $where . ' maps to terminal task ' . $taskUuid . '. The migration did NOT succeed.'
			);
		}

		if ($status !== 'pending') {
			return 0;
		}

		if ((string)$task->getState() !== Task::STATE_ENABLED) {
			throw new RuntimeException(
				'Approval migration failed: pending ' . $where . ' maps to a task that is not enabled. The migration did NOT succeed.'
			);
		}

		return 1;
	}//end verifyStep()

	/**
	 * No (anchor, template) may hold two running sequences.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming the anchor and template.
	 */
	private function verifySingleRunningSequences(): void {
		$rows = $this->rows(
			sql: 'SELECT `anchor_object_uuid`, `template_id`, COUNT(*) AS `n` FROM `*PREFIX*openregister_task_sequences`'
				. " WHERE `status` = 'running' GROUP BY `anchor_object_uuid`, `template_id` HAVING COUNT(*) > 1"
		);
		if ($rows !== []) {
			throw new RuntimeException(sprintf(
				'Approval migration failed: object %s holds %d running sequences for template %s. The migration did NOT succeed.',
				(string)$rows[0]['anchor_object_uuid'],
				(int)$rows[0]['n'],
				(string)$rows[0]['template_id']
			));
		}
	}//end verifySingleRunningSequences()

	/**
	 * The enabled migrated-task count must equal the pending step count.
	 *
	 * @param int $expected The number of steps that were `pending`.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming both counts.
	 */
	private function verifyEnabledCount(int $expected): void {
		$rows = $this->rows(
			sql: 'SELECT COUNT(*) AS `n` FROM `*PREFIX*openregister_tasks`'
				. " WHERE `legacy_step_id` IS NOT NULL AND `state` = 'enabled'"
		);
		$actual = (int)($rows[0]['n'] ?? 0);
		if ($actual !== $expected) {
			throw new RuntimeException(sprintf(
				'Approval migration failed: %d enabled migrated task(s) against %d pending step(s). The migration did NOT succeed.',
				$actual,
				$expected
			));
		}
	}//end verifyEnabledCount()

	/**
	 * The derived template for a chain row.
	 *
	 * Deterministic and identical to what the annotation compiler derives
	 * for the same (schema, chain key), so gate lookups and migrated
	 * sequences meet on one template id. Separation of duties is recorded as
	 * OFF for a chain the migration cannot see a declaration for, which is
	 * exactly the retired engine's behaviour for pure-CRUD chains.
	 *
	 * @param array<string, mixed> $chain The legacy chain row.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function templateFor(array $chain): array {
		$positions = [];
		$declared = json_decode((string)($chain['steps'] ?? '[]'), true);
		if (is_array($declared) === true) {
			$order = 1;
			foreach ($declared as $entry) {
				if (is_array($entry) === false || trim((string)($entry['role'] ?? '')) === '') {
					continue;
				}

				$positions[] = [
					'order' => (int)($entry['order'] ?? $order),
					'role' => trim((string)$entry['role']),
					'statusOnApprove' => ($entry['statusOnApprove'] ?? null),
					'statusOnReject' => ($entry['statusOnReject'] ?? null),
				];
				$order++;
			}
		}

		$schemaId = ($chain['schema_id'] ?? null);
		$schemaPart = 'legacy:' . (string)$chain['id'];
		if ($schemaId !== null) {
			$schemaId = (int)$schemaId;
			$schemaPart = (string)$schemaId;
		}

		$hash = md5(self::TEMPLATE_ID_NS . ':' . $schemaPart . ':' . (string)$chain['name']);

		return [
			'templateId' => sprintf(
				'%s-%s-%s-%s-%s',
				substr($hash, 0, 8),
				substr($hash, 8, 4),
				substr($hash, 12, 4),
				substr($hash, 16, 4),
				substr($hash, 20, 12)
			),
			'templateVersion' => 1,
			'name' => (string)$chain['name'],
			'schemaId' => $schemaId,
			'separationOfDuties' => false,
			'migratedFromChainId' => (int)$chain['id'],
			'positions' => $positions,
		];
	}//end templateFor()

	/**
	 * Rebuild the candidate index rows for a migrated task.
	 *
	 * @param Task $task The persisted task.
	 *
	 * @return void
	 */
	private function indexCandidates(Task $task): void {
		$rows = [];
		foreach (($task->getCandidateGroups() ?? []) as $groupId) {
			$rows[] = [
				'kind' => 'group',
				'ref' => (string)$groupId,
			];
		}

		if ($rows !== []) {
			$this->candidates->replaceForTask(taskId: (int)$task->getId(), candidates: $rows);
		}
	}//end indexCandidates()

	/**
	 * Append one audit entry.
	 *
	 * @param Task $task The task audited.
	 * @param string $action The audited action.
	 * @param string $stateAfter The state the entry records.
	 * @param string $actor The acting identity — the ORIGINAL decider for a
	 *                      migrated decision, kept even when no such user
	 *                      exists any more.
	 * @param string $reason The reason or migrated comment.
	 * @param DateTime|null $created The original time; now when unknown.
	 *
	 * @return void
	 */
	private function appendAudit(Task $task, string $action, string $stateAfter, string $actor, string $reason, ?DateTime $created): void {
		$entry = new TaskAudit();
		$entry->setTaskId((int)$task->getId());
		$entry->setAction($action);
		$entry->setStateAfter($stateAfter);
		$entry->setActor($actor);
		$entry->setPerformerType(Task::PERFORMER_GROUP);
		$entry->setReason($reason);
		$entry->setAuthorized(true);
		$entry->setCreated($created);
		$this->audits->insert($entry);
	}//end appendAudit()

	/**
	 * The lowest ordinal among a set of step rows, or null.
	 *
	 * @param array<int, array<string, mixed>> $steps The step rows.
	 *
	 * @return int|null The lowest `step_order`, or null for an empty set.
	 */
	private function firstOrdinal(array $steps): ?int {
		$ordinals = array_map(static fn (array $step): int => (int)$step['step_order'], $steps);
		if ($ordinals === []) {
			return null;
		}

		return min($ordinals);
	}//end firstOrdinal()

	/**
	 * Run a read query and fetch every row.
	 *
	 * @param string $sql The statement.
	 * @param array<int, mixed> $params Positional parameters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(string $sql, array $params = []): array {
		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end rows()

	/**
	 * Parse a database datetime string, tolerating null.
	 *
	 * @param mixed $value The raw column value.
	 *
	 * @return DateTime|null The parsed time, or null.
	 */
	private function parseDbDate(mixed $value): ?DateTime {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		try {
			return new DateTime($text);
		} catch (Throwable $unparsable) {
			return null;
		}
	}//end parseDbDate()

	/**
	 * A trimmed string, or null when empty.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string, or null.
	 */
	private function nullableString(mixed $value): ?string {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;
	}//end nullableString()
}//end class
