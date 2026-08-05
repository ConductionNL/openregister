<?php

/**
 * Creates the flow-definition table.
 *
 * Runs, links and state were already native tables; only the definition was an
 * OpenRegister object. That asymmetry is what forced the `IFlowResolver`
 * indirection — its whole purpose was to paper over "flows live in a different
 * register per app". One native store removes both the indirection and the
 * per-app duplication (hermiq's `agentflow` register, its own resolver, its own
 * executor).
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `openregister_flows`.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class Version1Date20260803000000 extends SimpleMigrationStep
{
    /**
     * Create the flow-definition table.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The updated schema, or null when nothing changed.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_flows') === true) {
            $output->info(message: 'openregister_flows already exists, skipping...');
            return null;
        }

        $table = $schema->createTable('openregister_flows');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
        $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);

        // The owning Nextcloud app id. This is the per-app scoping key that
        // replaces "one register+schema per app": OpenConnector lists
        // `app = openconnector`, OpenRegister lists everything.
        $table->addColumn('app', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'openregister']);

        $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
        $table->addColumn('trigger', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('trigger_register', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('trigger_schema', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('cron', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('execution_mode', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => 'async']);

        // The graph itself, plus the ceilings that make a walk provably
        // terminate (`maxNodes` / `maxIterations`) — carried over from hermiq's
        // `agentflow`, which is the only flow store that had them.
        $table->addColumn('nodes', Types::JSON, ['notnull' => false, 'default' => null]);
        $table->addColumn('edges', Types::JSON, ['notnull' => false, 'default' => null]);
        $table->addColumn('limits', Types::JSON, ['notnull' => false, 'default' => null]);

        // Per-flow retention override, in days. NULL means "follow the admin
        // setting", and must stay NULL rather than being materialised to the
        // current default — otherwise a later change to that default would
        // silently skip every flow created before it.
        $table->addColumn('retention_days', Types::INTEGER, ['notnull' => false, 'default' => null]);

        // Per-hop auditing and the oversight gate: engine-wide behaviour, but
        // optional. Both follow the retention convention — NULL means "inherit
        // the administrator setting", so changing the instance default moves
        // every flow that has not deliberately opted out of it.
        //
        // They are separate flags because they trade off differently. Auditing
        // is write volume (one AuditTrail entry per node, per run) and is
        // therefore off by default. Oversight is a safety rail — it is what
        // stops a running flow when the kill switch is thrown — and is
        // therefore on by default.
        $table->addColumn('audit_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => null]);
        $table->addColumn('oversight_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => null]);

        // A trigger fires with no acting user, so `owner` is the identity a run
        // is attributed to and executes as. A flow with no owner MUST NOT
        // dispatch — never defaulted to an empty, system or admin owner.
        $table->addColumn('owner', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);

        $table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('created', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('updated', Types::DATETIME, ['notnull' => false, 'default' => null]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'or_flow_uuid_idx');

        // The index surface: one app's flows, newest first.
        $table->addIndex(['app', 'id'], 'or_flow_app_idx');

        // The trigger matcher's hot read: enabled flows for one event. `enabled`
        // leads because a disabled flow must never be considered.
        $table->addIndex(['enabled', 'trigger'], 'or_flow_trigger_idx');

        // Every query is organisation-scoped.
        $table->addIndex(['organisation'], 'or_flow_org_idx');

        $output->info(message: 'Created openregister_flows table');

        return $schema;

    }//end changeSchema()
}//end class
