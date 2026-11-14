<?php

/**
 * ForeignIdentifierMapper — the indexed store of the identifiers other systems gave objects.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Reads and writes `openregister_foreign_ids`.
 *
 * @template-extends QBMapper<ForeignIdentifier>
 */
class ForeignIdentifierMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_foreign_ids', entityClass: ForeignIdentifier::class);
	}//end __construct()

	/**
	 * The identifier one issuer gave, or null.
	 *
	 * @param string $issuer The issuing system.
	 * @param string $value  The value as the issuer wrote it.
	 *
	 * @return ForeignIdentifier|null The row, or null when nobody carries it.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function findByIssuerAndValue(string $issuer, string $value): ?ForeignIdentifier {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('issuer', $qb->createNamedParameter($issuer)))
			->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findByIssuerAndValue()

	/**
	 * Every foreign identifier of one object, by issuer then value.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return ForeignIdentifier[] The rows.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function findForObject(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('issuer', 'ASC')
			->addOrderBy('value', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findForObject()
}//end class
