<?php

/**
 * Flow attribution columns on the audit trail.
 *
 * A flow run recorded exactly one object — the one that TRIGGERED it — so
 * everything a run went on to touch was attributable to nothing. These three
 * columns are what let an audit row name the run, node and step that caused it,
 * and therefore what lets a run report the objects it changed.
 *
 * `flow_run` is a plain stamp and deliberately NOT a foreign key. Flow-run
 * retention prunes runs, and an audit row is immutable — a constraint here
 * would either block a lawful prune or reach into rows that must never change.
 * A stamp naming a run that no longer exists stays readable as history.
 *
 * Schema only. The chain re-seal that the new canonical form requires lives in
 * {@see \OCA\OpenRegister\Migration\RechainAuditTrailForFlowAttribution}, a
 * repair step, because it verifies and rewrites rows rather than shaping the
 * table — and because it must run AFTER these columns exist.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add flow_run / flow_node / flow_step to openregister_audit_trails.
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */
class Version1Date20260828120000 extends SimpleMigrationStep {
	/**
	 * Add the attribution columns and the run index.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The modified schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_audit_trails') === false) {
			return null;
		}

		$table = $schema->getTable('openregister_audit_trails');
		$changed = false;

		// Every column is added independently and guarded on its own presence.
		// A single "if the first column is missing, add all three" guard is the
		// shape that leaves a half-migrated table permanently half-migrated
		// after one interrupted upgrade.
		if ($table->hasColumn('flow_run') === false) {
			$table->addColumn('flow_run', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$changed = true;
		}

		if ($table->hasColumn('flow_node') === false) {
			$table->addColumn('flow_node', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		if ($table->hasColumn('flow_step') === false) {
			$table->addColumn('flow_step', Types::INTEGER, [
				'notnull' => false,
			]);
			$changed = true;
		}

		// The run direction ("what did this run touch") is the one that needs
		// help; the object direction is already served by the existing
		// object_uuid index. No composite until a query asks for one — a
		// speculative index on this table is a write cost on every audited
		// mutation forever (ADR-009).
		if ($table->hasIndex('idx_audit_flow_run') === false) {
			$table->addIndex(['flow_run'], 'idx_audit_flow_run');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		$output->info('Added flow_run / flow_node / flow_step and idx_audit_flow_run to openregister_audit_trails');

		return $schema;
	}//end changeSchema()
}//end class
