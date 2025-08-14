<?php

/**
 * OpenRegister Migration - Add slug column to objects
 *
 * This migration adds a slug column to the openregister_objects table
 * to provide URL-friendly identifiers for objects.
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
 * Migration to add slug column to objects table
 *
 * This migration adds a slug column to provide URL-friendly identifiers
 * for objects, unique within register+schema combinations.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20250813140000 extends SimpleMigrationStep
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

        // Add slug column if it doesn't exist
        if ($table->hasColumn('slug') === false) {
            $table->addColumn('slug', 'string', [
                'notnull' => false,
                'length' => 255,
                'default' => null,
                'comment' => 'URL-friendly identifier for the object, unique within register+schema combination'
            ]);
            $output->info('Added slug column to openregister_objects table');
        }

        // Add unique constraint for slug+register+schema combination
        $uniqueIndexName = 'unique_slug_register_schema';
        if ($table->hasIndex($uniqueIndexName) === false) {
            $table->addUniqueIndex(['slug', 'register', 'schema'], $uniqueIndexName);
            $output->info('Added unique constraint for slug+register+schema combination');
        }

        return $schema;
    }//end changeSchema()

}//end class

