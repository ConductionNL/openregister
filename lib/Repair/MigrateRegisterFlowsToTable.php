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
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies register-authored flows into the table every subsystem reads.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
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
	 * The normalised form of a row that is neither an entity nor an array: no
	 * uuid, so it is counted as a failure.
	 *
	 * @var array{uuid: string, data: array<string, mixed>, owner: ?string, organisation: ?string}
	 */
	private const UNREADABLE_ROW = [
		'uuid'         => '',
		'data'         => [],
		'owner'        => null,
		'organisation' => null,
	];

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface      $flowMapper    Writes into the canonical flow store.
	 * @param ObjectOperationsInterface   $objectService Reads the register-authored flows.
	 * @param LoggerInterface $logger        Records what moved and what did not.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FlowMapperInterface $flowMapper,
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
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
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function run(IOutput $output): void {
		$objects = $this->readRegisterFlows(output: $output);
		if ($objects === null) {
			return;
		}

		$tally = new MigrateRegisterFlowsRun();
		foreach ($objects as $object) {
			$this->migrateOne(object: $object, tally: $tally);
		}

		$this->writeSummary(output: $output, tally: $tally);
	}//end run()

	/**
	 * Print what the run did, with every count.
	 *
	 * @param IOutput                 $output Output interface for status messages.
	 * @param MigrateRegisterFlowsRun $tally  The run's counts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function writeSummary(IOutput $output, MigrateRegisterFlowsRun $tally): void {
		// 🔴 THE COUNTS, ALWAYS. "Migrated successfully" with no numbers cannot
		// be told apart from a step that read an empty list, which is exactly how
		// the defect this repairs stayed invisible.
		$output->info(
			sprintf(
				'Register-authored flows: %d migrated, %d already in the table, %d not a flow definition, %d failed (register rows left in place).',
				$tally->moved,
				$tally->already,
				$tally->notFlows,
				$tally->failed
			)
		);
	}//end writeSummary()

	/**
	 * Every object in the flows register, or null when there is no such register.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return array<int, mixed>|null The rows, or null after saying there is nothing to migrate.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function readRegisterFlows(IOutput $output): ?array {
		try {
			return $this->registerFlows();
		} catch (Throwable $e) {
			// A register that does not exist is the ordinary case on an instance
			// that never authored a flow there. Not a failure.
			$this->logger->debug('[MigrateRegisterFlowsToTable] no flows register to read: ' . $e->getMessage());
			$output->info('No `flows` register on this instance — nothing to migrate.');
			return null;
		}
	}//end readRegisterFlows()

	/**
	 * Move one register object into the table, counting what happened to it.
	 *
	 * @param mixed                   $object One row from the flows register.
	 * @param MigrateRegisterFlowsRun $tally  The run's counts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function migrateOne(mixed $object, MigrateRegisterFlowsRun $tally): void {
		$row = $this->normalise(row: $object);
		if ($this->acceptsAsFlow(row: $row, tally: $tally) === false) {
			return;
		}

		if ($this->isNotInTable(uuid: $row['uuid'], tally: $tally) === false) {
			return;
		}

		$this->insertFlow(row: $row, tally: $tally);
	}//end migrateOne()

	/**
	 * Insert the flow, counting it as moved or failed.
	 *
	 * @param array{uuid: string, data: array<string, mixed>, owner: ?string, organisation: ?string} $row   The normalised row.
	 * @param MigrateRegisterFlowsRun                                                               $tally The run's counts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function insertFlow(array $row, MigrateRegisterFlowsRun $tally): void {
		try {
			$this->flowMapper->insert($this->toFlow(row: $row));
			$tally->moved++;
		} catch (Throwable $e) {
			$this->logger->warning('[MigrateRegisterFlowsToTable] could not migrate ' . $row['uuid'] . ': ' . $e->getMessage());
			$tally->failed++;
		}
	}//end insertFlow()

	/**
	 * Whether the row has a uuid and is a flow definition; counts and logs it when not.
	 *
	 * @param array{uuid: string, data: array<string, mixed>, owner: ?string, organisation: ?string} $row   The normalised row.
	 * @param MigrateRegisterFlowsRun                                                               $tally The run's counts.
	 *
	 * @return boolean True when the row may be moved.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function acceptsAsFlow(array $row, MigrateRegisterFlowsRun $tally): bool {
		if ($row['uuid'] === '') {
			$tally->failed++;
			return false;
		}

		// The second, independent guard — see isFlowDefinition().
		if ($this->isFlowDefinition(object: $row['data']) === true) {
			return true;
		}

		$tally->notFlows++;
		$this->logger->warning(
			'[MigrateRegisterFlowsToTable] refused ' . $row['uuid']
			. ': no nodes, no edges and no trigger, so it is not a flow definition'
		);
		return false;
	}//end acceptsAsFlow()

	/**
	 * Whether the flow is not in the table yet; counts it as already present, or as failed when the lookup fails.
	 *
	 * @param string                  $uuid  The flow uuid.
	 * @param MigrateRegisterFlowsRun $tally The run's counts.
	 *
	 * @return boolean True when this is one to move.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function isNotInTable(string $uuid, MigrateRegisterFlowsRun $tally): bool {
		try {
			$this->flowMapper->findByUuid($uuid);
			$tally->already++;
			return false;
		} catch (DoesNotExistException $e) {
			// Not in the table yet — this is the one we move.
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('[MigrateRegisterFlowsToTable] lookup failed for ' . $uuid . ': ' . $e->getMessage());
			$tally->failed++;
			return false;
		}
	}//end isNotInTable()


	/**
	 * Whether this object is a flow definition at all.
	 *
	 * 🔴 BOTH GUARDS, DELIBERATELY. Scoping the read (see registerFlows()) fixes
	 * the wrong POPULATION being selected; this fixes a wrong ROW inside the
	 * selected one being written. They fail independently, which is the point:
	 * the scoping guard depends on shared, mutable service state staying correct
	 * for the whole of an `occ upgrade`, and that state is exactly what broke.
	 * This one depends on nothing but the row in front of it.
	 *
	 * The test is what the flow engine needs to do anything: a graph to walk
	 * (`nodes` or `edges`) or something to start it (`trigger`). A row with none
	 * of the three cannot run, cannot be made to run, and is not a flow — it is
	 * whatever else the read handed us. It is not a completeness check: an empty
	 * graph that already names a trigger is a half-authored flow and crosses.
	 *
	 * @param array<string, mixed> $object The register object's payload.
	 *
	 * @return boolean True when the payload is a flow definition.
	 */
	private function isFlowDefinition(array $object): bool {
		if (empty($object['nodes']) === false) {
			return true;
		}

		if (empty($object['edges']) === false) {
			return true;
		}

		$trigger = ($object['trigger'] ?? null);

		return is_string($trigger) === true && trim($trigger) !== '';
	}//end isFlowDefinition()

	/**
	 * Every object in the flows register.
	 *
	 * @return array<int, mixed> The rows, each an ObjectEntity (see normalise()).
	 */
	private function registerFlows(): array {
		// 🔴 `register` / `schema` GO UNDER `filters`, AND NOWHERE ELSE.
		//
		// This step used to pass them at the top level of the config, which
		// reads correctly and does nothing: `ObjectService::prepareFindAllConfig()`
		// inspects `$config['filters']['register']` and `$config['filters']['schema']`
		// and no other key, so `setRegister()` / `setSchema()` were never called
		// and the read ran against whatever `$currentRegister` / `$currentSchema`
		// the SHARED ObjectService instance was still carrying.
		//
		// MEASURED, not inferred. `saveObject()` sets that context and never
		// restores it — `find()` does, in a `finally`; `saveObject()` has no such
		// block. `ImportCredentialBrokerRegister` runs four repair steps ahead of
		// this one and saves `credential_broker_register.json`'s two example
		// objects through it. So this step inherited `credential-broker` /
		// `brokeredcredential` and copied both examples into `openregister_flows`:
		// empty nodes, empty edges, no trigger, `_owner` = `__system__` (which is
		// only what OpenRegister stamps on a sessionless write, not a convention).
		//
		// Every other `findAll()` caller under `lib/` nests the pair. This step
		// was the outlier, and `_rbac` / `_multitenancy` are off because a repair
		// step has no session — `occ` runs as Anonymous, so a scoped read would
		// return nothing at all.
		$result = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::FLOW_REGISTER,
					'schema'   => self::FLOW_SCHEMA,
				],
				'limit'   => 1000,
			],
			_rbac: false,
			_multitenancy: false
		);

		return $this->rowsOf(result: $result);
	}//end registerFlows()

	/**
	 * The list of rows in a findAll() result.
	 *
	 * @param array<string, mixed> $result The findAll() result.
	 *
	 * @return array<int, mixed> The rows, empty when the result holds no list.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function rowsOf(array $result): array {
		// No `is_array($result)` guard: `findAll()` is typed to return an array,
		// so the check could never fire and phpstan says so. The guard below is
		// NOT redundant — `results` may be absent or hold something else.
		$rows = ($result['results'] ?? $result);
		if (is_array($rows) === false) {
			return [];
		}

		return $rows;
	}//end rowsOf()

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
			return self::fromEntity(entity: $row);
		}

		if (is_array($row) === false) {
			return self::UNREADABLE_ROW;
		}

		return self::fromArray(row: $row);
	}//end normalise()

	/**
	 * Reduce an ObjectEntity row.
	 *
	 * @param ObjectEntity $entity The row.
	 *
	 * @return array{uuid: string, data: array<string, mixed>, owner: ?string, organisation: ?string} The normalised row.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private static function fromEntity(ObjectEntity $entity): array {
		return [
			'uuid'         => (string)($entity->getUuid() ?? ''),
			'data'         => $entity->getObject(),
			'owner'        => $entity->getOwner(),
			'organisation' => $entity->getOrganisation(),
		];
	}//end fromEntity()

	/**
	 * Reduce a serialised row, reading `@self` first and the top level second.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array{uuid: string, data: array<string, mixed>, owner: ?string, organisation: ?string} The normalised row.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private static function fromArray(array $row): array {
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
	}//end fromArray()

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

		$this->copyFields(flow: $flow, object: $object);

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

	/**
	 * Copy the COPIED fields the register object carries onto the flow.
	 *
	 * @param Flow                 $flow   The flow being built.
	 * @param array<string, mixed> $object The register object's payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function copyFields(Flow $flow, array $object): void {
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
	}//end copyFields()
}//end class
