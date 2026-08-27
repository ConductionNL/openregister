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
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies register-authored flows into the table every subsystem reads.
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
			$uuid = (string)(($object['@self']['id'] ?? $object['id'] ?? ''));
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
				$this->flowMapper->insert($this->toFlow(uuid: $uuid, object: $object));
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
	 * @return array<int, array<string, mixed>> The flow objects.
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
	 * Map a register object onto a Flow entity.
	 *
	 * @param string               $uuid   The flow's uuid, preserved from the object.
	 * @param array<string, mixed> $object The register object.
	 *
	 * @return Flow The entity to insert.
	 */
	private function toFlow(string $uuid, array $object): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
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
		$self = ($object['@self'] ?? []);
		$flow->setOwner(($self['owner'] ?? $object['owner'] ?? null));
		$flow->setOrganisation(($self['organisation'] ?? $object['organisation'] ?? null));

		// Disabled on arrival — see the class docblock.
		$flow->setEnabled(false);

		return $flow;
	}//end toFlow()
}//end class
