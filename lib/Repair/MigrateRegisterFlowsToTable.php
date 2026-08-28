<?php

/**
 * MigrateRegisterFlowsToTable — one store for a flow, not two.
 *
 * OpenRegister kept TWO stores for "a flow": the `openregister_flows` table
 * behind `/api/flows`, and objects in the `flows` register. Every subsystem
 * reads the table; nothing reads the register.
 *
 * 🔴 MEASURED WITH CONTROLS, not inferred. The same flow definition, byte for
 * byte, into each store:
 *
 *   POST /api/flows          then one FlowScheduleWorker tick  -> 1 run queued
 *   POST /api/objects/18/184 then one FlowScheduleWorker tick  -> 0 runs
 *
 *   POST /api/federated-config/bundle {flowIds:[table-backed]} -> 1 flow
 *   POST /api/federated-config/bundle {flowIds:[register-one]} -> 0 flows
 *
 * A schedule authored in the register never fires and never bundles, with no
 * error anywhere — the run history is simply empty, which reads as a broken
 * scheduler rather than a flow nothing ever saw.
 *
 * AND THE DESCRIPTOR PROMISED OTHERWISE. `lib/Settings/flow_register.json`
 * described that register as "the store the resolver reads by default — so
 * triggers, sub-flows and the /test endpoint all work with a flow authored
 * here", naming an `OpenRegisterFlowResolver`. That class does not exist: it
 * appears nowhere in this repository except inside that sentence and the
 * docblock of the step that imports it. A reader following the documentation
 * lands in the store nothing reads.
 *
 * WHAT THIS STEP DOES
 *
 * Copies every flow object in the `flows` register into `openregister_flows`,
 * so the definitions people authored where they were told to keep working.
 *
 * 🔴 NON-DESTRUCTIVE AND IDEMPOTENT, deliberately:
 *   - a flow whose uuid is already in the table is SKIPPED, so a re-run is a
 *     no-op and a hand-migrated flow is never duplicated;
 *   - the register objects are LEFT IN PLACE. Deleting them would make this
 *     irreversible on a step that runs unattended at upgrade, and the register
 *     rows cost nothing beyond disk. Retiring them is a separate, deliberate
 *     act once the table copy has been seen to work.
 *
 * 🔴 AND A MIGRATED FLOW ARRIVES DISABLED. It has not run in this store before,
 * and a schedule that silently starts firing during an upgrade — against data
 * nobody re-checked — is a worse outcome than one an administrator switches on.
 * `Flow::canDispatch()` requires `enabled === true` AND an owner, so this is
 * enforced by the entity, not just by intent.
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

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies register-authored flows into the table every subsystem reads.
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class MigrateRegisterFlowsToTable implements IRepairStep {
	/**
	 * The register slug flows were authored in.
	 *
	 * @var string
	 */
	private const FLOW_REGISTER = 'flows';

	/**
	 * The schema slug within it.
	 *
	 * @var string
	 */
	private const FLOW_SCHEMA = 'flow';

	/**
	 * Fields copied verbatim from the register object onto the Flow entity.
	 *
	 * Ownership is handled separately — see run(). `uuid` is preserved on
	 * purpose so a sub-flow reference or a run row still resolves.
	 *
	 * @var array<int, string>
	 */
	private const COPIED = [
		'name',
		'description',
		'trigger',
		'triggerRegister',
		'triggerSchema',
		'cron',
		'executionMode',
		'nodes',
		'edges',
		'limits',
		'annotations',
	];

	/**
	 * Constructor.
	 *
	 * @param FlowMapper      $flowMapper    Writes into the canonical flow store.
	 * @param ObjectService   $objectService Reads the register-authored flows.
	 * @param LoggerInterface $logger        Records what moved and what did not.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FlowMapper $flowMapper,
		private readonly ObjectService $objectService,
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
		return 'Migrate register-authored flows into the flow table (one store per flow)';
	}//end getName()

	/**
	 * Copy every register-authored flow into `openregister_flows`.
	 *
	 * Never throws: a failure logs and leaves the instance healthy, matching the
	 * sibling steps. The visibility cost is paid by the summary line below, which
	 * states the counts rather than only succeeding quietly.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$objects = $this->registerFlows();
		} catch (Throwable $e) {
			// A register that does not exist is the ordinary case on an instance
			// that never authored a flow there. Not a failure.
			$this->logger->debug('[MigrateRegisterFlowsToTable] no flows register to read: ' . $e->getMessage());
			$output->info('No `flows` register on this instance — nothing to migrate.');
			return;
		}

		$moved = 0;
		$already = 0;
		$failed = 0;

		foreach ($objects as $object) {
			$row  = $this->normalise(row: $object);
			$uuid = $row['uuid'];
			if ($uuid === '') {
				$failed++;
				continue;
			}

			try {
				$this->flowMapper->findByUuid($uuid);
				$already++;
				continue;
			} catch (DoesNotExistException $e) {
				// Not in the table yet — this is the one we move.
				$already += 0;
			} catch (Throwable $e) {
				$this->logger->warning('[MigrateRegisterFlowsToTable] lookup failed for ' . $uuid . ': ' . $e->getMessage());
				$failed++;
				continue;
			}

			try {
				$this->flowMapper->insert($this->toFlow(row: $row));
				$moved++;
			} catch (Throwable $e) {
				$this->logger->warning('[MigrateRegisterFlowsToTable] could not migrate ' . $uuid . ': ' . $e->getMessage());
				$failed++;
			}
		}

		// 🔴 THE COUNTS, ALWAYS. "Migrated successfully" with no numbers cannot
		// be told apart from a step that read an empty list, which is exactly how
		// the defect this repairs stayed invisible.
		$output->info(
			sprintf(
				'Register-authored flows: %d migrated, %d already in the table, %d failed (register rows left in place).',
				$moved,
				$already,
				$failed
			)
		);
	}//end run()

	/**
	 * Every object in the flows register.
	 *
	 * @return array<int, mixed> The rows, each an ObjectEntity (see normalise()).
	 */
	private function registerFlows(): array {
		$result = $this->objectService->findAll(
			[
				'register' => self::FLOW_REGISTER,
				'schema'   => self::FLOW_SCHEMA,
				'limit'    => 1000,
			]
		);

		// No `is_array($result)` guard: `findAll()` is typed to return an array,
		// so the check could never fire and phpstan says so. The guard below is
		// NOT redundant — `results` may be absent or hold something else.
		$rows = ($result['results'] ?? $result);
		if (is_array($rows) === false) {
			return [];
		}

		return $rows;
	}//end registerFlows()

	/**
	 * Reduce one row from `findAll()` to the four things this step needs.
	 *
	 * 🔴 `ObjectService::findAll()` RETURNS `ObjectEntity` OBJECTS, NOT ARRAYS.
	 * This step read them as arrays, and PHP does not forgive that: enabling
	 * the app died with "Cannot use object of type ObjectEntity as array",
	 * which is a fatal during `occ app:enable` — the app will not install.
	 *
	 * The unit tests did not catch it because they mocked `findAll()` to return
	 * the array shape the code expected. A fake built from the caller's
	 * assumption validates the assumption, so the tests stayed green over a
	 * step that could not run once. Only CI, which actually enables the app,
	 * disagreed. `testItReadsRealObjectEntitiesNotArrays()` now uses a real
	 * `ObjectEntity` so the test can fail the way production did.
	 *
	 * Both shapes are accepted: the entity is what this caller gets, and the
	 * array form keeps the step correct if a future read path returns
	 * serialised objects instead.
	 *
	 * @param mixed $row One element of the findAll() result.
	 *
	 * @return array{uuid: string, data: array<string, mixed>, owner: ?string, organisation: ?string}
	 */
	private function normalise(mixed $row): array {
		if ($row instanceof ObjectEntity) {
			return [
				'uuid'         => (string)($row->getUuid() ?? ''),
				'data'         => $row->getObject(),
				'owner'        => $row->getOwner(),
				'organisation' => $row->getOrganisation(),
			];
		}

		if (is_array($row) === false) {
			return [
				'uuid'         => '',
				'data'         => [],
				'owner'        => null,
				'organisation' => null,
			];
		}

		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return [
			'uuid'         => (string)(($self['id'] ?? $row['id'] ?? '')),
			'data'         => $row,
			'owner'        => ($self['owner'] ?? $row['owner'] ?? null),
			'organisation' => ($self['organisation'] ?? $row['organisation'] ?? null),
		];
	}//end normalise()

	/**
	 * Map a normalised register object onto a Flow entity.
	 *
	 * @param array{uuid: string, data: array<string, mixed>, owner: ?string, organisation: ?string} $row The normalised row.
	 *
	 * @return Flow The entity to insert.
	 */
	private function toFlow(array $row): Flow {
		$object = $row['data'];

		$flow = new Flow();
		$flow->setUuid($row['uuid']);
		$flow->setApp('openregister');
		$flow->setCreated(new DateTime());
		$flow->setUpdated(new DateTime());

		foreach (self::COPIED as $field) {
			if (array_key_exists($field, $object) === false) {
				continue;
			}

			// 🔴 NO method_exists() GUARD. `Flow` inherits Nextcloud's `Entity`,
			// whose setters are MAGIC — `__call` dispatches `setName()` without a
			// declared method — so `method_exists()` is false for every one of
			// them and the guard skipped the entire copy. The migration ran, said
			// "1 migrated", and wrote a flow with no name, no trigger and no
			// nodes. Caught by the test asserting the name came across; the
			// summary line alone said success.
			//
			// The fields in COPIED are all real, so a direct call is safe: an
			// unknown one would raise rather than pass silently.
			$flow->{'set' . ucfirst($field)}($object[$field]);
		}

		// Ownership carries over from the register object, which OpenRegister
		// stamps on every write. A flow with no organisation is invisible to
		// every scoped read — the defect fixed in #2915 — so a migration that
		// dropped it would move the flow into the right store and out of sight.
		$flow->setOwner($row['owner']);
		$flow->setOrganisation($row['organisation']);

		// Disabled on arrival — see the class docblock.
		$flow->setEnabled(false);

		return $flow;
	}//end toFlow()
}//end class
