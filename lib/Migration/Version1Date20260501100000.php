<?php

/**
 * Migration: oc_openregister_notification_history table.
 *
 * Records every notification dispatch attempt for audit purposes.
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the openregister_notification_history table.
 */
class Version1Date20260501100000 extends SimpleMigrationStep
{
    /**
     * Apply schema changes.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_notification_history') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_notification_history');

        $table->addColumn(name: 'id', typeName: Types::INTEGER, options: ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn(name: 'rule_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => true]);
        $table->addColumn(name: 'channel', typeName: Types::STRING, options: ['length' => 64, 'notnull' => true]);
        $table->addColumn(name: 'recipient', typeName: Types::STRING, options: ['length' => 255, 'notnull' => true]);
        $table->addColumn(name: 'object_uuid', typeName: Types::STRING, options: ['length' => 36, 'notnull' => false, 'default' => null]);
        $table->addColumn(name: 'schema_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => false, 'default' => null]);
        $table->addColumn(name: 'register_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => false, 'default' => null]);
        $table->addColumn(name: 'status', typeName: Types::STRING, options: ['length' => 32, 'notnull' => true, 'default' => 'dispatched']);
        $table->addColumn(name: 'dispatched_at', typeName: Types::DATETIME, options: ['notnull' => true]);

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addIndex(columnNames: ['rule_id'], indexName: 'or_nh_rule_id');
        $table->addIndex(columnNames: ['channel'], indexName: 'or_nh_channel');
        $table->addIndex(columnNames: ['recipient'], indexName: 'or_nh_recipient');
        $table->addIndex(columnNames: ['object_uuid'], indexName: 'or_nh_object_uuid');
        $table->addIndex(columnNames: ['status'], indexName: 'or_nh_status');
        $table->addIndex(columnNames: ['dispatched_at'], indexName: 'or_nh_dispatched_at');

        return $schema;
    }//end changeSchema()
}//end class
