<?php

/**
 * Migration to create the openregister_time_links table.
 *
 * Stores per-entry time-tracking rows linked to OpenRegister objects, with a
 * denormalized total_minutes field for fast dashboard queries (AD-2).
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
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates openregister_time_links table for the Time Tracker integration.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
 */
class Version1Date20260605000001 extends SimpleMigrationStep
{

    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Migration output.
     * @param Closure                 $schemaClosure Schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-1
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_time_links') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_time_links');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull'       => true,
            'unsigned'      => true,
        ]);
        $table->addColumn('object_uuid', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        $table->addColumn('register_id', Types::INTEGER, [
            'notnull'  => false,
            'unsigned' => true,
        ]);
        $table->addColumn('backend_entry_id', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);
        $table->addColumn('backend_name', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
            'default' => 'timemanager',
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('duration_minutes', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('description', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('entry_date', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('total_minutes', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
            'comment' => 'Denormalized sum of all duration_minutes for this object_uuid.',
        ]);
        $table->addColumn('created_at', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('updated_at', Types::DATETIME, [
            'notnull' => false,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'or_time_links_obj_idx');
        $table->addIndex(['user_id'], 'or_time_links_user_idx');
        $table->addIndex(['entry_date'], 'or_time_links_date_idx');

        $output->info('Created openregister_time_links table.');

        return $schema;
    }//end changeSchema()
}//end class
