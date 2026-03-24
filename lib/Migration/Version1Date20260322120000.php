<?php

/**
 * Database migration to add GIN index on retention JSON field for archival metadata filtering.
 *
 * Supports the retention-management feature: efficient querying of archival metadata
 * (archiefnominatie, archiefstatus, archiefactiedatum, legalHold) stored in the
 * retention JSON column of openregister_objects.
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a GIN index on the retention JSON column for efficient archival metadata queries.
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
     * Run custom post-schema-change SQL for GIN index.
     *
     * Doctrine DBAL does not support GIN indexes natively, so we use
     * postSchemaChange to add it via raw SQL on PostgreSQL.
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
        // Get the database schema.
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return;
        }

        // GIN index is PostgreSQL-specific; skip for other databases.
        $connection = \OC::$server->getDatabaseConnection();
        $platform   = $connection->getDatabasePlatform();

        if (str_contains(get_class($platform), 'PostgreSQL') === false) {
            $output->info('Skipping GIN index creation: not PostgreSQL');
            return;
        }

        // Check if index already exists.
        $result = $connection->executeQuery(
            "SELECT 1 FROM pg_indexes WHERE indexname = 'idx_or_objects_retention_gin'"
        );

        if ($result->fetchOne() !== false) {
            $output->info('GIN index idx_or_objects_retention_gin already exists');
            return;
        }

        $connection->executeStatement(
            'CREATE INDEX idx_or_objects_retention_gin ON oc_openregister_objects USING gin (retention jsonb_path_ops)'
        );

        $output->info('Created GIN index idx_or_objects_retention_gin on openregister_objects.retention');
    }//end postSchemaChange()
}//end class
