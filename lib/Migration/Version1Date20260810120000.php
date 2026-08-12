<?php

/**
 * Adds the `annotations` column to `openregister_flows`.
 *
 * Free-placed notes an author pins to the canvas — the document's third
 * element, alongside `nodes` and `edges`. Each entry is `{id, x, y, text}` and
 * belongs to no node and no edge.
 *
 * The COLUMN NAME is the point of this migration, and it nearly went wrong.
 * `openregister_flows` already has a `notes` column of type STRING — the
 * flow's own prose — so calling this one `notes` would have collided with a
 * populated column of a different type. Depending on the platform that is
 * either a migration failure or a silent coercion of every existing flow's
 * prose into something a JSON decoder later rejects. A note about the whole
 * flow and a note pinned at a point on the canvas are different things and do
 * not share a name.
 *
 * Nullable, no default, no backfill. NULL means "this flow has no annotations",
 * which is true of every existing row: nothing could have written one before
 * this column existed. An empty array as a default would be the same claim
 * spelled more expensively.
 *
 * The engine does not read this column. `FlowDefinitionBuilder` reads `nodes`
 * and `edges` by key and never enumerates the document, so an annotation
 * contributes no place, no transition and no marking — which is the whole
 * reason annotations are not nodes. A node is lowered to a transition and
 * becomes something the run moves through; a comment arriving as a node would
 * be built, marked and waited on.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-the-engine-preserves-graph-annotations-and-never-executes-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the nullable `annotations` JSON column.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-the-engine-preserves-graph-annotations-and-never-executes-them
 */
class Version1Date20260810120000 extends SimpleMigrationStep
{
    /**
     * Add the column.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema, or null when unchanged.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-the-engine-preserves-graph-annotations-and-never-executes-them
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_flows') === false) {
            $output->info('openregister_flows does not exist yet; nothing to alter.');
            return null;
        }

        $table = $schema->getTable('openregister_flows');

        if ($table->hasColumn('annotations') === true) {
            return null;
        }

        $table->addColumn(
            'annotations',
            Types::JSON,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        return $schema;

    }//end changeSchema()
}//end class
