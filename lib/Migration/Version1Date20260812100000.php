<?php

/**
 * A home on the row for the one piece of a flow that explains it.
 *
 * A flow authored as a definition file carries its rationale in a top-level
 * `$comment` — on hydra's lock reaper, 90 lines recording four defects and the
 * reasoning that prevents each recurring. `openregister_flows` had no column
 * for it, so importing such a file and regenerating it FROM the database
 * returned a flow without that text, and nothing reported the loss.
 *
 * The workaround was to regenerate by MERGING file and database rather than
 * exporting, which left the file as the only home for the rationale — meaning
 * a flow edited through the UI could not carry one at all, and two authors
 * editing the same flow by different routes silently disagreed about why it
 * was shaped that way.
 *
 * TEXT, not STRING: the existing bodies already exceed 6,000 characters, and a
 * length-capped column would truncate on write rather than refuse it.
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Adds `openregister_flows.comment`.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class Version1Date20260812100000 extends SimpleMigrationStep
{
    /**
     * Constructor.
     *
     * @param IDBConnection $connection Used to index the existing sharded tables.
     */
    public function __construct(private readonly IDBConnection $connection)
    {

    }//end __construct()

    /**
     * Add the two identity indexes to the sharded tables ALREADY on disk.
     *
     * `MagicMapper::createTableIndexes()` gained `_slug` and `_uri`, but it only
     * runs on the table-creation path — every table already stored keeps the old
     * index set, which is most of them on any instance that has been used.
     *
     * Why it matters: the single-object read ORs all four identity columns
     * together (`_id OR _uuid OR _slug OR _uri`), and Postgres will not use an
     * index for a disjunction unless it can use one for EVERY branch. With two
     * of the four unindexed it served a primary-key-shaped read as a sequential
     * scan. Measured on the contract register (2,962 rows): 2.5ms as a Seq Scan
     * against 0.107ms by `_uuid` alone, and the plan becomes a BitmapOr across
     * all four once both indexes exist.
     *
     * Per-table try/catch on purpose: a single table that refuses an index —
     * `_uri` is TEXT, and btree rejects an entry over roughly 8KB — must not
     * abort the migration and leave the remaining tables unindexed. The failure
     * is reported and the sweep continues.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return void
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (str_contains(get_class($platform), 'PostgreSQL') === false) {
            // `CREATE INDEX IF NOT EXISTS` is Postgres-only, and this mirrors
            // createTableIndexes(), which makes the same assumption.
            $output->info('Skipping sharded-table identity indexes: PostgreSQL only.');
            return;
        }

        $tables = $this->connection->executeQuery(
            "SELECT tablename FROM pg_tables WHERE tablename LIKE '%openregister\\_table\\_%'"
        )->fetchAll();

        $indexed = 0;
        $failed  = 0;
        foreach ($tables as $row) {
            $table = ($row['tablename'] ?? '');
            if ($table === '') {
                continue;
            }

            foreach (['slug', 'uri'] as $column) {
                try {
                    $this->connection->executeStatement(
                        'CREATE INDEX IF NOT EXISTS "'.$table.'_'.$column.'_idx" ON "'.$table.'" (_'.$column.')'
                    );
                    $indexed++;
                } catch (Throwable $e) {
                    $failed++;
                    $output->warning('Could not index '.$table.'._'.$column.': '.$e->getMessage());
                }
            }
        }

        $output->info(
            sprintf(
                'Sharded-table identity indexes: %d created or already present across %d table(s), %d refused.',
                $indexed,
                count($tables),
                $failed
            )
        );

    }//end postSchemaChange()

    /**
     * Add the comment column.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema, or null when unchanged.
     *
     * @spec openspec/specs/flow-engine/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_flows') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_flows');

        if ($table->hasColumn('comment') === true) {
            return null;
        }

        $table->addColumn('comment', Types::TEXT, ['notnull' => false, 'default' => null]);

        return $schema;

    }//end changeSchema()
}//end class
