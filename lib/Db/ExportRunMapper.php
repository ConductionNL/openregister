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
	 * The runs one actor sees in the area, newest first.
	 *
	 * Expired runs are included: the row outliving the file is the point, and
	 * an administrator asked "who holds an export of this register" needs the
	 * ones that are gone as much as the ones that are not.
	 *
	 * @param string|null $actor    The actor, or null for every actor (admin).
	 * @param array       $filters  Optional equality filters on register, schema, profile, source or status.
	 * @param int         $limit    Page size.
	 * @param int         $offset   Page offset.
	 *
	 * @return ExportRun[] The runs.
	 *
	 * @psalm-return list<ExportRun>
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function findForActor(?string $actor, array $filters = [], int $limit = 50, int $offset = 0): array {
		$columns = [
			'register' => 'register_name',
			'schema' => 'schema_name',
			'profile' => 'profile',
			'source' => 'source',
			'status' => 'status',
		];

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->orderBy('produced_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		if ($actor !== null) {
			$qb->andWhere($qb->expr()->eq('actor', $qb->createNamedParameter($actor)));
		}

		foreach ($filters as $key => $value) {
			// An unknown filter key is DROPPED rather than widening the result:
			// a query that silently ignores a narrowing term returns more than
			// the caller asked for, which is the wrong direction to fail in.
			if (isset($columns[$key]) === false || $value === null || $value === '') {
				continue;
			}

			$qb->andWhere($qb->expr()->eq($columns[$key], $qb->createNamedParameter((string)$value)));
		}

		return $this->findEntities(query: $qb);
	}//end findForActor()

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
					$qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)
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
