<?php

/**
 * Migration adding `run_as` to `oc_openregister_flow_runs`, and backfilling it.
 *
 * Part of the `or-delegated-identity` change (ADR-099). A run has been answering
 * two questions with one column: `triggered_by` records WHO CAUSED the run
 * (provenance, immutable) and was simultaneously being read to decide WHOSE
 * RIGHTS its steps execute with (authorization, re-evaluated at every resume).
 * Those have different lifetimes and, for a scheduled run, different answers —
 * the cause is a schedule and the acting identity is a person.
 *
 * `run_as` becomes the sole authorization subject. `triggered_by` keeps its
 * meaning and is never read for access again.
 *
 * Data-preserving and idempotent. The backfill sets `run_as = triggered_by` for
 * every existing row, which reproduces today's behaviour exactly: the value the
 * nodes were already authorizing against becomes the value they will authorize
 * against. No run changes what it may do as a result of this migration.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `run_as` to the flow-runs table and backfills it from `triggered_by`.
 *
 * @spec openspec/specs/delegated-identity/spec.md
 */
class Version1Date20260824120000 extends SimpleMigrationStep {

	/**
	 * The table being altered.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_flow_runs';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection Used for the post-schema backfill.
	 */
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Add the `run_as` column.
	 *
	 * Nullable at the schema level even though a queued run must always carry a
	 * value. The invariant is enforced where it can produce a useful message —
	 * `FlowRunService::queue()` refuses an unattributable dispatch by name — and
	 * a NOT NULL constraint here would instead fail the migration itself on any
	 * legacy row the backfill could not resolve, turning a data question into an
	 * upgrade outage.
	 *
	 * @param IOutput $output The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The altered schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $options is part of the base signature.
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/* @var ISchemaWrapper $schema The schema wrapper. */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			$output->info('[or-delegated-identity] ' . self::TABLE . ' does not exist; nothing to alter.');
			return null;
		}

		$table = $schema->getTable(self::TABLE);

		if ($table->hasColumn('run_as') === true) {
			return null;
		}

		$table->addColumn('run_as', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'default' => null,
		]);

		return $schema;
	}//end changeSchema()

	/**
	 * Backfill `run_as` from `triggered_by`.
	 *
	 * Runs after the schema change so the column exists. Only rows where
	 * `run_as` is still null are touched, which makes a re-run a no-op rather
	 * than an overwrite — a repeated migration must not clobber an identity a
	 * later resume already re-resolved.
	 *
	 * Rows whose `triggered_by` is also null are left null and REPORTED. They
	 * cannot be resolved here: there is no correct identity to invent, and
	 * guessing one would be the exact defect this change exists to remove. Such
	 * a run fails closed the next time it is touched, which is the intended
	 * outcome.
	 *
	 * @param IOutput $output The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array $options Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $schemaClosure and $options are part of the base signature.
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$table = $this->connection->getPrefix() . self::TABLE;

		// Raw SQL, not the query builder. `set()` would need the second argument
		// wrapped to be read as a COLUMN rather than a bound literal, and the
		// silent-failure mode of getting that wrong is that every row's run_as
		// becomes the string "triggered_by" — which would then be an
		// unresolvable identity on every existing run. The neighbouring
		// migration (Version1Date20260818230000) records the same lesson from
		// the other direction: a builder mutation there no-opped through a
		// successful, error-free run.
		$backfilled = $this->connection->executeStatement(
			'UPDATE `*PREFIX*' . self::TABLE . '` SET `run_as` = `triggered_by` '
			. 'WHERE `run_as` IS NULL AND `triggered_by` IS NOT NULL'
		);

		$output->info(
			sprintf('[or-delegated-identity] backfilled run_as on %d flow run(s) from triggered_by.', $backfilled)
		);

		// Report what could not be resolved rather than leaving it silent. A
		// count of zero here is the expected result and is worth printing: it is
		// the difference between "no unattributed runs" and "the query never
		// ran".
		$result = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM `*PREFIX*' . self::TABLE . '` WHERE `run_as` IS NULL'
		);
		$unresolved = (int)$result->fetchOne();
		$result->closeCursor();

		$this->declareScheduleIdentities(output: $output);

		if ($unresolved === 0) {
			$output->info('[or-delegated-identity] every flow run carries an authorization subject.');
			return;
		}

		$output->warning(
			sprintf(
				'[or-delegated-identity] %d flow run(s) in %s carry neither triggered_by nor run_as. '
				. 'They are left unattributed deliberately — no identity can be inferred for them — and will '
				. 'fail closed if resumed. Inspect them before re-enabling anything that resumes old runs.',
				$unresolved,
				$table
			)
		);
	}//end postSchemaChange()

	/**
	 * Declare, on each existing schedule trigger, the identity it already runs as.
	 *
	 * 🔴 THIS IS A CUTOVER, NOT A FALLBACK, AND THE DIFFERENCE IS THE POINT.
	 *
	 * Until now a scheduled run took its identity from `flow.owner`, resolved
	 * implicitly at fire time. ADR-099 removes that, because authoring a flow is
	 * not consent to unattended execution as its author. But removing it without
	 * writing down what it was resolving to would stop every existing scheduled
	 * flow dead: measured on this instance, 3 flows carry a schedule trigger and
	 * none declares an identity, yet they account for 2968 of 4045 runs — they
	 * are Hydra's own dispatcher, sequencer and lock reaper, firing constantly.
	 *
	 * So this writes the CURRENT behaviour into the node as an explicit
	 * declaration: `runAs` becomes the flow's owner, once, recorded, visible in
	 * the editor and changeable there. After this migration nothing resolves an
	 * identity implicitly — the fallback is gone, and a NEW schedule trigger must
	 * declare one or fail to save.
	 *
	 * The distinction matters because the two are easy to confuse: a fallback
	 * keeps answering forever for flows nobody has looked at, while a one-time
	 * cutover makes a previously invisible decision auditable and leaves it where
	 * a human can see and revoke it.
	 *
	 * Flows whose owner is itself null are left undeclared and reported. There is
	 * no identity to promote, and inventing one is the defect this change exists
	 * to remove.
	 *
	 * @param IOutput $output The migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function declareScheduleIdentities(IOutput $output): void {
		$result = $this->connection->executeQuery(
			'SELECT `id`, `uuid`, `owner`, `nodes` FROM `*PREFIX*openregister_flows` '
			. 'WHERE `nodes` IS NOT NULL'
		);

		$declared = 0;
		$undeclarable = [];

		foreach ($result->fetchAll() as $row) {
			$nodes = json_decode((string)$row['nodes'], true);
			if (is_array($nodes) === false) {
				continue;
			}

			$owner = trim((string)($row['owner'] ?? ''));
			$changed = false;

			foreach ($nodes as $index => $node) {
				if (is_array($node) === false
					|| ($node['type'] ?? null) !== 'openregister.trigger-schedule'
				) {
					continue;
				}

				$config = ($node['config'] ?? []);
				// A mid-cutover node stores `[]`, which json_decode gives back as
				// an empty LIST, not an empty map. Writing a key into it would
				// otherwise produce `{"0":…}`-shaped nonsense on re-encode.
				if (is_array($config) === false) {
					$config = [];
				}

				if (trim((string)($config['runAs'] ?? '')) !== '') {
					continue;
				}

				if ($owner === '') {
					$undeclarable[] = (string)$row['uuid'];
					continue;
				}

				$config['runAs'] = $owner;
				$nodes[$index]['config'] = $config;
				$changed = true;
			}

			if ($changed === false) {
				continue;
			}

			$this->connection->executeStatement(
				'UPDATE `*PREFIX*openregister_flows` SET `nodes` = ? WHERE `id` = ?',
				[json_encode($nodes), $row['id']]
			);
			$declared++;
		}

		$result->closeCursor();

		$output->info(
			sprintf(
				'[or-delegated-identity] declared runAs on the schedule trigger of %d flow(s), '
				. 'preserving the identity they already ran as.',
				$declared
			)
		);

		if ($undeclarable === []) {
			return;
		}

		$output->warning(
			sprintf(
				'[or-delegated-identity] %d flow(s) carry a schedule trigger but no owner to promote, so their '
				. 'trigger still declares no identity and they will refuse to fire: %s. '
				. 'Open each in the flow editor and name the user it should run as.',
				count($undeclarable),
				implode(', ', $undeclarable)
			)
		);
	}//end declareScheduleIdentities()
}//end class
