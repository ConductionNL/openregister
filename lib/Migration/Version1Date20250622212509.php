<?php

/**
 * Version1Date20250622212509 Migration
 *
 * This file contains the migration class for adding groups columns and creating new tables.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Version1Date20250622212509 Migration
 *
 * Adds groups columns to existing tables and creates new tables for organisations and data access profiles.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class Version1Date20250622212509 extends SimpleMigrationStep
{


    /**
     * Change schema for migration
     *
     * @param IOutput                 $output        Output interface
     * @param Closure                 $schemaClosure Schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null Modified schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // 1. Add 'groups' column to existing tables.
        $table = $schema->getTable('openregister_objects');
        if ($table->hasColumn('groups') === false) {
            $table->addColumn(
                'groups',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );
        }

        if ($table->hasColumn('name') === false) {
            $table->addColumn(
                'name',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );
        }

        if ($table->hasColumn('description') === false) {
            $table->addColumn(
                'description',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );
        }

        if ($table->hasColumn('text_representation') === true) {
            $table->dropColumn('text_representation');
        }

        $table = $schema->getTable('openregister_schemas');
        if ($table->hasColumn('groups') === false) {
            $table->addColumn(
                'groups',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );
        }

        if ($table->hasColumn('immutable') === false) {
            $table->addColumn(
                'immutable',
                Types::BOOLEAN,
                [
                    'notnull' => true,
                    'default' => false,
                ]
            );
        }

        if ($table->hasColumn('configuration') === false) {
            $table->addColumn(
                'configuration',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );
        }

        $table = $schema->getTable('openregister_registers');
        if ($table->hasColumn('groups') === false) {
            $table->addColumn(
                'groups',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );
        }

        // 2. Create 'openregister_organisations' table.
        if ($schema->hasTable('openregister_organisations') === false) {
            $table = $schema->createTable('openregister_organisations');
            $table->addColumn(
                'id',
                Types::INTEGER,
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                ]
            );
            $table->addColumn(
                'uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                'name',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                'description',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'created',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'updated',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
            );
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['uuid'],
                'openregister_organisations_uuid_index'
            );
        }//end if

        // 3. Create 'openregister_data_access_profiles' table.
        if ($schema->hasTable('openregister_data_access_profiles') === false) {
            $table = $schema->createTable('openregister_data_access_profiles');
            $table->addColumn(
                'id',
                Types::INTEGER,
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                ]
            );
            $table->addColumn(
                'uuid',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                'name',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                'description',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'permissions',
                Types::JSON,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'created',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'updated',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
            );
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['uuid'],
                'openregister_dap_uuid_index'
            );
        }//end if

        return $schema;

    }//end changeSchema()


    /**
     * Post schema change hook
     *
     * @param IOutput                 $output        Output interface
     * @param Closure                 $schemaClosure Schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end postSchemaChange()


}//end class
