<?php

/**
 * OpenRegister Views Table Update Migration
 *
 * This migration updates the views table to use 'query' instead of 'configuration'
 * and adds the 'favored_by' column for favorite functionality.
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
 * Migration to update views table structure
 *
 * Changes:
 * - Rename `configuration` to `query` (focuses on query parameters only)
 * - Add `favored_by` for favorite functionality
 * - Update purpose: views are reusable query filters, not full UI state
 */
class Version1Date20251102150000 extends SimpleMigrationStep
{


    /**
     * Update views table structure
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        $output->info('🔧 Updating views table structure...');

        if ($schema->hasTable('openregister_views') === true) {
            $table = $schema->getTable('openregister_views');

            // Check if we still have old 'configuration' column.
            if ($table->hasColumn('configuration') === true) {
                // Drop old configuration column.
                $table->dropColumn('configuration');
                $output->info('   ✓ Dropped old configuration column');
            }

            // Add query column if it doesn't exist.
            if ($table->hasColumn('query') === false) {
                $table->addColumn(
                        'query',
                        Types::JSON,
                        [
                            'notnull' => true,
                            'comment' => 'Query parameters: registers, schemas, search terms, and facet filters',
                        ]
                        );
                $output->info('   ✓ Added query column');
            }

            // Add favored_by column if it doesn't exist.
            if ($table->hasColumn('favored_by') === false) {
                $table->addColumn(
                        'favored_by',
                        Types::JSON,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'Array of user IDs who favorited this view',
                        ]
                        );
                $output->info('   ✓ Added favored_by column');
            }

            $output->info('✅ Views table updated successfully');
            $output->info('🎯 Views now focus on:');
            $output->info('   • Query parameters (not full UI state)');
            $output->info('   • Reusable filters for API endpoints');
            $output->info('   • Favorite functionality');

            return $schema;
        } else {
            $output->info('⚠️  Views table not found!');
        }//end if

        return null;

    }//end changeSchema()


}//end class
