<?php

/**
 * Database migration to add a functional tsvector GIN index on openregister_chunks.
 *
 * Part of the hybrid-document-search change: gives OpenRegister a real, ranked
 * (`ts_rank`) keyword-search path over extracted chunk text. Before this change no
 * ranked keyword arm existed on any platform, so the index is purely additive;
 * ChunkMapper::searchByKeyword() returns [] with a logged warning on platforms
 * without it.
 *
 * IMPLEMENTATION AMENDMENT (2026-07-06, live-verified on PostgreSQL 16 / NC 34):
 * the design's original STORED `text_search tsvector` generated column is NOT
 * viable — Doctrine DBAL's introspectSchema() (run by every subsequent Nextcloud
 * migration and core upgrade) throws "Unknown database type" for tsvector-typed
 * columns on prefix-matched tables, exactly like the pgvector column in the
 * sibling migration. A functional (expression) GIN index over
 * to_tsvector('simple', text_content) provides the same index-backed ranked
 * keyword search with zero schema-visible changes; the expression-index precedent
 * is Version1Date20260322120000's `(retention::jsonb) jsonb_path_ops` GIN index,
 * which already lives on production installs without breaking migrations.
 *
 * The 'simple' text-search configuration (not 'english') is deliberate: it avoids
 * English-only stemming bias given OpenRegister's Dutch-government usage context
 * (design decision 7 of hybrid-document-search).
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
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the functional `to_tsvector('simple', text_content)` GIN index on PostgreSQL.
 *
 * PostgreSQL only — guarded the same way as Version1Date20260322120000 (retention
 * GIN index). On MariaDB/MySQL/SQLite this is a logged no-op.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#1.2
 */
class Version1Date20260706101000 extends SimpleMigrationStep
{
    /**
     * Constructor.
     *
     * @param IDBConnection $connection Database connection
     * @param IConfig       $config     Nextcloud config
     */
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly IConfig $config
    ) {
    }//end __construct()

    /**
     * Add the functional tsvector GIN index after schema changes (PostgreSQL only).
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#1.2
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_chunks') === false) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform();

        if (str_contains(get_class($platform), 'PostgreSQL') === false) {
            $output->info('Skipping tsvector keyword-search index: unsupported database platform (PostgreSQL only)');
            return;
        }

        $prefix    = $this->config->getSystemValueString('dbtableprefix', 'oc_');
        $tableName = $prefix.'openregister_chunks';

        $this->createGinIndex(tableName: $tableName, output: $output);
    }//end postSchemaChange()

    /**
     * Create the functional GIN index when missing (idempotent).
     *
     * @param string  $tableName Full table name with prefix
     * @param IOutput $output    Migration output
     *
     * @return void
     */
    private function createGinIndex(string $tableName, IOutput $output): void
    {
        $indexName = 'idx_or_chunks_text_search_gin';

        try {
            $result = $this->connection->executeQuery(
                'SELECT 1 FROM pg_indexes WHERE indexname = :idx',
                ['idx' => $indexName]
            );

            if ($result->fetchOne() !== false) {
                $output->info("GIN index $indexName already exists");
                return;
            }

            $this->connection->executeStatement(
                "CREATE INDEX $indexName ON $tableName "
                ."USING gin (to_tsvector('simple', text_content))"
            );

            $output->info(
                "Created functional GIN index $indexName on $tableName (to_tsvector('simple', text_content))"
            );
        } catch (Exception $e) {
            $output->warning(
                'Failed to create functional GIN index on '.$tableName.': '.$e->getMessage()
                .'. Ranked keyword search over chunk text stays unavailable.'
            );
        }//end try
    }//end createGinIndex()
}//end class
