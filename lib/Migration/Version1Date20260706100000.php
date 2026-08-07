<?php

/**
 * Database migration to add a pgvector ANN sidecar table + HNSW index for openregister_vectors.
 *
 * Part of the hybrid-document-search change: vector similarity search on PostgreSQL
 * becomes index-backed (HNSW approximate-nearest-neighbour) instead of a PHP cosine
 * loop over up to 500 unserialized BLOBs. The existing serialized-BLOB `embedding`
 * column stays as the durable, platform-portable storage-of-record.
 *
 * IMPLEMENTATION AMENDMENT (2026-07-06, live-verified on PostgreSQL 16 / NC 34):
 * the design's original in-table `embedding_vector vector(N)` column is NOT viable —
 * Doctrine DBAL's introspectSchema() (which every subsequent Nextcloud migration of
 * every app, and core upgrades, run over all prefix-matched tables) throws
 * "Unknown database type vector requested" the moment an oc_-prefixed table carries
 * a pgvector-typed column. The accelerated path therefore lives in an UNPREFIXED
 * sidecar table (`openregister_vec_ann`) that Nextcloud's migration filter
 * (`/^<prefix>/`) never introspects: one row per vector (vector_id -> embedding
 * vector(N)), FK ON DELETE CASCADE to the main table, HNSW cosine index. Semantics
 * are identical to the designed column: "no sidecar row" == "embedding_vector IS
 * NULL" for the job-only warm-up.
 *
 * Backfill (DECIDED 2026-07-06, job-only warm-up): this migration creates the
 * sidecar table and index ONLY. All BLOB-to-pgvector conversion happens through
 * ChunkVectorizationJob's warm-up iterations selecting vectors without a sidecar
 * row, giving zero upgrade-time impact.
 *
 * NOTE: being invisible to Nextcloud's schema tooling also means the sidecar table
 * is not dropped automatically on app removal, and an embedding-dimension change
 * requires `DROP TABLE openregister_vec_ann` + re-running this migration (matching
 * the existing "Clear All Embeddings + re-vectorize" model-change procedure).
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
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Service\Vectorization\Handlers\PgVectorPlatform;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Container\ContainerInterface;

/**
 * Creates the `openregister_vec_ann` pgvector sidecar table and its HNSW index on PostgreSQL.
 *
 * PostgreSQL only — guarded the same way as Version1Date20260322120000 (retention
 * GIN index). On MariaDB/MySQL/SQLite this is a logged no-op: those platforms keep
 * using the serialized-BLOB `embedding` column with the PHP cosine fallback.
 *
 * The vector dimension is sized from the currently-configured embedding model
 * (design decision 2 of hybrid-document-search); rows carrying a different
 * dimension get no sidecar row and continue to be served by the PHP-cosine
 * fallback until re-vectorized.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#1.1
 */
class Version1Date20260706100000 extends SimpleMigrationStep
{
    /**
     * Fallback embedding dimension when no model is configured and no rows exist.
     */
    private const DEFAULT_DIMENSION = 1536;

