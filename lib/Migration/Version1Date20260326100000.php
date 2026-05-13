<?php

/**
 * Migration to add linked entity type columns to entity tables.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Adds _mail, _contacts, _notes, _todos, _calendar, _talk, _deck columns
 * to openregister_registers, openregister_schemas, and openregister_organisations tables.
 *
 * These columns store lean JSON arrays of string IDs for linked Nextcloud entities.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260326100000 extends SimpleMigrationStep
{
    /**
     * The linked entity type columns to add.
     *
     * Note: _files is excluded because it already exists on entity tables
     * or is handled by existing migrations.
     */
    private const LINKED_COLUMNS = [
        '_mail',
        '_contacts',
        '_notes',
        '_todos',
        '_calendar',
        '_talk',
        '_deck',
    ];

    /**
     * The entity tables to update.
     */
    private const ENTITY_TABLES = [
        'openregister_registers',
        'openregister_schemas',
        'openregister_organisations',
    ];

    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $changed = false;

        foreach (self::ENTITY_TABLES as $tableName) {
            if ($schema->hasTable($tableName) === false) {
                continue;
            }

            $table = $schema->getTable($tableName);

            foreach (self::LINKED_COLUMNS as $columnName) {
                if ($table->hasColumn($columnName) === true) {
                    continue;
                }

                $table->addColumn(
                        name: $columnName,
                        typeName: Types::JSON,
                        options: [
                            'notnull' => false,
                            'default' => null,
                        ]
                        );
                $changed = true;

                $output->info("Added column $columnName to $tableName");
            }
        }//end foreach

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
