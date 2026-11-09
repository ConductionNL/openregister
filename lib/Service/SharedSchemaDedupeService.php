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
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\SharedSchema\RegisterConfigurationLocator;
use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator;
use OCA\OpenRegister\Service\SharedSchema\SharedSchemaOwnership;
use OCA\OpenRegister\Service\SharedSchema\SharedSchemaStore;
use OCP\IDBConnection;
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
	 * Database connection, for the split transaction.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Register lookups and persistence.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookups and the clone fallback.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * The configuration-driven schema create path.
	 *
	 * @var ImportHandler
	 */
	private readonly ImportHandler $importHandler;

	/**
	 * Reads a register's own app configuration.
	 *
	 * @var RegisterConfigurationLocator
	 */
	private readonly RegisterConfigurationLocator $locator;

	/**
	 * The pure detection and ownership rules.
	 *
	 * @var SchemaAttribution
	 */
	private readonly SchemaAttribution $attribution;

	/**
	 * Moves the object rows across the split.
	 *
	 * @var SchemaTableMigrator
	 */
	private readonly SchemaTableMigrator $migrator;

	/**
	 * Audit trail for every mutation.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

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
		SharedSchemaStore $store,
		SharedSchemaOwnership $ownership,
		LoggerInterface $logger,
	) {
		$this->db = $store->db;
		$this->registerMapper = $store->registerMapper;
		$this->schemaMapper = $store->schemaMapper;
		$this->importHandler = $ownership->importHandler;
		$this->locator = $ownership->locator;
		$this->attribution = $ownership->attribution;
		$this->migrator = $store->migrator;
		$this->logger = $logger;
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
		return $this->attribution->parseKeep(raw: $raw);
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
		$registers = $this->loadRegisters();
		$stored    = array_map(fn (Register $register) => $register->getSchemas(), $registers);

		$plan = [];
		foreach ($this->attribution->indexShared(registerSchemas: $stored) as $schemaId => $registerIds) {
			if ($this->involves(registerId: $registerId, registerIds: $registerIds) === false) {
				continue;
			}

			$entry = $this->planSchema(schemaId: $schemaId, registerIds: $registerIds, registers: $registers, keep: $keep);
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
	 * @param array<string, mixed> $entry  One {@see self::inspect()} plan entry.
	 * @param int                  $target The non-canonical register id to split off.
	 * @param bool                 $strict Whether unmapped columns must refuse the move.
	 *
	 * @return array{newSchemaId: int, rows: int, unmapped: string[], backup: string|null} The outcome.
	 *
	 * @throws RuntimeException When the plan is unattributed, or strict mode refuses.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function applySplit(array $entry, int $target, bool $strict): array {
		$split = $this->attributedSplit(entry: $entry, target: $target);
		if ($strict === true) {
			$this->assertNothingUnmapped(unmapped: ($split['unmapped'] ?? []));
		}

		$this->db->beginTransaction();
		try {
			$outcome = $this->splitLocked(entry: $entry, target: $target, split: $split);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $outcome;
	}//end applySplit()

	/**
	 * The plan's split for the target register, refusing a register outside the plan or an unattributed plan.
	 *
	 * @param array<string, mixed> $entry  The plan entry.
	 * @param int                  $target The register being split off.
	 *
	 * @return array<string, mixed> The per-register part of the plan.
	 *
	 * @throws RuntimeException When the register is not in the plan, or the plan has no owner.
	 */
	private function attributedSplit(array $entry, int $target): array {
		$split = ($entry['splits'][$target] ?? null);
		if (is_array($split) === false) {
			throw new RuntimeException(sprintf('Register %d is not part of this plan.', $target));
		}

		if (($entry['owner'] ?? null) === null) {
			throw new RuntimeException(
				sprintf('Schema %d is unattributed; pass --keep to name the owner.', (int)$entry['schemaId'])
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
	 * Perform one split inside an open transaction.
	 *
	 * @param array<string, mixed> $entry  The plan entry.
	 * @param int                  $target The register being split off.
	 * @param array<string, mixed> $split  The per-register part of the plan.
	 *
	 * @return array{newSchemaId: int, rows: int, unmapped: string[], backup: string|null} The outcome.
	 */
	private function splitLocked(array $entry, int $target, array $split): array {
		$register = $this->registerMapper->find(id: $target, _rbac: false, _multitenancy: false);
		$oldId    = (int)$entry['schemaId'];

		$schema = $this->createReplacementSchema(register: $register, definition: ($split['definition'] ?? null), oldId: $oldId);
		$newId  = (int)$schema->getId();
		$this->relink(register: $register, oldId: $oldId, newId: $newId);

		$moved = $this->migrator->migrate(register: $register, schema: $schema, oldId: $oldId);
		$this->logSplit(target: $target, oldId: $oldId, newId: $newId, rows: $moved['rows']);

		return [
			'newSchemaId' => $newId,
			'rows'        => $moved['rows'],
			'unmapped'    => $moved['unmapped'],
			'backup'      => $moved['backup'],
		];
	}//end splitLocked()

	/**
	 * Point the register at its new schema id instead of the shared one, and persist it.
	 *
	 * @param Register $register The register being split off.
	 * @param int      $oldId    The shared schema id.
	 * @param int      $newId    The register's own schema id.
	 *
	 * @return void
	 */
	private function relink(Register $register, int $oldId, int $newId): void {
		$register->setSchemas(
			schemas: $this->attribution->replaceSchemaId(
				schemas: $register->getSchemas(),
				oldId: $oldId,
				newId: $newId
			)
		);
		$this->registerMapper->update($register);
	}//end relink()

	/**
	 * Record a completed split in the audit trail.
	 *
	 * @param int $target The register split off.
	 * @param int $oldId  The shared schema id.
	 * @param int $newId  The register's own schema id.
	 * @param int $rows   The number of rows moved.
	 *
	 * @return void
	 */
	private function logSplit(int $target, int $oldId, int $newId, int $rows): void {
		$this->logger->warning(
			message: sprintf(
				'[SharedSchemaDedupe] Register %d split off shared schema %d onto %d (%d row(s) moved).',
				$target,
				$oldId,
				$newId,
				$rows
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $target, 'schema' => $oldId]
		);
	}//end logSplit()

	/**
	 * Create the register's own schema entity.
	 *
	 * Path A — the register's app configuration still declares the schema — goes
	 * through {@see ImportHandler::importSchema()} with an EMPTY register scope, so
	 * the per-register slug-uniqueness fix forces a brand new row rather than
	 * resolving back onto the shared one. Running the repair through the same
	 * create path the import uses is what makes the split durable: the next app
	 * import finds the register's own entity and updates that instead of forking
	 * the shared one again.
	 *
	 * Path B — no configuration on disk — clones the current entity content, which
	 * preserves the rows' shape exactly and so needs no column mapping.
	 *
	 * @param Register          $register   The register being split off.
	 * @param array<string, mixed> $definition Its configured definition, or null when it has none.
	 * @param int               $oldId      The shared schema id being left behind.
	 *
	 * @return Schema The newly created schema.
	 *
	 * @throws RuntimeException When neither path yields a persisted schema.
	 */
	private function createReplacementSchema(Register $register, ?array $definition, int $oldId): Schema {
		if ($definition !== null) {
			$schema = $this->importConfigured(register: $register, definition: $definition);
			if ($schema->getId() !== null) {
				return $schema;
			}
		}

		return $this->cloneShared(register: $register, oldId: $oldId);
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
		return $this->importHandler->importSchema(
			data: $definition,
			slugsAndIdsMap: $this->schemaMapper->getSlugToIdMap(),
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
		$source = $this->schemaMapper->find(id: $oldId, _rbac: false, _multitenancy: false);
		$clone  = $source->jsonSerialize();
		unset($clone['id'], $clone['uuid'], $clone['uri'], $clone['created'], $clone['updated']);
		$clone['application'] = $register->getApplication();

		// No `getId() === null` check: createFromArray() returns a persisted
		// Schema whose id the mapper has already assigned, and it throws rather
		// than handing back an unsaved one.
		return $this->schemaMapper->createFromArray(object: $clone);
	}//end cloneShared()

	/**
	 * Build the plan entry for one shared schema.
	 *
	 * @param int                  $schemaId    The shared schema id.
	 * @param int[]                $registerIds The referencing registers.
	 * @param array<int, Register> $registers   All loaded registers, by id.
	 * @param array<string, mixed> $keep        The parsed overrides.
	 *
	 * @return array<string, mixed>|null The plan entry, or null when the schema no longer exists.
	 */
	private function planSchema(int $schemaId, array $registerIds, array $registers, array $keep): ?array {
		$entity = $this->findSchema(schemaId: $schemaId);
		if ($entity === null) {
			return null;
		}

		$content    = $entity->jsonSerialize();
		$candidates = $this->configuredDefinitions(registerIds: $registerIds, registers: $registers, slug: strtolower((string)$entity->getSlug()));
		$scope      = ['registerIds' => $registerIds, 'registers' => $registers, 'keep' => $keep, 'candidates' => $candidates, 'content' => $content];
		return $this->planEntry(schemaId: $schemaId, entity: $entity, scope: $scope);
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
			return $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// A dangling id is `relink-schemas` territory, not a sharing problem.
			unset($e);
			return null;
		}
	}//end findSchema()

	/**
	 * Attribute an existing shared schema and build its plan entry.
	 *
	 * @param int                  $schemaId The shared schema id.
	 * @param Schema               $entity   The shared schema.
	 * @param array<string, mixed> $scope    The referencing registers, all registers, the overrides,
	 *                                       each register's configured definition and the entity content.
	 *
	 * @return array<string, mixed> The plan entry.
	 */
	private function planEntry(int $schemaId, Schema $entity, array $scope): array {
		$verdict    = $this->attribution->classify(candidates: $scope['candidates'], entity: $scope['content']);
		$owner      = $this->attribution->resolveOwner(verdict: $verdict, schemaId: $schemaId, registerIds: $scope['registerIds'], keep: $scope['keep']);

		$shared     = ['schemaId' => $schemaId, 'owner' => $owner['owner'], 'candidates' => $scope['candidates'], 'content' => $scope['content']];

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
	 * Read each referencing register's configured definition for one schema slug.
	 *
	 * @param int[]                $registerIds The referencing registers.
	 * @param array<int, Register> $registers   All loaded registers, by id.
	 * @param string               $slug        The lowercased schema slug.
	 *
	 * @return array<int, mixed> registerId => definition, or null when it declares none.
	 */
	private function configuredDefinitions(array $registerIds, array $registers, string $slug): array {
		$candidates = [];
		foreach ($registerIds as $registerId) {
			$register   = ($registers[$registerId] ?? null);
			$configured = null;
			if ($register !== null) {
				$configured = ($this->locator->schemasFor(register: $register)[$slug] ?? null);
			}

			$candidates[$registerId] = $configured;
		}

		return $candidates;
	}//end configuredDefinitions()

	/**
	 * Build the per-register split parts of a plan entry.
	 *
	 * @param array<string, mixed> $shared    The shared schema id, its resolved owner, each register's
	 *                                        configured definition and the current entity content.
	 * @param array<int, Register> $registers All loaded registers, by id.
	 *
	 * @return array<int, array<string, mixed>> registerId => the split that would be performed.
	 */
	private function planSplits(array $shared, array $registers): array {
		$splits = [];
		foreach ($shared['candidates'] as $registerId => $definition) {
			$register = ($registers[$registerId] ?? null);
			if ($registerId === $shared['owner'] || $register === null) {
				continue;
			}

			$splits[$registerId] = $this->planSplit(registerId: (int)$registerId, register: $register, definition: $definition, shared: $shared);
		}

		return $splits;
	}//end planSplits()

	/**
	 * Build the split that would move one non-owning register onto its own schema.
	 *
	 * @param int                  $registerId The register id.
	 * @param Register             $register   The register.
	 * @param mixed                $definition Its configured definition, or null when it declares none.
	 * @param array<string, mixed> $shared     The shared schema id and the current entity content.
	 *
	 * @return array<string, mixed> The split.
	 */
	private function planSplit(int $registerId, Register $register, mixed $definition, array $shared): array {
		$bare = $this->migrator->tableNameFor(registerId: $registerId, schemaId: $shared['schemaId']);

		return [
			'registerSlug' => (string)$register->getSlug(),
			'application'  => $register->getApplication(),
			'path'         => $this->splitPath(definition: $definition),
			'definition'   => $definition,
			'table'        => $bare,
			'rows'         => $this->migrator->countRows(table: $bare),
			'unmapped'     => $this->migrator->planUnmapped(table: $bare, definition: $definition, content: $shared['content']),
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
		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$registers[(int)$register->getId()] = $register;
		}

		return $registers;
	}//end loadRegisters()
}//end class
