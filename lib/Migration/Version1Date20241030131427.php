<?php
// phpcs:ignoreFile
/**
 * OpenRegister Migration
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
class Version1Date20241030131427 extends SimpleMigrationStep
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

        // Update the openregister_schemas table
        $table = $schema->getTable('openregister_schemas');
        if ($table->hasColumn('hard_validation') === false) {
            $table->addColumn(name: 'hard_validation', typeName: Types::BOOLEAN, options: ['notnull' => true])->setDefault(default: false);
        }

        if ($table->hasColumn('archive') === false) {
            $table->addColumn(name: 'archive', typeName: Types::JSON, options: ['notnull' => false])->setDefault(default: '{}');
        }

        if ($table->hasColumn('source') === false) {
            $table->addColumn(name: 'source', typeName: Types::STRING, options: ['notnull' => false])->setDefault(default: '');
        }

        // Update the openregister_registers table
        $table = $schema->getTable('openregister_registers');
        if ($table->hasColumn('source') === true) {
            $column = $table->getColumn('source');
            $column->setNotnull(false);
            $column->setDefault('');
        }

        if ($table->hasColumn('table_prefix') === true) {
            $column = $table->getColumn('table_prefix');
            $column->setNotnull(false);
            $column->setDefault('');
        }

        if ($schema->hasTable('openregister_object_audit_logs') === false) {
            $table = $schema->createTable('openregister_object_audit_logs');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('schema_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('object_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('session_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('changes', Types::JSON, ['notnull' => false]);
            $table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'object_audit_log_uuid');
            $table->addIndex(['schema_id'], 'object_audit_log_schema_id');
            $table->addIndex(['object_id'], 'object_audit_log_object_id');
            $table->addIndex(['user_id'], 'object_audit_log_user_id');
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
