<?php

/**
 * OpenRegister SharedSchemaDedupeService
 *
 * Detects schema entities that more than one register co-owns, attributes the
 * canonical owner from the referencing registers' own app configuration, and
 * splits the non-canonical ones onto their own schema entity — moving their
 * object rows with it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\SharedSchema\OwnerCandidates;
use OCA\OpenRegister\Service\SharedSchema\SchemaIdSwap;
use OCA\OpenRegister\Service\SharedSchema\SharedSchemaOwnership;
use OCA\OpenRegister\Service\SharedSchema\SharedSchemaStore;
use OCA\OpenRegister\Service\SharedSchema\SplitTableColumns;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Split schema entities that several registers wrongly share.
 *
 * A schema row carries no register column: the relation lives only as a JSON id
 * list on the register. Before the per-register slug-uniqueness fix in
 * {@see ImportHandler}, an import that resolved a slug globally re-used whatever
 * schema row already carried that slug, so two apps could end up pointing at one
 * entity. From then on every import of either app rewrote the definition for
 * both — last import wins, instance-wide.
 *
 * `occ openregister:registers:relink-schemas` ADDS lost linkage; this service is
 * its counterpart, which SPLITS linkage that was never meant to be shared.
 *
 * Attribution is evidence-based rather than heuristic, and lives in
 * {@see SchemaAttribution}. When the evidence does not single one owner out the
 * repair REFUSES and asks for an explicit `--keep`: guessing an owner is what
 * produced the damage in the first place.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
class SharedSchemaDedupeService implements SharedSchemaDedupeServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param SharedSchemaStore     $store     The database connection, the register and schema mappers and the table migrator
	 * @param SharedSchemaOwnership $ownership The import handler, the configuration locator and the attribution rules
	 * @param LoggerInterface       $logger    Audit trail for every mutation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SharedSchemaStore $store,
		private readonly SharedSchemaOwnership $ownership,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Parse the repeatable `--keep` option.
	 *
	 * @param array<int, mixed> $raw The raw option values.
	 *
	 * @return array{perSchema: array<int,int>, global: int|null} The parsed overrides.
	 *
	 * @throws RuntimeException When a value is not a positive id or id pair.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function parseKeep(array $raw): array {
		return $this->ownership->attribution->parseKeep(raw: $raw);
	}//end parseKeep()

	/**
	 * Inspect the instance and produce the full repair plan.
	 *
	 * Reports only — never mutates, so the operator sees every split, every row
	 * move and every column that would be left behind before opting in.
	 *
	 * @param int|null          $registerId Limit to plans involving this register, or null for all.
	 * @param array<string, mixed> $keep    The parsed `--keep` overrides.
	 *
	 * @return array<int, array<string, mixed>> One entry per shared schema.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function inspect(?int $registerId, array $keep): array {
		$run    = new SharedSchemaPlanRun(registers: $this->loadRegisters(), keep: $keep);
		$stored = array_map(fn (Register $register) => $register->getSchemas(), $run->registers);

		$plan = [];
		foreach ($this->ownership->attribution->indexShared(registerSchemas: $stored) as $schemaId => $registerIds) {
			if ($this->involves(registerId: $registerId, registerIds: $registerIds) === false) {
				continue;
			}

			$entry = $this->planSchema(run: $run, use: new SharedSchemaUse(schemaId: $schemaId, registerIds: $registerIds));
			if ($entry !== null) {
				$plan[] = $entry;
			}
		}

		return $plan;
	}//end inspect()

	/**
	 * Whether a shared schema is in scope: no register filter, or the filtered register references it.
	 *
	 * @param int|null $registerId  The register filter, or null for all.
	 * @param int[]    $registerIds The registers referencing the schema.
	 *
	 * @return bool
	 */
	private function involves(?int $registerId, array $registerIds): bool {
		return $registerId === null || in_array($registerId, $registerIds, true) === true;
	}//end involves()

	/**
	 * Execute one planned split.
	 *
	 * Creation of the schema, the relink and the row move are one unit: a
	 * half-applied split leaves a register pointing at an entity whose table holds
	 * no rows, which is worse than the shared state it started from.
	 *
	 * Unmapped columns are moved without them; {@see self::applySplitStrictly()} refuses instead.
	 *
	 * @param array<string, mixed> $entry  One {@see self::inspect()} plan entry.
	 * @param int                  $target The non-canonical register id to split off.
	 *
	 * @return array{newSchemaId: int, rows: int, unmapped: string[], backup: string|null} The outcome.
	 *
	 * @throws RuntimeException When the split is refused or fails; nothing is committed.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function applySplit(array $entry, int $target): array {
		return $this->applyRun(run: new SchemaSplitRun(entry: $entry, target: $target), strict: false);
	}//end applySplit()

	/**
	 * Execute one planned split, refused when any source column has no destination.
	 *
	 * @param array<string, mixed> $entry  One {@see self::inspect()} plan entry.
	 * @param int                  $target The non-canonical register id to split off.
	 *
	 * @return array{newSchemaId: int, rows: int, unmapped: string[], backup: string|null} The outcome.
	 *
	 * @throws RuntimeException When the split is refused or fails; nothing is committed.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function applySplitStrictly(array $entry, int $target): array {
		return $this->applyRun(run: new SchemaSplitRun(entry: $entry, target: $target), strict: true);
	}//end applySplitStrictly()

	/**
	 * Check one split run, then execute it in a single transaction.
	 *
	 * @param SchemaSplitRun $run    The plan entry and the register to split off.
	 * @param bool           $strict Whether unmapped columns must refuse the move.
	 *
	 * @return array{newSchemaId: int, rows: int, unmapped: string[], backup: string|null} The outcome.
	 *
	 * @throws RuntimeException When the split is refused or fails; nothing is committed.
	 */
	private function applyRun(SchemaSplitRun $run, bool $strict): array {
		$split = $this->attributedSplit(run: $run);
		if ($strict === true) {
			$this->assertNothingUnmapped(unmapped: ($split['unmapped'] ?? []));
		}

		$this->store->db->beginTransaction();
		try {
			$outcome = $this->splitLocked(run: $run, split: $split);
			$this->store->db->commit();
		} catch (Throwable $e) {
			$this->store->db->rollBack();
			throw $e;
		}

		return $outcome;
	}//end applyRun()

	/**
	 * The split planned for the target register, refused when it is not in the plan or the owner is unknown.
	 *
	 * @param SchemaSplitRun $run The plan entry and the register to split off.
	 *
	 * @return array<string, mixed> The planned split.
	 *
	 * @throws RuntimeException When the target is not in the plan or the plan has no owner.
	 */
	private function attributedSplit(SchemaSplitRun $run): array {
		$split = ($run->entry['splits'][$run->target] ?? null);
		if (is_array($split) === false) {
			throw new RuntimeException(sprintf('Register %d is not part of this plan.', $run->target));
		}

		if (($run->entry['owner'] ?? null) === null) {
			throw new RuntimeException(
				sprintf('Schema %d is unattributed; pass --keep to name the owner.', $run->oldId())
			);
		}

		return $split;
	}//end attributedSplit()

	/**
	 * Strict mode: refuse a move that would leave source columns without a destination.
	 *
	 * @param string[] $unmapped The source columns with no destination.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When any column is unmapped.
	 */
	private function assertNothingUnmapped(array $unmapped): void {
		if ($unmapped === []) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'Strict mode: %d source column(s) have no destination (%s).',
				count($unmapped),
				implode(', ', $unmapped)
			)
		);
	}//end assertNothingUnmapped()

	/**
	 * Create the replacement schema, relink the register and move its rows.
	 *
	 * @param SchemaSplitRun       $run   The plan entry and the register to split off.
	 * @param array<string, mixed> $split The planned split for that register.
	 *
	 * @return array{newSchemaId: int, rows: int, unmapped: string[], backup: string|null} The outcome.
	 */
	private function splitLocked(SchemaSplitRun $run, array $split): array {
		$run->register = $this->store->registerMapper->find(id: $run->target, _rbac: false, _multitenancy: false);

		$schema     = $this->createReplacementSchema(run: $run, definition: ($split['definition'] ?? null));
		$run->newId = (int)$schema->getId();
		$this->relink(run: $run);

		$moved = $this->store->migrator->migrate(table: new RegisterSchema(register: $run->register, schema: $schema), oldId: $run->oldId());
		$this->logSplit(run: $run, rows: $moved['rows']);

		return [
			'newSchemaId' => $run->newId,
			'rows'        => $moved['rows'],
			'unmapped'    => $moved['unmapped'],
			'backup'      => $moved['backup'],
		];
	}//end splitLocked()

	/**
	 * Point the register at the replacement schema instead of the shared one.
	 *
	 * @param SchemaSplitRun $run The split run, with its register and new schema id set.
	 *
	 * @return void
	 */
	private function relink(SchemaSplitRun $run): void {
		$run->register->setSchemas(
			schemas: $this->ownership->attribution->replaceSchemaId(
				schemas: $run->register->getSchemas(),
				swap: new SchemaIdSwap(oldId: $run->oldId(), newId: $run->newId)
			)
		);
		$this->store->registerMapper->update($run->register);
	}//end relink()

	/**
	 * Record the split in the log.
	 *
	 * @param SchemaSplitRun $run  The finished split run.
	 * @param int            $rows The number of rows moved.
	 *
	 * @return void
	 */
	private function logSplit(SchemaSplitRun $run, int $rows): void {
		$this->logger->warning(
			message: sprintf(
				'[SharedSchemaDedupe] Register %d split off shared schema %d onto %d (%d row(s) moved).',
				$run->target,
				$run->oldId(),
				$run->newId,
				$rows
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $run->target, 'schema' => $run->oldId()]
		);
	}//end logSplit()

	/**
	 * The register's own schema entity: imported from its configuration, or cloned from the shared one.
	 *
	 * @param SchemaSplitRun            $run        The split run, with its register set.
	 * @param array<string, mixed>|null $definition The register's configured definition, if any.
	 *
	 * @return Schema The persisted replacement schema.
	 */
	private function createReplacementSchema(SchemaSplitRun $run, ?array $definition): Schema {
		if ($definition !== null) {
			$schema = $this->importConfigured(register: $run->register, definition: $definition);
			if ($schema->getId() !== null) {
				return $schema;
			}
		}

		return $this->cloneShared(register: $run->register, oldId: $run->oldId());
	}//end createReplacementSchema()

	/**
	 * Path A: create the schema from the register's configured definition.
	 *
	 * @param Register             $register   The register being split off.
	 * @param array<string, mixed> $definition Its configured definition.
	 *
	 * @return Schema The imported schema (unsaved when it has no id).
	 */
	private function importConfigured(Register $register, array $definition): Schema {
		return $this->ownership->importHandler->importSchema(
			data: $definition,
			slugsAndIdsMap: $this->store->schemaMapper->getSlugToIdMap(),
			owner: $register->getOwner(),
			appId: $register->getApplication(),
			version: (string)($definition['version'] ?? '0.0.1'),
			force: true,
			registerSchemaIds: []
		);
	}//end importConfigured()

	/**
	 * Path B: clone the shared entity's content into a new schema for the register's application.
	 *
	 * @param Register $register The register being split off.
	 * @param int      $oldId    The shared schema id being left behind.
	 *
	 * @return Schema The newly created schema.
	 */
	private function cloneShared(Register $register, int $oldId): Schema {
		$source = $this->store->schemaMapper->find(id: $oldId, _rbac: false, _multitenancy: false);
		$clone  = $source->jsonSerialize();
		unset($clone['id'], $clone['uuid'], $clone['uri'], $clone['created'], $clone['updated']);
		$clone['application'] = $register->getApplication();

		// No `getId() === null` check: createFromArray() returns a persisted
		// Schema whose id the mapper has already assigned, and it throws rather
		// than handing back an unsaved one.
		return $this->store->schemaMapper->createFromArray(object: $clone);
	}//end cloneShared()

	/**
	 * Plan one shared schema: its owner verdict and the split for every other register.
	 *
	 * @param SharedSchemaPlanRun $run The registers and owner overrides of this inspection.
	 * @param SharedSchemaUse     $use The schema and the registers sharing it.
	 *
	 * @return array<string, mixed>|null The plan entry, or null when the schema id dangles.
	 */
	private function planSchema(SharedSchemaPlanRun $run, SharedSchemaUse $use): ?array {
		$entity = $this->findSchema(schemaId: $use->schemaId);
		if ($entity === null) {
			return null;
		}

		$slug       = strtolower((string)$entity->getSlug());
		$configured = $this->configuredSchemas(run: $run, use: $use);
		$candidates = array_map(static fn (array $schemas) => ($schemas[$slug] ?? null), $configured);
		$scope      = [
			'schemaId'    => $use->schemaId,
			'registerIds' => $use->registerIds,
			'registers'   => $run->registers,
			'keep'        => $run->keep,
			'candidates'  => $candidates,
			'content'     => $entity->jsonSerialize(),
		];
		return $this->planEntry(entity: $entity, scope: $scope);
	}//end planSchema()

	/**
	 * Load a schema by id, or null when it no longer exists.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return Schema|null The schema, or null for a dangling id.
	 */
	private function findSchema(int $schemaId): ?Schema {
		try {
			return $this->store->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// A dangling id is `relink-schemas` territory, not a sharing problem.
			unset($e);
			return null;
		}
	}//end findSchema()

	/**
	 * Build one plan entry from the schema and its planning scope.
	 *
	 * @param Schema               $entity The shared schema entity.
	 * @param array<string, mixed> $scope  The schema id, registers, overrides, candidates and content.
	 *
	 * @return array<string, mixed> The plan entry.
	 */
	private function planEntry(Schema $entity, array $scope): array {
		$schemaId = $scope['schemaId'];
		$rules    = $this->ownership->attribution;
		$verdict  = $rules->classify(candidates: $scope['candidates'], entity: $scope['content']);
		$owner    = $rules->resolveOwner(
			verdict: $verdict,
			candidates: new OwnerCandidates(schemaId: $schemaId, registerIds: $scope['registerIds'], keep: $scope['keep'])
		);

		$shared = ['schemaId' => $schemaId, 'owner' => $owner['owner'], 'candidates' => $scope['candidates'], 'content' => $scope['content']];

		return [
			'schemaId'    => $schemaId,
			'schemaSlug'  => (string)$entity->getSlug(),
			'registerIds' => $scope['registerIds'],
			'status'      => $verdict['status'],
			'matches'     => $verdict['matches'],
			'owner'       => $owner['owner'],
			'ownerSource' => $owner['source'],
			'splits'      => $this->planSplits(shared: $shared, registers: $scope['registers']),
		];
	}//end planEntry()

	/**
	 * Each sharing register's configured schemas, keyed by slug; empty for a register that is gone.
	 *
	 * @param SharedSchemaPlanRun $run The registers of this inspection.
	 * @param SharedSchemaUse     $use The registers sharing the schema.
	 *
	 * @return array<int, array<string, mixed>> Configured schemas per register id.
	 */
	private function configuredSchemas(SharedSchemaPlanRun $run, SharedSchemaUse $use): array {
		$configured = [];
		foreach ($use->registerIds as $registerId) {
			$register = ($run->registers[$registerId] ?? null);
			$configured[$registerId] = [];
			if ($register !== null) {
				$configured[$registerId] = $this->ownership->locator->schemasFor(register: $register);
			}
		}

		return $configured;
	}//end configuredSchemas()

	/**
	 * Plan the split for every register other than the owner.
	 *
	 * @param array<string, mixed> $shared    The schema id, owner, candidates and content.
	 * @param array<int, Register> $registers Every register, keyed by id.
	 *
	 * @return array<int, array<string, mixed>> The planned splits per register id.
	 */
	private function planSplits(array $shared, array $registers): array {
		$splits = [];
		foreach (array_keys($shared['candidates']) as $registerId) {
			$register = ($registers[$registerId] ?? null);
			if ($registerId === $shared['owner'] || $register === null) {
				continue;
			}

			$splits[$registerId] = $this->planSplit(register: $register, shared: $shared);
		}

		return $splits;
	}//end planSplits()

	/**
	 * Plan the split of one register: which path, which table and what it moves.
	 *
	 * @param Register             $register The register to split off.
	 * @param array<string, mixed> $shared   The schema id, owner, candidates and content.
	 *
	 * @return array<string, mixed> The planned split.
	 */
	private function planSplit(Register $register, array $shared): array {
		$registerId = (int)$register->getId();
		$definition = $shared['candidates'][$registerId];
		$bare       = $this->store->migrator->tableNameFor(registerId: $registerId, schemaId: $shared['schemaId']);

		return [
			'registerSlug' => (string)$register->getSlug(),
			'application'  => $register->getApplication(),
			'path'         => $this->splitPath(definition: $definition),
			'definition'   => $definition,
			'table'        => $bare,
			'rows'         => $this->store->migrator->countRows(table: $bare),
			'unmapped'     => $this->store->migrator->planUnmapped(
				split: new SplitTableColumns(table: $bare, definition: $definition, content: $shared['content'])
			),
		];
	}//end planSplit()

	/**
	 * The create path a split takes: from the configuration, or a clone when there is none.
	 *
	 * @param mixed $definition The register's configured definition, or null.
	 *
	 * @return string `configuration` or `clone`.
	 */
	private function splitPath(mixed $definition): string {
		if ($definition === null) {
			return 'clone';
		}

		return 'configuration';
	}//end splitPath()

	/**
	 * Load every register keyed by id.
	 *
	 * @return array<int, Register> registerId => register.
	 */
	private function loadRegisters(): array {
		$registers = [];
		// No instanceof filter: findAll() is declared to return Register[].
		foreach ($this->store->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$registers[(int)$register->getId()] = $register;
		}

		return $registers;
	}//end loadRegisters()
}//end class
