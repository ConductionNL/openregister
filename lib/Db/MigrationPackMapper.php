<?php

/**
 * Mapper for MigrationPack entities (declarative import mapping definitions).
 *
 * Admin-managed: writes are gated by `MigrationPackService`/`MigrationPacksController`,
 * not this mapper. Backed by `openregister_migration_packs`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class MigrationPackMapper
 *
 * @template-extends QBMapper<MigrationPack>
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class MigrationPackMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_migration_packs', entityClass: MigrationPack::class);
    }//end __construct()

    /**
     * Find a migration pack by numeric id.
     *
     * @param int $id The migration pack id.
     *
     * @return MigrationPack
     *
     * @throws DoesNotExistException When no row matches.
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    public function find(int $id): MigrationPack
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find a migration pack by its pack-document slug (`packSlug`, i.e. the
     * definition's own `id` field). This is the lookup used by the import
     * endpoint's `packId` request parameter.
     *
     * @param string $packSlug The pack document's own `id`.
     *
     * @return MigrationPack
     *
     * @throws DoesNotExistException When no row matches.
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    public function findByPackSlug(string $packSlug): MigrationPack
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('pack_slug', $qb->createNamedParameter($packSlug)));

        return $this->findEntity(query: $qb);
    }//end findByPackSlug()

    /**
     * Find every migration pack.
     *
     * @return MigrationPack[]
     *
     * @spec openspec/specs/migration-mapping-packs/spec.md
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('id', 'ASC');

        return $this->findEntities(query: $qb);
    }//end findAll()
}//end class
