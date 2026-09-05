<?php

/**
 * OpenRegister Schema Deletion Service
 *
 * Owns schema-wide object deletion and the schema-teardown cascade:
 * every object of a schema is snapshotted to the (hash-chained) audit trail
 * before its row is removed, and — for the cascade — the schema's now-empty
 * magic table is dropped and the schema entity deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class SchemaDeletionService
 *
 * Single implementation of "delete every object of a schema", shared by the bulk
 * endpoints (`POST /api/bulk/{register}/{schema}/delete-objects`) and the schema
 * cascade (`DELETE /api/schemas/{id}?deleteObjects=true`).
 *
 * The cascade is deliberately split into two phases (see design D2):
 *
 *   Phase 1 (one transaction) — audit every object, hard-delete the rows, delete
 *   the schema entity, commit. Any failure rolls the whole thing back, so a
 *   committed audit entry always implies a genuinely deleted object.
 *
 *   Phase 2 (after the commit, best effort) — drop the magic tables. `DROP TABLE`
 *   is DDL and causes an implicit commit on MySQL/MariaDB, so it can neither
 *   participate in phase 1's transaction nor be rolled back. A drop failure
 *   therefore leaves an EMPTY orphan table, which is reported honestly as
 *   `tableDropped: false` — it never fails a request whose data work succeeded.
 *
 * ARCHIVAL IMMUTABILITY: a schema declaring `x-openregister-archival` holds records
 * its operator is legally required to retain, so neither entry point will destroy its
 * objects. `deleteObjectsBySchema()` refuses outright; `cascadeDeleteSchema()` refuses
 * unless an operator authorises the destruction explicitly from the CLI. See
 * {@see self::rejectIfArchivalImmutable()} for why the audit trail is not a substitute
 * for the record.
 *
 * KNOWN LIMIT: the cascade is synchronous, and its audit writes are inherently
 * sequential (hash-chained, ADR-003). A schema with far more than ~10k objects will
 * make the request slow and may hit the PHP time limit. Because phase 1 is
 * transactional, such a timeout rolls back cleanly — the failure is slow, not dirty.
 * Moving very large cascades to a background job is deferred, not designed away.
 *
 * @package OCA\OpenRegister\Service
 */
class SchemaDeletionService {

	/**
	 * Audit action recorded for every object removed by a schema-wide delete.
	 *
	 * @var string
	 */
	public const ACTION_CASCADE_DELETE = 'schema.cascade_delete';

	/**
	 * Trigger context for objects removed because their schema is being deleted.
	 *
	 * @var string
	 */
	public const TRIGGER_SCHEMA_DELETION = 'schema_deletion';

	/**
	 * Trigger context for objects removed via the bulk delete-objects endpoints.
	 *
	 * @var string
	 */
	public const TRIGGER_BULK_DELETE = 'bulk_schema_delete';

	/**
	 * Objects snapshotted + audited per batch.
	 *
	 * ADR-009 (bounded per-object work): the object set is read and audited in
	 * fixed-size chunks so a schema with many objects never materialises its
	 * entire object set in memory at once.
	 *
	 * @var int
	 */
	private const CHUNK_SIZE = 500;

