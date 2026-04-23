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
 * Relax `object` NOT NULL on `oc_openregister_audit_trails`.
 *
 * The referential-integrity audit entries (cascade_delete, set_null,
 * set_default, restrict_blocked) are written from code paths that only know
 * the affected object's UUID, not its integer primary key. With `object`
 * declared NOT NULL the INSERT fails on PostgreSQL, which aborts the entire
 * delete transaction and surfaces as a 403 to the caller. `object_uuid`
 * already carries the useful identifier; `object` is a legacy FK-ish column.
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

        $table = $schema->getTable('openregister_audit_trails');
        if ($table->hasColumn('object') === false) {
            return null;
        }

        $column = $table->getColumn('object');
        if ($column->getNotnull() === false) {
            return null;
        }

        $column->setNotnull(false);
        $output->info('Relaxed NOT NULL on openregister_audit_trails.object');

        return $schema;
    }//end changeSchema()
}//end class
