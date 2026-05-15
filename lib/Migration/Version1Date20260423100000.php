<?php

/**
 * Migration to make the audit-trail `object` column nullable.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
 * Relax NOT NULL on legacy `oc_openregister_audit_trails` columns.
 *
 * The referential-integrity audit entries (cascade_delete, set_null,
 * set_default, restrict_blocked) are written from code paths that only know
 * the affected object's UUID, the action, the changed payload, the user ID,
 * and the timestamp. With `object`, `user_name`, and `session` declared
 * NOT NULL the INSERT fails on PostgreSQL, which aborts the entire delete
 * transaction and surfaces as a 403 to the caller. The alternate
 * identifiers (`object_uuid`, `user`) already carry the useful information;
 * `user_name` and `session` are display-only legacy columns, and `object`
 * is a legacy FK-ish integer column.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260423100000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_audit_trails') === false) {
            return null;
        }

        $table   = $schema->getTable('openregister_audit_trails');
        $changed = false;

        foreach (['object', 'user_name', 'session'] as $columnName) {
            if ($table->hasColumn($columnName) === false) {
                continue;
            }

            $column = $table->getColumn($columnName);
            if ($column->getNotnull() === false) {
                continue;
            }

            $column->setNotnull(false);
            $output->info("Relaxed NOT NULL on openregister_audit_trails.$columnName");
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