	/**
	 * SchemaDeletionService constructor.
	 *
	 * @param IDBConnection $db Database connection, for phase-1 transaction control.
	 * @param MagicMapper $magicMapper Magic-table mapper (row reads, bulk delete, table drop).
	 * @param RegisterMapper $registerMapper Register mapper, to resolve register entities.
	 * @param SchemaMapper $schemaMapper Schema mapper, to resolve and delete schema entities.
	 * @param AuditTrailMapper $auditTrailMapper Audit trail mapper (ADR-003 hash-chained writes).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly MagicMapper $magicMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Delete every object of a register/schema pair, without deleting the schema.
	 *
	 * Backs `POST /api/bulk/{register}/{schema}/delete-objects` (and its legacy
	 * twin `/delete-schema`). Each object is snapshotted to the audit trail before
	 * its row is touched, because `MagicMapper::deleteObjectsBySchema()` returns
	 * only a count and cannot tell us afterwards what it removed.
	 *
	 * ARCHIVAL SCHEMAS ARE REFUSED OUTRIGHT. Both callers are HTTP routes, and the
	 * spec's answer for an HTTP caller is the same at every door: an archival record
	 * leaves only through the retention cron or the `occ` purge. There is no override
	 * parameter here on purpose — an override this method accepted would be reachable
	 * over HTTP the moment a controller passed it through.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId The schema id.
	 * @param bool $hardDelete True to remove the rows, false to soft-delete them.
	 * @param string $triggeredBy Audit trigger context.
	 *
	 * @throws ArchivalImmutableException If the schema declares `x-openregister-archival`.
	 * @throws \Exception If the register or schema cannot be resolved, or deletion fails.
	 *
	 * @return array{deleted_count: int, deleted_uuids: array<int, string>, schema_id: int} The deletion result.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The hard/soft toggle mirrors the mapper primitive it wraps.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function deleteObjectsBySchema(
		int $registerId,
		int $schemaId,
		bool $hardDelete = false,
		string $triggeredBy = self::TRIGGER_BULK_DELETE,
	): array {
		$register = $this->registerMapper->find(id: $registerId);
		$schema = $this->schemaMapper->find(id: $schemaId);

		$this->rejectIfArchivalImmutable(schema: $schema, operation: 'delete');

		$result = $this->deleteObjectsOfPair(
			register: $register,
			schema: $schema,
			hardDelete: $hardDelete,
			triggeredBy: $triggeredBy
		);

		return [
			'deleted_count' => $result['deleted_count'],
			'deleted_uuids' => $result['deleted_uuids'],
			'schema_id' => $schemaId,
		];

	}//end deleteObjectsBySchema()

	/**
	 * Cascade-delete a schema: its objects, its magic tables, and the schema itself.
	 *
	 * Phase 1 (transactional): audit + hard-delete every object of every magic table
	 * belonging to this schema, then delete the schema entity, then commit. Any
	 * failure rolls the entire phase back and the exception propagates (HTTP 500).
	 *
	 * Phase 2 (post-commit, best effort): drop the now-empty magic tables. A failure
	 * here is logged at WARNING and reported as `tableDropped: false`; the request
	 * still succeeds, because the caller's intent is already satisfied and DDL is not
	 * rollbackable on MySQL/MariaDB.
	 *
	 * ARCHIVAL SCHEMAS ARE REFUSED UNLESS THE CALLER OVERRIDES EXPLICITLY, and the
	 * override is an argument rather than a default because the two callers do not
	 * carry the same authority. `DELETE /api/schemas/{id}?deleteObjects=true` never
	 * passes it: an HTTP caller cannot destroy a legally retained record, exactly as
	 * at the other three doors. `occ openregister:schemas:prune-retired` passes it
	 * only when the operator typed `--force-archival`, which is the same bargain
	 * `occ openregister:objects:purge --force` already strikes — shell access is an
	 * authorization boundary an HTTP request cannot cross, and the operator has to
	 * name the archival records out loud rather than sweep them up inside a flag
	 * they passed for another reason.
	 *
	 * @param Schema $schema The schema to tear down.
	 * @param bool $archivalOverride True only when an operator has explicitly authorised
	 *                               destroying a legally retained record from the CLI.
	 *
	 * @throws ArchivalImmutableException If the schema is archival and no override was given.
	 * @throws \Exception If phase 1 fails (nothing is deleted).
	 *
	 * @return array{deletedCount: int, deletedUuids: array<int, string>, tableDropped: bool} The cascade result.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The override is the caller's authority, and it has to be stated per call site.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function cascadeDeleteSchema(Schema $schema, bool $archivalOverride = false): array {
		if ($archivalOverride === false) {
			$this->rejectIfArchivalImmutable(schema: $schema, operation: 'delete');
		}

		$schemaId = (int)$schema->getId();
		$tables = $this->resolveMagicTablesForSchema(schema: $schema);

		$deletedCount = 0;
		$deletedUuids = [];

		// ---- Phase 1: one transaction. ----
		$this->db->beginTransaction();
		try {
			foreach ($tables as $entry) {
				if ($entry['register'] === null) {
					// The magic table outlives its register (stale pair). There is no
					// Register entity to read rows through, so nothing can be audited
					// or deleted here — but the table is still dropped in phase 2.
					continue;
				}

				$result = $this->deleteObjectsOfPair(
					register: $entry['register'],
					schema: $schema,
					hardDelete: true,
					triggeredBy: self::TRIGGER_SCHEMA_DELETION
				);

				$deletedCount += $result['deleted_count'];
				$deletedUuids = array_merge($deletedUuids, $result['deleted_uuids']);
			}

			// The rows are gone, so the mapper guard naturally counts 0 — no force needed.
			$this->schemaMapper->delete(entity: $schema);

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();

			$this->logger->error(
				message: '[SchemaDeletionService] Cascade delete rolled back',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $schemaId,
					'error' => $e->getMessage(),
				]
			);

			throw $e;
		}//end try

		// ---- Phase 2: post-commit, best effort. ----
		$tableDropped = $this->dropTables(
			tableNames: array_column($tables, 'tableName'),
			schemaId: $schemaId
		);

		$this->logger->info(
			message: '[SchemaDeletionService] Cascade deleted schema',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $schemaId,
				'schemaSlug' => $schema->getSlug(),
				'deletedCount' => $deletedCount,
				'tableDropped' => $tableDropped,
			]
		);

		return [
			'deletedCount' => $deletedCount,
			'deletedUuids' => $deletedUuids,
			'tableDropped' => $tableDropped,
		];

	}//end cascadeDeleteSchema()

	/**
	 * Drop the schema's magic tables, but only when they hold no rows at all.
	 *
	 * Used by the plain (no-flag, zero-object) delete path so it stops leaving an
	 * orphan table behind. The emptiness check counts EVERY row, including
	 * soft-deleted ones — the controller's object count excludes soft-deleted rows,
	 * so a "0 object" schema may still have tombstones in its table. Dropping such a
	 * table would destroy those rows with no audit entry, so we keep the table
	 * instead and log it. The cascade disposition is the audited way to remove them.
	 *
	 * Never throws: a failed reclaim of an empty table must not fail the delete that
	 * already succeeded.
	 *
	 * @param Schema $schema The schema whose tables should be reclaimed.
	 *
	 * @return bool True when no magic table for this schema remains.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function dropEmptyTablesForSchema(Schema $schema): bool {
		$schemaId = (int)$schema->getId();

		try {
			$tables = $this->resolveMagicTablesForSchema(schema: $schema);
			$droppable = [];

			foreach ($tables as $entry) {
				$rowCount = $this->countAllRows(tableName: $entry['tableName']);
				if ($rowCount > 0) {
					$this->logger->warning(
						message: '[SchemaDeletionService] Keeping non-empty magic table of a deleted schema',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schemaId' => $schemaId,
							'tableName' => $entry['tableName'],
							'rowCount' => $rowCount,
						]
					);
					continue;
				}

				$droppable[] = $entry['tableName'];
			}

			$allDropped = $this->dropTables(tableNames: $droppable, schemaId: $schemaId);

			return ($allDropped === true && count($droppable) === count($tables));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaDeletionService] Could not reclaim magic tables of a deleted schema',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $schemaId,
					'error' => $e->getMessage(),
				]
			);

			return false;
		}//end try

	}//end dropEmptyTablesForSchema()

	/**
	 * Refuse to destroy the objects of a schema that holds legally retained records.
	 *
	 * THE ONE PLACE THIS SERVICE DECIDES A SCHEMA-WIDE DELETE IS NOT ALLOWED.
	 *
	 * The condition is not restated here: it is read from
	 * {@see Schema::hasArchivalAnnotation()}, the single definition of "is archival"
	 * that `ObjectService::deleteObject()`, `ObjectService::rejectIfArchivalImmutable()`,
	 * `DeletedController::destroy()` and `occ openregister:objects:purge` all ask. A
	 * fifth reading of the annotation is a fifth chance to disagree with the other four.
	 *
	 * The gate sits in this service rather than in its callers because this service is
	 * the single implementation of "delete every object of a schema": both bulk routes,
	 * the HTTP cascade and the prune CLI reach the destruction through here, so a future
	 * caller inherits the refusal instead of having to remember it.
	 *
	 * WHY REFUSE RATHER THAN PERMIT-WITH-AUDIT. This service audits every object into the
	 * hash-chained trail before dropping it, which is genuinely a design for deliberate
	 * destruction — but an audit entry records that a record was destroyed, it is not the
	 * record. A retention obligation is discharged by still holding the row, so the audit
	 * trail cannot stand in for it, and the fact that this path destroys carefully is not
	 * a reason to let it destroy here.
	 *
	 * @param Schema $schema The schema whose objects are about to be destroyed.
	 * @param string $operation The operation name reported in the structured error body.
	 *
	 * @throws ArchivalImmutableException When the schema declares `x-openregister-archival`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function rejectIfArchivalImmutable(Schema $schema, string $operation): void {
		if ($schema->hasArchivalAnnotation() === false) {
			return;
		}

		$this->logger->warning(
			message: '[SchemaDeletionService] Refused a schema-wide delete on an archival schema',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => (int)$schema->getId(),
				'schemaSlug' => $schema->getSlug(),
				'operation' => $operation,
			]
		);

		throw new ArchivalImmutableException(
			schemaIdentifier: ($schema->getSlug() ?? (string)$schema->getId()),
			operation: $operation
		);
	}//end rejectIfArchivalImmutable()

	/**
	 * Audit and delete every object of one register/schema magic table.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete True to remove the rows, false to soft-delete them.
	 * @param string $triggeredBy Audit trigger context.
	 *
	 * @return array{deleted_count: int, deleted_uuids: array<int, string>} The deletion result.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The hard/soft toggle mirrors the mapper primitive it wraps.
	 */
	private function deleteObjectsOfPair(
		Register $register,
		Schema $schema,
		bool $hardDelete,
		string $triggeredBy,
	): array {
		if ($this->magicMapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return [
				'deleted_count' => 0,
				'deleted_uuids' => [],
			];
		}

		// Snapshot BEFORE deleting: the mapper primitive returns only a count, so the
		// pre-deletion state has to be captured here or it is unrecoverable.
		$deletedUuids = $this->auditObjectsBeforeDeletion(
			register: $register,
			schema: $schema,
			hardDelete: $hardDelete,
			triggeredBy: $triggeredBy
		);

		$deletedCount = $this->magicMapper->deleteObjectsBySchema(
			register: $register,
			schema: $schema,
			hardDelete: $hardDelete
		);

		return [
			'deleted_count' => $deletedCount,
			'deleted_uuids' => $deletedUuids,
		];

	}//end deleteObjectsOfPair()

