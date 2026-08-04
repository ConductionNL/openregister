<?php

/**
 * Database migration that bootstraps the PostgreSQL `pg_trgm` extension once.
 *
 * Part of the searchable-property-index change: OpenRegister's magic-table
 * fuzzy/substring search (`_search`, `_fuzzy=true`) runs `similarity()` and
 * `ILIKE` against `_name` and `searchable`-flagged string columns. Those
 * queries become index-backed only when a `pg_trgm` GIN index exists, and a
 * `gin_trgm_ops` index can only be created once the `pg_trgm` extension is
 * present. This migration installs the extension once at `occ upgrade` time so
 * the index creation in `MagicMapper::createTableForRegisterSchema()` /
 * `createTableIndexes()` (gated by `hasPgTrgmExtension()`) can succeed.
 *
 * The extension detection logic itself (`MagicMapper::hasPgTrgmExtension()` /
 * `MagicSearchHandler::hasPgTrgmExtension()`) is unchanged — this migration only
 * adds the bootstrap; it does not introduce a new detection mechanism.
 *
 * PostgreSQL only — guarded the same way as Version1Date20260706100000
 * (pgvector `CREATE EXTENSION`). On MariaDB/MySQL/SQLite this is a logged no-op:
 * `pg_trgm` does not exist there, and the search code degrades gracefully to the
 * existing unindexed `ILIKE`/`CAST` path. A `CREATE EXTENSION` failure (e.g.
 * missing superuser privileges) is logged and skipped, never fatal — indexes
 * are simply never created and the slower-but-correct query path continues.
 *
 * Adds NO column and NO table, so it does not touch Doctrine's schema
 * introspection at all (unlike the vector/tsvector-typed columns the sibling
 * hybrid-document-search change had to route around a functional GIN index /
 * unprefixed sidecar table for). `CREATE EXTENSION` is a session-level DDL that
 * leaves no typed column behind.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Runs `CREATE EXTENSION IF NOT EXISTS pg_trgm` once on PostgreSQL.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @spec openspec/changes/searchable-property-index/tasks.md#1.1
 */
class Version1Date20260706110000 extends SimpleMigrationStep
{
    /**
     * Constructor.
     *
     * @param IDBConnection $connection Database connection
     */
    public function __construct(
        private readonly IDBConnection $connection
    ) {
    }//end __construct()

    /**
     * Ensure the pg_trgm extension exists after schema changes (PostgreSQL only).
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/searchable-property-index/tasks.md#1.1
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (str_contains(get_class($platform), 'PostgreSQL') === false) {
            $output->info('Skipping pg_trgm extension bootstrap: unsupported database platform (PostgreSQL only)');
            return;
        }

        $this->ensurePgTrgmExtension(output: $output);
    }//end postSchemaChange()

    /**
     * Create the pg_trgm extension when missing, tolerating privilege failures.
     *
     * Matches the tolerant-check pattern used by the sibling pgvector migration
     * (Version1Date20260706100000::ensureVectorExtension) and by
     * MagicMapper::hasPgTrgmExtension(): a failure to CREATE EXTENSION (usually
     * missing superuser privileges) is logged and skipped rather than failing
     * the whole migration — the runtime index-creation code degrades gracefully
     * to the unindexed query path when the extension is absent.
     *
     * @param IOutput $output Migration output
     *
     * @return void
     */
    private function ensurePgTrgmExtension(IOutput $output): void
    {
        try {
            $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $output->info('pg_trgm extension is available (fuzzy/substring search indexes can be created)');
            return;
        } catch (Exception $e) {
            // Creation failed (usually privileges); check whether it already exists.
            try {
                $result = $this->connection->executeQuery(
                    "SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"
                );
                if ($result->fetchOne() !== false) {
                    $output->info('pg_trgm extension already installed');
                    return;
                }
            } catch (Exception $inner) {
                // Fall through to the warning below.
            }

            $reason = 'pg_trgm extension is not installed and could not be created ('.$e->getMessage().').';
            $impact = 'Fuzzy/substring search over magic-table _name and searchable properties stays on the unindexed ILIKE/similarity() path.';
            $action = 'Run CREATE EXTENSION pg_trgm; as a superuser and re-run this migration to enable it.';
            $output->warning($reason.' '.$impact.' '.$action);
        }//end try
    }//end ensurePgTrgmExtension()
}//end class
