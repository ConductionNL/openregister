<?php

/**
 * OpenRegister Authorization Exception Performance Migration
 *
 * This migration adds performance optimization indexes to the authorization
 * exceptions table for improved query performance.
 *
 * @category Database
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add performance optimization indexes to authorization exceptions table
 *
 * This migration adds strategic database indexes to optimize common query patterns
 * in the authorization exception system, significantly improving performance.
 *
 * @category Database
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git_id>
 * @link    https://www.OpenRegister.app
 */
class Version1Date20250903160000 extends SimpleMigrationStep
{


    /**
     * Perform the migration
     *
     * @param         IOutput $output        The output interface for logging
     * @param         Closure $schemaClosure Closure that returns the current schema
     * @param         array   $options       Migration options
     * @phpstan-param array<string, mixed> $options
     * @psalm-param   array<string, mixed> $options
     *
     * @return ISchemaWrapper|null The new schema or null if no changes
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Skip if table doesn't exist yet
        if ($schema->hasTable('openregister_authorization_exceptions') === false) {
            return null;
        }

        $table   = $schema->getTable('openregister_authorization_exceptions');
        $changed = false;

        // Add performance optimization indexes if they don't exist
        // 1. Composite index for most common lookup pattern (user/group + action + active + priority)
        if (!$table->hasIndex('openregister_auth_exc_perf_lookup')) {
            $table->addIndex(
                ['subject_type', 'subject_id', 'action', 'active', 'priority'],
                'openregister_auth_exc_perf_lookup'
            );
            $output->info('Added performance lookup index for authorization exceptions');
            $changed = true;
        }

        // 2. Index for schema-specific lookups with action filtering
        if (!$table->hasIndex('openregister_auth_exc_schema_perf')) {
            $table->addIndex(
                ['schema_uuid', 'action', 'active', 'subject_type', 'priority'],
                'openregister_auth_exc_schema_perf'
            );
            $output->info('Added schema performance index for authorization exceptions');
            $changed = true;
        }

        // 3. Index for organization-specific lookups
        if (!$table->hasIndex('openregister_auth_exc_org_perf')) {
            $table->addIndex(
                ['organization_uuid', 'action', 'active', 'priority'],
                'openregister_auth_exc_org_perf'
            );
            $output->info('Added organization performance index for authorization exceptions');
            $changed = true;
        }

        // 4. Index for bulk user lookups (covering index)
        if (!$table->hasIndex('openregister_auth_exc_bulk_users')) {
            $table->addIndex(
                ['subject_id', 'subject_type', 'action', 'active', 'priority', 'type'],
                'openregister_auth_exc_bulk_users'
            );
            $output->info('Added bulk user lookup index for authorization exceptions');
            $changed = true;
        }

        // 5. Index for exception type and priority sorting
        if (!$table->hasIndex('openregister_auth_exc_type_priority')) {
            $table->addIndex(
                ['type', 'priority', 'active'],
                'openregister_auth_exc_type_priority'
            );
            $output->info('Added type and priority index for authorization exceptions');
            $changed = true;
        }

        // 6. Index for register-specific lookups
        if (!$table->hasIndex('openregister_auth_exc_register_perf')) {
            $table->addIndex(
                ['register_uuid', 'action', 'active', 'priority'],
                'openregister_auth_exc_register_perf'
            );
            $output->info('Added register performance index for authorization exceptions');
            $changed = true;
        }

        // 7. Index for created_by and created_at (for auditing and cleanup)
        if (!$table->hasIndex('openregister_auth_exc_audit')) {
            $table->addIndex(
                ['created_by', 'created_at', 'active'],
                'openregister_auth_exc_audit'
            );
            $output->info('Added audit index for authorization exceptions');
            $changed = true;
        }

        return $changed ? $schema : null;

    }//end changeSchema()


}//end class
