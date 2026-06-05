<?php

/**
 * Migration to create the openregister_collective_links table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the openregister_collective_links table for the Collectives integration.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260605000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_collective_links') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_collective_links');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );
        $table->addColumn(
            'object_uuid',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 36,
            ]
        );
        $table->addColumn(
            'collective_name',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'page_id',
            Types::BIGINT,
            [
                'notnull' => true,
            ]
        );
        $table->addColumn(
            'page_title',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]
        );
        $table->addColumn(
            'page_url',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 1024,
                'default' => null,
            ]
        );
        $table->addColumn(
            'linked_by',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'linked_at',
            Types::DATETIME,
            [
                'notnull' => true,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'or_collective_links_obj_uuid');
        $table->addIndex(['object_uuid', 'page_id'], 'or_collective_links_obj_page');

        $output->info('Created openregister_collective_links table');

        return $schema;
    }//end changeSchema()
}//end class
