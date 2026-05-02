<?php

/**
 * Migration creating `oc_openregister_notification_readstate`.
 *
 * Cross-channel read/unread tracking per (user_id, notification_id)
 * tuple. NC's INotificationManager already tracks the in-app
 * `nc-notification` channel; this table extends the contract to
 * email / webhook / talk channels by recording when the user
 * acknowledged a notification on any channel.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notificatie-engine/tasks.md "Read/unread tracking MUST be maintained per user per notification"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the cross-channel notification read-state table.
 */
class Version1Date20260502190000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_notification_readstate') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_notification_readstate');

        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );

        $table->addColumn(
            name: 'user_id',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 64,
                'comment' => 'NC user UID who marked the notification read',
            ]
        );

        $table->addColumn(
            name: 'notification_id',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 128,
                'comment' => 'Notification identifier (uuid / NC notification id / channel-specific token)',
            ]
        );

        $table->addColumn(
            name: 'read_at',
            typeName: Types::DATETIME,
            options: [
                'notnull' => true,
                'comment' => 'Timestamp when the notification was marked read',
            ]
        );

        $table->setPrimaryKey(columnNames: ['id']);
        // Per-(user, notification) uniqueness so markRead is idempotent at the DB layer.
        $table->addUniqueIndex(
            columnNames: ['user_id', 'notification_id'],
            indexName: 'idx_or_readstate_uid_nid'
        );
        $table->addIndex(columnNames: ['user_id'], indexName: 'idx_or_readstate_user_id');

        return $schema;

    }//end changeSchema()
}//end class
