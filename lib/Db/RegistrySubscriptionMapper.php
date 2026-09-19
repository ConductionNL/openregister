<?php

/**
 * Mapper for the registry subscription state (finding B22).
 *
 * One row per object, keyed by object uuid. Requesting a subscription
 * again upserts the same row rather than creating a second one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes `openregister_registry_subs`.
 *
 * @template-extends QBMapper<RegistrySubscription>
 */
class RegistrySubscriptionMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_registry_subs', entityClass: RegistrySubscription::class);
	}//end __construct()

	/**
	 * The subscription row for one object, or null when it never requested one.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return RegistrySubscription|null The row, when any.
	 */
	public function findForObject(string $objectUuid): ?RegistrySubscription {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findForObject()

	/**
	 * The subscription rows for one object per batch call, indexed by
	 * object uuid, for the render-mirror choke point to consult without an
	 * N+1 query per listed row.
	 *
	 * @param array<int, string> $objectUuids The object uuids to look up.
	 *
	 * @return array<string, RegistrySubscription> Rows found, keyed by object uuid.
	 */
	public function findForObjects(array $objectUuids): array {
		if (count($objectUuids) === 0) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('object_uuid', $qb->createNamedParameter($objectUuids, IQueryBuilder::PARAM_STR_ARRAY)));

		$rows = [];
		foreach ($this->findEntities(query: $qb) as $row) {
			$rows[(string)$row->getObjectUuid()] = $row;
		}

		return $rows;
	}//end findForObjects()

	/**
	 * Every ACTIVE row subscribed to a given registry+identity value — the
	 * inbound update endpoint's lookup path. More than one row can match:
	 * two different objects (in different registers) may legitimately track
	 * the same real-world BSN or KvK number.
	 *
	 * @param string $registry The registry id (`brp`, `kvk`, ...).
	 * @param string $identityValue The identity value the registry pushed a change for.
	 *
	 * @return array<int, RegistrySubscription> Matching active rows.
	 */
	public function findActiveByIdentity(string $registry, string $identityValue): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('registry', $qb->createNamedParameter($registry)))
			->andWhere($qb->expr()->eq('identity_value', $qb->createNamedParameter($identityValue)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(RegistrySubscription::STATE_ACTIVE)));

		return $this->findEntities(query: $qb);
	}//end findActiveByIdentity()

	/**
	 * Insert or update a state row, keyed by object uuid.
	 *
	 * @param RegistrySubscription $subscription The row to persist.
	 *
	 * @return RegistrySubscription The persisted row.
	 */
	public function save(RegistrySubscription $subscription): RegistrySubscription {
		if ($subscription->getId() === null) {
			return $this->insert(entity: $subscription);
		}

		return $this->update(entity: $subscription);
	}//end save()

	/**
	 * Delete the subscription row for an object, if any (called when the
	 * object itself is deleted — this table never outlives its object).
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return void
	 */
	public function deleteForObject(string $objectUuid): void {
		$row = $this->findForObject(objectUuid: $objectUuid);
		if ($row !== null) {
			$this->delete(entity: $row);
		}
	}//end deleteForObject()
}//end class
