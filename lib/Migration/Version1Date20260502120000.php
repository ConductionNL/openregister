<?php

/**
 * Migration adding `import_job_id` to `openregister_audit_trails`.
 *
 * Carries the per-import-job tag attached to every `create` audit row
 * generated during a bulk import. Powers the rollback contract added by
 * the `data-import-export` change (decision 2026-05-02): on critical
 * failure or explicit user request, all objects whose creation audit
 * row carries the import-job UUID can be soft-deleted as a unit.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/data-import-export/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an indexed nullable `import_job_id` column to the
 * `openregister_audit_trails` table.
 */
class Version1Date20260502120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_audit_trails') === false) {
            return null;
        }

        $table = $schema->getTable(tableName: 'openregister_audit_trails');

        if ($table->hasColumn(name: 'import_job_id') === false) {
            $table->addColumn(
                name: 'import_job_id',
                typeName: Types::STRING,
                options: [
                    'notnull' => false,
                    'length'  => 36,
                ]
            );
        }

        if ($table->hasIndex(name: 'idx_audit_trails_import_job_id') === false) {
            $table->addIndex(
                columnNames: ['import_job_id'],
                indexName: 'idx_audit_trails_import_job_id'
            );
        }

        return $schema;

    }//end changeSchema()
}//end class
