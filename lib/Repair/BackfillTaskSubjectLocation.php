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

use OCA\OpenRegister\Service\Task\TaskSubjectLocator;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
		try {
			$db = $this->container->get(IDBConnection::class);
			$locator = $this->container->get(TaskSubjectLocator::class);
			if ($db->tableExists('openregister_tasks') === false) {
				$output->info('Task subject location: no task table yet.');
				return;
			}

			$uuids = $this->unlocatedSubjects(db: $db);
		} catch (Throwable $e) {
			$output->info('Task subject location backfill skipped: ' . $e->getMessage());
			return;
		}

		$located = 0;
		foreach (array_chunk($uuids, self::BATCH) as $batch) {
			try {
				foreach ($locator->locateMany(uuids: $batch) as $uuid => $location) {
					$located += $this->record(db: $db, uuid: $uuid, location: $location);
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'[BackfillTaskSubjectLocation] A batch could not be located: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}

		$output->info(
			sprintf(
				'Task subject location: %d of %d tasks located; the rest keep the cross-table lookup.',
				$located,
				$this->countUnlocatedTasks(db: $db) + $located
			)
		);
	}//end run()

	/**
	 * The distinct subject uuids of tasks without a location.
	 *
	 * @param IDBConnection $db The connection.
	 *
	 * @return array<int, string> The uuids.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function unlocatedSubjects(IDBConnection $db): array {
		$qb = $this->unlocated(db: $db);
		$qb->selectDistinct('object_uuid');

		return array_map('strval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}//end unlocatedSubjects()

	/**
	 * How many tasks are still without a location.
	 *
	 * @param IDBConnection $db The connection.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function countUnlocatedTasks(IDBConnection $db): int {
		$qb = $this->unlocated(db: $db);
		$qb->select($qb->func()->count('*'));

		return (int)$qb->executeQuery()->fetchOne();
	}//end countUnlocatedTasks()

	/**
	 * Tasks with a subject but without its register or schema.
	 *
	 * @param IDBConnection $db The connection.
	 *
	 * @return IQueryBuilder The query, without a select.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function unlocated(IDBConnection $db): IQueryBuilder {
		$qb = $db->getQueryBuilder();
		$qb->from('openregister_tasks')
			->where($qb->expr()->isNotNull('object_uuid'))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('register_id'),
					$qb->expr()->isNull('schema_id')
				)
			);

		return $qb;
	}//end unlocated()

	/**
	 * Write one subject's location onto its unlocated tasks.
	 *
	 * @param IDBConnection $db The connection.
	 * @param string $uuid The subject uuid.
	 * @param array{registerId: int, schemaId: int} $location Where it lives.
	 *
	 * @return int Tasks updated.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function record(IDBConnection $db, string $uuid, array $location): int {
		$qb = $db->getQueryBuilder();
		$qb->update('openregister_tasks')
			->set('register_id', $qb->createNamedParameter($location['registerId'], IQueryBuilder::PARAM_INT))
			->set('schema_id', $qb->createNamedParameter($location['schemaId'], IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($uuid)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('register_id'),
					$qb->expr()->isNull('schema_id')
				)
			);

		return $qb->executeStatement();
	}//end record()
}//end class
