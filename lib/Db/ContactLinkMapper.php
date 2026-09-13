<?php

/**
 * Mapper for contact link entities.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class ContactLinkMapper
 *
 * @template-extends QBMapper<ContactLink>
 */
class ContactLinkMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_contact_links', entityClass: ContactLink::class);
	}//end __construct()

	/**
	 * Find contact links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return ContactLink[] Array of contact links.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find contact links by contact UID.
	 *
	 * @param string $contactUid The contact UID from the vCard.
	 *
	 * @return ContactLink[] Array of contact links.
	 */
	public function findByContactUid(string $contactUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByContactUid()

	/**
	 * Count contact links for an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Count of links.
	 */
	public function countByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countByObjectUuid()

	/**
	 * Delete all contact links for an object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectUuid()

	/**
	 * Find the single link for an (objectUuid, contactUid) pair.
	 *
	 * Backs the upsert path in `ContactService::linkContact()` — the DB
	 * already enforces uniqueness via the Tier-2 composite index
	 * `idx_contact_object_uid_uniq`, but reading first lets the service
	 * update the cached vCard fields (phone/org/avatar) and role
	 * in-place instead of failing on the duplicate-key.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $contactUid The vCard UID.
	 *
	 * @return ContactLink|null The link, or null when no row exists.
	 */
	public function findByObjectAndContact(string $objectUuid, string $contactUid): ?ContactLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndContact()

	/**
	 * The link of one person on one object in one role, or null.
	 *
	 * The upsert key since people-on-objects: a person may hold several
	 * roles on an object, one row each.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $contactUid The contact uid, `user:<uid>` for a user.
	 * @param string|null $role The role, null for a link without one.
	 *
	 * @return ContactLink|null The link.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function findByObjectContactAndRole(string $objectUuid, string $contactUid, ?string $role): ?ContactLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid)))
			->setMaxResults(1);
		if ($role === null || $role === '') {
			$qb->andWhere($qb->expr()->isNull('role'));
		}

		if ($role !== null && $role !== '') {
			$qb->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role)));
		}

		try {
			return $this->findEntity(query: $qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectContactAndRole()

	/**
	 * Every link that names a Nextcloud user, newest first.
	 *
	 * @param string $userId The user id.
	 *
	 * @return ContactLink[] The links.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function findByUserId(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByUserId()
}//end class
