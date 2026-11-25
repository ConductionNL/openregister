<?php

/**
 * AuditSealBacklogCursor: the narrow reads the seal-backlog driver walks the backlog with.
 *
 * Every read selects ids or counts only, never a row's payload: audit rows
 * average ~5 KB, and the backlog this exists for spans most of the table.
 * The unsealed-id read is the one the partial index `idx_audit_unsealed`
 * (`WHERE hash IS NULL`) serves, so its predicate is `hash IS NULL` exactly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads where the unsealed audit rows are, by id.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
 */
class AuditSealBacklogCursor implements AuditSealBacklogCursorInterface {

	/**
	 * The audit trail table.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_audit_trails';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The newest row carrying a hash that is no retention tombstone, or 0.
	 *
	 * @return int The id.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function lastLinkId(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))
			->from(self::TABLE)
			->where($qb->expr()->isNotNull('hash'))
			->andWhere($qb->expr()->neq('hash', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->isNull('purged_at'));

		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();

		return (int)$id;
	}//end lastLinkId()

	/**
	 * Up to `$limit` ids of rows without a hash after the given id, ascending.
	 *
	 * @param int $afterId Exclusive lower bound on `id`.
	 * @param int $limit   Most ids to return.
	 *
	 * @return list<int> The ids, oldest first.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function unsealedIdsAfter(int $afterId, int $limit): array {
		if ($limit < 1) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->isNull('hash'))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$ids = [];
		foreach ($result->fetchAll() as $row) {
			$ids[] = (int)$row['id'];
		}

		$result->closeCursor();

		return $ids;
	}//end unsealedIdsAfter()

	/**
	 * How many rows without a hash sit before the given id.
	 *
	 * @param int $beforeId Exclusive upper bound on `id`.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function countUnsealedBefore(int $beforeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'unsealed'))
			->from(self::TABLE)
			->where($qb->expr()->isNull('hash'))
			->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}//end countUnsealedBefore()
}//end class
