<?php

/**
 * Pinned flow definitions, and the run column that points at one.
 *
 * A suspended run resumed against the LIVE definition: `FlowRunAdvancer`
 * re-resolved the flow by id on every pass, so a case parked for a week on a
 * human step came back to whatever the graph had become. ADR-098 Decision 6
 * calls that the programme's highest-risk defect and forbids shipping human
 * task nodes without it — dossiq already ships two.
 *
 * 🔑 THE DEFINITION IS HASH-ADDRESSED, NOT COPIED PER RUN. A flow definition
 * measured 4.3 KB (dossiq's 18-node case flow); a copy on every run of a
 * busy object-created trigger is megabytes of identical JSON. Keying on the
 * canonical hash means an unedited flow stores ONE row no matter how many
 * runs it backs, and an edit stores exactly one more.
 *
 * `flow_uuid` is a plain stamp, not a foreign key, for the same reason
 * `flow_run` is one on the audit trail: deleting a flow must not be blocked
 * by, nor cascade into, the definitions that in-flight runs still need. A
 * definition outliving its flow is the point — that is what lets a run
 * finish against a graph its author has since removed.
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
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the definition and version stores, and the columns that point at them.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class Version1Date20260829090000 extends SimpleMigrationStep {
	/**
	 * Create the definition store and point runs at it.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The modified schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		// 🔑 EACH CALL FIRST, THEN THE OR. Written as `$changed || $this->x()`
		// the short-circuit would SKIP the later steps as soon as one reported
		// a change — creating the definition store and silently never adding
		// the columns that point at it.
		$definitions = $this->definitionStore(schema: $schema);
		$versions = $this->versionStore(schema: $schema);
		$columns = $this->pinColumns(schema: $schema);

		$changed = ($definitions === true || $versions === true || $columns === true);

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Create the content store: one row per distinct graph, keyed by its hash.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return boolean Whether anything changed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function definitionStore(ISchemaWrapper $schema): bool {
		$changed = false;

	if ($schema->hasTable('openregister_flow_defs') === false) {
				$table = $schema->createTable('openregister_flow_defs');

				$table->addColumn('id', Types::BIGINT, [
					'autoincrement' => true,
					'notnull' => true,
					'length' => 20,
				]);

				// 64 hex characters: sha256 over the canonical definition. The
				// hash IS the identity — two flows that happen to hold the same
				// graph share one row, which is correct and costs nothing.
				$table->addColumn('hash', Types::STRING, [
					'notnull' => true,
					'length' => 64,
				]);

				$table->addColumn('flow_uuid', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);

				// TEXT, not JSON: this column is only ever read back whole and
				// decoded in PHP. Nothing queries inside it, so a JSON type would
				// buy indexing nobody uses and cost portability across the three
				// databases the fleet supports.
				$table->addColumn('definition', Types::TEXT, [
					'notnull' => true,
				]);

				$table->addColumn('created', Types::DATETIME, [
					'notnull' => false,
				]);

				$table->setPrimaryKey(['id']);

				// UNIQUE is what makes the dedupe real rather than hopeful: two
				// workers pinning the same unedited flow concurrently race, and
				// the constraint is what turns that race into a caught duplicate
				// instead of two rows.
				$table->addUniqueIndex(['hash'], 'idx_flow_defs_hash');
				$table->addIndex(['flow_uuid'], 'idx_flow_defs_flow');

				$changed = true;
			}//end if
		return $changed;

	}//end definitionStore()

	/**
	 * Create the version rows that NAME those graphs.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return boolean Whether anything changed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function versionStore(ISchemaWrapper $schema): bool {
		$changed = false;

	if ($schema->hasTable('openregister_flow_versions') === false) {
				$versions = $schema->createTable('openregister_flow_versions');

				$versions->addColumn('id', Types::BIGINT, [
					'autoincrement' => true,
					'notnull' => true,
					'length' => 20,
				]);

				$versions->addColumn('flow_uuid', Types::STRING, [
					'notnull' => true,
					'length' => 64,
				]);

				$versions->addColumn('version', Types::INTEGER, [
					'notnull' => true,
					'default' => 1,
				]);

				$versions->addColumn('status', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'draft',
				]);

				// 🔑 THE GRAPH IS NOT COPIED HERE. A version row POINTS AT a row in
				// `openregister_flow_defs`, which is keyed by the canonical hash of
				// the graph. Publishing an unchanged graph therefore stores one
				// short row, not a second 4.3 KB copy of the same 18 nodes; and two
				// versions that happen to hold identical graphs share the content.
				//
				// It also makes immutability structural rather than promised: the
				// hash addresses the content, so a version cannot be edited in
				// place without becoming a different hash — which is a different
				// row, which the unique index would reject.
				$versions->addColumn('definition_hash', Types::STRING, [
					'notnull' => true,
					'length' => 64,
				]);

				// Copied onto the version rather than read through the flow. The
				// flow's owner can change after a version is published, and a run
				// pinned to that version must keep answering the question "who did
				// this belong to when it was published".
				$versions->addColumn('owner', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);

				$versions->addColumn('organisation', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);

				$versions->addColumn('published_at', Types::DATETIME, [
					'notnull' => false,
				]);

				$versions->addColumn('published_by', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);

				$versions->addColumn('deprecated_at', Types::DATETIME, [
					'notnull' => false,
				]);

				$versions->addColumn('created', Types::DATETIME, [
					'notnull' => false,
				]);

				$versions->setPrimaryKey(['id']);

				// UNIQUE(flow, version) is the constraint that makes "version 3 of
				// this flow" a name rather than a hope: two concurrent publishes
				// cannot both claim N+1.
				$versions->addUniqueIndex(['flow_uuid', 'version'], 'or_flowver_uniq');

				// The hot read is "the published version of this flow", on every
				// dispatch. Indexed on (flow, status) so it is one index seek and
				// does not scan a flow's whole history.
				$versions->addIndex(['flow_uuid', 'status'], 'or_flowver_status');

				$changed = true;
			}//end if
		return $changed;

	}//end versionStore()

	/**
	 * Add the columns that point at a version: the flow head, and the run pin.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return boolean Whether anything changed.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function pinColumns(ISchemaWrapper $schema): bool {
		$changed = false;

	if ($schema->hasTable('openregister_flows') === true) {
				$flows = $schema->getTable('openregister_flows');

				if ($flows->hasColumn('version') === false) {
					$flows->addColumn('version', Types::INTEGER, [
						'notnull' => true,
						'default' => 1,
					]);
					$changed = true;
				}

				// 🔴 DELIBERATELY NOT `status`. `openregister_flows` already has a
				// `status` column, and it means the last RUN's outcome. Reusing it
				// for the lifecycle would make "this flow is failed" and "this flow
				// is a draft" the same field. They answer different questions and
				// change at different times.
				if ($flows->hasColumn('lifecycle_status') === false) {
					$flows->addColumn('lifecycle_status', Types::STRING, [
						'notnull' => true,
						'length' => 16,
						'default' => 'draft',
					]);
					$changed = true;
				}
			}//end if

			if ($schema->hasTable('openregister_flow_runs') === true) {
				$runs = $schema->getTable('openregister_flow_runs');

				// Nullable, and it must stay nullable at the SCHEMA level: the
				// column has to exist before the repair step that fills it can run.
				// The repair step then pins every non-terminal run, so no run that
				// can still move is left without one — but a terminated run from
				// before the upgrade keeps its null, because inventing a version
				// for history would be a lie about what it executed.
				if ($runs->hasColumn('flow_version') === false) {
					$runs->addColumn('flow_version', Types::INTEGER, [
						'notnull' => false,
					]);
					$changed = true;
				}
			}
		return $changed;

	}//end pinColumns()
}//end class
