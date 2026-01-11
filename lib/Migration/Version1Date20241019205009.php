<?php

/**
 * OpenRegister Migration
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

/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration step for adding uuid and version columns to sources and schemas tables
 */

class Version1Date20241019205009 extends SimpleMigrationStep
{
    /**
     * Execute actions before schema changes
     *
     * @param IOutput                   $output        Output interface for migration progress
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
     * @param array                     $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }//end preSchemaChange()

    /**
     * Apply schema changes
     *
     * @param IOutput                   $output        Output interface for migration progress
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
     * @param array                     $options       Migration options
     *
     * @return ISchemaWrapper
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Update the openregister_sources table.
        $table = $schema->getTable('openregister_sources');
        if ($table->hasColumn('uuid') === false) {
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
            $table->addIndex(['uuid'], 'openregister_sources_uuid_index');
        }

        if ($table->hasColumn('version') === false) {
            $versionOptions = ['notnull' => true, 'length' => 255, 'default' => '0.0.1'];
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOptions);
        }

        // Update the openregister_schemas table.
        $table = $schema->getTable('openregister_schemas');
        if ($table->hasColumn('uuid') === false) {
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
            $table->addIndex(['uuid'], 'openregister_schemas_uuid_index');
        }

        // Update the openregister_registers table.
        $table = $schema->getTable('openregister_registers');
        if ($table->hasColumn('uuid') === false) {
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
            $table->addIndex(['uuid'], 'openregister_registers_uuid_index');
        }

        if ($table->hasColumn('version') === false) {
            $versionOptions = ['notnull' => true, 'length' => 255, 'default' => '0.0.1'];
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOptions);
        }

        // Update the openregister_objects table.
        $table = $schema->getTable('openregister_objects');
        if ($table->hasColumn('version') === false) {
            $versionOptions = ['notnull' => true, 'length' => 255, 'default' => '0.0.1'];
            $table->addColumn(name: 'version', typeName: Types::STRING, options: $versionOptions);
        }

        return $schema;
    }//end changeSchema()

    /**
     * Execute actions after schema changes
     *
     * @param IOutput                   $output        Output interface for migration progress
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure function
     * @param array                     $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }//end postSchemaChange()
}//end class
