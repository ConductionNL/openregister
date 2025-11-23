<?php
declare(strict_types=1);
/**
 * OpenRegister Configuration Management Migration
 *
 * This migration adds columns to the configurations table to support:
 * - Local vs External configuration tracking (isLocal)
 * - Automatic synchronization settings (syncEnabled, syncInterval)
 * - Synchronization status tracking (lastSyncDate, syncStatus)
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add configuration management columns
 *
 * Adds support for:
 * - Distinguishing between local (owned) and external (imported) configurations
 * - Automatic synchronization from external sources
 * - Tracking synchronization status and last sync time
 */
class Version1Date20251115000000 extends SimpleMigrationStep
{


    /**
     * Add configuration management columns to configurations table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        $output->info(message: '🔧 Adding configuration management columns...');

        if ($schema->hasTable('openregister_configurations') === true) {
            $table = $schema->getTable('openregister_configurations');

            // Add isLocal field (boolean) - true = maintained locally, false = imported externally.
            if ($table->hasColumn('is_local') === false) {
                $table->addColumn(
                    'is_local',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => true,
                        'comment' => 'Whether this configuration is maintained locally (true) or imported from external source (false)',
                    ]
                );

                $output->info(message: '   ✓ Added is_local column to configurations table');
            } else {
                $output->info(message: '   ⚠️  is_local column already exists');
            }

            // Add syncEnabled field (boolean) - whether auto-sync is enabled.
            if ($table->hasColumn('sync_enabled') === false) {
                $table->addColumn(
                    'sync_enabled',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => false,
                        'comment' => 'Whether automatic synchronization is enabled for this configuration',
                    ]
                );

                $output->info(message: '   ✓ Added sync_enabled column to configurations table');
            } else {
                $output->info(message: '   ⚠️  sync_enabled column already exists');
            }

            // Add syncInterval field (integer) - sync interval in hours.
            if ($table->hasColumn('sync_interval') === false) {
                $table->addColumn(
                    'sync_interval',
                    Types::INTEGER,
                    [
                        'notnull' => true,
                        'default' => 24,
                        'comment' => 'Synchronization interval in hours',
                    ]
                );

                $output->info(message: '   ✓ Added sync_interval column to configurations table');
            } else {
                $output->info(message: '   ⚠️  sync_interval column already exists');
            }

            // Add lastSyncDate field (datetime) - last synchronization timestamp.
            if ($table->hasColumn('last_sync_date') === false) {
                $table->addColumn(
                    'last_sync_date',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Last time the configuration was synchronized with its source',
                    ]
                );

                $output->info(message: '   ✓ Added last_sync_date column to configurations table');
            } else {
                $output->info(message: '   ⚠️  last_sync_date column already exists');
            }

            // Add syncStatus field (string) - status of last sync.
            if ($table->hasColumn('sync_status') === false) {
                $table->addColumn(
                    'sync_status',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 20,
                        'default' => 'never',
                        'comment' => 'Status of the last synchronization attempt: success, failed, pending, never',
                    ]
                );

                $output->info(message: '   ✓ Added sync_status column to configurations table');
            } else {
                $output->info(message: '   ⚠️  sync_status column already exists');
            }

            // Add openregister field (string) - required OpenRegister version.
            if ($table->hasColumn('openregister') === false) {
                $table->addColumn(
                    'openregister',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 100,
                        'default' => null,
                        'comment' => 'Required OpenRegister version using Composer notation (e.g., ^v8.14.0, ~1.2.0, >=1.0.0 <2.0.0)',
                    ]
                );

                $output->info(message: '   ✓ Added openregister column to configurations table');
            } else {
                $output->info(message: '   ⚠️  openregister column already exists');
            }

            $output->info(message: '✅ Configuration management columns added successfully');
            $output->info('🎯 Features enabled:');
            $output->info(message: '   • Local vs External configuration tracking');
            $output->info(message: '   • Automatic synchronization from external sources');
            $output->info(message: '   • Synchronization status and history tracking');
            $output->info(message: '   • Configurable sync intervals per configuration');
        } else {
            $output->info(message: '⚠️  Configurations table does not exist!');
        }//end if

        return $schema;

    }//end changeSchema()


}//end class