	/**
	 * Write one hash-chained audit entry per object, capturing its full pre-delete state.
	 *
	 * Reads the objects in chunks (ADR-009) and resolves the schema-derived audit
	 * context once for the whole operation rather than once per object. RBAC and
	 * multitenancy filters are switched off for the read: the caller is already gated
	 * on `checkSchemaManagePermission()`, and the delete that follows removes EVERY
	 * row, so an RBAC-narrowed read would audit fewer objects than it destroys.
	 * Soft-deleted rows are included for the same reason.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete Whether the rows are about to be hard-deleted.
	 * @param string $triggeredBy Audit trigger context.
	 *
	 * @return array<int, string> The UUIDs of the audited objects.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Recorded as audit context, not a behaviour switch.
	 */
	private function auditObjectsBeforeDeletion(
		Register $register,
		Schema $schema,
		bool $hardDelete,
		string $triggeredBy,
	): array {
		// Schema-derived values: resolved ONCE for the whole operation (ADR-009).
		$baseContext = [
			'triggeredBy' => $triggeredBy,
			'hardDelete' => $hardDelete,
			'cascadeContext' => [
				'triggerSchema' => $schema->getSlug(),
				'triggerSchemaId' => (int)$schema->getId(),
				'registerId' => (int)$register->getId(),
			],
		];

		$filters = [
			// See the docblock: audit exactly the set that is about to be deleted.
			'_includeDeleted' => true,
			'_rbac' => false,
			'_multitenancy' => false,
		];

		$uuids = [];
		$offset = 0;

		while (true) {
			$objects = $this->magicMapper->findAllInRegisterSchemaTable(
				register: $register,
				schema: $schema,
				limit: self::CHUNK_SIZE,
				offset: $offset,
				filters: $filters
			);

			if (empty($objects) === true) {
				break;
			}

			foreach ($objects as $object) {
				$uuid = $this->auditObject(object: $object, baseContext: $baseContext);
				if ($uuid !== null) {
					$uuids[] = $uuid;
				}
			}

			if (count($objects) < self::CHUNK_SIZE) {
				break;
			}

			$offset += self::CHUNK_SIZE;
		}//end while

		return $uuids;
	}//end auditObjectsBeforeDeletion()

