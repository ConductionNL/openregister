<?php

/**
 * Migration to create the openregister_map_links table.
 *
 * Stores cached lat/lon + address for NC Maps geolocations linked to objects.
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
 * @spec openspec/changes/integration-maps/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates openregister_map_links to cache geocoordinates linked to objects.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260605000001 extends SimpleMigrationStep
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
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_map_links') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_map_links');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('register_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
        $table->addColumn('address', Types::STRING, ['length' => 1024, 'notnull' => false, 'default' => null]);
        $table->addColumn('lat', Types::FLOAT, ['notnull' => false, 'default' => null]);
        $table->addColumn('lon', Types::FLOAT, ['notnull' => false, 'default' => null]);
        $table->addColumn('address_source', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => false, 'default' => null]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'or_map_links_obj_uuid_idx');

        $output->info('Created openregister_map_links table');

        return $schema;
    }//end changeSchema()
}//end class
