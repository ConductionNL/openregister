<?php

/**
 * Creates the two schema-cache tables the cache handlers have always queried.
 *
 * `SchemaCacheHandler` and `FacetCacheHandler` read and write
 * `openregister_schema_cache` and `openregister_schema_facet_cache`. No
 * migration ever created either one, so every statement they issued failed at
 * the database and every failure was swallowed by a `catch` whose comment
 * described the state as transitional — "the migration hasn't been run yet".
 * There was no migration for it to be transitional to, so the tolerance was
 * permanent and the persistent schema cache has never cached anything. Only
 * the in-process `self::$memoryCache` was ever doing work, and that does not
 * survive the request.
 *
 * Measured on ConductionNL/openbuild Code Quality run 31083894467 (E2E job
 * 92559832374, which pins `openregister@development`): a single run produced
 * 33x `relation "oc_openregister_schema_cache" does not exist` and 22x
 * `relation "oc_openregister_schema_facet_cache" does not exist` in the
 * Postgres server log. The same errors appear in decidesk, pipelinq and
 * nldesign, all of which pin the same ref.
 *
 * The column set below is not invented: it is exactly what the two handlers
 * already write. `openregister_schema_cache` comes from
 * `SchemaCacheHandler::setCachedData()` (schema_id, cache_key, cache_data,
 * created, updated, expires) and is read back by `getCachedData()` on
 * (schema_id, cache_key). `openregister_schema_facet_cache` comes from
 * `FacetCacheHandler::setCachedFacetData()` (schema_id, facet_type,
 * field_name, facet_config, cache_data, created, updated, expires), whose
 * update arm keys on (schema_id, field_name) and whose statistics query
 * groups by facet_type.
 *
 * The uniqueness constraints are what make the handlers' update-then-insert
 * upsert correct rather than merely usual: without them a lost race appends a
 * second row for the same key and the reader's `fetch()` would return whichever
 * one the planner happened to reach first.
 *
 * `expires` is nullable because a zero TTL means "no expiry" in both handlers;
 * NULL there means the entry never expires, not that expiry is unknown.
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
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `openregister_schema_cache` and `openregister_schema_facet_cache`.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class Version1Date20260809000000 extends SimpleMigrationStep
{

    /**
     * Name of the schema cache table.
     *
     * @var string
     */
    private const SCHEMA_CACHE_TABLE = 'openregister_schema_cache';

    /**
     * Name of the facet cache table.
     *
     * @var string
     */
    private const FACET_CACHE_TABLE = 'openregister_schema_facet_cache';

    /**
     * Create both cache tables when they are absent.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema, or null when unchanged.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable(tableName: self::SCHEMA_CACHE_TABLE) === false) {
            $this->createSchemaCacheTable(schema: $schema);
            $output->info('Created '.self::SCHEMA_CACHE_TABLE.'.');
            $changed = true;
        }

        if ($schema->hasTable(tableName: self::FACET_CACHE_TABLE) === false) {
            $this->createFacetCacheTable(schema: $schema);
            $output->info('Created '.self::FACET_CACHE_TABLE.'.');
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;

    }//end changeSchema()

    /**
     * Create the schema cache table.
     *
     * @param ISchemaWrapper $schema The schema being modified.
     *
     * @return void
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function createSchemaCacheTable(ISchemaWrapper $schema): void
    {
        $table = $schema->createTable(self::SCHEMA_CACHE_TABLE);

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
        // The handler's own vocabulary is `schema_object`, `facetable_fields`,
        // `configuration` and `properties`; 64 leaves room without inviting a
        // key long enough to blow the composite index.
        $table->addColumn(
            'cache_key',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'cache_data',
            Types::TEXT,
            ['notnull' => true]
        );
        $table->addColumn(
            'created',
            Types::DATETIME,
            ['notnull' => true]
        );
        $table->addColumn(
            'updated',
            Types::DATETIME,
            ['notnull' => true]
        );
        // NULL means "never expires" — a zero TTL in the handler.
        $table->addColumn(
            'expires',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['schema_id', 'cache_key'], 'or_schema_cache_key_uniq');
        $table->addIndex(['expires'], 'or_schema_cache_expires_idx');

    }//end createSchemaCacheTable()

    /**
     * Create the facet cache table.
     *
     * @param ISchemaWrapper $schema The schema being modified.
     *
     * @return void
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function createFacetCacheTable(ISchemaWrapper $schema): void
    {
        $table = $schema->createTable(self::FACET_CACHE_TABLE);

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
        $table->addColumn(
            'facet_type',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        // A schema property name. 255 keeps the composite unique index inside
        // the 3072-byte InnoDB limit at utf8mb4.
        $table->addColumn(
            'field_name',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 255,
            ]
        );
        $table->addColumn(
            'facet_config',
            Types::TEXT,
            [
                'notnull' => false,
                'default' => null,
            ]
        );
        $table->addColumn(
            'cache_data',
            Types::TEXT,
            ['notnull' => true]
        );
        $table->addColumn(
            'created',
            Types::DATETIME,
            ['notnull' => true]
        );
        $table->addColumn(
            'updated',
            Types::DATETIME,
            ['notnull' => true]
        );
        $table->addColumn(
            'expires',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );

        $table->setPrimaryKey(['id']);
        // `setCachedFacetData()` updates on (schema_id, field_name), so that is
        // the pair that must be unique for its upsert to hold.
        $table->addUniqueIndex(['schema_id', 'field_name'], 'or_facet_cache_field_uniq');
        $table->addIndex(['facet_type'], 'or_facet_cache_type_idx');
        $table->addIndex(['expires'], 'or_facet_cache_expires_idx');

    }//end createFacetCacheTable()
}//end class
