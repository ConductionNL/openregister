<?php

/**
 * Database migration for Action Registry.
 *
 * Creates the oc_openregister_actions and oc_openregister_action_logs tables
 * with all columns required by the Action Registry specification.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
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
 * Creates oc_openregister_actions and oc_openregister_action_logs tables.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260325000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        // Create oc_openregister_actions table.
        if ($schema->hasTable('openregister_actions') === false) {
            $table = $schema->createTable('openregister_actions');

            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('slug', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 20, 'default' => '1.0.0']);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'draft']);
            $table->addColumn('event_type', Types::TEXT, ['notnull' => true]);
            $table->addColumn('engine', Types::STRING, ['notnull' => true, 'length' => 50]);
            $table->addColumn('workflow_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 10, 'default' => 'sync']);
            $table->addColumn('execution_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('timeout', Types::INTEGER, ['notnull' => true, 'default' => 30]);
            $table->addColumn('on_failure', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'reject']);
            $table->addColumn('on_timeout', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'reject']);
            $table->addColumn('on_engine_down', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'allow']);
            $table->addColumn('filter_condition', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('configuration', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('mapping', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->addColumn('schemas', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('registers', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('schedule', Types::STRING, ['notnull' => false, 'length' => 100, 'default' => null]);
            $table->addColumn('max_retries', Types::INTEGER, ['notnull' => true, 'default' => 3]);
            $table->addColumn('retry_policy', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'exponential']);
            $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
            $table->addColumn('owner', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('application', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('last_executed_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('execution_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('success_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('failure_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('updated', Types::DATETIME, ['notnull' => true]);
            $table->addColumn('deleted', Types::DATETIME, ['notnull' => false, 'default' => null]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'or_actions_uuid_idx');
            $table->addUniqueIndex(['slug'], 'or_actions_slug_idx');
            $table->addIndex(['status'], 'or_actions_status_idx');
            $table->addIndex(['enabled'], 'or_actions_enabled_idx');
            $table->addIndex(['schedule'], 'or_actions_schedule_idx');
            $table->addIndex(['deleted'], 'or_actions_deleted_idx');

            $output->info('Created openregister_actions table');
            $changed = true;
        }//end if

        // Create oc_openregister_action_logs table.
        if ($schema->hasTable('openregister_action_logs') === false) {
            $table = $schema->createTable('openregister_action_logs');

            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('action_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('action_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36, 'default' => null]);
            $table->addColumn('schema_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->addColumn('register_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->addColumn('engine', Types::STRING, ['notnull' => true, 'length' => 50]);
            $table->addColumn('workflow_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20]);
            $table->addColumn('duration_ms', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $table->addColumn('request_payload', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('response_payload', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('error_message', Types::TEXT, ['notnull' => false, 'default' => null]);
            $table->addColumn('attempt', Types::INTEGER, ['notnull' => true, 'default' => 1]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['action_id'], 'or_actlog_action_id_idx');
            $table->addIndex(['action_uuid'], 'or_actlog_action_uuid_idx');
            $table->addIndex(['object_uuid'], 'or_actlog_object_uuid_idx');
            $table->addIndex(['status'], 'or_actlog_status_idx');

            $output->info('Created openregister_action_logs table');
            $changed = true;
        }//end if

        if ($changed === true) {
            return $schema;
        }

        return null;
    }//end changeSchema()
}//end class
