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
 * Single-column indexes are added through the schema migrator (changeSchema()).
 * Composite indexes are created in postSchemaChange() via raw SQL: that runs
 * after the schema has been applied, so the live `openregister_objects` table
 * is guaranteed to exist, and it lets us branch on the database platform —
 * MySQL/MariaDB need column-length prefixes on TEXT columns to stay under the
 * key-length limit, whereas PostgreSQL and SQLite reject the `col(n)` syntax.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20250828120000 extends SimpleMigrationStep
{
    /**
     * Single-column faceting indexes, keyed by column name.
     *
     * @var array<string, string>
     */
    private const SINGLE_INDEXES = [
        'published'    => 'objects_published_idx',
        'depublished'  => 'objects_depublished_idx',
        'created'      => 'objects_created_idx',
        'updated'      => 'objects_updated_idx',
        'owner'        => 'objects_owner_idx',
        'organisation' => 'objects_organisation_idx',
    ];

    /**
     * Composite faceting indexes, keyed by index name. Text-column entries
     * carry an optional `(n)` length prefix that is only applied on MySQL.
     *
     * @var array<string, array<int, string>>
     */
    private const COMPOSITE_INDEXES = [
        'objects_published_depublished_idx'     => ['published', 'depublished'],
        'objects_register_schema_published_idx' => ['register(20)', 'schema(20)', 'published'],
        'objects_register_published_idx'        => ['register(20)', 'published'],
        'objects_schema_published_idx'          => ['schema(20)', 'published'],
        'objects_org_published_idx'             => ['organisation(20)', 'published'],
        'objects_created_published_idx'         => ['created', 'published'],
        'objects_updated_published_idx'         => ['updated', 'published'],
    ];

    /**
     * Add single-column faceting indexes via the schema migrator.
     *
     * @param IOutput $output        Output interface for logging
     * @param Closure $schemaClosure Schema retrieval closure
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper Modified schema, or null when nothing changed
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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

        $table   = $schema->getTable('openregister_objects');
        $changed = false;

        foreach (self::SINGLE_INDEXES as $column => $indexName) {
            if ($table->hasColumn($column) === true && $table->hasIndex($indexName) === false) {
                $table->addIndex([$column], $indexName);
                $output->info(message: "Added index {$indexName} on column {$column}");
                $changed = true;
            }
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create composite faceting indexes via raw SQL.
     *
     * Runs after the schema has been applied, so `openregister_objects` is
     * guaranteed present and we can pick the right index syntax per platform.
     * Failures are logged and swallowed — a missing index only costs query
     * performance, and re-running the migration must not abort here.
     *
     * @param IOutput $output        Output interface for logging
     * @param Closure $schemaClosure Schema retrieval closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Index conditions require several guards.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return;
        }

        $table       = $schema->getTable('openregister_objects');
        $connection  = \OC::$server->getDatabaseConnection();
        $config      = \OC::$server->getConfig();
        $tablePrefix = (string) $config->getSystemValue('dbtableprefix', 'oc_');
        $dbType      = (string) $config->getSystemValue('dbtype', 'sqlite');
        $usePrefixes = in_array($dbType, ['mysql', 'mariadb'], true);
        $tableName   = $tablePrefix.'openregister_objects';

        foreach (self::COMPOSITE_INDEXES as $indexName => $columns) {
            if ($table->hasIndex($indexName) === true) {
                continue;
            }

            $baseColumns = array_map(
                static function ($col) {
                    return preg_replace('/\(\d+\)/', '', $col);
                },
                $columns
            );

            $missingColumn = false;
            foreach ($baseColumns as $column) {
                if ($table->hasColumn($column) === false) {
                    $missingColumn = true;
                    break;
                }
            }

            if ($missingColumn === true) {
                continue;
            }

            $indexColumns = $baseColumns;
            if ($usePrefixes === true) {
                $indexColumns = $columns;
            }

            try {
                $sql = sprintf('CREATE INDEX %s ON %s (%s)', $indexName, $tableName, implode(', ', $indexColumns));
                $connection->executeStatement($sql);
                $output->info("Added composite index {$indexName} on columns: ".implode(', ', $indexColumns));
            } catch (\Throwable $e) {
                $output->info("Skipped composite index {$indexName}: ".$e->getMessage());
            }
        }//end foreach
    }//end postSchemaChange()
}//end class
