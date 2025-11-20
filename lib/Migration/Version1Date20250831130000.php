<?php

/**
 * OpenRegister Migration - Add expires column to objects
 *
 * This migration adds an expires column to the openregister_objects table
 * to track when objects should be permanently deleted.
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
 * Migration to add expires column to objects table
 *
 * This migration adds an expires column to track when objects should be
 * permanently deleted for better data lifecycle management.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20250831130000 extends SimpleMigrationStep
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

        // Check if the objects table exists.
        if ($schema->hasTable('openregister_objects') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_objects');

        // Add expires column if it doesn't exist.
        if ($table->hasColumn('expires') === false) {
            $table->addColumn(
                    'expires',
                    'datetime',
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Expiration timestamp for permanent deletion',
                    ]
                    );
            $output->info('Added expires column to openregister_objects table');
        }

        return $schema;

    }//end changeSchema()


}//end class
