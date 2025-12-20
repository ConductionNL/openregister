<?php
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
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

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
     * @param IOutput                   $output        Migration output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return null Updated schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // Get schema wrapper instance from closure (validation only).
        $schemaClosure();

        // This migration requires raw SQL as Nextcloud's schema wrapper doesn't.
        // Support the ON UPDATE CURRENT_TIMESTAMP syntax directly.
        $output->info(message: '🔧 This migration requires manual SQL execution for ON UPDATE functionality');
        $output->info(message: 'ℹ️  Nextcloud schema wrapper has limited support for MySQL-specific timestamp features');

        // No schema changes via wrapper - will use postSchemaChange.
        return null;

    }//end changeSchema()

    /**
     * Execute raw SQL to modify updated column behavior
     *
     * @param IOutput                   $output        Migration output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array                     $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info(message: '🔧 Modifying updated column to auto-update on row changes...');

        // Use direct database connection for MySQL-specific syntax.
        $connection = \OC::$server->getDatabaseConnection();

        try {
            // Modify the updated column to include ON UPDATE CURRENT_TIMESTAMP.
            $sql = "ALTER TABLE `oc_openregister_objects`
                    MODIFY COLUMN `updated` datetime NOT NULL
                    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";

            $connection->executeStatement($sql);

            $output->info(message: '✅ Updated column now auto-updates on row modifications');
            $output->info(message: '🎯 This enables precise create vs update tracking:');
            $output->info(message: '   • created = updated → Object was just created (INSERT)');
            $output->info(message: '   • created ≠ updated → Object was updated (UPDATE)');
            $output->info(message: '🚀 Bulk imports can now distinguish creates vs updates per-object!');
        } catch (\Exception $e) {
            $output->info(message: '❌ Failed to modify updated column: '.$e->getMessage());
            $output->info(message: '⚠️  This may prevent precise create/update tracking');
            $output->info(
                message: '💡 Manual SQL fix: See migration docs for ALTER TABLE command'
            );
            // End try.
        }//end try

    }//end postSchemaChange()
}//end class
