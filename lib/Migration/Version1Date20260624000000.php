<?php

/**
 * Database migration to drop deprecated SOLR and publishing columns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Removes:
 * - `indexed_in_solr` column + `file_texts_solr_idx` index from the file_texts
 *   table (the SOLR search index was removed; search runs on the database).
 * - `published`/`depublished` columns from the registers and schemas tables
 *   (anonymous Register/Schema visibility is now decided by RBAC `public`-group
 *   rules with the `$now` dynamic variable, not dedicated publication columns).
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the deprecated SOLR index column and Register/Schema publication columns.
 *
 * The migration is idempotent: each column/index is only dropped when present,
 * so re-running it on an already-migrated database is a no-op.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260624000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema, or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema  = $schemaClosure();
        $changed = false;

        // 1. Drop the SOLR index tracking column + index from the file_texts table.
        if ($schema->hasTable('openregister_file_texts') === true) {
            $fileTexts = $schema->getTable('openregister_file_texts');

            if ($fileTexts->hasIndex('file_texts_solr_idx') === true) {
                $fileTexts->dropIndex('file_texts_solr_idx');
                $output->info('   ✓ Dropped file_texts_solr_idx index');
                $changed = true;
            }

            if ($fileTexts->hasColumn('indexed_in_solr') === true) {
                $fileTexts->dropColumn('indexed_in_solr');
                $output->info('   ✓ Dropped indexed_in_solr column from file_texts table');
                $changed = true;
            }
        }

        // 2. Drop the publication columns from the registers and schemas tables.
        foreach (['openregister_registers', 'openregister_schemas'] as $tableName) {
            if ($schema->hasTable($tableName) === false) {
                continue;
            }

            $table = $schema->getTable($tableName);

            foreach (['published', 'depublished'] as $columnName) {
                if ($table->hasColumn($columnName) === true) {
                    $table->dropColumn($columnName);
                    $output->info('   ✓ Dropped '.$columnName.' column from '.$tableName.' table');
                    $changed = true;
                }
            }
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
