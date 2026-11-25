<?php

/**
 * ConsistencyRepairService: the writing half, as a separate authorised act.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Kernel\Contracts\FileExtractionQueueInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Repairs one named inconsistency, after saying what it will change.
 *
 * D-5 splits the check from the fix, and this is the fix. Three properties are
 * what make it a separate act rather than a second button on the check:
 *
 * 1. **It names what it will change before it runs.** {@see plan()} returns the
 *    rows it would delete, from the check, so the authorisation is given
 *    against a list rather than against a word.
 * 2. **It needs its own authorisation.** The caller passes the uid; there is no
 *    path through here without one.
 * 3. **It is recorded.** One run row per repair, naming the actor and the
 *    objects, so the instance can answer "who changed this, and what did it
 *    hold before" after the person has forgotten.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
class ConsistencyRepairService implements ConsistencyRepairServiceInterface {

	/**
	 * The act, as the run log names it.
	 *
	 * @var string
	 */
	public const ACT = 'OperationsConsole::repair';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection                     $db          The connection the repair writes through.
	 * @param ConsistencyCheckService           $check       The read that says what is wrong.
	 * @param JobRunRecorder                    $recorder    Where the act is recorded.
	 * @param FileExtractionQueueInterface|null $extractions Where an index repair queues its extractions.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ConsistencyCheckService $check,
		private readonly JobRunRecorder $recorder,
		private readonly ?FileExtractionQueueInterface $extractions = null,
	) {
	}//end __construct()

	/**
	 * What the repair would change.
	 *
	 * @param string $slug The probe slug.
	 *
	 * @return array<string, mixed> The plan: the action, the table and the rows.
	 *
	 * @throws RepairRefusedException When no such probe exists.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function plan(string $slug): array {
		$plan = $this->check->repairPlan(slug: $slug);

		if ($plan === null) {
			throw new RepairRefusedException(
				message: 'There is no consistency check called "'.$slug.'", so there is nothing to repair.',
				reason: 'unknown-check'
			);
		}

		$finding = $this->check->checkOne(slug: $slug);

		return [
			'slug' => $slug,
			'action' => $plan['action'],
			'table' => $plan['table'],
			'kind' => ($plan['kind'] ?? IndexReconciliationProbes::REPAIR_DELETE),
			'column' => ($plan['column'] ?? 'id'),
			'count' => (int)($finding['count'] ?? 0),
			'objects' => ($finding['objects'] ?? []),
		];

	}//end plan()

	/**
	 * Apply the repair, as this administrator.
	 *
	 * @param string $slug  The probe slug.
	 * @param string $actor The uid authorising and performing it.
	 *
	 * @return array<string, mixed> What was changed.
	 *
	 * @throws RepairRefusedException When no such probe exists, or nobody is named.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	public function apply(string $slug, string $actor): array {
		$this->refuseWithoutActor(actor: $actor);

		$plan = $this->plan(slug: $slug);

		if ($plan['count'] === 0) {
			return self::nothingRepaired(slug: $slug);
		}

		$changed = $this->repairRows(plan: $plan, actor: $actor);

		return self::repaired(plan: $plan, changed: $changed);

	}//end apply()

	/**
	 * Refuse a repair nobody is named as performing.
	 *
	 * @param string $actor The uid authorising and performing it.
	 *
	 * @return void
	 *
	 * @throws RepairRefusedException When the actor is empty.
	 */
	private function refuseWithoutActor(string $actor): void {
		if ($actor === '') {
			// A repair with no actor is a repair nobody can be asked about.
			throw new RepairRefusedException(
				message: 'A repair is performed by somebody, and no actor was named.',
				reason: 'no-actor'
			);
		}

	}//end refuseWithoutActor()

	/**
	 * Repair the planned rows the way the probe names, and record the act.
	 *
	 * @param array<string, mixed> $plan  The plan the administrator was shown.
	 * @param string               $actor The uid performing the repair.
	 *
	 * @return int How many rows were deleted, marked or queued.
	 *
	 * @throws RepairRefusedException When the probe names a repair this service cannot make.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	private function repairRows(array $plan, string $actor): int {
		$changed = match ($plan['kind']) {
			IndexReconciliationProbes::REPAIR_DELETE => $this->delete(table: (string)$plan['table'], ids: self::rowIds(rows: $plan['objects'])),
			IndexReconciliationProbes::REPAIR_QUEUE_FILE_EXTRACTION => $this->queueFileExtractions(
				fileIds: self::columnIds(rows: $plan['objects'], column: (string)$plan['column'])
			),
			IndexReconciliationProbes::REPAIR_MARK_FOR_VECTORISATION => $this->markForVectorisation(ids: self::rowIds(rows: $plan['objects'])),
			default => throw new RepairRefusedException(
				message: 'The "'.$plan['slug'].'" check names a repair this instance cannot make.',
				reason: 'unknown-repair'
			),
		};

		$this->recorder->recordAct(
			act: new OperatorAct(
				jobClass: self::ACT,
				actor: $actor,
				details: self::repairDetails(plan: $plan),
				message: 'Repaired '.$changed.' row(s) found by the "'.$plan['slug'].'" check.'
			)
		);

		return $changed;

	}//end repairRows()

	/**
	 * The ids of the offending rows, skipping rows that carry none.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the check returned.
	 *
	 * @return array<int, int> The row ids.
	 */
	private static function rowIds(array $rows): array {
		return array_values(
			array_filter(
				array_map(self::rowId(...), $rows),
				static fn (?int $id): bool => $id !== null
			)
		);

	}//end rowIds()

	/**
	 * The distinct integer values of one column of the offending rows, skipping rows without it.
	 *
	 * Stale chunks of one file are many rows naming one file: it is queued once.
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param string                           $column The column.
	 *
	 * @return array<int, int> The values, each once.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	private static function columnIds(array $rows, string $column): array {
		$ids = [];
		foreach ($rows as $row) {
			if (isset($row[$column]) === true) {
				$ids[(int)$row[$column]] = true;
			}
		}

		return array_keys($ids);

	}//end columnIds()

	/**
	 * The id of one offending row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return int|null The id, or null when the row carries none.
	 */
	private static function rowId(array $row): ?int {
		if (isset($row['id']) === true) {
			return (int)$row['id'];
		}

		return null;

	}//end rowId()

	/**
	 * The details recorded with a repair.
	 *
	 * @param array<string, mixed> $plan The plan that was applied.
	 *
	 * @return array<string, mixed> The details.
	 */
	private static function repairDetails(array $plan): array {
		return [
			'check' => $plan['slug'],
			'table' => $plan['table'],
			'objects' => $plan['objects'],
		];

	}//end repairDetails()

	/**
	 * The outcome of a repair whose check found nothing.
	 *
	 * @param string $slug The probe slug.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private static function nothingRepaired(string $slug): array {
		return [
			'slug' => $slug,
			'changed' => 0,
			'objects' => [],
			'recorded' => false,
		];

	}//end nothingRepaired()

	/**
	 * The outcome of an applied repair.
	 *
	 * @param array<string, mixed> $plan    The plan that was applied.
	 * @param int                  $changed How many rows were deleted.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private static function repaired(array $plan, int $changed): array {
		return [
			'slug' => $plan['slug'],
			'changed' => $changed,
			'objects' => $plan['objects'],
			'recorded' => true,
		];

	}//end repaired()

	/**
	 * Queue one text extraction per named file.
	 *
	 * The extraction job re-reads a file whose chunks are older than the file
	 * and reads a file that has none, so queueing is the whole repair; nothing is
	 * deleted first, and the current chunks answer searches until the new ones land.
	 *
	 * @param array<int, int> $fileIds The file ids.
	 *
	 * @return int How many extractions were queued.
	 *
	 * @throws RepairRefusedException When no extraction queue is wired.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	private function queueFileExtractions(array $fileIds): int {
		if ($this->extractions === null) {
			throw new RepairRefusedException(
				message: 'This repair queues extractions, and no extraction queue is available to queue them on.',
				reason: 'no-extraction-queue'
			);
		}

		foreach ($fileIds as $fileId) {
			$this->extractions->queueFileExtraction(fileId: $fileId);
		}

		return count($fileIds);

	}//end queueFileExtractions()

	/**
	 * Mark the named chunks unvectorised, so the vectorisation job embeds them again.
	 *
	 * Keyed on the ids the check returned, like {@see self::delete()}.
	 *
	 * @param array<int, int> $ids The chunk ids.
	 *
	 * @return int How many chunks were marked.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	private function markForVectorisation(array $ids): int {
		if ($ids === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('openregister_chunks')
			->set('vectorized', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return (int)$qb->executeStatement();

	}//end markForVectorisation()

	/**
	 * Delete the named rows, and only those.
	 *
	 * The delete is keyed on the ids the check returned rather than on the
	 * check's own condition. Re-running the condition inside a DELETE would
	 * act on whatever matches NOW, which is not what the administrator was
	 * shown and authorised.
	 *
	 * @param string          $table The table.
	 * @param array<int, int> $ids   The row ids.
	 *
	 * @return int How many rows were deleted.
	 */
	private function delete(string $table, array $ids): int {
		if ($ids === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, $qb::PARAM_INT_ARRAY)));

		return (int)$qb->executeStatement();

	}//end delete()
}//end class
