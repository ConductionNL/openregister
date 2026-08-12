<?php

/**
 * Create the running-sequence table backing the declarative `sequence`
 * calculation operator.
 *
 * Adds `openregister_sequences`: one row per (register, schema, scope_key)
 * triple holding the next value to hand out. The `sequence` calc node reserves
 * a number atomically on object CREATE so identifiers like `2026-0042` are
 * declarative — no leaf-app code required. Purely additive; created only when
 * absent so re-running is a no-op.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the `openregister_sequences` table.
 */
class Version1Date20260625000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null The updated schema, or null when no change.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_sequences') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_sequences');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('scope_key', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $table->addColumn('next_value', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 1]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['register_id', 'schema_id', 'scope_key'], 'idx_or_seq_scope');

        $output->info('Created openregister_sequences table');

        return $schema;
    }//end changeSchema()
}//end class
