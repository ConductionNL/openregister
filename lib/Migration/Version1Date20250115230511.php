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
 * Migration to add locked, owner, authorization and folder columns to openregister_objects table
 * and folder column to openregister_registers table.
 * These columns are used to track object locking, ownership, access permissions and folder location
 */

class Version1Date20250115230511 extends SimpleMigrationStep
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
     * @return ISchemaWrapper|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Update the openregister_objects table.
        $table = $schema->getTable('openregister_objects');

        // Add locked column to store lock tokens as JSON array.
        if ($table->hasColumn('locked') === false) {
            $table->addColumn(
                'locked',
                Types::JSON,
                [
                    'notnull' => false,
                    'default' => null,
                ]
            );
        }

        // Add owner column to store user ID of object owner.
        if ($table->hasColumn('owner') === false) {
            $table->addColumn(
                'owner',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => '',
                ]
            );
        }

        // Add authorization column to store access permissions as JSON object.
        if ($table->hasColumn('authorization') === false) {
            $table->addColumn(
                'authorization',
                Types::TEXT,
                [
                    'notnull' => false,
                    'default' => null,
                ]
            );
        }

        // Add folder column to store Nextcloud folder path.
        if ($table->hasColumn('folder') === false) {
            $table->addColumn(
                'folder',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 4000,
                    'default' => '',
                ]
            );
        }

        // Update the openregister_registers table.
        $registersTable = $schema->getTable('openregister_registers');

        // Add folder column to store Nextcloud folder path for registers.
        if ($registersTable->hasColumn('folder') === false) {
            $registersTable->addColumn(
                'folder',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 4000,
                    'default' => '',
                ]
            );
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
