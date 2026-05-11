<?php

/**
 * Migration creating `openregister_notif_dispatch_log`.
 *
 * Stores one row per (notification_slug, idempotency_key) first
 * dispatch so the notification engine can deduplicate subsequent
 * dispatches with the same key within a configurable window (default
 * 24 h). Closes the scholiq deps idempotency requirement:
 *
 * "Dispatches MUST be stored in a dedupe table; the second dispatch
 * with the same key MUST be a no-op."
 *
 * Schema:
 * - id                — autoincrement PK
 * - notification_slug — annotation key that fired (e.g. `reminderT30`)
 * - idempotency_key   — resolved key template (e.g. `uuid-123-T30-2026-06-01`)
 * - dispatched_at     — wall-clock timestamp of the first dispatch
 *
 * Indexes:
 * - unique (notification_slug, idempotency_key) — prevents duplicate rows
 *   and is the primary lookup path for the dedupe check
 * - (dispatched_at) — used by the prune query to clean up expired rows
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Create the notification dispatch log table for idempotency-key dedup.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260511120000 extends SimpleMigrationStep
{

    /**
     * Add the openregister_notif_dispatch_log table when missing.
     *
     * @param IOutput                   $output        Migration output sink.
     * @param Closure(): ISchemaWrapper $schemaClosure Closure returning the ISchemaWrapper.
     * @param array<string, mixed>      $options       Migration options (unused).
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_notif_dispatch_log') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_notif_dispatch_log');

        $table->addColumn(
            name: 'id',
            typeName: Types::BIGINT,
            options: [
                'autoincrement' => true,
                'notnull'       => true,
            ]
        );

        $table->addColumn(
            name: 'notification_slug',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 255,
                'comment' => 'Annotation key (per-schema rule identifier)',
            ]
        );

        $table->addColumn(
            name: 'idempotency_key',
            typeName: Types::STRING,
            options: [
                'notnull' => true,
                'length'  => 512,
                'comment' => 'Resolved idempotency key template',
            ]
        );

        $table->addColumn(
            name: 'dispatched_at',
            typeName: Types::DATETIME,
            options: [
                'notnull' => true,
                'comment' => 'Wall-clock timestamp of first dispatch for this key',
            ]
        );

        $table->setPrimaryKey(columnNames: ['id']);

        // Unique index is the primary dedup lookup (slug, key).
        $table->addUniqueIndex(
            columnNames: ['notification_slug', 'idempotency_key'],
            indexName: 'idx_or_ndl_slug_key'
        );

        // Secondary index on dispatched_at for the prune DELETE query.
        $table->addIndex(
            columnNames: ['dispatched_at'],
            indexName: 'idx_or_ndl_dispatched_at'
        );

        return $schema;

    }//end changeSchema()
}//end class
