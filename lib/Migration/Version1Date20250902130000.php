<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add individual indexes for search optimization
 *
 * This migration adds individual indexes on name, description, and summary columns
 * for improved search performance. Uses prefix indexes for TEXT columns to avoid
 * MySQL key length limits.
 */
class Version1Date20250902130000 extends SimpleMigrationStep
{
    /**
     * Apply database schema changes for search performance
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

        // Add individual indexes for search performance
        // name is STRING(255) - can use regular index
        if ($table->hasColumn('name') && !$table->hasIndex('objects_name_idx')) {
            $table->addIndex(['name'], 'objects_name_idx');
            $output->info('Added index objects_name_idx on name column');
        }

        return $schema;
    }

    /**
     * Execute raw SQL for TEXT column prefix indexes
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

        // Add prefix indexes for TEXT columns (description, summary) to avoid key length limits
        $textIndexes = [
            'description' => ['index' => 'objects_description_idx', 'length' => 255],
            'summary' => ['index' => 'objects_summary_idx', 'length' => 255],
        ];

        foreach ($textIndexes as $column => $config) {
            try {
                // Check if column exists first
                $table = $schema->getTable('openregister_objects');
                if (!$table->hasColumn($column)) {
                    continue;
                }

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
