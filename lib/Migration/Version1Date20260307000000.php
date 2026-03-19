<?php

/**
 * Database migration to create the consumers table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the openregister_consumers table for API client authentication.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260307000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_consumers') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_consumers');

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
                'length'  => 36,
            ]
        );
        $table->addColumn(
            'name',
            Types::STRING,
            [
                'notnull' => false,
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
            'domains',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'ips',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'authorization_type',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 50,
            ]
        );
        $table->addColumn(
            'authorization_configuration',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'user_id',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
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
        $table->addUniqueIndex(['uuid'], 'or_consumers_uuid_idx');
        $table->addIndex(['name'], 'or_consumers_name_idx');

        return $schema;

    }//end changeSchema()
}//end class
