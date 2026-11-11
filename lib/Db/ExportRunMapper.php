<?php

/**
 * Mapper for ExportRun entities.
 *
 * The sweep query selects on `expires_at` and on `status`, which are the two
 * columns the recorder wrote. Nothing here reads a file's timestamp, and
 * nothing should: that inference is what once made every export in this fleet
 * arrive already expired, with a green unit test on each half of it.
 *
 * A run with a null `expires_at` is never selected. It was produced to be
 * kept, and keeping it is the whole meaning of the null.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ExportRunMapper
 *
 * @template-extends QBMapper<ExportRun>
 */
class ExportRunMapper extends QBMapper {

	/**
	 * The table the runs live in.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_export_runs';

	/**
	 * The list filters listForActor() accepts, mapped to their column.
	 *
	 * @var array<string, string>
	 */
	private const FILTER_COLUMNS = [
		'register' => 'register_name',
		'schema' => 'schema_name',
		'profile' => 'profile',
		'source' => 'source',
		'status' => 'status',
		'actor' => 'actor',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: self::TABLE,
			entityClass: ExportRun::class
		);
	}//end __construct()

	/**
	 * Find one run by its uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ExportRun The run.
	 *
	 * @throws DoesNotExistException When no such run exists.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function findByUuid(string $uuid): ExportRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * The runs one actor sees in the area, newest first, for one listing.
	 *
	 * @param string|null $actor The actor, or null for every actor (admin).
	 * @param ListQuery   $query The page (limit, and offset or none) and the filters listForActor() accepts.
	 *
	 * @return ExportRun[] The runs.
	 *
	 * @psalm-return list<ExportRun>
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function listForActor(?string $actor, ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('produced_at', 'DESC')
			->setMaxResults($query->limit)
			->setFirstResult($query->offset ?? 0);

		$this->narrowToActor(qb: $qb, actor: $actor);
		$this->narrowToPeriod(qb: $qb, filters: $query->filters);
		$this->narrowToColumns(qb: $qb, filters: $query->filters);

		return $this->findEntities(query: $qb);
	}//end listForActor()

	/**
	 * Scope a run list to one actor; null (an administrator) adds nothing.
	 *
	 * @param IQueryBuilder $qb    The query being built.
	 * @param string|null   $actor The actor, or null for every actor.
	 *
	 * @return void
	 */
	private function narrowToActor(IQueryBuilder $qb, ?string $actor): void {
		if ($actor !== null) {
			$qb->andWhere($qb->expr()->eq('actor', $qb->createNamedParameter($actor)));
		}
	}//end narrowToActor()

	/**
	 * Bound `produced_at` by the `from` / `until` filters, inclusive on both ends.
	 *
	 * They arrive as DateTime values; the controller parsed and refused a bad
	 * date already, so nothing here guesses at a string.
	 *
	 * @param IQueryBuilder $qb      The query being built.
	 * @param array         $filters The list filters.
	 *
	 * @return void
	 */
	private function narrowToPeriod(IQueryBuilder $qb, array $filters): void {
		$from = ($filters['from'] ?? null);
		if ($from instanceof DateTime === true) {
			$qb->andWhere($qb->expr()->gte('produced_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}

		$until = ($filters['until'] ?? null);
		if ($until instanceof DateTime === true) {
			$qb->andWhere($qb->expr()->lte('produced_at', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}
	}//end narrowToPeriod()

	/**
	 * Add one equality predicate per known column filter.
	 *
	 * An unknown filter key is DROPPED rather than widening the result: a
	 * query that silently ignores a narrowing term returns more than the
	 * caller asked for, which is the wrong direction to fail in.
	 *
	 * @param IQueryBuilder $qb      The query being built.
	 * @param array         $filters The list filters; `from` / `until` are skipped.
	 *
	 * @return void
	 */
	private function narrowToColumns(IQueryBuilder $qb, array $filters): void {
		unset($filters['from'], $filters['until']);

		foreach ($filters as $key => $value) {
			if (isset(self::FILTER_COLUMNS[$key]) === false || is_scalar($value) === false || $value === '') {
				continue;
			}

			$qb->andWhere($qb->expr()->eq(self::FILTER_COLUMNS[$key], $qb->createNamedParameter((string)$value)));
		}
	}//end narrowToColumns()

	/**
	 * The runs whose STORED expiry has passed and whose file is still there.
	 *
	 * A run with a null `expires_at` is absent from this result by the
	 * predicate itself, not by a later check, so nothing downstream has to
	 * remember that a kept run is kept.
	 *
	 * @param DateTime $now   The moment to compare against.
	 * @param int      $limit How many to take in one sweep.
	 *
	 * @return ExportRun[] The runs whose files are due.
	 *
	 * @psalm-return list<ExportRun>
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function findDueForSweep(DateTime $now, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->isNotNull('expires_at'))
			->andWhere(
				$qb->expr()->lte(
					'expires_at',
					$qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->andWhere(
				$qb->expr()->eq('status', $qb->createNamedParameter(ExportRun::STATUS_AVAILABLE))
			)
			->orderBy('expires_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDueForSweep()
}//end class
