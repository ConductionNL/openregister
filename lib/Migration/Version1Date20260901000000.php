<?php

/**
 * Re-run of the PostgreSQL `pg_trgm` extension bootstrap.
 *
 * Version1Date20260706110000 already installs `pg_trgm` — but that file's
 * `CREATE EXTENSION IF NOT EXISTS pg_trgm` call was added on 2026-07-21
 * (openregister commit 15d93450a3c), *after* the migration had already
 * shipped and been recorded as run on existing installations. Nextcloud's
 * migrator only executes each `app+version` row once, so instances that
 * ran the older empty version of the file never install the extension —
 * and `MagicSearchHandler::hasPgTrgmExtension()` correctly reports false,
 * so `_fuzzy=true` silently degrades to unindexed ILIKE and never sets
 * `@self.relevance`. Symptom: fuzzy typo-tolerance and relevance-scoring
 * broken on any instance upgraded through the intermediate window.
 *
 * This migration is a NEW `app+version` row that re-runs the same
 * idempotent `CREATE EXTENSION IF NOT EXISTS pg_trgm` on every instance.
 * Same tolerant-failure contract as Version1Date20260706110000 —
 * privilege denial is logged, never fatal.
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
 * Re-runs `CREATE EXTENSION IF NOT EXISTS pg_trgm` on PostgreSQL.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260901000000 extends SimpleMigrationStep
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
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (str_contains(get_class($platform), 'PostgreSQL') === false) {
            $output->info('Skipping pg_trgm extension re-bootstrap: unsupported database platform (PostgreSQL only)');
            return;
        }

        $this->ensurePgTrgmExtension(output: $output);
    }//end postSchemaChange()

    /**
     * Create the pg_trgm extension when missing, tolerating privilege failures.
     *
     * Same contract as Version1Date20260706110000::ensurePgTrgmExtension —
     * `CREATE EXTENSION IF NOT EXISTS` is idempotent, and a privilege
     * failure is logged rather than fatal so the migration is safe on
     * instances where the extension is already present or where the
     * connecting role lacks the CREATE EXTENSION privilege.
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
            $impact = 'Fuzzy/substring search (_fuzzy=true, @self.relevance) stays on the unindexed ILIKE path.';
            $action = 'Run CREATE EXTENSION pg_trgm; as a superuser and re-run this migration to enable it.';
            $output->warning($reason.' '.$impact.' '.$action);
        }//end try
    }//end ensurePgTrgmExtension()
}//end class