    /**
     * Constructor.
     *
     * @param IDBConnection      $connection Database connection
     * @param IConfig            $config     Nextcloud config
     * @param ContainerInterface $container  App container, used to resolve the
     *                                       embedding services lazily so a DI
     *                                       failure during upgrade degrades
     *                                       instead of aborting
     */
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly IConfig $config,
        private readonly ContainerInterface $container
    ) {
    }//end __construct()

    /**
     * Create the pgvector sidecar table + HNSW index after schema changes (PostgreSQL only).
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#1.1
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_vectors') === false) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform();

        if (str_contains(get_class($platform), 'PostgreSQL') === false) {
            $output->info('Skipping pgvector ANN sidecar table: unsupported database platform (PostgreSQL only)');
            return;
        }

        $prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');

        if ($prefix === '') {
            // With an empty table prefix Nextcloud's migration filter matches
            // every table, so the sidecar cannot be hidden from Doctrine
            // introspection (whose type system does not know `vector`).
            $output->warning(
                'Skipping pgvector ANN sidecar table: empty dbtableprefix would expose the '
                .'vector-typed table to Doctrine schema introspection. Vector search stays '
                .'on the PHP fallback.'
            );
            return;
        }

        if ($this->ensureVectorExtension(output: $output) === false) {
            return;
        }

        $dimension = $this->resolveConfiguredDimension();

        $this->createSidecarTable(prefix: $prefix, dimension: $dimension, output: $output);
        $this->createHnswIndex(output: $output);
    }//end postSchemaChange()

    /**
     * Create the pgvector extension when missing, tolerating privilege failures.
     *
     * Matches the tolerant-check pattern used by MagicMapper::hasPgTrgmExtension():
     * a failure to CREATE EXTENSION (e.g. missing superuser privileges) is logged
     * and skipped rather than failing the whole migration — the runtime code
     * degrades gracefully to the BLOB path when the sidecar is absent.
     *
     * @param IOutput $output Migration output
     *
     * @return bool True when the extension is available
     */
    private function ensureVectorExtension(IOutput $output): bool
    {
        try {
            $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS vector');
            return true;
        } catch (Exception $e) {
            // Creation failed (usually privileges); check whether it already exists.
            try {
                $result = $this->connection->executeQuery(
                    "SELECT 1 FROM pg_extension WHERE extname = 'vector'"
                );
                if ($result->fetchOne() !== false) {
                    return true;
                }
            } catch (Exception $inner) {
                // Fall through to the warning below.
            }

            $output->warning(
                'pgvector extension is not installed and could not be created ('
                .$e->getMessage()
                .'). Skipping ANN sidecar table; vector search stays on the PHP fallback. '
                .'Run CREATE EXTENSION vector; as a superuser and re-run this migration to enable it.'
            );
            return false;
        }//end try
    }//end ensureVectorExtension()

    /**
     * Resolve the pgvector dimension from the configured embedding model.
     *
     * Order: currently-configured embedding model's dimension (design decision 2),
     * then the most common dimension among existing vector rows, then 1536.
     *
     * @return int Vector dimension
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Provider-specific settings lookup + fallbacks
     */
    private function resolveConfiguredDimension(): int
    {
        // 1. Currently-configured embedding model (SettingsService), resolved
        // lazily so a DI failure during upgrade degrades instead of aborting.
        try {
            $settingsService = $this->container->get(SettingsService::class);
            $generator       = $this->container->get(EmbeddingGeneratorHandler::class);

            if ($settingsService instanceof SettingsService
                && $generator instanceof EmbeddingGeneratorHandler
            ) {
                $llmSettings = $settingsService->getLLMSettingsOnly();
                $provider    = $llmSettings['embeddingProvider'] ?? null;

                $model = match ($provider) {
                    'openai' => $llmSettings['openaiConfig']['model'] ?? null,
                    'ollama' => $llmSettings['ollamaConfig']['model'] ?? null,
                    'fireworks' => $llmSettings['fireworksConfig']['embeddingModel'] ?? null,
                    default => null
                };

                if ($model !== null && $model !== '') {
                    return $generator->getDefaultDimensions($model);
                }
            }
        } catch (\Throwable $e) {
            // Fall through to data-driven fallback.
        }//end try

        // 2. Most common dimension among existing rows.
        try {
            $result = $this->connection->executeQuery(
                'SELECT embedding_dimensions FROM *PREFIX*openregister_vectors '
                .'WHERE embedding_dimensions IS NOT NULL '
                .'GROUP BY embedding_dimensions ORDER BY COUNT(*) DESC LIMIT 1'
            );
            $modal  = $result->fetchOne();
            if ($modal !== false && (int) $modal > 0) {
                return (int) $modal;
            }
        } catch (Exception $e) {
            // Fall through to the static default.
        }

        return self::DEFAULT_DIMENSION;
    }//end resolveConfiguredDimension()

    /**
     * Create the sidecar table when missing (idempotent).
     *
     * @param string  $prefix    Nextcloud table prefix (used for the FK target)
     * @param int     $dimension Vector dimension
     * @param IOutput $output    Migration output
     *
     * @return void
     */
    private function createSidecarTable(string $prefix, int $dimension, IOutput $output): void
    {
        $sidecar   = PgVectorPlatform::SIDECAR_TABLE;
        $mainTable = $prefix.'openregister_vectors';

        try {
            $result = $this->connection->executeQuery(
                'SELECT 1 FROM information_schema.tables WHERE table_name = :tbl',
                ['tbl' => $sidecar]
            );

            if ($result->fetchOne() !== false) {
                $output->info("pgvector ANN sidecar table $sidecar already exists");
                return;
            }

            $this->connection->executeStatement(
                "CREATE TABLE IF NOT EXISTS $sidecar ("
                ."vector_id BIGINT PRIMARY KEY REFERENCES $mainTable (id) ON DELETE CASCADE, "
                ."embedding vector($dimension) NOT NULL"
                .')'
            );

            $output->info("Created pgvector ANN sidecar table $sidecar (vector($dimension), cascade to $mainTable)");
        } catch (Exception $e) {
            $output->warning(
                'Failed to create pgvector ANN sidecar table '.$sidecar.': '.$e->getMessage()
                .'. Vector search stays on the PHP fallback.'
            );
        }//end try
    }//end createSidecarTable()

    /**
     * Create the HNSW ANN index when missing (idempotent).
     *
     * Non-concurrent by design (consistent with every other index-creation call
     * site in this codebase); the sidecar starts empty (job-only warm-up), so
     * the index build is instant at migration time.
     *
     * @param IOutput $output Migration output
     *
     * @return void
     */
    private function createHnswIndex(IOutput $output): void
    {
        $sidecar   = PgVectorPlatform::SIDECAR_TABLE;
        $indexName = 'idx_or_vec_ann_hnsw';

        try {
            $result = $this->connection->executeQuery(
                'SELECT 1 FROM pg_indexes WHERE indexname = :idx',
                ['idx' => $indexName]
            );

            if ($result->fetchOne() !== false) {
                $output->info("HNSW index $indexName already exists");
                return;
            }

            $this->connection->executeStatement(
                "CREATE INDEX $indexName ON $sidecar USING hnsw (embedding vector_cosine_ops)"
            );

            $output->info("Created HNSW index $indexName on $sidecar.embedding");
        } catch (Exception $e) {
            $output->warning(
                'Failed to create HNSW index on '.$sidecar.'.embedding: '.$e->getMessage()
                .'. KNN queries fall back to a sequential scan on the sidecar '
                .'(pgvector >= 0.5.0 is required for HNSW).'
            );
        }//end try
    }//end createHnswIndex()
}//end class
