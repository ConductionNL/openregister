<?php

/**
 * OpenRegister Handoff Queue Entry Mapper
 *
 * Mapper for HandoffQueueEntry entities (`oc_openregister_handoff_queue`).
 * Query surface for the queue-mode handoff drain triggers: parked entries
 * overall, per kind, and per source object (availability endpoint's
 * `queued` state).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * HandoffQueueEntryMapper
 *
 * @method HandoffQueueEntry insert(Entity $entity)
 * @method HandoffQueueEntry update(Entity $entity)
 * @method HandoffQueueEntry insertOrUpdate(Entity $entity)
 * @method HandoffQueueEntry delete(Entity $entity)
 * @method HandoffQueueEntry findEntity(IQueryBuilder $query)
 * @method list<HandoffQueueEntry> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<HandoffQueueEntry>
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Scenario: No provider installed, queue mode)
 */
class HandoffQueueEntryMapper extends QBMapper
{
    /**
     * Constructor for HandoffQueueEntryMapper.
     *
     * @param IDBConnection $db Database connection.
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_handoff_queue', entityClass: HandoffQueueEntry::class);

    }//end __construct()

    /**
     * Find a queue entry by id.
     *
     * @param int $id Entry id.
     *
     * @return HandoffQueueEntry The entry.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When no entry has the id.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException Never (id is unique).
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, queue mode)
     */
    public function find(int $id): HandoffQueueEntry
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);

    }//end find()

    /**
     * All parked entries, oldest first (fallback TimedJob sweep).
     *
     * @param int|null $limit Optional maximum number of entries.
     *
     * @return list<HandoffQueueEntry> Parked entries.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, queue mode)
     */
    public function findParked(?int $limit=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(HandoffQueueEntry::STATUS_PARKED)))
            ->orderBy('created', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $this->findEntities(query: $qb);

    }//end findParked()

    /**
     * Parked entries waiting on a specific kind URI (schema-save drain).
     *
     * @param string $kindUri The target kind URI.
     *
     * @return list<HandoffQueueEntry> Parked entries for the kind, oldest first.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, queue mode)
     */
    public function findParkedByKind(string $kindUri): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(HandoffQueueEntry::STATUS_PARKED)))
            ->andWhere($qb->expr()->eq('target_kind', $qb->createNamedParameter($kindUri)))
            ->orderBy('created', 'ASC');

        return $this->findEntities(query: $qb);

    }//end findParkedByKind()

    /**
     * The distinct kind URIs that currently have parked entries.
     *
     * @return array<int, string> Distinct parked kind URIs.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Scenario: No provider installed, queue mode)
     */
    public function findParkedKinds(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectDistinct('target_kind')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(HandoffQueueEntry::STATUS_PARKED)));

        $result = $qb->executeQuery();
        $kinds  = [];
        while (($row = $result->fetch()) !== false) {
            $kinds[] = (string) $row['target_kind'];
        }

        $result->closeCursor();

        return $kinds;

    }//end findParkedKinds()

    /**
     * Parked entries for one source object (availability `queued` state).
     *
     * @param string $sourceObjectUuid The source object's UUID.
     *
     * @return list<HandoffQueueEntry> Parked entries for the object.
     *
     * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
     *   (Requirement: Handoff REST surface)
     */
    public function findParkedForObject(string $sourceObjectUuid): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(HandoffQueueEntry::STATUS_PARKED)))
            ->andWhere($qb->expr()->eq('source_object_uuid', $qb->createNamedParameter($sourceObjectUuid)))
            ->orderBy('created', 'ASC');

        return $this->findEntities(query: $qb);

    }//end findParkedForObject()
}//end class
