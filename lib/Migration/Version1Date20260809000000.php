<?php

/**
 * Creates `openregister_schema_cache` — the table the persistent schema cache
 * has always written to, and which no migration ever created.
 *
 * `SchemaCacheHandler` has referenced this table since it was written. Nothing
 * in `lib/Migration/` creates, alters or drops it, so the persistent tier of
 * the schema cache has never worked on any instance.
 *
 * It failed silently rather than loudly. Every read and write sits inside a
 * try/catch whose comment describes the table as merely missing "yet", so the
 * handler degraded to its in-memory tier on every request and reported
 * nothing. A cache that is never populated and a cache that is always cold are
 * indistinguishable from the outside — the only visible symptom was
 * `relation "oc_openregister_schema_cache" does not exist` in the logs, which
 * reads like a migration ordering fault rather than a table that was never
 * declared.
 *
 * The column set is derived from the handler's own statements, not invented:
 * `schema_id`/`cache_key` from the WHERE clauses in `getCachedData()`,
 * `cache_data`/`created`/`updated`/`expires` from the INSERT in
 * `setCachedData()`.
 *
 * The unique index on (`schema_id`, `cache_key`) is load-bearing rather than
 * decorative. `setCachedData()` is an UPDATE followed by an INSERT when zero
 * rows were affected; without uniqueness two concurrent writers both see zero
 * updated rows and both insert, after which every subsequent read has two
 * candidate rows and `fetch()` returns whichever the planner picks. The index
 * makes that race a constraint violation instead of a silently wrong answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * Creates the `openregister_schema_cache` table.
 */
class Version1Date20260809000000 extends SimpleMigrationStep
{
    /**
     * Create the table if it does not already exist.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema, or null when unchanged.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_schema_cache') === true) {
            $output->info('openregister_schema_cache already exists; nothing to create.');
            return null;
        }

        $table = $schema->createTable('openregister_schema_cache');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]
        );

        $table->addColumn(
            'schema_id',
            Types::BIGINT,
            [
                'notnull' => true,
                'length'  => 20,
            ]
        );

        // The cache-key discriminator (`properties`, `resolved`, …). 64 is
        // ample for the handler's fixed vocabulary and keeps the composite
        // unique index within index-length limits on MySQL.
        $table->addColumn(
            'cache_key',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );

        // TEXT, not STRING: the handler stores `json_encode($data)` of a fully
        // resolved schema, which routinely exceeds any VARCHAR length that
        // MySQL would accept in a row alongside the other columns.
        $table->addColumn(
            'cache_data',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        foreach (['created', 'updated', 'expires'] as $column) {
            $table->addColumn(
                $column,
                Types::DATETIME,
                [
                    'notnull' => false,
                    'default' => null,
                ]
            );
        }

        $table->setPrimaryKey(['id']);

        // See the file docblock: this is what makes the handler's
        // UPDATE-then-INSERT safe under concurrency.
        $table->addUniqueIndex(['schema_id', 'cache_key'], 'or_schema_cache_uniq');

        // `getCachedData()` filters on `expires`, and the sweep in
        // `clearExpired()` scans it alone.
        $table->addIndex(['expires'], 'or_schema_cache_expires');

        $output->info('Created openregister_schema_cache.');

        return $schema;

    }//end changeSchema()
}//end class
