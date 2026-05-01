<?php

/**
 * Migration creating `openregister_notification_history`.
 *
 * Records every notification dispatch (channel + recipient + rule)
 * so operators have an audit trail of what was sent, when, and to
 * whom. Closes the `notificatie-engine` spec's
 * "Notification history MUST be stored and queryable for audit
 * purposes" requirement — the existing `oc_openregister_webhook_logs`
 * only covers webhook deliveries, and `oc_activity` records what
 * happened to the object but not which notification rule fired.
 *
 * Schema:
 * - id (autoincrement)
 * - rule_id           — annotation key (per-schema rule identifier)
 * - schema_id         — schema the rule lives on
 * - register_id       — register the object lives in
 * - object_uuid       — object the event happened on
 * - channel           — `nc-notification` | `email` | `activity`
 *                       | `webhook` | `talk`
 * - recipient         — uid for per-recipient channels, `__webhook__`
 *                       / `__talk__` for broadcast channels
 * - subject           — interpolated subject string actually emitted
 * - status            — `dispatched` | `rate-limited` | `failed`
 * - error_message     — populated when status == failed
 * - locale            — locale of the recipient (or null for broadcast)
 * - dispatched_at     — wall-clock timestamp
 *
 * Indexes:
 * - (object_uuid, dispatched_at) for "what fired for this object"
 * - (rule_id, dispatched_at)     for "what fired for this rule"
 * - (recipient, dispatched_at)   for "what fired for this user"
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * Create notification history table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260501100000 extends SimpleMigrationStep
{
    /**
     * Add the openregister_notification_history table when missing.
     *
     * @param IOutput                   $output        Migration output sink.
     * @param Closure(): ISchemaWrapper $schemaClosure Closure returning the ISchemaWrapper.
     * @param array<string, mixed>      $options       Migration options (unused).
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_notification_history') === true) {
            return $schema;
        }

        $table = $schema->createTable('openregister_notification_history');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );
        $table->addColumn(
            'rule_id',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'schema_id',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'register_id',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'object_uuid',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'channel',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 32,
            ]
        );
        $table->addColumn(
            'recipient',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'subject',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'status',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 32,
                'default' => 'dispatched',
            ]
        );
        $table->addColumn(
            'error_message',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'locale',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 16,
            ]
        );
        $table->addColumn(
            'dispatched_at',
            Types::DATETIME,
            [
                'notnull' => true,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid', 'dispatched_at'], 'or_notif_hist_obj_idx');
        $table->addIndex(['rule_id', 'dispatched_at'], 'or_notif_hist_rule_idx');
        $table->addIndex(['recipient', 'dispatched_at'], 'or_notif_hist_recip_idx');

        return $schema;
    }//end changeSchema()
}//end class