	/**
	 * Write a single object's pre-deletion snapshot to the audit trail.
	 *
	 * Written through AuditTrailMapper so ADR-003's SHA-256 hash-chaining is applied
	 * at insert time, and inside the caller's transaction so a rollback discards it.
	 *
	 * @param ObjectEntity $object The object about to be deleted.
	 * @param array $baseContext Operation-wide audit context.
	 *
	 * @return string|null The object's UUID, or null when it has none.
	 */
	private function auditObject(ObjectEntity $object, array $baseContext): ?string {
		$context = $baseContext;
		$context['snapshot'] = $object->jsonSerialize();

		$this->auditTrailMapper->createAuditTrailEntry(
			object: $object,
			action: self::ACTION_CASCADE_DELETE,
			context: $context
		);

		$uuid = $object->getUuid();
		if ($uuid === null) {
			return null;
		}

		return (string)$uuid;
	}//end auditObject()

	/**
	 * Resolve every magic table that belongs to this schema.
	 *
	 * Tables are discovered from the tables that actually exist (the same source the
	 * controller's object count uses), not from the registers that merely reference
	 * the schema — so a table whose register has since dropped the reference is still
	 * reclaimed. When the register entity itself is gone the table is still returned,
	 * with a null register, so phase 2 can drop it.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, array{registerId: int, register: Register|null, tableName: string}> The tables.
	 */
	private function resolveMagicTablesForSchema(Schema $schema): array {
		$schemaId = (int)$schema->getId();
		$tables = [];

		foreach ($this->magicMapper->getAllRegisterSchemaPairs() as $pair) {
			if ((int)$pair['schemaId'] !== $schemaId) {
				continue;
			}

			$registerId = (int)$pair['registerId'];
			$register = null;

			try {
				$register = $this->registerMapper->find(id: $registerId);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[SchemaDeletionService] Magic table has no register entity',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'registerId' => $registerId,
						'schemaId' => $schemaId,
						'error' => $e->getMessage(),
					]
				);
			}

