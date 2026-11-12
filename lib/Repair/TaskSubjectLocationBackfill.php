<?php

/**
 * OpenRegister task subject location backfill
 *
 * One pass of the task subject location backfill: the subject uuids of the
 * tasks without a register or schema, located batch by batch and written onto
 * those tasks, with the running count.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Task\TaskSubjectLocatorInterface;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The unlocated task subjects, and how many tasks this pass located.
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */
final class TaskSubjectLocationBackfill {

	/**
	 * Tasks updated so far.
	 *
	 * @var integer
	 */
	public int $located = 0;

	/**
	 * The distinct subject uuids of tasks without a location.
	 *
	 * @var array<int, string>
	 */
	private array $uuids = [];

	/**
	 * Hold the connection and the locator.
	 *
	 * @param IDBConnection               $db      The connection.
	 * @param TaskSubjectLocatorInterface $locator Finds where each subject lives.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly TaskSubjectLocatorInterface $locator,
	) {
	}//end __construct()

	/**
	 * Start a pass: read the distinct subject uuids of the tasks without a location.
	 *
	 * @param IDBConnection               $db      The connection.
	 * @param TaskSubjectLocatorInterface $locator Finds where each subject lives.
	 *
	 * @return self The pass.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public static function load(IDBConnection $db, TaskSubjectLocatorInterface $locator): self {
		$backfill = new self(db: $db, locator: $locator);
		$backfill->loadUnlocatedSubjects();

		return $backfill;
	}//end load()

	/**
	 * Read the distinct subject uuids of tasks without a location.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function loadUnlocatedSubjects(): void {
		$qb = $this->unlocated();
		$qb->selectDistinct('object_uuid');

		$this->uuids = array_map('strval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}//end loadUnlocatedSubjects()

	/**
	 * The loaded uuids in batches.
	 *
	 * @param int $size The batch size.
	 *
	 * @return array<int, array<int, string>> The batches.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function batches(int $size): array {
		return array_chunk($this->uuids, $size);
	}//end batches()

	/**
	 * Locate one batch of subjects and write each location onto its unlocated tasks.
	 *
	 * @param array<int, string> $batch The subject uuids.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function locateBatch(array $batch): void {
		foreach ($this->locator->locateMany(uuids: $batch) as $uuid => $location) {
			$this->located += $this->record(location: ['uuid' => (string)$uuid] + $location);
		}
	}//end locateBatch()

	/**
	 * How many tasks lacked a location before this pass: those still without one plus those it located.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	public function total(): int {
		$qb = $this->unlocated();
		$qb->select($qb->func()->count('*'));

		return (int)$qb->executeQuery()->fetchOne() + $this->located;
	}//end total()

	/**
	 * Tasks with a subject but without its register or schema.
	 *
	 * @return IQueryBuilder The query, without a select.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function unlocated(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->from('openregister_tasks')
			->where($qb->expr()->isNotNull('object_uuid'))
			->andWhere(self::missingLocation(qb: $qb));

		return $qb;
	}//end unlocated()

	/**
	 * Write one subject's location onto its unlocated tasks.
	 *
	 * @param array{uuid: string, registerId: int, schemaId: int} $location The subject uuid and where it lives.
	 *
	 * @return int Tasks updated.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private function record(array $location): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('openregister_tasks')
			->set('register_id', $qb->createNamedParameter($location['registerId'], IQueryBuilder::PARAM_INT))
			->set('schema_id', $qb->createNamedParameter($location['schemaId'], IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($location['uuid'])))
			->andWhere(self::missingLocation(qb: $qb));

		return $qb->executeStatement();
	}//end record()

	/**
	 * A task row without its register or its schema.
	 *
	 * @param IQueryBuilder $qb The query the condition is for.
	 *
	 * @return ICompositeExpression The condition.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md
	 */
	private static function missingLocation(IQueryBuilder $qb): ICompositeExpression {
		return $qb->expr()->orX(
			$qb->expr()->isNull('register_id'),
			$qb->expr()->isNull('schema_id')
		);
	}//end missingLocation()
}//end class
