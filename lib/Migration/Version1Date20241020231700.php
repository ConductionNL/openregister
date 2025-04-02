<?php
// phpcs:ignoreFile
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

class Version1Date20241020231700 extends SimpleMigrationStep
{


    /**
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end preSchemaChange()


    /**
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // create the openregister_logs table
        if (!$schema->hasTable('openregister_audit_trails')) {
            $table = $schema->createTable('openregister_audit_trails');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('schema', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('regsiter', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('object', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('action', Types::STRING, ['notnull' => true, 'default' => 'update']);
            $table->addColumn('changed', Types::JSON, ['notnull' => true]);
            $table->addColumn('user', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('user_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('session', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('request', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('ip_address', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user'], 'openregister_logs_user_index');
            $table->addIndex(['uuid'], 'openregister_logs_uuid_index');
        }//end if

        // Update the openregister_objects table
        $table = $schema->getTable('openregister_objects');
        if (!$table->hasColumn('text_representation')) {
            $table->addColumn(name: 'text_representation', typeName: Types::TEXT, options: ['notnull' => false]);
        }

        return $schema;

    }//end changeSchema()


    /**
     * @param IOutput                   $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array                     $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {

    }//end postSchemaChange()


}//end class
