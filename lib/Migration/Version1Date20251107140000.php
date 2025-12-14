<?php
/**
 * OpenRegister Database Migration
 *
 * This file contains the migration for adding views, agents, sources,
 * and applications columns to the configurations table.
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
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add entity management columns to configurations table
 *
 * This migration adds columns for tracking views, agents, sources,
 * and applications that are managed by configurations.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20251107140000 extends SimpleMigrationStep
{


    /**
     * Change the database schema
     *
     * @param IOutput $output        The output interface
     * @param Closure $schemaClosure The schema closure
     * @param array   $options       The options
     *
     * @return null|ISchemaWrapper The schema wrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Check if the configurations table exists.
        if ($schema->hasTable('openregister_configurations') === true) {
            $table = $schema->getTable('openregister_configurations');

            // Add views column if it doesn't exist.
            if ($table->hasColumn('views') === false) {
                $table->addColumn(
                    'views',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
            }

            // Add agents column if it doesn't exist.
            if ($table->hasColumn('agents') === false) {
                $table->addColumn(
                    'agents',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
            }

            // Add sources column if it doesn't exist.
            if ($table->hasColumn('sources') === false) {
                $table->addColumn(
                    'sources',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
            }

            // Add applications column if it doesn't exist.
            if ($table->hasColumn('applications') === false) {
                $table->addColumn(
                    'applications',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                    ]
                );
            }

            return $schema;
        }//end if

        return null;

    }//end changeSchema()


}//end class
