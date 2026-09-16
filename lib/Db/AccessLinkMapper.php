<?php

/**
 * Mapper for access links.
 *
 * Lookups return null on a miss rather than throwing, because the public
 * endpoint must answer the same 404 for an unknown anchor and a revoked one.
 * A `DoesNotExistException` escaping here would separate the two cases for any
 * caller that forgot to catch it, and that separation is exactly the
 * enumeration oracle the 404 exists to close.
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
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class AccessLinkMapper
 *
 * @template-extends QBMapper<AccessLink>
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
class AccessLinkMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_access_links',
			entityClass: AccessLink::class
		);
	}//end __construct()

	/**
	 * Find a link by its random anchor.
	 *
	 * @param string $anchor The anchor from the URL.
	 *
	 * @return AccessLink|null The link row, or null when no link carries that anchor.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function findByAnchor(string $anchor): ?AccessLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('anchor', $qb->createNamedParameter($anchor)));

		return $this->firstOrNull(query: $qb);
	}//end findByAnchor()

	/**
	 * Find a link by its own uuid.
	 *
	 * @param string $uuid The link uuid.
	 *
	 * @return AccessLink|null The link row, or null when unknown.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function findByUuid(string $uuid): ?AccessLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->firstOrNull(query: $qb);
	}//end findByUuid()

	/**
	 * Find a link by its row id.
	 *
	 * @param int $id The link row id.
	 *
	 * @return AccessLink|null The link row, or null when unknown.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function findById(int $id): ?AccessLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->firstOrNull(query: $qb);
	}//end findById()

	/**
	 * Every link one principal minted, newest first.
	 *
	 * Revoked and expired rows are included on purpose: an owner reviewing what
	 * they published needs to see what they published, not only what is still
	 * open.
	 *
	 * @param string $userId The minting principal.
	 *
	 * @return array<int, AccessLink> The link rows.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function findByCreator(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByCreator()

	/**
	 * Every link over one subject, newest first.
	 *
	 * @param string $subjectType The subject kind.
	 * @param string $subjectId The subject identifier.
	 *
	 * @return array<int, AccessLink> The link rows.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function findBySubject(string $subjectType, string $subjectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter($subjectType)))
			->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findBySubject()

	/**
	 * The first row a query returns, or null.
	 *
	 * @param IQueryBuilder $query The prepared query.
	 *
	 * @return AccessLink|null The row, or null on a miss.
	 */
	private function firstOrNull(IQueryBuilder $query): ?AccessLink {
		try {
			return $this->findEntity(query: $query);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $miss) {
			unset($miss);
			return null;
		}
	}//end firstOrNull()
}//end class
