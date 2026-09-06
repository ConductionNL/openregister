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
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskState;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Write migrated decisions back onto the kept approval-step rows.
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
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
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
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
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ((bool)$input->getOption('dry-run') === true);

		try {
			$result = $this->db->executeQuery(
				'SELECT * FROM `*PREFIX*openregister_approval_steps` WHERE `migrated_task_uuid` IS NOT NULL'
			);
			$steps = $result->fetchAll();
			$result->closeCursor();
		} catch (Throwable $absent) {
			$output->writeln('<error>The legacy approval tables are gone; there is nothing to roll back onto.</error>');

			return Command::FAILURE;
		}

		$written = 0;
		$notCarriable = [];
		foreach ($steps as $step) {
			$written += $this->carryBack(step: $step, dryRun: $dryRun, output: $output, notCarriable: $notCarriable);
		}

		$mode = 'written back';
		if ($dryRun === true) {
			$mode = 'would be written back';
		}

		$output->writeln(sprintf('%d decision(s) %s onto legacy step rows.', $written, $mode));

		if ($notCarriable !== []) {
			$output->writeln('<comment>The legacy schema cannot hold the following facts; they stay ONLY on the task side:</comment>');
			foreach ($notCarriable as $line) {
				$output->writeln('  - ' . $line);
			}
		}

		$output->writeln('The legacy tables were not dropped, and dropping them is not part of any rollback path.');

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Carry ONE step's task-side decision back, when there is one.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 * @param bool $dryRun Whether to report without writing.
	 * @param OutputInterface $output Command output.
	 * @param array<int, string> $notCarriable Collects the facts left behind.
	 *
	 * @return int One when a decision was carried back, zero otherwise.
	 */
	private function carryBack(array $step, bool $dryRun, OutputInterface $output, array &$notCarriable): int {
		$taskUuid = trim((string)$step['migrated_task_uuid']);
		try {
			$task = $this->tasks->findByUuid(uuid: $taskUuid);
		} catch (Throwable $gone) {
			$output->writeln(sprintf('<comment>Step %d: migrated task %s no longer exists; row left as it is.</comment>', (int)$step['id'], $taskUuid));

			return 0;
		}

		if ($task->isInTerminalState() === false || (string)$task->getState() !== Task::STATE_COMPLETED) {
			// No decision was taken on the task side; the step keeps
			// whatever it already recorded.
			return 0;
		}

		$status = 'approved';
		if (TaskState::isRejectingOutcome(outcome: $task->getOutcome()) === true) {
			$status = 'rejected';
		}

		if ((string)$step['status'] === $status && trim((string)($step['decided_by'] ?? '')) !== '') {
			// Already decided identically before migration; nothing new to
			// carry back.
			return 0;
		}

		if ($dryRun === false) {
			$this->writeDecision(step: $step, task: $task, status: $status);
		}

		foreach ($this->lostFacts(task: $task) as $lost) {
			$notCarriable[] = sprintf('step %d (task %s): %s', (int)$step['id'], $taskUuid, $lost);
		}

		return 1;
	}//end carryBack()

	/**
	 * Write one decision onto its legacy step row.
	 *
	 * @param array<string, mixed> $step The legacy step row.
	 * @param Task $task The decided task.
	 * @param string $status The legacy status to record.
	 *
	 * @return void
	 */
	private function writeDecision(array $step, Task $task, string $status): void {
		$update = $this->db->getQueryBuilder();
		$update->update('openregister_approval_steps')
			->set('status', $update->createNamedParameter($status))
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
