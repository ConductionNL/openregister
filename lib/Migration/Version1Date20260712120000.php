<?php

/**
 * Notification delivery-window / digest-schedule queue migration.
 *
 * Adds `openregister_notification_queue` — one row per notification the
 * dispatcher held instead of dispatching immediately, either because the
 * recipient's quiet-hours delivery window is active or because the
 * triggering rule's fixed-time `digest` schedule has not yet fired.
 * Replaces the unwired in-memory `NotificationDigest` primitive (see
 * openspec/changes/notification-delivery-windows/design.md).
 *
 *  - id (bigint, PK, autoincrement)
 *  - schema_id (bigint unsigned, not null) — owning schema id
 *  - rule_key (string 191, not null) — notification annotation key
 *  - recipient (string 191, not null) — recipient user UID
 *  - reason (string 32, not null) — quiet-hours | digest-schedule | quiet-hours+digest-schedule
 *  - object_uuid (string 36, nullable) — the triggering object, when applicable
 *  - payload (text, not null) — pre-resolved subject/message/channels/context JSON
 *  - due_at_hint (datetime, not null) — advisory, operator-visibility only
 *  - created_at (datetime, not null)
 *
 * Indexes:
 *  - (recipient, reason) — the flush job's per-recipient grouping scan
 *  - (due_at_hint) — operator-facing "how backed up is the queue" (follow-up)
 *
 * Idempotent: creates the table only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-delivery-windows/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_notification_queue table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260712120000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_notification_queue') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_notification_queue');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('rule_key', Types::STRING, ['notnull' => true, 'length' => 191, 'default' => '']);
        $table->addColumn('recipient', Types::STRING, ['notnull' => true, 'length' => 191, 'default' => '']);
        $table->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
        $table->addColumn('payload', Types::TEXT, ['notnull' => true]);
        $table->addColumn('due_at_hint', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['recipient', 'reason'], 'idx_notifqueue_recip_reason');
        $table->addIndex(['due_at_hint'], 'idx_notifqueue_due_hint');
        $table->addIndex(['rule_key', 'recipient'], 'idx_notifqueue_rule_recip');

        $output->info('Created openregister_notification_queue table');

        return $schema;
    }//end changeSchema()
}//end class
