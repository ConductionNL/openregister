<?php

/**
 * OpenRegister Migration Version1Date20260306000000
 *
 * Migration to create the workflow engines table.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create workflow engines table.
 *
 * @psalm-suppress UnusedClass
 */
class Version1Date20260306000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                   $output        Output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array<string, mixed>      $options       Migration options
     *
     * @return ISchemaWrapper|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_workflow_engines') === false) {
            $table = $schema->createTable('openregister_workflow_engines');

            $table->addColumn(
                'id',
                Types::BIGINT,
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
                    'length'  => 36,
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
                'engine_type',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 50,
                ]
            );
            $table->addColumn(
                'base_url',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 512,
                ]
            );
            $table->addColumn(
                'auth_type',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 50,
                    'default' => 'none',
                ]
            );
            $table->addColumn(
                'auth_config',
                Types::TEXT,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'enabled',
                Types::BOOLEAN,
                [
                    'notnull' => true,
                    'default' => true,
                ]
            );
            $table->addColumn(
                'default_timeout',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 30,
                ]
            );
            $table->addColumn(
                'health_status',
                Types::BOOLEAN,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'last_health_check',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
            );
            $table->addColumn(
                'created',
                Types::DATETIME,
                [
                    'notnull' => true,
                ]
            );
            $table->addColumn(
                'updated',
                Types::DATETIME,
                [
                    'notnull' => true,
                ]
            );

            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openreg_wfengine_uuid_idx');
            $table->addIndex(['engine_type'], 'openreg_wfengine_type_idx');
        }//end if

        return $schema;
    }//end changeSchema()
}//end class
