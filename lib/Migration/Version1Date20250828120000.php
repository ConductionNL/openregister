<?php

/**
 * OpenRegister Migration - Add Performance Indexes for Faceting
 *
 * This migration adds critical indexes to optimize faceting performance
 * and reduce query execution time from 7+ seconds to under 1 second.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
 * Migration to add performance indexes for faceting optimization
 *
 * This migration addresses critical performance bottlenecks in the faceting system
 * by adding proper indexes on frequently queried columns and composite indexes
 * for common filter combinations.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20250828120000 extends SimpleMigrationStep
{


    /**
     * Apply database schema changes for faceting performance
     *
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /**
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_objects');

        // 1. Critical single-column indexes for common faceting fields
        $singleIndexes = [
            'deleted' => 'objects_deleted_idx',
            'published' => 'objects_published_idx', 
            'depublished' => 'objects_depublished_idx',
            'created' => 'objects_created_idx',
            'updated' => 'objects_updated_idx',
            'owner' => 'objects_owner_idx',
            'organisation' => 'objects_organisation_idx',
        ];

        foreach ($singleIndexes as $column => $indexName) {
            if ($table->hasColumn($column) && !$table->hasIndex($indexName)) {
                $table->addIndex([$column], $indexName);
                $output->info("Added index {$indexName} on column {$column}");
            }
        }

        // 2. Critical composite indexes for common filter combinations
        $compositeIndexes = [
            // For base filtering (deleted + published state)
            ['deleted', 'published'] => 'objects_deleted_published_idx',
            ['deleted', 'published', 'depublished'] => 'objects_lifecycle_idx',
            
            // For register/schema filtering with lifecycle
            ['register', 'schema', 'deleted'] => 'objects_register_schema_deleted_idx',
            ['register', 'deleted', 'published'] => 'objects_register_lifecycle_idx',
            ['schema', 'deleted', 'published'] => 'objects_schema_lifecycle_idx',
            
            // For organisation-based filtering
            ['organisation', 'deleted', 'published'] => 'objects_org_lifecycle_idx',
            
            // For date range queries on faceting
            ['created', 'deleted'] => 'objects_created_deleted_idx',
            ['updated', 'deleted'] => 'objects_updated_deleted_idx',
        ];

        foreach ($compositeIndexes as $columns => $indexName) {
            // Check all columns exist
            $allColumnsExist = true;
            foreach ($columns as $column) {
                if (!$table->hasColumn($column)) {
                    $allColumnsExist = false;
                    break;
                }
            }
            
            if ($allColumnsExist && !$table->hasIndex($indexName)) {
                $table->addIndex($columns, $indexName);
                $output->info("Added composite index {$indexName} on columns: " . implode(', ', $columns));
            }
        }

        return $schema;

    }//end changeSchema()


}//end class
