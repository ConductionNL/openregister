<?php

/**
 * Database migration to add indexed JSON access on retention field.
 *
 * Supports the retention-management feature: efficient querying of archival metadata
 * (archiefnominatie, archiefstatus, archiefactiedatum, legalHold) stored in the
 * retention JSON column of openregister_objects.
 *
 * PostgreSQL: GIN index with jsonb_path_ops (cast from json to jsonb).
 * MariaDB:    Virtual generated columns + btree indexes on key retention fields.
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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds database-specific indexes on the retention JSON column.
 *
 * This enables performant filtering on retention.archiefnominatie, retention.archiefstatus,
 * retention.archiefactiedatum, and retention.legalHold.active used by the DestructionCheckJob
 * and retention API endpoints.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260322120000 extends SimpleMigrationStep
{
    /**
     * Apply database-specific JSON indexes after schema changes.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return;
        }

        $connection = \OC::$server->get(IDBConnection::class);
        $platform   = $connection->getDatabasePlatform();
        $prefix     = \OC::$server->getConfig()->getSystemValueString('dbtableprefix', 'oc_');
        $tableName  = $prefix.'openregister_objects';

        if (str_contains(get_class($platform), 'PostgreSQL') === true) {
            $this->createPostgreSqlIndex(connection: $connection, tableName: $tableName, output: $output);
        } else if (str_contains(get_class($platform), 'MariaDb') === true
            || str_contains(get_class($platform), 'MySQL') === true
        ) {
            $this->createMariaDbIndexes(connection: $connection, tableName: $tableName, output: $output);
        } else {
            $output->info('Skipping retention JSON index: unsupported database platform');
        }//end if
    }//end postSchemaChange()

    /**
     * Create a GIN index on PostgreSQL using jsonb cast.
     *
     * @param IDBConnection $connection Database connection
     * @param string        $tableName  Full table name with prefix
     * @param IOutput       $output     Migration output
     *
     * @return void
     */
    private function createPostgreSqlIndex(IDBConnection $connection, string $tableName, IOutput $output): void
    {
        $indexName = 'idx_or_objects_retention_gin';

        $result = $connection->executeQuery(
            'SELECT 1 FROM pg_indexes WHERE indexname = :idx',
            ['idx' => $indexName]
        );

        if ($result->fetchOne() !== false) {
            $output->info("GIN index $indexName already exists");
            return;
        }

        // Nextcloud DBAL stores JSON columns as `json` (not `jsonb`),
        // so cast to jsonb before applying jsonb_path_ops.
        $connection->executeStatement(
            "CREATE INDEX $indexName ON $tableName USING gin ((retention::jsonb) jsonb_path_ops)"
        );

        $output->info("Created GIN index $indexName on $tableName.retention");
    }//end createPostgreSqlIndex()

    /**
     * Create virtual generated columns with btree indexes on MariaDB.
     *
     * MariaDB does not support GIN indexes, so we extract the most-queried
     * retention fields into virtual columns and index those instead.
     *
     * @param IDBConnection $connection Database connection
     * @param string        $tableName  Full table name with prefix
     * @param IOutput       $output     Migration output
     *
     * @return void
     */
    private function createMariaDbIndexes(IDBConnection $connection, string $tableName, IOutput $output): void
    {
        $columns = [
            'retention_nominatie' => "JSON_UNQUOTE(JSON_EXTRACT(retention, '$.archiefnominatie'))",
            'retention_status'    => "JSON_UNQUOTE(JSON_EXTRACT(retention, '$.archiefstatus'))",
            'retention_datum'     => "JSON_UNQUOTE(JSON_EXTRACT(retention, '$.archiefactiedatum'))",
        ];

        foreach ($columns as $colName => $expression) {
            $indexName = 'idx_or_obj_'.$colName;

            // Check if column already exists.
            $result = $connection->executeQuery(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME = :tbl AND COLUMN_NAME = :col',
                ['tbl' => $tableName, 'col' => $colName]
            );

            if ($result->fetchOne() !== false) {
                $output->info("Virtual column $colName already exists");
                continue;
            }

            $connection->executeStatement(
                "ALTER TABLE $tableName ADD COLUMN $colName VARCHAR(255) GENERATED ALWAYS AS ($expression) VIRTUAL"
            );
            $connection->executeStatement(
                "CREATE INDEX $indexName ON $tableName ($colName)"
            );

            $output->info("Created virtual column + index $indexName on $tableName.$colName");
        }//end foreach
    }//end createMariaDbIndexes()
}//end class
