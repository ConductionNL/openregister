<?php
/**
 * Migration to add default value to configurations type column
 *
 * This migration ensures the type column has a default value to prevent
 * database errors when creating configurations without explicitly setting a type.
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
 * Migration to add default value to configurations type column
 */
class Version1Date20251107120000 extends SimpleMigrationStep
{


    /**
     * Change the database schema
     *
     * @param IOutput       $output        Output for the migration process
     * @param Closure       $schemaClosure The schema closure
     * @param array<string> $options       Migration options
     *
     * @phpstan-return ISchemaWrapper|null
     * @psalm-return   ISchemaWrapper|null
     * @return         ISchemaWrapper|null The modified schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Check if the configurations table exists.
        if ($schema->hasTable('openregister_configurations')) {
            $table = $schema->getTable('openregister_configurations');

            // Update the type column to have a default value.
            if ($table->hasColumn('type')) {
                $column = $table->getColumn('type');

                // Set default value for the type column.
                $column->setDefault('default');
                $column->setNotnull(true);

                $output->info('Added default value to openregister_configurations.type column');
            }
        }

        return $schema;

    }//end changeSchema()


}//end class
