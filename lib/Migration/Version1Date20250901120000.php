<?php

/**
 * OpenRegister Migration - Add summary column to objects
 *
 * This migration adds a summary column to the openregister_objects table
 * to store object summaries extracted from configured schema properties.
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
 * Migration to add summary column to objects table
 *
 * This migration adds a summary column to store object summaries
 * extracted from configured schema properties for better searchability
 * and display purposes.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20250901120000 extends SimpleMigrationStep
{

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Check if the objects table exists
        if ($schema->hasTable('openregister_objects') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_objects');

        // Add summary column if it doesn't exist
        if ($table->hasColumn('summary') === false) {
            $table->addColumn('summary', 'text', [
                'notnull' => false,
                'default' => null,
                'comment' => 'Summary of the object extracted from configured schema property'
            ]);
            $output->info('Added summary column to openregister_objects table');
        }

        return $schema;
    }//end changeSchema()

}//end class
