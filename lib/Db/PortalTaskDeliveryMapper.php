<?php

/**
 * Reads and writes the portal delivery request records.
 *
 * Two state moves and no delete: a delivery row is evidence of what was
 * asked of the portal and when, and evidence is not tidied away.
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
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper for {@see PortalTaskDelivery}.
 *
 * @template-extends QBMapper<PortalTaskDelivery>
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */
class PortalTaskDeliveryMapper extends QBMapper {

	/**
	 * The table name.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_portal_deliveries';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: PortalTaskDelivery::class);

	}//end __construct()

	/**
	 * Insert a delivery request, stamping uuid, state and timestamps.
	 *
	 * @param Entity $entity The delivery to insert.
	 *
	 * @return PortalTaskDelivery The inserted row.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function insert(Entity $entity): PortalTaskDelivery {
		/**
		 * @var PortalTaskDelivery $entity
		 */
		if ($entity->getUuid() === null) {
			$entity->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($entity->getState() === null) {
			$entity->setState(PortalTaskDelivery::STATE_REQUESTED);
		}

		$now = new DateTime();
		if ($entity->getRequestedAt() === null) {
			$entity->setRequestedAt($now);
		}

		$entity->setCreated($now);

		/**
		 * @var PortalTaskDelivery $inserted
		 */
		$inserted = parent::insert($entity);

		return $inserted;
	}//end insert()

	/**
	 * One delivery by uuid.
	 *
	 * @param string $uuid The delivery uuid.
	 *
	 * @return PortalTaskDelivery The row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When absent.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function findByUuid(string $uuid): PortalTaskDelivery {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * Every delivery row of one task, oldest first.
	 *
	 * @param string $taskUuid The task.
	 *
	 * @return array<int, PortalTaskDelivery> The rows.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function findForTask(string $taskUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_uuid', $qb->createNamedParameter($taskUuid)))
			->orderBy('requested_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findForTask()

	/**
	 * Delivery requests not yet reported on, oldest first: what portaliq
	 * picks up to render and send.
	 *
	 * @param int $limit Page size.
	 *
	 * @return array<int, PortalTaskDelivery> The pending rows.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function findPending(int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(PortalTaskDelivery::STATE_REQUESTED)))
			->orderBy('requested_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults(max(1, min($limit, 500)));

		return $this->findEntities(query: $qb);
	}//end findPending()

	/**
	 * A channel reports the delivery went out.
	 *
	 * @param PortalTaskDelivery $delivery The row.
	 *
	 * @return PortalTaskDelivery The updated row.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function markDelivered(PortalTaskDelivery $delivery): PortalTaskDelivery {
		$delivery->setState(PortalTaskDelivery::STATE_DELIVERED);
		$delivery->setDeliveredAt(new DateTime());
		$delivery->setError(null);

		/**
		 * @var PortalTaskDelivery $updated
		 */
		$updated = parent::update($delivery);

		return $updated;
	}//end markDelivered()

	/**
	 * A channel reports the delivery failed, and why.
	 *
	 * @param PortalTaskDelivery $delivery The row.
	 * @param string $error The failure, as the channel described it.
	 *
	 * @return PortalTaskDelivery The updated row.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function markFailed(PortalTaskDelivery $delivery, string $error): PortalTaskDelivery {
		$delivery->setState(PortalTaskDelivery::STATE_FAILED);
		$delivery->setError(mb_substr($error, 0, 1000));

		/**
		 * @var PortalTaskDelivery $updated
		 */
		$updated = parent::update($delivery);

		return $updated;
	}//end markFailed()

	/**
	 * The delivery rows of several tasks in ONE query, grouped by task uuid.
	 *
	 * @param array<int, string> $taskUuids The tasks.
	 *
	 * @return array<string, array<int, PortalTaskDelivery>> Rows by task uuid.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function findForTasks(array $taskUuids): array {
		$taskUuids = array_values(array_unique(array_filter(array_map('strval', $taskUuids), static fn (string $uuid): bool => $uuid !== '')));
		if ($taskUuids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('task_uuid', $qb->createNamedParameter($taskUuids, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('requested_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$grouped = [];
		foreach ($this->findEntities(query: $qb) as $row) {
			$grouped[(string)$row->getTaskUuid()][] = $row;
		}

		return $grouped;
	}//end findForTasks()
}//end class
