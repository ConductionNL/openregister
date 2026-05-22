<?php

/**
 * Repair migration: drop the bare `_uuid` index on every dynamic
 * `oc_openregister_table_*` table.
 *
 * Why: `MagicMapper::createTable()` previously emitted
 *   ALTER TABLE ... CREATE TABLE ... , UNIQUE (`_uuid`)
 * without an explicit constraint name. MySQL/MariaDB fall back to
 * using the column name as the constraint/index name, so every
 * dynamic openregister table ended up with an index literally
 * called `_uuid`. The next time Nextcloud's MigrationService runs
 * `ensureUniqueNamesConstraints` at app-install time, the duplicate
 * index name across tables raises `InvalidArgumentException` and
 * blocks the install (observed first when installing doriath).
 *
 * The forward fix in MagicMapper now emits a uniquely-named
 * CONSTRAINT, so newly-created tables are safe. This migration
 * repairs already-broken installations by dropping the bare index.
 * The correctly-prefixed `{table}_uuid_idx` (also unique, also on
 * `_uuid`) created by `createTableIndexes()` remains, so the column
 * stays unique and PostgreSQL `ON CONFLICT` still has a unique
 * index to target.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Version1Date20260521120000
 *
 * Drops the bare `_uuid` index on every dynamic openregister table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20260521120000 extends SimpleMigrationStep
{

    /**
     * Database connection.
     *
     * @var IDBConnection
     */
    private IDBConnection $connection;

    /**
     * Constructor.
     *
     * @param IDBConnection $connection Database connection
     */
    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;

    }//end __construct()

    /**
     * No schema changes — repair runs in postSchemaChange where raw
     * DDL against dynamic tables is allowed.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper Always null — no schema diff
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;

    }//end changeSchema()

    /**
     * Drop the bare `_uuid` index on every `oc_openregister_table_*` table.
     *
     * Idempotent: skips tables that don't have the index.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $isPostgres = ($this->connection->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES);

        $affectedTables = $this->findAffectedTables(isPostgres: $isPostgres);

        if (\count($affectedTables) === 0) {
            $output->info(message: 'No dynamic openregister tables have the bare `_uuid` index — nothing to repair.');
            return;
        }

        if ($isPostgres === true) {
            // PostgreSQL index names are schema-scoped, not table-scoped — so
            // each per-table bare `_uuid` index has a unique fully-qualified
            // identifier (the auto-naming would have suffixed numbers if it
            // ever collided). We can't safely drop by bare name across the
            // schema; instead drop per-table via the table's own metadata.
            $dropped = 0;
            foreach ($affectedTables as $tableName) {
                try {
                    // Look up indexes on this table named `_uuid` and drop each by full name.
                    $sql  = "SELECT indexname
                            FROM pg_indexes
                            WHERE tablename = ?
                              AND indexname LIKE '\\_uuid%' ESCAPE '\\'";
                    $rows = $this->connection->executeQuery($sql, [$tableName])->fetchAll();
                    foreach ($rows as $row) {
                        $indexName = (string) $row['indexname'];
                        // Be conservative: only drop indexes whose name is exactly `_uuid`
                        // or the bare-name suffixed variants Postgres emits on collision.
                        if (preg_match('/^_uuid(\d+)?$/', $indexName) === 1) {
                            $this->connection->executeStatement(
                                sprintf('DROP INDEX IF EXISTS "%s"', $indexName)
                            );
                            $dropped++;
                        }
                    }
                } catch (Throwable $e) {
                    $output->warning(
                        message: sprintf(
                            'Failed to drop bare `_uuid` index on `%s`: %s',
                            $tableName,
                            $e->getMessage()
                        )
                    );
                }//end try
            }//end foreach
        } else {
            $dropped = 0;
            foreach ($affectedTables as $tableName) {
                try {
                    $this->connection->executeStatement(
                        sprintf('ALTER TABLE `%s` DROP INDEX `_uuid`', $tableName)
                    );
                    $dropped++;
                } catch (Throwable $e) {
                    $output->warning(
                        message: sprintf(
                            'Failed to drop bare `_uuid` index on `%s`: %s',
                            $tableName,
                            $e->getMessage()
                        )
                    );
                }
            }
        }//end if

        $output->info(
            message: sprintf(
                'Dropped bare `_uuid` index on %d of %d dynamic openregister table(s).',
                $dropped,
                \count($affectedTables)
            )
        );

    }//end postSchemaChange()

    /**
     * Find every `oc_openregister_table_*` table that has an index literally
     * named `_uuid`. Returns the unprefixed table names that MySQL/MariaDB
     * expect in `ALTER TABLE`, i.e. including the `oc_` prefix.
     *
     * @param bool $isPostgres Whether the platform is PostgreSQL
     *
     * @return string[] List of fully-qualified table names to repair
     */
    private function findAffectedTables(bool $isPostgres): array
    {
        $tableNames = [];

        try {
            if ($isPostgres === true) {
                // Mirror the LIKE pattern used in postSchemaChange so a table
                // whose bare index ended up as `_uuid1` (Postgres' on-collision
                // auto-suffix) is still surfaced for repair. The downstream
                // drop step re-filters with a strict `^_uuid(\d+)?$` regex.
                $sql    = "SELECT tablename
                        FROM pg_indexes
                        WHERE indexname LIKE '\\_uuid%' ESCAPE '\\'
                          AND tablename LIKE 'oc_openregister_table_%'";
                $result = $this->connection->executeQuery($sql);
            } else {
                $sql    = 'SELECT TABLE_NAME
                        FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND INDEX_NAME = ?
                          AND TABLE_NAME LIKE ?
                        GROUP BY TABLE_NAME';
                $result = $this->connection->executeQuery($sql, ['_uuid', 'oc_openregister_table_%']);
            }

            foreach ($result->fetchAll() as $row) {
                $name = $row['tablename'] ?? $row['TABLE_NAME'] ?? null;
                if ($name !== null) {
                    $tableNames[] = (string) $name;
                }
            }
        } catch (Throwable $e) {
            // If introspection fails (unsupported platform, permission issue),
            // surface no tables and let the caller log the no-op message.
            return [];
        }//end try

        return $tableNames;

    }//end findAffectedTables()
}//end class
