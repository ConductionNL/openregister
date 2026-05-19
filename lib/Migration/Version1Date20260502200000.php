<?php

/**
 * Migration: oc_openregister_notification_subscriptions table.
 *
 * Per-user (register, schema) notification subscription preferences.
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the openregister_notification_subscriptions table.
 */
class Version1Date20260502200000 extends SimpleMigrationStep
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

        if ($schema->hasTable(tableName: 'openregister_notification_subscriptions') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_notification_subscriptions');

        $table->addColumn(name: 'id', typeName: Types::INTEGER, options: ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn(name: 'user_id', typeName: Types::STRING, options: ['length' => 64, 'notnull' => true]);
        $table->addColumn(name: 'register_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => false, 'default' => null]);
        $table->addColumn(name: 'schema_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => false, 'default' => null]);
        $table->addColumn(name: 'created_at', typeName: Types::DATETIME, options: ['notnull' => true]);

        $table->setPrimaryKey(columnNames: ['id']);
        $table->addUniqueIndex(columnNames: ['user_id', 'register_id', 'schema_id'], indexName: 'or_ns_user_register_schema');
        $table->addIndex(columnNames: ['user_id'], indexName: 'or_ns_user_id');

        return $schema;
    }//end changeSchema()
}//end class
