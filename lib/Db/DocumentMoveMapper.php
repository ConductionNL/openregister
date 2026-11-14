<?php

/**
 * Reads and writes the document store move mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The mapping rows of a document store move.
 *
 * @extends QBMapper<DocumentMove>
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentMoveMapper extends QBMapper {

	public const TABLE = 'openregister_document_moves';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: DocumentMove::class);

	}//end __construct()

	/**
	 * Every row of one register, oldest first.
	 *
	 * @param int $registerId The register.
	 *
	 * @return list<DocumentMove>
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function forRegister(int $registerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);

	}//end forRegister()

	/**
	 * Mark every verified row of a register switched.
	 *
	 * @param int      $registerId The register.
	 * @param DateTime $at         When it switched.
	 *
	 * @return int The rows switched.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function switchRegister(int $registerId, DateTime $at): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(DocumentMove::STATE_SWITCHED))
			->set('moved_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(DocumentMove::STATE_VERIFIED)));

		return $qb->executeStatement();

	}//end switchRegister()

	/**
	 * The old ids of every file and folder whose copy was switched to.
	 *
	 * @return array<int, true> Old node id => true.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function switchedOldIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('old_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(DocumentMove::STATE_SWITCHED)))
			->andWhere($qb->expr()->neq('kind', $qb->createNamedParameter(DocumentMove::KIND_VERSION)));

		$ids = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$ids[(int)$row['old_id']] = true;
		}

		$result->closeCursor();
		return $ids;

	}//end switchedOldIds()
}//end class
