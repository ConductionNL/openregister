<?php

/**
 * Mapper for GDPR entities.
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

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Class GdprEntityMapper
 *
 * @method GdprEntity insert(Entity $entity)
 * @method GdprEntity update(Entity $entity)
 * @method GdprEntity insertOrUpdate(Entity $entity)
 * @method GdprEntity delete(Entity $entity)
 * @method GdprEntity find(int|string $id)
 * @method GdprEntity findEntity(IQueryBuilder $query)
 * @method GdprEntity[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<GdprEntity> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<GdprEntity>
 */
class GdprEntityMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection        $db     Database connection.
     * @param LoggerInterface|null $logger Optional structured log sink. Used by lookups
     *                                     that need to surface dedup-invariant violations
     *                                     (more than one catalogue row matching the same
     *                                     value+type). Nullable so legacy callers that
     *                                     constructed the mapper directly without DI
     *                                     continue to work; the warning is silently
     *                                     dropped in that case.
     */
    public function __construct(
        IDBConnection $db,
        private readonly ?LoggerInterface $logger=null
    ) {
        parent::__construct(db: $db, tableName: 'openregister_entities', entityClass: GdprEntity::class);
    }//end __construct()

    /**
     * Public wrapper for findEntities (parent protected method).
     *
     * @param IQueryBuilder $query The query builder.
     *
     * @return list<GdprEntity> Array of entities.
     */
    public function findEntitiesPublic(IQueryBuilder $query): array
    {
        return parent::findEntities(query: $query);
    }//end findEntitiesPublic()

    /**
     * Find entity by ID.
     *
     * @param int $id Entity ID.
     *
     * @return GdprEntity The entity.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If entity not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple entities found.
     */
    public function find(int $id): GdprEntity
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Look up a catalogue entry by its (value, type) pair.
     *
     * Used by `ManualEntityService` to implement the lookup-or-create
     * catalogue dedup invariant: a `(value, type)` pair that already
     * exists is REUSED on subsequent manual-entity adds so the same
     * placeholder ID survives across documents.
     *
     * The query selects up to two rows. If two are returned the dedup
     * invariant has been violated (most likely by a parallel manual-
     * add race per design §D4); we log a warning and return the first
     * row. We do NOT throw — the worst case is two near-identical
     * catalogue rows, neither destructive, and the caller has a usable
     * entity to proceed with.
     *
     * @param string $value Operator-supplied entity value (case- and whitespace-sensitive).
     * @param string $type  Entity type tag (e.g. `PERSON`, `ORGANIZATION`).
     *
     * @return GdprEntity|null The matching entity, or null when no row matches.
     */
    public function findOneByValueAndType(string $value, string $type): ?GdprEntity
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('value', $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR))
                )
            )
            // Deterministic ordering so that when the dedup invariant is
            // violated (two rows for one (value, type) — design §D4) the
            // SAME canonical row wins on every call. Without this the
            // storage engine's arbitrary order could flip the chosen row
            // between retries, yielding unstable `[<TYPE>: <id>]` placeholders.
            ->orderBy('id', 'ASC')
            ->setMaxResults(2);

        $matches = $this->findEntities(query: $qb);
        if (empty($matches) === true) {
            return null;
        }

        if (count($matches) > 1 && $this->logger !== null) {
            // Catalogue dedup invariant violated — log structurally so
            // operators can audit it. PII-safe: we log the type + the
            // colliding ids (not the value, per ADR-005). Value can be
            // re-derived from the row in the catalogue audit log if a
            // forensic step is needed.
            $this->logger->warning(
                '[GdprEntityMapper] findOneByValueAndType: multiple catalogue rows match the same (value, type) — dedup invariant violated.',
                [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'type'         => $type,
                    'collidingIds' => array_map(static fn (GdprEntity $e): int => (int) $e->getId(), $matches),
                ]
            );
        }

        return $matches[0];

    }//end findOneByValueAndType()
}//end class
