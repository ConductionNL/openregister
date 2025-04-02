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
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version1Date20241216094112 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_files') === false) {
            $table = $schema->createTable('openregister_files');
            $table->addColumn(name: 'id', typeName: Types::BIGINT, options: ['autoincrement' => true, 'notnull' => true, 'length' => 255]);
            $table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
            $table->addColumn(name: 'filename', typeName: Types::STRING, options: ['notnull' => false, 'length' => 255]);
            $table->addColumn(name: 'download_url', typeName: Types::STRING, options: ['notnull' => false, 'length' => 1023]);
            $table->addColumn(name: 'share_url', typeName: Types::STRING, options: ['notnull' => false, 'length' => 1023]);
            $table->addColumn(name: 'access_url', typeName: Types::STRING, options: ['notnull' => false, 'length' => 1023]);
            $table->addColumn(name: 'extension', typeName: Types::STRING, options: ['notnull' => false, 'length' => 255]);
            $table->addColumn(name: 'checksum', typeName: Types::STRING, options: ['notnull' => false, 'length' => 255]);
            $table->addColumn(name: 'source', typeName: Types::INTEGER, options: ['notnull' => false, 'length' => 255]);
            $table->addColumn(name: 'user_id', typeName: Types::STRING, options: ['notnull' => false, 'length' => 255]);
            $table->addColumn(name: 'created', typeName: Types::DATETIME_IMMUTABLE, options: ['notnull' => true, 'length' => 255]);
            $table->addColumn(name: 'updated', typeName: Types::DATETIME_MUTABLE, options: ['notnull' => true, 'length' => 255]);
            $table->addColumn(name: 'file_path', typeName: Types::STRING)->setNotnull(false)->setDefault(null);

            $table->setPrimaryKey(['id']);
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
