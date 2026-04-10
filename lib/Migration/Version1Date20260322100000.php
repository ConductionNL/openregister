<?php

/**
 * Database migration to add hash chain columns to the audit trails table.
 *
 * Adds `hash` and `previous_hash` columns for cryptographic hash chaining,
 * plus an index on `processing_activity_id` for verwerkingsregister queries.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Adds hash chain columns and processing activity index to audit trails table.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @psalm-suppress UnusedClass
 */
class Version1Date20260322100000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                   $output        Migration output
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array<string, mixed>      $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // Get the schema wrapper from the closure.
        $schema = $schemaClosure();

        $tableName = 'openregister_audit_trails';

        if ($schema->hasTable($tableName) === false) {
            $output->info("Table {$tableName} does not exist, skipping migration");
            return null;
        }

        $table   = $schema->getTable($tableName);
        $changed = false;

        // Add hash column for cryptographic chain integrity.
        if ($table->hasColumn('hash') === false) {
            $table->addColumn(
                'hash',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                    'comment' => 'SHA-256 hash of this entry chained to previous entry',
                ]
            );
            $output->info("Added 'hash' column to {$tableName}");
            $changed = true;
        }

        // Add previous_hash column linking to the preceding entry.
        if ($table->hasColumn('previous_hash') === false) {
            $table->addColumn(
                'previous_hash',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                    'comment' => 'SHA-256 hash of the previous audit trail entry',
                ]
            );
            $output->info("Added 'previous_hash' column to {$tableName}");
            $changed = true;
        }

        // Add index on hash column for verification queries.
        if ($table->hasIndex('idx_audit_hash') === false) {
            $table->addIndex(['hash'], 'idx_audit_hash');
            $output->info("Added index 'idx_audit_hash' on {$tableName}");
            $changed = true;
        }

        // Add index on processing_activity_id for verwerkingsregister queries.
        if ($table->hasIndex('idx_audit_proc_act') === false
            && $table->hasColumn('processing_activity_id') === true
        ) {
            $table->addIndex(['processing_activity_id'], 'idx_audit_proc_act');
            $output->info("Added index 'idx_audit_proc_act' on {$tableName}");
            $changed = true;
        }

        if ($changed === false) {
            $output->info("All columns and indexes already exist on {$tableName}, skipping");
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
