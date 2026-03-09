<?php

/**
 * Database migration to add mappings column to configurations table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Adds the mappings JSON column to openregister_configurations table.
 *
 * Allows Configuration entities to track which mapping IDs are managed
 * by a given configuration, enabling mapping import from JSON config files.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260308000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_configurations') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_configurations');

        if ($table->hasColumn('mappings') === true) {
            return null;
        }

        $table->addColumn(
            'mappings',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        return $schema;

    }//end changeSchema()
}//end class
