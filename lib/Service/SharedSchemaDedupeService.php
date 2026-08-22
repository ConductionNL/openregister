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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\SharedSchema\RegisterConfigurationLocator;
use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator;
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
 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
 */
class SharedSchemaDedupeService {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection                $db             Database connection, for the split transaction.
	 * @param RegisterMapper               $registerMapper Register lookups and persistence.
	 * @param SchemaMapper                 $schemaMapper   Schema lookups and the clone fallback.
	 * @param ImportHandler                $importHandler  The configuration-driven schema create path.
	 * @param RegisterConfigurationLocator $locator        Reads a register's own app configuration.
	 * @param SchemaAttribution            $attribution    The pure detection and ownership rules.
	 * @param SchemaTableMigrator          $migrator       Moves the object rows across the split.
	 * @param LoggerInterface              $logger         Audit trail for every mutation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ImportHandler $importHandler,
		private readonly RegisterConfigurationLocator $locator,
		private readonly SchemaAttribution $attribution,
		private readonly SchemaTableMigrator $migrator,
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
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
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
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function inspect(?int $registerId, array $keep): array {
		$registers = $this->loadRegisters();
		$stored    = [];
		foreach ($registers as $id => $register) {
			$stored[$id] = $register->getSchemas();
		}

		$plan = [];
		foreach ($this->attribution->indexShared(registerSchemas: $stored) as $schemaId => $registerIds) {
			if ($registerId !== null && in_array($registerId, $registerIds, true) === false) {
				continue;
			}

			$entry = $this->planSchema(
				schemaId: $schemaId,
				registerIds: $registerIds,
				registers: $registers,
				keep: $keep
			);

			if ($entry !== null) {
				$plan[] = $entry;
			}
		}

		return $plan;
	}//end inspect()

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
	 * @spec openspec/changes/dedupe-shared-schemas/proposal.md
	 */
	public function applySplit(array $entry, int $target, bool $strict): array {
		$split = ($entry['splits'][$target] ?? null);
		if (is_array($split) === false) {
			throw new RuntimeException(sprintf('Register %d is not part of this plan.', $target));
		}

		if (($entry['owner'] ?? null) === null) {
			throw new RuntimeException(
				sprintf('Schema %d is unattributed; pass --keep to name the owner.', (int)$entry['schemaId'])
			);
		}

		$unmapped = ($split['unmapped'] ?? []);
		if ($strict === true && $unmapped !== []) {
			throw new RuntimeException(
				sprintf(
					'Strict mode: %d source column(s) have no destination (%s).',
					count($unmapped),
					implode(', ', $unmapped)
				)
			);
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

		$schema = $this->createReplacementSchema(
			register: $register,
			definition: ($split['definition'] ?? null),
			oldId: $oldId
		);

		$newId = (int)$schema->getId();

		$register->setSchemas(
			schemas: $this->attribution->replaceSchemaId(
				schemas: $register->getSchemas(),
				oldId: $oldId,
				newId: $newId
			)
		);
		$this->registerMapper->update($register);

		$moved = $this->migrator->migrate(register: $register, schema: $schema, oldId: $oldId);

		$this->logger->warning(
			message: sprintf(
				'[SharedSchemaDedupe] Register %d split off shared schema %d onto %d (%d row(s) moved).',
				$target,
				$oldId,
				$newId,
				$moved['rows']
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $target, 'schema' => $oldId]
		);

		return [
			'newSchemaId' => $newId,
			'rows'        => $moved['rows'],
			'unmapped'    => $moved['unmapped'],
			'backup'      => $moved['backup'],
		];
	}//end splitLocked()

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
			$schema = $this->importHandler->importSchema(
				data: $definition,
				slugsAndIdsMap: $this->schemaMapper->getSlugToIdMap(),
				owner: $register->getOwner(),
				appId: $register->getApplication(),
				version: (string)($definition['version'] ?? '0.0.1'),
				force: true,
				registerSchemaIds: []
			);

			if ($schema->getId() !== null) {
				return $schema;
			}
		}

		$source = $this->schemaMapper->find(id: $oldId, _rbac: false, _multitenancy: false);
		$clone  = $source->jsonSerialize();
		unset($clone['id'], $clone['uuid'], $clone['uri'], $clone['created'], $clone['updated']);
		$clone['application'] = $register->getApplication();

		$schema = $this->schemaMapper->createFromArray(object: $clone);
		if ($schema->getId() === null) {
			throw new RuntimeException(sprintf('Could not create a replacement for schema %d.', $oldId));
		}

		return $schema;
	}//end createReplacementSchema()

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
		try {
			$entity = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// A dangling id is `relink-schemas` territory, not a sharing problem.
			unset($e);
			return null;
		}

		$content    = $entity->jsonSerialize();
		$candidates = $this->configuredDefinitions(
			registerIds: $registerIds,
			registers: $registers,
			slug: strtolower((string)$entity->getSlug())
		);

		$verdict = $this->attribution->classify(candidates: $candidates, entity: $content);
		$owner   = $this->attribution->resolveOwner(
			verdict: $verdict,
			schemaId: $schemaId,
			registerIds: $registerIds,
			keep: $keep
		);

		return [
			'schemaId'    => $schemaId,
			'schemaSlug'  => (string)$entity->getSlug(),
			'registerIds' => $registerIds,
			'status'      => $verdict['status'],
			'matches'     => $verdict['matches'],
			'owner'       => $owner['owner'],
			'ownerSource' => $owner['source'],
			'splits'      => $this->planSplits(
				schemaId: $schemaId,
				owner: $owner['owner'],
				registers: $registers,
				candidates: $candidates,
				content: $content
			),
		];
	}//end planSchema()

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
	 * @param int                  $schemaId   The shared schema id.
	 * @param int|null             $owner      The resolved owner, when attributed.
	 * @param array<int, Register> $registers  All loaded registers, by id.
	 * @param array<int, mixed>    $candidates Each register's configured definition.
	 * @param array<string, mixed> $content    The current entity content.
	 *
	 * @return array<int, array<string, mixed>> registerId => the split that would be performed.
	 */
	private function planSplits(int $schemaId, ?int $owner, array $registers, array $candidates, array $content): array {
		$splits = [];
		foreach ($candidates as $registerId => $definition) {
			$register = ($registers[$registerId] ?? null);
			if ($registerId === $owner || $register === null) {
				continue;
			}

			$bare = $this->migrator->tableNameFor(registerId: (int)$registerId, schemaId: $schemaId);
			$path = 'configuration';
			if ($definition === null) {
				$path = 'clone';
			}

			$splits[$registerId] = [
				'registerSlug' => (string)$register->getSlug(),
				'application'  => $register->getApplication(),
				'path'         => $path,
				'definition'   => $definition,
				'table'        => $bare,
				'rows'         => $this->migrator->countRows(table: $bare),
				'unmapped'     => $this->migrator->planUnmapped(
					table: $bare,
					definition: $definition,
					content: $content
				),
			];
		}//end foreach

		return $splits;
	}//end planSplits()

	/**
	 * Load every register keyed by id.
	 *
	 * @return array<int, Register> registerId => register.
	 */
	private function loadRegisters(): array {
		$registers = [];
		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			if ($register instanceof Register === false) {
				continue;
			}

			$registers[(int)$register->getId()] = $register;
		}

		return $registers;
	}//end loadRegisters()
}//end class
