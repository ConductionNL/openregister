<?php

/**
 * OpenRegister Objects Schema Version Migration
 *
 * This migration adds the schema_version column to the openregister_objects table
 * to track the version of the schema used for each object.
 *
 * NOTE: Uses snake_case (schema_version) for PostgreSQL compatibility.
 * PostgreSQL converts unquoted identifiers to lowercase, so camelCase columns
 * would become 'schemaversion' and break Entity property mapping.
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
 * Add schema_version column to objects table migration
 *
 * Uses snake_case naming for cross-database compatibility.
 */
class Version1Date20250830130000 extends SimpleMigrationStep
{
    /**
     * Change the database schema
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null The modified schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Check if the objects table exists.
        if ($schema->hasTable('openregister_objects') === true) {
            $table = $schema->getTable('openregister_objects');

            // Add schema_version column if it doesn't exist.
            if ($table->hasColumn('schema_version') === false) {
                $table->addColumn(
                    'schema_version',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                        'comment' => 'Version of the schema used for this object',
                    ]
                );
                $output->info(message: 'Added schema_version column to openregister_objects table');
            }
        }

        return $schema;
    }//end changeSchema()
}//end class
