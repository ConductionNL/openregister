<?php

/**
 * Web Push subscription store migration.
 *
 * Creates `openregister_push_subscriptions` — infrastructure DB state for the
 * `web-push` notification channel (openregister-web-push-engine). Each row is
 * a browser Push API endpoint owned by one user:
 *
 *  - id (bigint, PK, autoincrement)
 *  - user_id (string 64, not null) — owning Nextcloud uid
 *  - endpoint (string 1024, not null) — push service URL (FCM/Mozilla/Apple)
 *  - p256dh (string 255, not null) — client P-256 ECDH public key
 *  - auth (string 255, not null) — client auth secret
 *  - user_agent (string 512) — browser UA at subscribe time (diagnostics)
 *  - created_at (datetime, not null)
 *
 * Indexes:
 *  - (user_id) — owner lookup for delivery
 *  - (endpoint) — prune-on-410 + upsert lookup
 *
 * This is explicitly NOT an OpenRegister object/register (ADR-001): the rows
 * are transient cryptographic endpoints with no business meaning. Idempotent:
 * creates the table only when absent.
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
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_push_subscriptions table.
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class Version1Date20260615130000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_push_subscriptions') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_push_subscriptions');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('endpoint', Types::STRING, ['notnull' => true, 'length' => 1024]);
        $table->addColumn('p256dh', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('auth', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('user_agent', Types::STRING, ['notnull' => false, 'length' => 512]);
        $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'idx_or_push_sub_user');
        $table->addIndex(['endpoint'], 'idx_or_push_sub_endpoint');

        $output->info('Created openregister_push_subscriptions table');

        return $schema;

    }//end changeSchema()
}//end class
