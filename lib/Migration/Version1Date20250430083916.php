<?php
// phpcs:ignoreFile
/**
 * Migration to add and modify columns in various tables and drop unused tables.
 *
 * This migration adds columns to the openregister_objects, openregister_schemas,
 * openregister_registers, and openregister_audit_trails tables. It also drops the
 * openregister_object_audit_logs table as it is no longer used.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git-id>
 *
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1Date20250430083916 extends SimpleMigrationStep
{
    /**
     * Change the database schema
     *
     * @param IOutput        $output Output for the migration process
     * @param Closure       $schemaClosure The schema closure
     * @param array<string> $options Migration options
     *
     * @phpstan-return ISchemaWrapper|null
     * @psalm-return ISchemaWrapper|null
     * @return ISchemaWrapper|null The modified schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Update the openregister_configurations table
        $table = $schema->getTable('openregister_schemas');

        // Add the authorization column if it doesn't exist
        if (!$table->hasColumn('icon')) {
            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
        }
        
        
        // Update the openregister_configurations table
        $table = $schema->getTable('openregister_objects');

        // Add the authorization column if it doesn't exist
        if (!$table->hasColumn('size')) {
            $table->addColumn('size', Types::INTEGER, [
                'notnull' => false,
            ]);
        }

        if (!$table->hasColumn('published')) {
            $table->addColumn('published', Types::DATETIME, ['notnull' => false]);
        }

        // Update the openregister_registers table
        $table = $schema->getTable('openregister_audit_trails');

        // Add the authorization column if it doesn't exist
        if (!$table->hasColumn('size')) {
            $table->addColumn('size', Types::INTEGER, [
                'notnull' => false,
            ]);
        }

        // drop the files table (deprecated)
        if ($schema->hasTable('openregister_files')) {
            $schema->dropTable('openregister_files');
        }

        // drop the audit log table (deprecated)
        if ($schema->hasTable('openregister_object_audit_logs')) {
            $schema->dropTable('openregister_object_audit_logs');
        }


        return $schema;
    }
} 