<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add performance-critical indexes for OpenRegister object searches
 *
 * **PERFORMANCE OPTIMIZATION**: This migration adds composite indexes specifically
 * designed to optimize the most common search patterns used by the searchObjects
 * method and improve sub-500ms response times for simple requests.
 *
 * Key Performance Indexes Added:
 * - register + schema: Most common filter combination
 * - register + schema + created: Common for chronological searches
 * - register + schema + updated: Common for recently modified objects
 * - organisation: Critical for multi-tenancy performance
 * - published + depublished: Critical for publication status filtering
 *
 * @category Migration
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
class Version1Date20250902140000 extends SimpleMigrationStep
{


    /**
     * Apply performance-critical database indexes
     *
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_objects');

        // Skip complex index creation for now to avoid MySQL key length issues.
        // TODO: Add indexes after app is enabled
        $output->info('Skipping complex index creation to avoid MySQL key length issues');

        // Multi-tenancy organization filtering (critical for performance).
        if (!$table->hasIndex('objects_organisation_idx')) {
            $table->addIndex(['organisation'], 'objects_organisation_idx');
            $output->info('Added index objects_organisation_idx for multi-tenancy performance');
        }

        // Publication status filtering.
        if (!$table->hasIndex('objects_published_idx')) {
            $table->addIndex(['published'], 'objects_published_idx');
            $output->info('Added index objects_published_idx for publication filtering');
        }

        if (!$table->hasIndex('objects_depublished_idx')) {
            $table->addIndex(['depublished'], 'objects_depublished_idx');
            $output->info('Added index objects_depublished_idx for depublication filtering');
        }

        // Owner filtering for RBAC.
        if (!$table->hasIndex('objects_owner_idx')) {
            $table->addIndex(['owner'], 'objects_owner_idx');
            $output->info('Added index objects_owner_idx for RBAC owner filtering');
        }

        // Soft delete filtering.
        if (!$table->hasIndex('objects_deleted_idx')) {
            $table->addIndex(['deleted'], 'objects_deleted_idx');
            $output->info('Added index objects_deleted_idx for soft delete filtering');
        }

        // Skip super-performance index creation for now to avoid MySQL key length issues.
        // TODO: Add indexes after app is enabled
        $output->info('Skipping super-performance index creation to avoid MySQL key length issues');

        return $schema;

    }//end changeSchema()


}//end class
