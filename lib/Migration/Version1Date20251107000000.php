<?php

declare(strict_types=1);

/**
 * OpenRegister Remove Roles Column Migration
 *
 * This migration removes the deprecated 'roles' column from the organisations table.
 * This is a cleanup migration after data was migrated to 'groups' column.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git_id>
 *
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to remove deprecated roles column from organisations table
 *
 * Removes the roles column after data has been migrated to groups column.
 * This is a cleanup migration to complete the roles→groups renaming.
 */
class Version1Date20251107000000 extends SimpleMigrationStep
{

    /**
     * Remove roles column from organisations table
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            
            // Check if roles column still exists
            if ($table->hasColumn('roles')) {
                $output->info('🗑️  Removing deprecated roles column from organisations table...');
                
                $table->dropColumn('roles');
                
                $output->info('   ✓ Dropped roles column');
                $output->info('✅ Cleanup completed - organisations table now only uses groups column');
                
                return $schema;
                
            } else {
                $output->info('   ℹ️  Roles column already removed');
            }
        }

        return null;

    }//end changeSchema()


}//end class



