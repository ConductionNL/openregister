<?php

/**
 * OpenRegister RedactionReviewMarkMapper
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
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Stores review marks; the latest mark of a file is the one that counts.
 *
 * @template-extends QBMapper<RedactionReviewMark>
 */
class RedactionReviewMarkMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_redaction_review_marks', entityClass: RedactionReviewMark::class);
	}//end __construct()

	/**
	 * The most recent mark on a file, or null when nobody checked it.
	 *
	 * @param int $fileId The file.
	 *
	 * @return RedactionReviewMark|null The mark.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.2
	 */
	public function findLatestForFile(int $fileId): ?RedactionReviewMark {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('checked_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		$marks = $this->findEntities(query: $qb);

		return ($marks[0] ?? null);
	}//end findLatestForFile()
}//end class
