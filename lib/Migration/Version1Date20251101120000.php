<?php

/**
 * OpenRegister Applications Table Migration
 *
 * This migration creates the openregister_applications table to store
 * application entities under organisations.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create applications table
 *
 * This migration creates a new table for storing applications:
 * - Applications are logical groupings of configurations, registers, and schemas
 * - Applications can be assigned to organisations for multi-tenancy
 * - Applications support resource allocation (storage, bandwidth, API requests)
 */
class Version1Date20251101120000 extends SimpleMigrationStep
{
    /**
     * Create applications table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        $output->info(message: '🔧 Creating applications table...');

        if ($schema->hasTable('openregister_applications') === false) {
            $table = $schema->createTable('openregister_applications');

            // Primary key.
            $table->addColumn(
                'id',
                Types::BIGINT,
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'comment'       => 'Primary key',
                ]
            );

            // Unique identifier.
            $table->addColumn(
                'uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                    'comment' => 'Unique identifier for the application',
                ]
            );

            // Basic information.
            $table->addColumn(
                'name',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                    'comment' => 'Application name',
                ]
            );

            $table->addColumn(
                'description',
                Types::TEXT,
                [
                    'notnull' => false,
                    'comment' => 'Application description',
                ]
            );

            $table->addColumn(
                'version',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 64,
                    'comment' => 'Application version',
                ]
            );

            // Organisation link.
            $table->addColumn(
                'organisation',
                Types::BIGINT,
                [
                    'notnull' => false,
                    'comment' => 'Organisation ID this application belongs to',
                ]
            );

            // Status.
            $table->addColumn(
                'active',
                Types::BOOLEAN,
                [
                    'notnull' => true,
                    'default' => true,
                    'comment' => 'Whether the application is active',
                ]
            );

            // Relations (stored as JSON arrays of IDs).
            $table->addColumn(
                'configurations',
                Types::JSON,
                [
                    'notnull' => false,
                    'comment' => 'Array of configuration IDs',
                ]
            );

            $table->addColumn(
                'registers',
                Types::JSON,
                [
                    'notnull' => false,
                    'comment' => 'Array of register IDs',
                ]
            );

            $table->addColumn(
                'schemas',
                Types::JSON,
                [
                    'notnull' => false,
                    'comment' => 'Array of schema IDs',
                ]
            );

            // Resource allocation quotas.
            $table->addColumn(
                'storage_quota',
                Types::BIGINT,
                [
                    'notnull' => false,
                    'comment' => 'Storage quota in bytes (NULL = unlimited)',
                ]
            );

            $table->addColumn(
                'bandwidth_quota',
                Types::BIGINT,
                [
                    'notnull' => false,
                    'comment' => 'Bandwidth quota in bytes per month (NULL = unlimited)',
                ]
            );

            $table->addColumn(
                'request_quota',
                Types::INTEGER,
                [
                    'notnull' => false,
                    'comment' => 'API request quota per day (NULL = unlimited)',
                ]
            );

            // Ownership.
            $table->addColumn(
                'owner',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                    'comment' => 'User ID of the application owner',
                ]
            );

            // Timestamps.
            $table->addColumn(
                'created',
                Types::DATETIME,
                [
                    'notnull' => true,
                    'comment' => 'Creation timestamp',
                ]
            );

            $table->addColumn(
                'updated',
                Types::DATETIME,
                [
                    'notnull' => true,
                    'comment' => 'Last update timestamp',
                ]
            );

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Add indexes for common queries.
            $table->addIndex(['uuid'], 'applications_uuid_index');
            $table->addIndex(['name'], 'applications_name_index');
            $table->addIndex(['organisation'], 'applications_organisation_index');
            $table->addIndex(['owner'], 'applications_owner_index');
            $table->addIndex(['active'], 'applications_active_index');

            $output->info(message: '✅ Created openregister_applications table');
            $output->info('🎯 Applications support:');
            $output->info(message: '   • Grouping of configurations, registers, and schemas');
            $output->info(message: '   • Multi-tenancy via organisation assignment');
            $output->info(message: '   • Resource allocation quotas (storage, bandwidth, requests)');
            $output->info(message: '   • Version tracking and activation status');

            return $schema;
        } else {
            $output->info(message: 'ℹ️  Applications table already exists, skipping...');
        }//end if

        return null;

    }//end changeSchema()
}//end class