			$tables[] = [
				'registerId' => $registerId,
				'register' => $register,
				'tableName' => MagicMapper::TABLE_PREFIX . $registerId . '_' . $schemaId,
			];
		}//end foreach

		return $tables;
	}//end resolveMagicTablesForSchema()

	/**
	 * Drop magic tables, best effort.
	 *
	 * @param array<int, string> $tableNames The tables to drop.
	 * @param int $schemaId The schema being torn down (for logging).
	 *
	 * @return bool True when every table was dropped.
	 */
	private function dropTables(array $tableNames, int $schemaId): bool {
		$allDropped = true;

		foreach ($tableNames as $tableName) {
			try {
				$this->magicMapper->dropTable(tableName: $tableName);
			} catch (Throwable $e) {
				$allDropped = false;

				// The objects and the schema are already gone and committed. Failing the
				// request now would report failure for work that succeeded, and there is
				// nothing to roll back to — DDL is not rollbackable on MySQL/MariaDB.
				// The leftover table is empty and reclaimable later.
				$this->logger->warning(
					message: '[SchemaDeletionService] Could not drop magic table after schema deletion',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schemaId' => $schemaId,
						'tableName' => $tableName,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $allDropped;
	}//end dropTables()

	/**
	 * Count every row of a magic table, including soft-deleted ones.
	 *
	 * @param string $tableName The magic table name.
	 *
	 * @return int The row count (0 when the table does not exist).
	 */
	private function countAllRows(string $tableName): int {
		if ($this->db->tableExists($tableName) === false) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($tableName);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countAllRows()
}//end class
