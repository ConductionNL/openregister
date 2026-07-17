<?php

/**
 * AVG / GDPR per-access processing-log (verwerkingenlogging) migration.
 *
 * Adds `openregister_processing_log` — an append-only record of every
 * READ / EXPORT of an object whose schema (or register) opts in via the
 * `x-openregister-processing` annotation (`logReads: true`). Writes are
 * NOT duplicated here: the hash-chained `openregister_audit_trail`
 * already stamps `processing_activity_id` on every mutation, and the
 * per-subject extract joins the two. This table closes the AVG Art 5(2)
 * / Art 30 accountability gap for raadplegen (reads), which the audit
 * trail never covered (VNG Logging Verwerkingen standard).
 *
 *  - id (bigint, PK, autoincrement)
 *  - uuid (string 36, not null) — VNG resource identity
 *  - activity_id (string 36, not null) — Verwerkingsactiviteit uuid
 *  - action (string 16, not null) — `read` | `export`
 *  - actor (string 191, not null) — NC user id or API-client identifier
 *  - channel (string 16, not null) — ui | api | graphql | mcp | public | background
 *  - register_id (string 36) — scoping slice
 *  - schema_id (string 36) — scoping slice
 *  - object_uuid (string 36) — read object (null for collapsed lists)
 *  - subject_id_type (string 64) — e.g. `BSN`
 *  - subject_id_value (string 191) — e.g. `123456789`
 *  - object_count (int) — bulk/list entries (default 1)
 *  - confidential (bool) — denormalised from the activity at write time
 *  - organisation_id (string 36) — tenant slice
 *  - created (datetime, not null) — period queries + retention prune
 *
 * Indexes (aggregate-friendly, no JSON in the hot filters):
 *  - (subject_id_type, subject_id_value) — per-betrokkene inzage
 *  - (register_id, activity_id, created)  — Art 30 / app slices
 *  - (created) — period queries + retention prune
 *  - (actor) — actor filter
 *
 * Append-only by surface: no update/delete-single mapper API, no
 * write routes. Idempotent: creates the table only when absent.
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_processing_log table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class Version1Date20260614000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_processing_log') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_processing_log');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('activity_id', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'read']);
        $table->addColumn('actor', Types::STRING, ['notnull' => true, 'length' => 191, 'default' => '']);
        $table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ui']);
        $table->addColumn('register_id', Types::STRING, ['notnull' => false, 'length' => 36]);
        $table->addColumn('schema_id', Types::STRING, ['notnull' => false, 'length' => 36]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
        $table->addColumn('subject_id_type', Types::STRING, ['notnull' => false, 'length' => 64]);
        $table->addColumn('subject_id_value', Types::STRING, ['notnull' => false, 'length' => 191]);
        $table->addColumn('object_count', Types::INTEGER, ['notnull' => true, 'default' => 1, 'unsigned' => true]);
        $table->addColumn('confidential', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('organisation_id', Types::STRING, ['notnull' => false, 'length' => 36]);
        $table->addColumn('created', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'idx_proclog_uuid');
        $table->addIndex(['subject_id_type', 'subject_id_value'], 'idx_proclog_subject');
        $table->addIndex(['register_id', 'activity_id', 'created'], 'idx_proclog_slice');
        $table->addIndex(['created'], 'idx_proclog_created');
        $table->addIndex(['actor'], 'idx_proclog_actor');

        $output->info('Created openregister_processing_log table');

        return $schema;

    }//end changeSchema()
}//end class
