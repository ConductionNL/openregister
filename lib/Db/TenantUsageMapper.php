<?php

/**
 * OpenRegister TenantUsage Mapper
 *
 * Database mapper for tenant usage tracking records.
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
class TenantUsageMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_tenant_usage', TenantUsage::class);
    }//end __construct()

    /**
     * Find usage record for an organisation and period.
     *
     * @param string   $organisationUuid Organisation UUID
     * @param DateTime $period           Hourly bucket timestamp
     *
     * @return TenantUsage|null The usage record or null
     */
    public function findByOrgAndPeriod(string $organisationUuid, DateTime $period): ?TenantUsage
    {
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
            return $this->findEntity($qb);
        } catch (\Exception $e) {
            return null;
        }
    }//end findByOrgAndPeriod()

    /**
     * Find usage records for an organisation within a date range.
     *
     * @param string   $organisationUuid Organisation UUID
     * @param DateTime $from             Start date
     * @param DateTime $to               End date
     *
     * @return TenantUsage[] Array of usage records
     */
    public function findByOrgAndDateRange(
        string $organisationUuid,
        DateTime $from,
        DateTime $to
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

        return $this->findEntities($qb);
    }//end findByOrgAndDateRange()

    /**
     * Upsert a usage record (insert or update on conflict).
     *
     * @param string   $organisationUuid Organisation UUID
     * @param DateTime $period           Hourly bucket
     * @param int      $requestCount     Requests to add
     * @param int      $bandwidthBytes   Bandwidth to add
     * @param int      $storageBytes     Current storage usage
     *
     * @return TenantUsage The upserted entity
     */
    public function upsertUsage(
        string $organisationUuid,
        DateTime $period,
        int $requestCount,
        int $bandwidthBytes,
        int $storageBytes
    ): TenantUsage {
        $existing = $this->findByOrgAndPeriod($organisationUuid, $period);

        if ($existing !== null) {
            $existing->setRequestCount($existing->getRequestCount() + $requestCount);
            $existing->setBandwidthBytes($existing->getBandwidthBytes() + $bandwidthBytes);
            $existing->setStorageBytes($storageBytes);
            $existing->setUpdated(new DateTime());
            return $this->update($existing);
        }

        $entity = new TenantUsage();
        $entity->setOrganisationUuid($organisationUuid);
        $entity->setPeriod($period);
        $entity->setRequestCount($requestCount);
        $entity->setBandwidthBytes($bandwidthBytes);
        $entity->setStorageBytes($storageBytes);
        $entity->setCreated(new DateTime());
        $entity->setUpdated(new DateTime());

        return $this->insert($entity);
    }//end upsertUsage()

    /**
     * Delete usage records older than a given date.
     *
     * @param DateTime $before Delete records before this date
     *
     * @return int Number of deleted records
     */
    public function deleteOlderThan(DateTime $before): int
    {
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
}//end class
