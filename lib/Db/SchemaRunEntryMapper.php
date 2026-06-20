<?php

/**
 * Mapper for SchemaRunEntry entities (per-object run results side table).
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class SchemaRunEntryMapper
 *
 * @template-extends QBMapper<SchemaRunEntry>
 */
class SchemaRunEntryMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_schema_run_entries', entityClass: SchemaRunEntry::class);

    }//end __construct()

    /**
     * Find entries for a run, optionally filtered by outcome.
     *
     * @param int         $runId   The run id.
     * @param string|null $outcome Optional outcome filter.
     * @param int|null    $limit   Optional page size.
     * @param int|null    $offset  Optional page offset.
     *
     * @return SchemaRunEntry[] The entries.
     */
    public function findByRun(int $runId, ?string $outcome=null, ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');

        if ($outcome !== null) {
            $qb->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter($outcome)));
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);

    }//end findByRun()

    /**
     * Create an entry from an array of values.
     *
     * @param array<string, mixed> $data The entry values.
     *
     * @return SchemaRunEntry The persisted entry.
     */
    public function createFromArray(array $data): SchemaRunEntry
    {
        $entry = new SchemaRunEntry();
        $entry->hydrate($data);

        return $this->insert(entity: $entry);

    }//end createFromArray()

    /**
     * Delete all entries for a run.
     *
     * @param int $runId The run id.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByRun(int $runId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();

    }//end deleteByRun()
}//end class
