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

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version1Date20240924200009 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_sources') === false) {
            $table = $schema->createTable('openregister_sources');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
            $table->addColumn('database_url', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['title'], 'register_sources_title_index');
            $table->addIndex(['type'], 'register_sources_type_index');
            $table->addIndex(['uuid'], 'register_sources_uuid_index');
        }

        if ($schema->hasTable('openregister_schemas') === false) {
            $table = $schema->createTable('openregister_schemas');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('summary', Types::TEXT, ['notnull' => false]);
            $table->addColumn('required', Types::JSON, ['notnull' => false]);
            $table->addColumn('properties', Types::JSON, ['notnull' => false]);
            $table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['title'], 'register_schemas_title_index');
            $table->addIndex(['uuid'], 'register_schemas_uuid_index');
        }

        if ($schema->hasTable('openregister_registers') === false) {
            $table = $schema->createTable('openregister_registers');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('schemas', Types::JSON, ['notnull' => false]);
            $table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('table_prefix', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['title'], 'registers_title_index');
            $table->addIndex(['source'], 'registers_source_index');
            $table->addIndex(['uuid'], 'registers_uuid_index');
        }

        if ($schema->hasTable('openregister_objects') === false) {
            $table = $schema->createTable('openregister_objects');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
            $table->addColumn('register', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('schema', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('object', Types::JSON, ['notnull' => false]);
            $table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'object_entity_uuid');
            $table->addIndex(['register'], 'object_entity_register');
            $table->addIndex(['schema'], 'object_entity_schema');
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
