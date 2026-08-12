<?php

/**
 * Mapper for CaseToken entities (public "track your case" links).
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
 * @version GIT: <git-id>
 * @link    https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class CaseTokenMapper
 *
 * @template-extends QBMapper<CaseToken>
 */
class CaseTokenMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_case_tokens', entityClass: CaseToken::class);
	}//end __construct()

	/**
	 * Find a case token by its opaque token string.
	 *
	 * Returns null on miss so callers can fail-closed (404) without a
	 * DoesNotExistException leaking through — important for the public
	 * resolve endpoint which must not become an enumeration oracle.
	 *
	 * @param string $token The opaque token string.
	 *
	 * @return CaseToken|null The token row, or null when unknown.
	 */
	public function findByToken(string $token): ?CaseToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByToken()

	/**
	 * Find every token minted against the given object uuid.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return CaseToken[] Token rows.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find a token by its primary id (used by the revoke path so the
	 * controller / provider can address a token unambiguously).
	 *
	 * @param int $id The token row id.
	 *
	 * @return CaseToken|null The token, or null when unknown.
	 */
	public function findById(int $id): ?CaseToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findById()
}//end class
