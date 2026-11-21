<?php

/**
 * BackfillTaskSubjectLocation — record the register and schema of every
 * existing task's subject.
 *
 * Tasks created before `TaskSubjectLocator` carried only their subject's uuid,
 * so the inbox resolved each subject with a UNION over every magic table:
 * measured 2026-10-06 at 1.5 to 6 s per `GET /api/flow-tasks` on an instance
 * with 1,664 such tables, where 0 of 224 tasks were located. New tasks are
 * located when written; this step catches up the rows already there.
 *
 * Idempotent: it only touches rows still missing a location, so a second run
 * finds nothing to do. A subject that no longer exists stays unlocated and the
 * inbox keeps its cross-table fallback for it, so the worst case is the old
 * speed, never a lost subject.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Task\TaskSubjectLocatorInterface;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Locates the subjects of existing tasks.
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */
class BackfillTaskSubjectLocation implements IRepairStep {

	/**
	 * Subjects located per cross-table search. One search costs about as much
	 * for 500 uuids as for one, and 500 keeps the statement well under any
	 * driver's parameter limit.
	 */
	private const BATCH = 500;

	/**
	 * Constructor.
	 *
	 * Resolved lazily from the container, as the other repair steps are: this
	 * runs during install and upgrade, when the task table may not exist yet.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function getName(): string {
		return 'Record the register and schema of each task\'s subject';
	}//end getName()

	/**
	 * Run the backfill. Never throws: a slow inbox is a far better outcome
	 * than an upgrade that aborted over a task row.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function run(IOutput $output): void {
		$backfill = $this->prepare(output: $output);
		if ($backfill === null) {
			return;
		}

		foreach ($backfill->batches(size: self::BATCH) as $batch) {
			$this->locateBatch(backfill: $backfill, batch: $batch);
		}

		$output->info(self::summary(backfill: $backfill));
	}//end run()

	/**
	 * The closing line: how many of the tasks without a location this pass located.
	 *
	 * @param TaskSubjectLocationBackfill $backfill The finished pass.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private static function summary(TaskSubjectLocationBackfill $backfill): string {
		return sprintf(
			'Task subject location: %d of %d tasks located; the rest keep the cross-table lookup.',
			$backfill->located,
			$backfill->total()
		);
	}//end summary()

	/**
	 * Resolve the services and read the unlocated subjects, or say why the step is skipped.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return TaskSubjectLocationBackfill|null The pass, or null when there is nothing to do.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function prepare(IOutput $output): ?TaskSubjectLocationBackfill {
		try {
			$db = $this->container->get(IDBConnection::class);
			$locator = $this->container->get(TaskSubjectLocatorInterface::class);
			if ($db->tableExists('openregister_tasks') === false) {
				$output->info('Task subject location: no task table yet.');
				return null;
			}

			$backfill = TaskSubjectLocationBackfill::load(db: $db, locator: $locator);
		} catch (Throwable $e) {
			$output->info('Task subject location backfill skipped: ' . $e->getMessage());
			return null;
		}

		return $backfill;
	}//end prepare()

	/**
	 * Locate one batch; a batch that fails is logged and the next one still runs.
	 *
	 * @param TaskSubjectLocationBackfill $backfill The pass.
	 * @param array<int, string>          $batch    The subject uuids.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function locateBatch(TaskSubjectLocationBackfill $backfill, array $batch): void {
		try {
			$backfill->locateBatch(batch: $batch);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[BackfillTaskSubjectLocation] A batch could not be located: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end locateBatch()
}//end class
