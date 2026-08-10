<?php

/**
 * Reads and rebuilds the indexed trigger set.
 *
 * The rows here are DERIVED — `FlowTriggerDerivation` reads a flow's trigger
 * nodes and this writes the result. Nothing else may write them: a row that is
 * not reproducible from the flow's nodes is a subscription with no visible
 * cause, which is the failure mode the whole node model exists to remove.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The indexed trigger set.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */
class FlowTriggerMapper
{

    /**
     * The table holding the derived trigger set.
     *
     * @var string
     */
    public const TABLE = 'openregister_flow_triggers';

    /**
     * Constructor.
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(private readonly IDBConnection $db)
    {

    }//end __construct()

    /**
     * The UUIDs of enabled flows subscribed to one object event.
     *
     * ONE indexed read, whatever the number of flows or the number of triggers
     * each carries. `register` and `schema` are matched exactly — a trigger node
     * cannot express "any", so there is no unscoped row to also match.
     *
     * @param string $event    The event id.
     * @param string $register The register slug the event fired on.
     * @param string $schema   The schema slug the event fired on.
     *
     * @return array<int, string> The flow UUIDs, each at most once.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
     */
    public function flowUuidsFor(string $event, string $register, string $schema): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('flow_uuid')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('event', $qb->createNamedParameter($event)))
            ->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($register)))
            ->andWhere($qb->expr()->eq('schema_slug', $qb->createNamedParameter($schema)))
            ->andWhere(
                $qb->expr()->eq(
                    'enabled',
                    $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
                )
            );

        $result = $qb->executeQuery();
        $uuids  = [];
        while (($row = $result->fetch()) !== false) {
            $uuids[] = (string) $row['flow_uuid'];
        }

        $result->closeCursor();

        return $uuids;

    }//end flowUuidsFor()

    /**
     * Whether a flow has any derived trigger rows at all.
     *
     * This is what the column fallback turns on: a flow with NO rows has not
     * been converted (or cannot be), and its columns still decide. Asking
     * "are there rows" rather than "did the match succeed" is deliberate —
     * a converted flow that simply does not want THIS event must not fall back
     * to its columns and fire anyway.
     *
     * @param string $flowUuid The flow's UUID.
     *
     * @return bool Whether the flow is represented in the index.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
     */
    public function hasTriggers(string $flowUuid): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('flow_uuid')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return ($row !== false);

    }//end hasTriggers()

    /**
     * The UUIDs of every flow represented in the index.
     *
     * Used to decide, in ONE read, which flows the column fallback still owns —
     * rather than asking `hasTriggers()` per candidate flow.
     *
     * @return array<int, string> The flow UUIDs.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
     */
    public function representedFlowUuids(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('flow_uuid')->from(self::TABLE);

        $result = $qb->executeQuery();
        $uuids  = [];
        while (($row = $result->fetch()) !== false) {
            $uuids[] = (string) $row['flow_uuid'];
        }

        $result->closeCursor();

        return $uuids;

    }//end representedFlowUuids()

    /**
     * Replace one flow's trigger rows with the given set.
     *
     * Delete-then-insert rather than a diff: the set is small, and a diff would
     * have to decide what "the same trigger" means when a node's config is
     * edited — getting that wrong leaves a flow subscribed to an event it no
     * longer declares, which is the one outcome worse than not being subscribed.
     *
     * @param string $flowUuid The flow's UUID.
     * @param array  $triggers The derived triggers, each event/register/schema.
     * @param bool   $enabled  Whether the flow is enabled.
     *
     * @return int The number of rows written.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
     */
    public function replaceFor(string $flowUuid, array $triggers, bool $enabled): int
    {
        $this->deleteFor(flowUuid: $flowUuid);

        $written = 0;
        foreach ($triggers as $trigger) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::TABLE)
                ->values(
                    [
                        'flow_uuid'   => $qb->createNamedParameter($flowUuid),
                        'event'       => $qb->createNamedParameter((string) $trigger['event']),
                        'register'    => $qb->createNamedParameter((string) $trigger['register']),
                        'schema_slug' => $qb->createNamedParameter((string) $trigger['schema']),
                        'enabled'     => $qb->createNamedParameter($enabled, IQueryBuilder::PARAM_BOOL),
                    ]
                );
            $qb->executeStatement();
            $written++;
        }

        return $written;

    }//end replaceFor()

    /**
     * Drop one flow's trigger rows.
     *
     * @param string $flowUuid The flow's UUID.
     *
     * @return int The number of rows removed.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
     */
    public function deleteFor(string $flowUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)));

        return $qb->executeStatement();

    }//end deleteFor()
}//end class
