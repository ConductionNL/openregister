<?php

/**
 * OpenRegister Migration Version1Date20251201120000
 *
 * Migration to add organisation column to openregister_views table for multi-tenancy support.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add organisation column to openregister_views table
 *
 * This migration adds the organisation column to the openregister_views table
 * to support multi-tenancy filtering. The column stores the organisation UUID
 * and allows views to be filtered by organisation.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
class Version1Date20251201120000 extends SimpleMigrationStep
{
    /**
     * Change database schema
     *
     * @param IOutput $output        Output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return ISchemaWrapper|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Add organisation column to openregister_views table.
        if ($schema->hasTable('openregister_views') === true) {
            $table = $schema->getTable('openregister_views');

            // Add organisation column if it doesn't exist.
            if ($table->hasColumn('organisation') === false) {
                $output->info('🔧 Adding organisation column to openregister_views table...');

                $table->addColumn(
                    'organisation',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 36,
                        'default' => null,
                        'comment' => 'Organisation UUID for multi-tenancy',
                    ]
                );

                // Add index for performance.
                $table->addIndex(['organisation'], 'views_organisation_index');

                $output->info('✅ Added organisation column to openregister_views table');
            } else {
                $output->info('ℹ️  Organisation column already exists in openregister_views table');
            }//end if
        } else {
            $output->info('ℹ️  openregister_views table does not exist, skipping...');
        }//end if

        return $schema;
    }//end changeSchema()

    /**
     * Post-schema change hook
     *
     * @param IOutput $output        Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('✅ Views multi-tenancy migration complete');
        $output->info('   Views can now be filtered by organisation');
    }//end postSchemaChange()
}//end class
