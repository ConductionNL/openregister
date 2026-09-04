<?php

/**
 * PurgePhantomMigratedFlows — removes rows the flow migration should never have written.
 *
 * 🔴 WHAT WENT WRONG, MEASURED.
 *
 * {@see MigrateRegisterFlowsToTable} asked `ObjectService::findAll()` for
 * `['register' => 'flows', 'schema' => 'flow']` at the TOP LEVEL of the config.
 * `prepareFindAllConfig()` reads `$config['filters']['register']` and
 * `$config['filters']['schema']` and no other key, so both were inert: the read
 * was never scoped and ran against whatever `$currentRegister` / `$currentSchema`
 * the SHARED ObjectService instance was still carrying.
 *
 * `saveObject()` sets that context and never restores it — `find()` puts it back
 * in a `finally`, `saveObject()` has no such block — and
 * `ImportCredentialBrokerRegister` runs four repair steps ahead of the migration,
 * saving `credential_broker_register.json`'s two example objects through it. So
 * the migration inherited `credential-broker` / `brokeredcredential` and copied
 * both examples into `openregister_flows`.
 *
 * The rows it left behind have empty nodes, empty edges, no trigger, no trigger
 * schema and `_owner` = `__system__` — which is only what OpenRegister stamps on
 * a sessionless write, not a convention for a shipped flow. They cost twice:
 * they are listed forever as flows that can never run, and they were read as
 * precedent by a later diagnosis that nearly copied `owner=__system__` into
 * another app's shipped flow declarations.
 *
 * 🔴 DELETING ROWS IS IRREVERSIBLE, SO THE PREDICATE IS PROOF, NOT A HEURISTIC.
 *
 * A row is removed only when ALL of the following hold:
 *
 *   1. it is owned by `openregister` — the only `app` value the migration writes;
 *   2. it is disabled;
 *   3. `nodes` is empty AND `edges` is empty — nothing to walk;
 *   4. `trigger`, `triggerRegister`, `triggerSchema` and `cron` are all empty —
 *      nothing that could ever start it;
 *   5. it has never been dispatched: no `lastRunUuid`, no `lastRunAt`, no
 *      `lastRunStatus`, and zero rows in `openregister_flow_runs`;
 *   6. and — the provenance proof — its uuid still resolves to a register object
 *      whose schema is NOT `flow`. That is the defect's signature: the row is a
 *      copy of something that was never a flow.
 *
 * (1)–(5) establish that the row cannot run and never has. (6) establishes that
 * it is this defect's artefact rather than somebody's empty draft. Only the
 * conjunction deletes.
 *
 * 🔴 WHAT IT DELIBERATELY SPARES, and reports instead:
 *
 *   - any flow with one node or one edge, whatever else is missing;
 *   - any flow naming a trigger, trigger register/schema or cron — including a
 *     graph-less draft someone is part-way through authoring;
 *   - any enabled flow, whatever its shape;
 *   - any flow with run history, even a single terminal run;
 *   - any flow owned by another app;
 *   - AND the ambiguous case: an unrunnable shell whose uuid resolves to nothing,
 *     or to a real `flow` object. Its source may have been deleted since, or it
 *     may be a genuine empty draft, and the two are indistinguishable from here.
 *     Reported by uuid, never removed. Erring toward keeping an unrunnable row is
 *     recoverable; erring the other way is not.
 *
 * Idempotent: a second run finds nothing left to match and reports zero.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes non-flow rows the mis-scoped flow migration copied into the flow table.
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class PurgePhantomMigratedFlows implements IRepairStep {
	/**
	 * The only `app` value MigrateRegisterFlowsToTable writes.
	 *
	 * Scoping to it spares every flow another app owns without having to reason
	 * about that app's conventions at all.
	 *
	 * @var string
	 */
	private const MIGRATED_APP = 'openregister';

	/**
	 * The schema slug a real flow definition lives under.
	 *
	 * @var string
	 */
	private const FLOW_SCHEMA = 'flow';

	/**
	 * Page size for the table walk.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param FlowMapper      $flowMapper    Reads and removes rows in the flow table.
	 * @param FlowRunMapper   $flowRunMapper Proves a row has never been dispatched.
	 * @param MagicMapper     $objectMapper  Resolves a uuid back to the object it was copied from.
	 * @param SchemaMapper    $schemaMapper  Turns that object's schema reference into a slug.
	 * @param LoggerInterface $logger        Records every removal and every spared ambiguity.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FlowMapper $flowMapper,
		private readonly FlowRunMapper $flowRunMapper,
		private readonly MagicMapper $objectMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function getName(): string {
		return 'Remove non-flow rows copied into the flow table by the mis-scoped flow migration';
	}//end getName()

	/**
	 * Walk the flow table and remove only what is provably this defect's artefact.
	 *
	 * Never throws: a failure here must not take an upgrade down, and a row left
	 * in place is the safe direction anyway.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function run(IOutput $output): void {
		$removed = 0;
		$reported = [];

		try {
			foreach ($this->migratedFlows() as $flow) {
				if ($this->isUnrunnableShell(flow: $flow) === false) {
					continue;
				}

				$uuid = (string)$flow->getUuid();

				if ($this->hasEverRun(flow: $flow) === true) {
					$reported[] = $uuid . ' (unrunnable, but it has run history)';
					continue;
				}

				$sourceSchema = $this->sourceSchemaSlug(uuid: $uuid);
				if ($sourceSchema === null) {
					$reported[] = $uuid . ' (unrunnable, but no source object to identify it by)';
					continue;
				}

				if ($sourceSchema === self::FLOW_SCHEMA) {
					$reported[] = $uuid . ' (unrunnable, but it really is a flow object — an empty draft)';
					continue;
				}

				$this->flowMapper->delete($flow);
				$removed++;
				$this->logger->warning(
					'[PurgePhantomMigratedFlows] removed ' . $uuid
					. ': an unrunnable, never-dispatched copy of a `' . $sourceSchema . '` object'
				);
			}//end foreach
		} catch (Throwable $e) {
			$this->logger->warning('[PurgePhantomMigratedFlows] aborted: ' . $e->getMessage());
			$output->warning('Phantom-flow purge stopped early: ' . $e->getMessage());
		}//end try

		// 🔴 THE COUNTS AND THE SPARED UUIDS, ALWAYS. A destructive step that
		// says only "done" cannot be told from one that matched nothing, and the
		// reported rows are the ones a human has to look at — naming them here is
		// the whole point of not deleting them.
		$output->info(
			sprintf('Phantom flow rows: %d removed, %d reported for review.', $removed, count($reported))
		);

		foreach ($reported as $line) {
			$output->info('  needs review: ' . $line);
		}
	}//end run()

	/**
	 * Every flow this migration could have written, page by page.
	 *
	 * @return iterable<int, Flow> The candidate rows.
	 */
	private function migratedFlows(): iterable {
		$offset = 0;

		while (true) {
			$page = $this->flowMapper->findAllFlows(
				app: self::MIGRATED_APP,
				limit: self::PAGE,
				offset: $offset
			);

			if (empty($page) === true) {
				return;
			}

			yield from $page;

			if (count($page) < self::PAGE) {
				return;
			}

			$offset += self::PAGE;
		}
	}//end migratedFlows()

	/**
	 * Whether this row has nothing to walk and nothing that could start it.
	 *
	 * Conjuncts (2)–(4) of the predicate in the class docblock. Every one of them
	 * spares something: `enabled` spares a switched-on flow whatever its shape,
	 * the graph pair spares anything with a single node or edge, and the trigger
	 * group spares a graph-less draft that already names how it should start.
	 *
	 * @param Flow $flow The row under test.
	 *
	 * @return boolean True when the row cannot run and cannot be started.
	 */
	private function isUnrunnableShell(Flow $flow): bool {
		if ($flow->getEnabled() === true) {
			return false;
		}

		if (empty($flow->getNodes()) === false || empty($flow->getEdges()) === false) {
			return false;
		}

		$starters = [
			$flow->getTrigger(),
			$flow->getTriggerRegister(),
			$flow->getTriggerSchema(),
			$flow->getCron(),
		];

		foreach ($starters as $starter) {
			if ($starter !== null && trim($starter) !== '') {
				return false;
			}
		}

		return true;
	}//end isUnrunnableShell()

	/**
	 * Whether this flow has ever been dispatched.
	 *
	 * Conjunct (5). Both halves are load-bearing: the `lastRun*` columns are the
	 * flow's own memory of a dispatch, and the run table is the record of one.
	 * A run row can be pruned by retention while the columns survive, and the
	 * columns were only added in a later migration while older run rows remain —
	 * so either alone would miss a flow that has demonstrably run.
	 *
	 * @param Flow $flow The row under test.
	 *
	 * @return boolean True when anything at all says this flow has run.
	 */
	private function hasEverRun(Flow $flow): bool {
		if ($flow->getLastRunUuid() !== null || $flow->getLastRunAt() !== null || $flow->getLastRunStatus() !== null) {
			return true;
		}

		return $this->flowRunMapper->countRunsForFlow((string)$flow->getUuid()) > 0;
	}//end hasEverRun()

	/**
	 * The schema slug of the register object this uuid came from, if any.
	 *
	 * Conjunct (6), the provenance proof. The migration preserves the source
	 * object's uuid and leaves the register rows in place, so the object it was
	 * copied from is still there to be asked. `findMultipleAcrossAllMagicTables()`
	 * is used rather than `ObjectService` on purpose: it takes no register/schema
	 * context, and inheriting a stale one is the bug being repaired.
	 *
	 * @param string $uuid The flow row's uuid.
	 *
	 * @return string|null The source object's schema slug, or null when nothing resolves.
	 */
	private function sourceSchemaSlug(string $uuid): ?string {
		if (trim($uuid) === '') {
			return null;
		}

		try {
			$objects = $this->objectMapper->findMultipleAcrossAllMagicTables(uuids: [$uuid]);
		} catch (Throwable $e) {
			$this->logger->warning('[PurgePhantomMigratedFlows] could not resolve ' . $uuid . ': ' . $e->getMessage());
			return null;
		}

		$object = ($objects[0] ?? null);
		if ($object === null) {
			return null;
		}

		$schemaRef = $object->getSchema();
		if ($schemaRef === null || trim((string)$schemaRef) === '') {
			return null;
		}

		try {
			$schema = $this->schemaMapper->find(id: $schemaRef, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('[PurgePhantomMigratedFlows] could not resolve schema for ' . $uuid . ': ' . $e->getMessage());
			return null;
		}

		$slug = $schema->getSlug();
		if ($slug === null || trim($slug) === '') {
			return null;
		}

		return $slug;
	}//end sourceSchemaSlug()
}//end class
