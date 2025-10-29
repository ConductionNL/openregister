<?php
/**
 * OpenRegister Objects Schema Version Migration
 *
 * This migration adds the schemaVersion column to the openregister_objects table
 * to track the version of the schema used for each object.
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
 * Add schemaVersion column to objects table migration
 */
class Version1Date20250830130000 extends SimpleMigrationStep
{


    /**
     * Change the database schema
     *
     * @param IOutput       $output        Output for the migration process
     * @param Closure       $schemaClosure The schema closure
     * @param array<string> $options       Migration options
     *
     * @return ISchemaWrapper|null The modified schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Check if the objects table exists
        if ($schema->hasTable('openregister_objects') === true) {
            $table = $schema->getTable('openregister_objects');

            // Add schemaVersion column if it doesn't exist
            if ($table->hasColumn('schemaVersion') === false) {
                $table->addColumn(
                        'schemaVersion',
                        Types::STRING,
                        [
                            'notnull' => false,
                            'length'  => 255,
                            'default' => null,
                            'comment' => 'Version of the schema used for this object',
                        ]
                        );
                $output->info('Added schemaVersion column to openregister_objects table');
            }
        }

        return $schema;

    }//end changeSchema()


}//end class
