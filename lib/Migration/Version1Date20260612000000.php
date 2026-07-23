<?php

/**
 * Scheduled-notification per-object dedup state migration.
 *
 * Adds `openregister_notification_dedupe` — one row per
 * (schema_id, rule_key, object_uuid) capturing the dispatch fingerprint
 * for the scheduled-notification engine's per-object dedup loop
 * (notification-engine-scheduled-conditions Phase 3).
 *
 *  - id (bigint, PK, autoincrement)
 *  - schema_id (bigint unsigned, not null) — owning schema id
 *  - rule_key (string 191, not null) — notification annotation key
 *  - object_uuid (string 36, not null) — target ObjectEntity UUID
 *  - fingerprint (string 64, not null) — SHA-1 of watched-field values
 *  - dispatched_at (datetime, not null) — first dispatch (or re-arm)
 *  - seen_at (datetime, not null) — last evaluator match
 *
 * Indexes:
 *  - UNIQUE (schema_id, rule_key, object_uuid) — primary lookup
 *  - (object_uuid) — purge-on-object-delete
 *  - (seen_at) — retention sweep
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
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_notification_dedupe table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260612000000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_notification_dedupe') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_notification_dedupe');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('rule_key', Types::STRING, ['notnull' => true, 'length' => 191, 'default' => '']);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36, 'default' => '']);
        $table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $table->addColumn('dispatched_at', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('seen_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['schema_id', 'rule_key', 'object_uuid'], 'idx_notifdedup_triple');
        $table->addIndex(['object_uuid'], 'idx_notifdedup_object');
        $table->addIndex(['seen_at'], 'idx_notifdedup_seen');

        $output->info('Created openregister_notification_dedupe table');

        return $schema;
    }//end changeSchema()
}//end class
