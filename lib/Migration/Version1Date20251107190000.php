<?php

declare(strict_types=1);

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
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git-id>
 *
 * @link     https://www.OpenRegister.nl
 */

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
     * @param IOutput $output   Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options  Options
     *
     * @return ISchemaWrapper|null The modified schema or null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $updated = false;

        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            
            // Remove is_default column if it exists
            if ($table->hasColumn('is_default')) {
                $table->dropColumn('is_default');
                $output->info('✅ Removed is_default column from organisations table');
                $updated = true;
            } else {
                $output->info('ℹ️  is_default column does not exist in organisations table');
            }
        } else {
            $output->warning('⚠️  openregister_organisations table does not exist');
        }

        return $updated ? $schema : null;
    }

    /**
     * Post-schema change hook
     *
     * @param IOutput $output   Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options  Options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('✅ Migration complete - is_default column removed from organisations table');
        $output->info('   Default organisation is now managed via configuration');
    }
}

