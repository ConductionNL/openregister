<?php

/**
 * OpenRegister Migration Version1Date20260306100000
 *
 * Migration to create the deployed workflows table.
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
 * Create deployed workflows table.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class Version1Date20260306100000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_deployed_workflows') === false) {
            $table = $schema->createTable('openregister_deployed_workflows');

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
                'engine',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 50,
                ]
            );
            $table->addColumn(
                'engine_workflow_id',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                'source_hash',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 64,
                ]
            );
            $table->addColumn(
                'attached_schema',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                ]
            );
            $table->addColumn(
                'attached_event',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 50,
                ]
            );
            $table->addColumn(
                'import_source',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 512,
                ]
            );
            $table->addColumn(
                'version',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 1,
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
            $table->addUniqueIndex(['name', 'engine'], 'openreg_dwf_name_engine_idx');
            $table->addIndex(['uuid'], 'openreg_dwf_uuid_idx');
            $table->addIndex(['attached_schema'], 'openreg_dwf_schema_idx');
            $table->addIndex(['import_source'], 'openreg_dwf_source_idx');
        }//end if

        return $schema;
    }//end changeSchema()
}//end class
