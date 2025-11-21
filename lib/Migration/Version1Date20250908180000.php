<?php

declare(strict_types=1);

/**
 * OpenRegister Updated Column Enhancement Migration
 *
 * This migration modifies the 'updated' column to automatically update timestamps
 * on row modifications, enabling precise tracking of created vs updated objects
 * during bulk import operations.
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
 * Migration to enhance updated column for precise create/update tracking
 *
 * This migration modifies the database schema to enable precise distinction
 * between created and updated objects during bulk operations by ensuring:
 * - created: Set only on INSERT (never changes)
 * - updated: Set on INSERT and automatically updated on every UPDATE
 */
class Version1Date20250908180000 extends SimpleMigrationStep
{

    /**
     * Enhance updated column with ON UPDATE CURRENT_TIMESTAMP
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

        // This migration requires raw SQL as Nextcloud's schema wrapper doesn't.
        // support the ON UPDATE CURRENT_TIMESTAMP syntax directly.
        $output->info('🔧 This migration requires manual SQL execution for ON UPDATE functionality');
        $output->info('ℹ️  Nextcloud schema wrapper has limited support for MySQL-specific timestamp features');
        
        return null; // No schema changes via wrapper - will use postSchemaChange
    }

    /**
     * Execute raw SQL to modify updated column behavior
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('🔧 Modifying updated column to auto-update on row changes...');
        
        // Use direct database connection for MySQL-specific syntax.
        $connection = \OC::$server->getDatabaseConnection();
        
        try {
            // Modify the updated column to include ON UPDATE CURRENT_TIMESTAMP.
            $sql = "ALTER TABLE `oc_openregister_objects` 
                    MODIFY COLUMN `updated` datetime NOT NULL 
                    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
            
            $connection->executeStatement($sql);
            
            $output->info('✅ Updated column now auto-updates on row modifications');
            $output->info('🎯 This enables precise create vs update tracking:');
            $output->info('   • created = updated → Object was just created (INSERT)');
            $output->info('   • created ≠ updated → Object was updated (UPDATE)');
            $output->info('🚀 Bulk imports can now distinguish creates vs updates per-object!');
            
        } catch (\Exception $e) {
            $output->info('❌ Failed to modify updated column: ' . $e->getMessage());
            $output->info('⚠️  This may prevent precise create/update tracking');
            $output->info('💡 Manual fix: ALTER TABLE oc_openregister_objects MODIFY updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        }
    }

}//end class
