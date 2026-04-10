<?php

/**
 * Remove is_default column from organisations table
 *
 * This migration removes the is_default column from the organisations table
 * as it's no longer used in the Organisation entity. The default organisation
 * is now managed via configuration instead of a database column.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Remove is_default column from organisations table
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251107190000 extends SimpleMigrationStep
{
    /**
     * Modify the database schema
     *
     * @param IOutput $output        Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return ISchemaWrapper|null The modified schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $updated = false;

        if ($schema->hasTable('openregister_organisations') === true) {
            $table = $schema->getTable('openregister_organisations');

            // Remove is_default column if it exists.
            if ($table->hasColumn('is_default') === false) {
                $output->info(message: 'ℹ️  is_default column does not exist in organisations table');
                return null;
            }

            $table->dropColumn('is_default');
            $output->info(message: '✅ Removed is_default column from organisations table');
            $updated = true;
        }

        if ($schema->hasTable('openregister_organisations') === false) {
            $output->warning(message: '⚠️  openregister_organisations table does not exist');
        }

        if ($updated === false) {
            return null;
        }

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
        $output->info(message: '✅ Migration complete - is_default column removed from organisations table');
        $output->info(message: '   Default organisation is now managed via configuration');
    }//end postSchemaChange()
}//end class
