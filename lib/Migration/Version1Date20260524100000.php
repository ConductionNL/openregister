<?php

/**
 * Tier-2 migration: extend `openregister_contact_links` with the columns
 * the dedicated ContactService + REST surface (and the new
 * `CnContactPicker` / `CnContactCreate` consumer-side dialogs) need to
 * cache and render a contact without having to round-trip CardDAV on
 * every list call.
 *
 * Adds:
 *   - schema_id     (int, indexed) — the OR schema the link belongs to
 *   - phone         (varchar 64, nullable) — primary TEL from vCard
 *   - org           (varchar 255, nullable) — primary ORG from vCard
 *   - avatar_url    (varchar 512, nullable) — PHOTO URI or per-uid route
 *   - metadata      (text/json, nullable) — extension bag for provider-
 *     specific payloads (per ADR-019 §AD-6)
 *
 * Also adds a unique composite index on (object_uuid, contact_uid) so
 * the upsert path in `ContactService::linkContact()` can rely on the DB
 * for idempotency instead of an application-level read-modify-write.
 *
 * The migration is idempotent — re-running skips columns that already
 * exist (so manual re-applies during development are safe).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
 * Version1Date20260524100000
 *
 * Extend the contact-links table with phone / org / avatar_url / metadata
 * + the (object_uuid, contact_uid) unique composite index.
 */
class Version1Date20260524100000 extends SimpleMigrationStep
{

    /**
     * Target table name (without `oc_` prefix).
     *
     * @var string
     */
    private const TABLE = 'openregister_contact_links';

    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Migration output stream
     * @param Closure                 $schemaClosure Schema closure
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

        if ($schema->hasTable(self::TABLE) === false) {
            // Earlier consolidated migration may have been dropped on
            // installs that moved to the magic-column pattern. Recreate
            // the table here so the Tier-2 path always has a target.
            $this->createTable(schema: $schema, output: $output);
            return $schema;
        }

        $table = $schema->getTable(self::TABLE);

        // Tier-2 columns, in the order they must be appended. Driving them from
        // a table keeps this method at one branch per concern instead of one
        // branch per column (which pushed NPath complexity to 256).
        $newColumns = [
            'schema_id'  => [
                'type'    => Types::BIGINT,
                'options' => [
                    'notnull'  => false,
                    'default'  => null,
                    'unsigned' => true,
                ],
            ],
            'phone'      => [
                'type'    => Types::STRING,
                'options' => [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                ],
            ],
            'org'        => [
                'type'    => Types::STRING,
                'options' => [
                    'notnull' => false,
                    'length'  => 255,
                    'default' => null,
                ],
            ],
            'avatar_url' => [
                'type'    => Types::STRING,
                'options' => [
                    'notnull' => false,
                    'length'  => 512,
                    'default' => null,
                ],
            ],
            'metadata'   => [
                'type'    => Types::TEXT,
                'options' => [
                    'notnull' => false,
                    'default' => null,
                ],
            ],
        ];

        $changed = false;

        foreach ($newColumns as $columnName => $spec) {
            if ($table->hasColumn(name: $columnName) === true) {
                continue;
            }

            $table->addColumn(
                name: $columnName,
                typeName: $spec['type'],
                options: $spec['options']
            );
            $output->info(message: 'Added '.$columnName.' column to '.self::TABLE);
            $changed = true;
        }

        if ($table->hasIndex(name: 'idx_contact_object_uid_uniq') === false) {
            $table->addUniqueIndex(
                columnNames: ['object_uuid', 'contact_uid'],
                indexName: 'idx_contact_object_uid_uniq'
            );
            $output->info(message: 'Added unique (object_uuid, contact_uid) index to '.self::TABLE);
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;

    }//end changeSchema()

    /**
     * Recreate the table when an earlier migration dropped it.
     *
     * Mirrors the Tier-2 columns directly so a fresh install ends up
     * with the same shape as a migrated existing install.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output stream
     *
     * @return void
     */
    private function createTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable(self::TABLE);

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('contact_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('addressbook_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('contact_uri', Types::STRING, ['notnull' => true, 'length' => 512]);
        $table->addColumn('display_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('phone', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('org', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('avatar_url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
        $table->addColumn('role', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('metadata', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'idx_contact_object');
        $table->addIndex(['contact_uid'], 'idx_contact_uid');
        $table->addIndex(['role'], 'idx_contact_role');
        $table->addUniqueIndex(['object_uuid', 'contact_uid'], 'idx_contact_object_uid_uniq');

        $output->info(message: 'Recreated '.self::TABLE.' with Tier-2 columns');

    }//end createTable()
}//end class
