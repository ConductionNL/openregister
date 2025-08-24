<?php

/**
 * OpenRegister Migration - Add size column to search trails
 *
 * This migration adds a size column to the openregister_search_trails table
 * to track the size of search trail entries in bytes.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add size column to search trails table
 *
 * This migration adds a size column to track the size of search trail entries
 * in bytes for better storage management and analytics.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20250831120000 extends SimpleMigrationStep
{


    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Check if the search trails table exists
        if ($schema->hasTable('openregister_search_trails') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_search_trails');

        // Add size column if it doesn't exist
        if ($table->hasColumn('size') === false) {
            $table->addColumn(
                    'size',
                    'bigint',
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Size of the search trail entry in bytes',
                    ]
                    );
            $output->info('Added size column to openregister_search_trails table');
        }

        return $schema;

    }//end changeSchema()


}//end class
