<?php

/**
 * OpenRegister approval-rollback command
 *
 * The reverse repair of the approval consolidation
 * (flow-approval-consolidation design D-6, Migration Plan step 9). Writes
 * migrated tasks' decisions BACK onto their originating approval-step rows
 * for the fields the legacy schema can express (status, decider, comment,
 * decision time), and REPORTS every fact it cannot carry: the performer
 * type, an on-behalf-of identity, a mandate, and the per-entry audit trail.
 *
 * Deliberately a command and not a registered repair step: rollback is an
 * operator's decision, never an upgrade side effect. It is only safe
 * TOGETHER with redeploying the previous app version; run alone it changes
 * step rows nothing reads. Dropping the legacy tables is NOT part of this
 * command and must not be part of any rollback path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Command\Support\ApprovalRollbackRun;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskStateRules;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Write migrated decisions back onto the kept approval-step rows.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TaskState is the published,
 * stateless state vocabulary; calling it statically is the point.
 */
class RollbackApprovalMigrationCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Reads the step rows and writes the decisions back.
	 * @param TaskMapper $tasks Reads the migrated tasks.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly TaskMapper $tasks,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:approval:rollback-to-steps')
			->setDescription(
				description: 'Write decisions taken on migrated approval tasks back onto the legacy step rows, and report what the legacy schema cannot hold'
			)
			->addOption(
				name: 'dry-run',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be written back without changing any row'
			);
	}//end configure()

	/**
	 * Execute the reverse repair.
	 *
	 * @param InputInterface $input Command input.
	 * @param OutputInterface $output Command output.
	 *
	 * @return int Zero on success.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$run = new ApprovalRollbackRun(dryRun: ((bool)$input->getOption('dry-run') === true), output: $output);

		$steps = $this->migratedSteps();
		if ($steps === null) {
			$output->writeln('<error>The legacy approval tables are gone; there is nothing to roll back onto.</error>');

			return Command::FAILURE;
		}

		foreach ($steps as $step) {
			$this->carryBack(step: $step, run: $run);
		}

		$this->report(run: $run);

		return Command::SUCCESS;
	}//end execute()

	/**
	 * The legacy step rows that were migrated onto tasks.
	 *
	 * @return array<int, array<string, mixed>>|null Null when the legacy tables are gone.
	 */
	private function migratedSteps(): ?array {
		try {
			$result = $this->db->executeQuery(
				'SELECT * FROM `*PREFIX*openregister_approval_steps` WHERE `migrated_task_uuid` IS NOT NULL'
			);
			$steps = $result->fetchAll();
			$result->closeCursor();
		} catch (Throwable $absent) {
			return null;
		}

		return $steps;
	}//end migratedSteps()

	/**
	 * Print the summary of a pass.
	 *
	 * @param ApprovalRollbackRun $run The finished pass.
	 *
	 * @return void
	 */
	private function report(ApprovalRollbackRun $run): void {
		$mode = 'written back';
		if ($run->dryRun === true) {
			$mode = 'would be written back';
		}

		$run->output->writeln(sprintf('%d decision(s) %s onto legacy step rows.', $run->written(), $mode));

		if ($run->notCarriable() !== []) {
			$run->output->writeln('<comment>The legacy schema cannot hold the following facts; they stay ONLY on the task side:</comment>');
			foreach ($run->notCarriable() as $line) {
				$run->output->writeln('  - ' . $line);
			}
		}

		$run->output->writeln('The legacy tables were not dropped, and dropping them is not part of any rollback path.');
	}//end report()

	/**
	 * Carry ONE step's task-side decision back, when there is one.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 * @param ApprovalRollbackRun $run The pass, which collects what was carried and left behind.
	 *
	 * @return void
	 */
	private function carryBack(array $step, ApprovalRollbackRun $run): void {
		$task = $this->migratedTask(step: $step, output: $run->output);
		if ($task === null || $this->hasNewDecision(step: $step, task: $task) === false) {
			return;
		}

		if ($run->dryRun === false) {
			$this->writeDecision(step: $step, task: $task);
		}

		$run->recordCarried(
			step: sprintf('step %d (task %s)', (int)$step['id'], $this->taskUuid(step: $step)),
			lostFacts: $this->lostFacts(task: $task)
		);
	}//end carryBack()

	/**
	 * The trimmed uuid of the task a step was migrated onto.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 *
	 * @return string
	 */
	private function taskUuid(array $step): string {
		return trim((string)$step['migrated_task_uuid']);
	}//end taskUuid()

	/**
	 * The task a step was migrated onto, reporting it when it is gone.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 * @param OutputInterface $output Command output.
	 *
	 * @return Task|null Null when the task no longer exists.
	 */
	private function migratedTask(array $step, OutputInterface $output): ?Task {
		$taskUuid = $this->taskUuid(step: $step);
		try {
			return $this->tasks->findByUuid(uuid: $taskUuid);
		} catch (Throwable $gone) {
			$output->writeln(sprintf('<comment>Step %d: migrated task %s no longer exists; row left as it is.</comment>', (int)$step['id'], $taskUuid));

			return null;
		}
	}//end migratedTask()

	/**
	 * Whether the task side holds a decision the step does not already record.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 * @param Task $task The migrated task.
	 *
	 * @return bool
	 */
	private function hasNewDecision(array $step, Task $task): bool {
		if ($task->isInTerminalState() === false || (string)$task->getState() !== Task::STATE_COMPLETED) {
			// No decision was taken on the task side; the step keeps
			// whatever it already recorded.
			return false;
		}

		// Already decided identically before migration: nothing new to
		// carry back.
		$decidedIdentically = (string)$step['status'] === $this->legacyStatus(task: $task)
			&& trim((string)($step['decided_by'] ?? '')) !== '';

		return $decidedIdentically === false;
	}//end hasNewDecision()

	/**
	 * The legacy status a completed task's outcome maps onto.
	 *
	 * @param Task $task The completed task.
	 *
	 * @return string Either "approved" or "rejected".
	 */
	private function legacyStatus(Task $task): string {
		if (TaskStateRules::isRejectingOutcome(outcome: $task->getOutcome()) === true) {
			return 'rejected';
		}

		return 'approved';
	}//end legacyStatus()

	/**
	 * Write one decision onto its legacy step row.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 * @param Task $task The decided task.
	 *
	 * @return void
	 */
	private function writeDecision(array $step, Task $task): void {
		$update = $this->db->getQueryBuilder();
		$update->update('openregister_approval_steps')
			->set('status', $update->createNamedParameter($this->legacyStatus(task: $task)))
			->set('decided_by', $update->createNamedParameter((string)($task->getCompletedBy() ?? '')))
			->set('comment', $update->createNamedParameter((string)($task->getComment() ?? '')))
			->set('decided_at', $update->createNamedParameter($task->getCompletedAt()?->format('Y-m-d H:i:s')))
			->where($update->expr()->eq('id', $update->createNamedParameter((int)$step['id'])));
		$update->executeStatement();
	}//end writeDecision()

	/**
	 * The facts about a decision the legacy step schema cannot express.
	 *
	 * @param Task $task The migrated, decided task.
	 *
	 * @return array<int, string> One line per fact that stays behind.
	 */
	private function lostFacts(Task $task): array {
		$lost = ['the per-entry audit trail'];
		if ((string)$task->getPerformerType() !== '' && (string)$task->getPerformerType() !== Task::PERFORMER_GROUP) {
			$lost[] = sprintf("performer type '%s'", (string)$task->getPerformerType());
		}

		if (trim((string)$task->getOnBehalfOf()) !== '') {
			$lost[] = sprintf("on-behalf-of identity '%s'", (string)$task->getOnBehalfOf());
		}

		if (trim((string)$task->getMandate()) !== '') {
			$lost[] = sprintf("mandate '%s'", (string)$task->getMandate());
		}

		return $lost;
	}//end lostFacts()
}//end class
