<?php

/**
 * Index `openregister_flow_runs` on (organisation, status, id) for the
 * active-runs read.
 *
 * OR-FLOW-ACTIVE-RUNS (openspec/changes/or-flow-active-runs). The live-runs
 * surface filters `organisation = ? AND status IN (queued, running, suspended)`
 * and takes the newest N by id. The table's existing indexes cover the worker's
 * reads — `(status, id)` and `(status, resume_at)` — but nothing covers
 * `organisation`, and the planner therefore answered the widget's query by
 * walking the primary key BACKWARDS and filtering, because `ORDER BY id DESC
 * LIMIT 10` makes that look cheap.
 *
 * It is not. Measured on a dev instance with 48,058 runs, of which ~26k queued
 * and all but a handful carrying no organisation:
 *
 *     Index Scan Backward using ..._pkey  (actual time=1.157..293.639 rows=1)
 *       Rows Removed by Filter: 48048
 *
 * One matching row, 48,048 rows read to find it, and the cost grows with the
 * table — on a surface that a dashboard widget POLLS every 15 seconds, for
 * every user of every app that places it. That is the difference between a
 * cheap read and an instance-wide load source.
 *
 * `organisation` leads the index because it is the equality that eliminates the
 * most rows (runs are per-tenant), `status` follows as the second equality
 * (an IN over three values), and `id` last so the newest-first ordering and the
 * LIMIT are satisfied from the index rather than by a sort.
 *
 * Strictly additive: an index add cannot fail on existing data and changes no
 * rows. Idempotent: added only when absent.
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
 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `or_flowrun_org_status_idx` on `openregister_flow_runs`.
 *
 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
 */
class Version1Date20260730120000 extends SimpleMigrationStep
{
    /**
     * The index that serves the tenant-scoped active-runs read.
     *
     * @var string
     */
    private const ACTIVE_RUNS_INDEX = 'or_flowrun_org_status_idx';

    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_flow_runs') === false) {
            return $schema;
        }

        $table = $schema->getTable('openregister_flow_runs');

        if ($table->hasIndex(self::ACTIVE_RUNS_INDEX) === true) {
            $output->info(self::ACTIVE_RUNS_INDEX.' already present on openregister_flow_runs; nothing to do.');
            return $schema;
        }

        $table->addIndex(['organisation', 'status', 'id'], self::ACTIVE_RUNS_INDEX);
        $output->info(
            'Added '.self::ACTIVE_RUNS_INDEX.' on openregister_flow_runs(organisation, status, id): '
            .'the active-runs read previously walked the primary key backwards and filtered, '
            .'reading tens of thousands of rows to return one.'
        );

        return $schema;
    }//end changeSchema()
}//end class
