<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add performance indexes for search and faceting optimization
 *
 * This migration adds indexes on metadata columns (name, summary, description, image)
 * and composite indexes to improve search and faceting performance.
 */
class Version1Date20250902120000 extends SimpleMigrationStep
{
    /**
     * Apply database schema changes for search and faceting performance
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

        // 1. Add single-column indexes for new metadata fields and existing common faceting fields
        // Note: Text columns need length limits to avoid MySQL key length limits
        $singleIndexes = [
            'deleted' => 'objects_deleted_idx',
            'published' => 'objects_published_idx',
            'depublished' => 'objects_depublished_idx',
            'created' => 'objects_created_idx',
            'updated' => 'objects_updated_idx',
            'owner' => 'objects_owner_idx',
            'organisation' => 'objects_organisation_idx',
            'register' => 'objects_register_idx',
            'schema' => 'objects_schema_idx',
            'uuid' => 'objects_uuid_idx',
            'slug' => 'objects_slug_idx',
        ];

        foreach ($singleIndexes as $column => $indexName) {
            if ($table->hasColumn($column) && !$table->hasIndex($indexName)) {
                $table->addIndex([$column], $indexName);
                $output->info("Added index {$indexName} on column {$column}");
            }
        }



        // 2. Add critical composite indexes for common filter combinations
        $compositeIndexes = [
            // For base filtering (deleted + published state)
            'objects_deleted_published_idx' => ['deleted', 'published'],
            'objects_lifecycle_idx' => ['deleted', 'published', 'depublished'],

            // For register/schema filtering with lifecycle
            'objects_register_schema_deleted_idx' => ['register', 'schema', 'deleted'],
            'objects_register_lifecycle_idx' => ['register', 'deleted', 'published'],
            'objects_schema_lifecycle_idx' => ['schema', 'deleted', 'published'],

            // For organisation-based filtering
            'objects_org_lifecycle_idx' => ['organisation', 'deleted', 'published'],

            // For date range queries on faceting
            'objects_created_deleted_idx' => ['created', 'deleted'],
            'objects_updated_deleted_idx' => ['updated', 'deleted'],

            // For combined search on metadata fields
            'objects_name_summary_description_idx' => ['name', 'summary', 'description'],
            'objects_name_description_idx' => ['name', 'description'],
            'objects_name_summary_idx' => ['name', 'summary'],
            'objects_description_summary_idx' => ['description', 'summary'],
        ];

        foreach ($compositeIndexes as $indexName => $columns) {
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
    }

    /**
     * Execute raw SQL for text column indexes that need prefix length limits
     *
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /**
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return;
        }

        // Get database connection for raw SQL
        $connection = \OC::$server->getDatabaseConnection();

        // Add prefix indexes for text columns to avoid key length limits
        $textIndexes = [
            'name' => ['index' => 'objects_name_idx', 'length' => 255],
            'description' => ['index' => 'objects_description_idx', 'length' => 255],
            'summary' => ['index' => 'objects_summary_idx', 'length' => 255],
            'image' => ['index' => 'objects_image_idx', 'length' => 255],
        ];

        foreach ($textIndexes as $column => $config) {
            try {
                // Check if index already exists
                $indexExists = $connection->executeQuery(
                    "SHOW INDEX FROM `*PREFIX*openregister_objects` WHERE Key_name = ?",
                    [$config['index']]
                )->fetch();

                if (!$indexExists) {
                    // Create prefix index with raw SQL
                    $sql = "CREATE INDEX `{$config['index']}` ON `*PREFIX*openregister_objects` (`{$column}`({$config['length']}))";
                    $connection->executeStatement($sql);
                    $output->info("Added prefix index {$config['index']} on column {$column} with length {$config['length']}");
                } else {
                    $output->info("Index {$config['index']} already exists on column {$column}");
                }
            } catch (\Exception $e) {
                $output->warning("Failed to create index {$config['index']} on column {$column}: " . $e->getMessage());
            }
        }
    }
}
