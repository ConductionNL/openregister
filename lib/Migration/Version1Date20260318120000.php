<?php

/**
 * Database migration to add `languages` column to the openregister_registers table.
 *
 * Adds a JSON column for storing available language codes per register,
 * supporting the register-i18n feature for multi-language content management.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
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
 * Adds the `languages` JSON column to the openregister_registers table.
 *
 * This column stores an array of BCP 47 language codes (e.g., ["nl", "en"]).
 * The first language in the array is the default (required) language.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260318120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tableName = 'openregister_registers';

        if ($schema->hasTable($tableName) === false) {
            $output->info("Table {$tableName} does not exist, skipping migration");
            return null;
        }

        $table = $schema->getTable($tableName);

        if ($table->hasColumn('languages') === true) {
            $output->info("Column 'languages' already exists on {$tableName}, skipping");
            return null;
        }

        $table->addColumn('languages', Types::TEXT, [
            'notnull' => false,
            'default' => null,
            'comment' => 'JSON array of available BCP 47 language codes, e.g. ["nl","en"]',
        ]);

        $output->info("Added 'languages' column to {$tableName}");

        return $schema;
    }//end changeSchema()
}//end class
