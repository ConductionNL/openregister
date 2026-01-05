<?php

/**
 * OpenRegister Migration - Add Configuration Management Columns
 *
 * This migration adds columns to support remote configuration management,
 * version tracking, GitHub integration, and notification features.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
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
 * Add configuration management columns to configurations table
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251105140000 extends SimpleMigrationStep
{
    /**
     * Add configuration management columns to configurations table
     *
     * @param IOutput $output        The migration output handler
     * @param Closure $schemaClosure The closure to get the schema
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper The updated schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.NPathComplexity)       Database migration requires checking many columns
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $updated = false;

        // Add new columns to configurations table.
        if ($schema->hasTable('openregister_configurations') === true) {
            $table = $schema->getTable('openregister_configurations');

            // Add sourceType column.
            if ($table->hasColumn('source_type') === false) {
                $table->addColumn(
                    'source_type',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 64,
                        'default' => 'manual',
                    ]
                );
                $output->info(message: 'Added source_type column to openregister_configurations');
                $updated = true;
            }

            // Add sourceUrl column.
            if ($table->hasColumn('source_url') === false) {
                $table->addColumn(
                    'source_url',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added source_url column to openregister_configurations');
                $updated = true;
            }

            // Add localVersion column.
            if ($table->hasColumn('local_version') === false) {
                $table->addColumn(
                    'local_version',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added local_version column to openregister_configurations');
                $updated = true;
            }

            // Add remoteVersion column.
            if ($table->hasColumn('remote_version') === false) {
                $table->addColumn(
                    'remote_version',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added remote_version column to openregister_configurations');
                $updated = true;
            }

            // Add lastChecked column.
            if ($table->hasColumn('last_checked') === false) {
                $table->addColumn(
                    'last_checked',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added last_checked column to openregister_configurations');
                $updated = true;
            }

            // Add autoUpdate column.
            if ($table->hasColumn('auto_update') === false) {
                $table->addColumn(
                    'auto_update',
                    Types::BOOLEAN,
                    [
                        'notnull' => false,
                        'default' => false,
                    ]
                );
                $output->info(message: 'Added auto_update column to openregister_configurations');
                $updated = true;
            }

            // Add notificationGroups column.
            if ($table->hasColumn('notification_groups') === false) {
                $table->addColumn(
                    'notification_groups',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added notification_groups column to openregister_configurations');
                $updated = true;
            }

            // Add githubRepo column.
            if ($table->hasColumn('github_repo') === false) {
                $table->addColumn(
                    'github_repo',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added github_repo column to openregister_configurations');
                $updated = true;
            }

            // Add githubBranch column.
            if ($table->hasColumn('github_branch') === false) {
                $table->addColumn(
                    'github_branch',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => 'main',
                    ]
                );
                $output->info(message: 'Added github_branch column to openregister_configurations');
                $updated = true;
            }

            // Add githubPath column.
            if ($table->hasColumn('github_path') === false) {
                $table->addColumn(
                    'github_path',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 500,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added github_path column to openregister_configurations');
                $updated = true;
            }

            // Add schemas column if it doesn't exist.
            if ($table->hasColumn('schemas') === false) {
                $table->addColumn(
                    'schemas',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added schemas column to openregister_configurations');
                $updated = true;
            }

            // Add objects column if it doesn't exist.
            if ($table->hasColumn('objects') === false) {
                $table->addColumn(
                    'objects',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added objects column to openregister_configurations');
                $updated = true;
            }

            // Add uuid column if it doesn't exist.
            if ($table->hasColumn('uuid') === false) {
                $table->addColumn(
                    'uuid',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 36,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added uuid column to openregister_configurations');
                $updated = true;
            }

            // Add app column if it doesn't exist (replaces owner).
            if ($table->hasColumn('app') === false) {
                $table->addColumn(
                    'app',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 64,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added app column to openregister_configurations');
                $updated = true;
            }

            // Add organisation column if it doesn't exist.
            if ($table->hasColumn('organisation') === false) {
                $table->addColumn(
                    'organisation',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
                $output->info(message: 'Added organisation column to openregister_configurations');
                $updated = true;
            }

            // Add indexes for better query performance.
            if ($table->hasIndex('openregister_config_source_type_idx') === false) {
                $table->addIndex(['source_type'], 'openregister_config_source_type_idx');
                $output->info(message: 'Added index for source_type');
                $updated = true;
            }

            if ($table->hasIndex('openregister_config_app_idx') === false) {
                $table->addIndex(['app'], 'openregister_config_app_idx');
                $output->info(message: 'Added index for app');
                $updated = true;
            }
        }//end if

        if ($updated === true) {
            return $schema;
        }

        return null;
    }//end changeSchema()
}//end class
