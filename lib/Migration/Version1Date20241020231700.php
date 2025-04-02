<?php
/**
 * OpenRegister Migration
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
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
     * @param  IOutput                   $output
     * @param  Closure(): ISchemaWrapper $schemaClosure
     * @param  array                     $options
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
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => TRUE, 'notnull' => TRUE]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => FALSE, 'length' => 255]);
            $table->addColumn('schema', Types::INTEGER, ['notnull' => FALSE]);
            $table->addColumn('regsiter', Types::INTEGER, ['notnull' => FALSE]);
            $table->addColumn('object', Types::INTEGER, ['notnull' => TRUE]);
            $table->addColumn('action', Types::STRING, ['notnull' => TRUE, 'default' => 'update']);
            $table->addColumn('changed', Types::JSON, ['notnull' => TRUE]);
            $table->addColumn('user', Types::STRING, ['notnull' => TRUE, 'length' => 255]);
            $table->addColumn('user_name', Types::STRING, ['notnull' => TRUE, 'length' => 255]);
            $table->addColumn('session', Types::STRING, ['notnull' => TRUE, 'length' => 255]);
            $table->addColumn('request', Types::STRING, ['notnull' => FALSE, 'length' => 255]);
            $table->addColumn('ip_address', Types::STRING, ['notnull' => FALSE, 'length' => 255]);
            $table->addColumn('version', Types::STRING, ['notnull' => FALSE, 'length' => 255]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => TRUE]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user'], 'openregister_logs_user_index');
            $table->addIndex(['uuid'], 'openregister_logs_uuid_index');
        }//end if

        // Update the openregister_objects table
        $table = $schema->getTable('openregister_objects');
        if (!$table->hasColumn('text_representation')) {
            $table->addColumn(name: 'text_representation', typeName: Types::TEXT, options: ['notnull' => FALSE]);
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
