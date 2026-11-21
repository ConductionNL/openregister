<?php

/**
 * OpenRegister OriginalCustodyMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTimeInterface;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Stores the custody rows of published redacted copies.
 *
 * @template-extends QBMapper<OriginalCustody>
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */
class OriginalCustodyMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_custody', entityClass: OriginalCustody::class);
	}//end __construct()

	/**
	 * The most recent custody row naming the file as original or as copy.
	 *
	 * @param int $fileId The original's or the copy's file id.
	 *
	 * @return OriginalCustody|null The row, or null when none was written.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public function findLatestForFile(int $fileId): ?OriginalCustody {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('original_file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('copy_file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
				)
			)
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);

		return ($rows[0] ?? null);
	}//end findLatestForFile()

	/**
	 * The scheduled rows whose deletion date has passed, oldest first.
	 *
	 * @param DateTimeInterface $now   The moment to compare against.
	 * @param int               $limit The most rows to return.
	 *
	 * @return list<OriginalCustody> The rows.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public function findDue(DateTimeInterface $now, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(OriginalCustody::STATUS_SCHEDULED)))
			->andWhere($qb->expr()->isNotNull('delete_after'))
			->andWhere($qb->expr()->lte('delete_after', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)))
			->orderBy('delete_after', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		return array_values($this->findEntities(query: $qb));
	}//end findDue()
}//end class
