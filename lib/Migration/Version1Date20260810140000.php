<?php

/**
 * The indexed trigger set, so trigger NODES can drive matching.
 *
 * A flow's entry points are authored as nodes, but an object event fires inside
 * the dispatch of a user action — a save, an upload, a delete. Deciding which
 * flows want that event by decoding two JSON columns per flow would put a cost
 * proportional to the number of flows on every write in the instance.
 *
 * So the nodes are the authoring surface and THIS TABLE is the matching
 * surface: one row per (flow, event, register, schema), indexed on the three
 * columns a lookup filters by. A flow with several trigger nodes has several
 * rows — the shape `openregister_flows`' four trigger columns could not hold at
 * all, since they store exactly one trigger between them.
 *
 * The old columns are NOT dropped here. They stay authoritative for flows that
 * have no rows in this table, which is every flow until the backfill runs and
 * permanently for the ones that cannot be represented as nodes (an "any
 * register, any schema" subscription, which a trigger node deliberately refuses
 * to express). Dropping them in the same migration that introduces their
 * replacement would make the fallback impossible and stop those flows firing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
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

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `openregister_flow_triggers`.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */
class Version1Date20260810140000 extends SimpleMigrationStep
{
    /**
     * Create the indexed trigger table.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema, or null when unchanged.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_flow_triggers') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_flow_triggers');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);

        // The flow's UUID, not its numeric id: the resolver answers in UUIDs and
        // every other flow surface addresses a flow by UUID.
        $table->addColumn('flow_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);

        $table->addColumn('event', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('register', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('schema_slug', Types::STRING, ['notnull' => true, 'length' => 255]);

        // Denormalised from the flow row so a match needs no join. A trigger
        // lookup runs on every object write, and reading `enabled` from here
        // means the hot path touches ONE table.
        $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);

        $table->setPrimaryKey(['id']);

        // The lookup index, in the order a match filters: an object event knows
        // its event, register and schema, and wants only enabled flows.
        $table->addIndex(['enabled', 'event', 'register', 'schema_slug'], 'or_flowtrig_match_idx');

        // Rebuilding one flow's triggers deletes by flow, so that has an index too.
        $table->addIndex(['flow_uuid'], 'or_flowtrig_flow_idx');

        $output->info('Created openregister_flow_triggers (indexed trigger set derived from trigger nodes).');

        return $schema;

    }//end changeSchema()
}//end class
