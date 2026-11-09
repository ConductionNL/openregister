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
class TenantUsageMapper extends QBMapper implements TenantUsageMapperInterface {
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
		$qb = $this->organisationRowsQuery(organisationUuid: $organisationUuid);
		$this->wherePeriodFrom(qb: $qb, from: $from);
		$this->wherePeriodTo(qb: $qb, to: $to);
		$qb->orderBy('period', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByOrgAndDateRange()

	/**
	 * Every usage row of one organisation, before any period bound.
	 *
	 * @param string $organisationUuid The organisation UUID.
	 *
	 * @return IQueryBuilder The query.
	 */
	private function organisationRowsQuery(string $organisationUuid): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq(
					'organisation_uuid',
					$qb->createNamedParameter($organisationUuid, IQueryBuilder::PARAM_STR)
				)
			);

		return $qb;
	}//end organisationRowsQuery()

	/**
	 * Only periods at or after a moment.
	 *
	 * @param IQueryBuilder $qb   The query being built.
	 * @param DateTime      $from The inclusive lower bound.
	 *
	 * @return void
	 */
	private function wherePeriodFrom(IQueryBuilder $qb, DateTime $from): void {
		$qb->andWhere(
			$qb->expr()->gte(
				'period',
				$qb->createNamedParameter($from->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)
			)
		);
	}//end wherePeriodFrom()

	/**
	 * Only periods at or before a moment.
	 *
	 * @param IQueryBuilder $qb The query being built.
	 * @param DateTime      $to The inclusive upper bound.
	 *
	 * @return void
	 */
	private function wherePeriodTo(IQueryBuilder $qb, DateTime $to): void {
		$qb->andWhere(
			$qb->expr()->lte(
				'period',
				$qb->createNamedParameter($to->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)
			)
		);
	}//end wherePeriodTo()

	/**
	 * Upsert a usage record (insert or update on conflict).
	 *
	 * @param string           $organisationUuid Organisation UUID
	 * @param DateTime         $period           Hourly bucket
	 * @param UsageMeasurement $measurement      The traffic to add and the current storage
	 *
	 * @return TenantUsage The upserted entity
	 */
	public function upsertUsage(string $organisationUuid, DateTime $period, UsageMeasurement $measurement): TenantUsage {
		$existing = $this->findByOrgAndPeriod(organisationUuid: $organisationUuid, period: $period);

		if ($existing !== null) {
			$measurement->addTo(row: $existing);
			$existing->setUpdated(new DateTime());
			return $this->update(entity: $existing);
		}

		$entity = new TenantUsage();
		$entity->setOrganisationUuid($organisationUuid);
		$entity->setPeriod($period);
		$measurement->writeTo(row: $entity);
		$entity->setCreated(new DateTime());
		$entity->setUpdated(new DateTime());

		return $this->insert(entity: $entity);
	}//end upsertUsage()

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
