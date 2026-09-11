<?php

/**
 * OpenRegister TenantUsage Mapper
 *
 * Database mapper for tenant usage tracking records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * TenantUsageMapper
 *
 * Handles CRUD operations for tenant usage tracking records.
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<TenantUsage>
 */
class TenantUsageMapper extends QBMapper {
	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_tenant_usage', entityClass: TenantUsage::class);
	}//end __construct()

	/**
	 * Find usage record for an organisation and period.
	 *
	 * @param string $organisationUuid Organisation UUID
	 * @param DateTime $period Hourly bucket timestamp
	 *
	 * @return TenantUsage|null The usage record or null
	 */
	public function findByOrgAndPeriod(string $organisationUuid, DateTime $period): ?TenantUsage {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq(
					'organisation_uuid',
					$qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)
				)
			)
			->andWhere(
				$qb->expr()->eq(
					'period',
					$qb->createNamedParameter(
						$period->format('Y-m-d H:i:s'),
						IQueryBuilder::PARAM_STR
					)
				)
			);

		try {
			return $this->findEntity(query: $qb);
		} catch (\Exception $e) {
			return null;
		}
	}//end findByOrgAndPeriod()

	/**
	 * Find usage records for an organisation within a date range.
	 *
	 * @param string $organisationUuid Organisation UUID
	 * @param DateTime $from Start date
	 * @param DateTime $to End date
	 *
	 * @return TenantUsage[] Array of usage records
	 */
	public function findByOrgAndDateRange(
		string $organisationUuid,
		DateTime $from,
		DateTime $to,
	): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq(
					'organisation_uuid',
					$qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)
				)
			)
			->andWhere(
				$qb->expr()->gte(
					'period',
					$qb->createNamedParameter($from->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)
				)
			)
			->andWhere(
				$qb->expr()->lte(
					'period',
					$qb->createNamedParameter($to->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)
				)
			)
			->orderBy('period', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByOrgAndDateRange()

	/**
	 * Upsert a usage record (insert or update on conflict).
	 *
	 * @param string $organisationUuid Organisation UUID
	 * @param DateTime $period Hourly bucket
	 * @param int $requestCount Requests to add
	 * @param int $bandwidthBytes Bandwidth to add
	 * @param int $storageBytes Current storage usage
	 *
	 * @return TenantUsage The upserted entity
	 */
	public function upsertUsage(
		string $organisationUuid,
		DateTime $period,
		int $requestCount,
		int $bandwidthBytes,
		int $storageBytes,
	): TenantUsage {
		$existing = $this->findByOrgAndPeriod(organisationUuid: $organisationUuid, period: $period);

		if ($existing !== null) {
			$existing->setRequestCount($existing->getRequestCount() + $requestCount);
			$existing->setBandwidthBytes($existing->getBandwidthBytes() + $bandwidthBytes);
			$existing->setStorageBytes($storageBytes);
			$existing->setUpdated(new DateTime());
			return $this->update(entity: $existing);
		}

		$entity = new TenantUsage();
		$entity->setOrganisationUuid($organisationUuid);
		$entity->setPeriod($period);
		$entity->setRequestCount($requestCount);
		$entity->setBandwidthBytes($bandwidthBytes);
		$entity->setStorageBytes($storageBytes);
		$entity->setCreated(new DateTime());
		$entity->setUpdated(new DateTime());

		return $this->insert(entity: $entity);
	}//end upsertUsage()

	/**
	 * Delete every organisation's usage records older than a given date.
	 *
	 * INSTALLATION-WIDE. This is a retention sweep across ALL organisations
	 * and has no organisation parameter. Never use it to remove one
	 * organisation's records: TenantPurgeJob once did, with a far-future date,
	 * and every purge deleted the usage history of every tenant. Use
	 * deleteByOrganisation() for that.
	 *
	 * @param DateTime $before Delete records before this date
	 *
	 * @return int Number of deleted records
	 */
	public function deleteOlderThan(DateTime $before): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->lt(
					'period',
					$qb->createNamedParameter($before->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)
				)
			);

		return $qb->executeStatement();
	}//end deleteOlderThan()

	/**
	 * Delete all usage records of one organisation, and nothing else.
	 *
	 * The WHERE clause is the organisation's uuid and only that, so the rows of
	 * every other organisation are out of reach whatever their period. An empty
	 * uuid is refused rather than run: it names no organisation, and a purge
	 * that cannot say whose records it deletes must not delete any.
	 *
	 * @param string $organisationUuid The uuid of the organisation being purged
	 *
	 * @return int Number of deleted records
	 *
	 * @throws InvalidArgumentException When the uuid is empty
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-a-purge-must-touch-only-the-organisation-it-purges
	 */
	public function deleteByOrganisation(string $organisationUuid): int {
		if (trim($organisationUuid) === '') {
			throw new InvalidArgumentException('Refusing to delete usage records without an organisation uuid');
		}

		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq(
					'organisation_uuid',
					$qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)
				)
			);

		return $qb->executeStatement();
	}//end deleteByOrganisation()
}//end class
