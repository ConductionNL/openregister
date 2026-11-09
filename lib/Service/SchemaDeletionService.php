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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
 * KEPT ROWS: a frozen, archived or locked row refuses every whole-table delete. Every
 * entry point checks inside its transaction, with the rows held by a row lock until it
 * ends, and refuses before any row is touched. Only the cascade can be forced, from the
 * CLI, and a forced teardown leaves its own audit entry.
 *
 * KNOWN LIMIT: the cascade is synchronous, and its audit writes are inherently
 * sequential (hash-chained, ADR-003). A schema with far more than ~10k objects will
 * make the request slow and may hit the PHP time limit. Because phase 1 is
 * transactional, such a timeout rolls back cleanly — the failure is slow, not dirty.
 * Moving very large cascades to a background job is deferred, not designed away.
 *
 * @package OCA\OpenRegister\Service
 */
class SchemaDeletionService implements SchemaTeardownInterface {

	/**
	 * Audit action recorded for every object removed by a schema-wide delete.
	 *
	 * @var string
	 */
	public const ACTION_CASCADE_DELETE = 'schema.cascade_delete';

	/**
	 * Audit action recorded once for a teardown an operator forced.
	 *
	 * @var string
	 */
	public const ACTION_FORCED_TEARDOWN = 'schema.teardown_forced';

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
	 * Trigger context for objects removed via the bulk delete-register endpoint.
	 *
	 * @var string
	 */
	public const TRIGGER_BULK_REGISTER_DELETE = 'bulk_register_delete';

	/**
	 * Magic-table mapper (row reads, bulk delete, table drop).
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Register mapper, to resolve register entities.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper, to resolve and delete schema entities.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Audit trail mapper (ADR-003 hash-chained writes).
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * SchemaDeletionService constructor.
	 *
	 * @param IDBConnection $db Database connection, for phase-1 transaction control.
	 * @param SchemaDeletionStores $stores The object, register, schema and audit trail mappers.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		SchemaDeletionStores $stores,
		private readonly LoggerInterface $logger,
	) {
		$this->magicMapper = $stores->magicMapper;
		$this->registerMapper = $stores->registerMapper;
		$this->schemaMapper = $stores->schemaMapper;
		$this->auditTrailMapper = $stores->auditTrailMapper;
	}//end __construct()

	/**
	 * Delete every object of a register/schema pair, without deleting the schema.
	 *
	 * Backs `POST /api/bulk/{register}/{schema}/delete-objects` (and its legacy
	 * twin `/delete-schema`). Each object is snapshotted to the audit trail before
	 * its row is touched, because `MagicMapper::deleteObjectsBySchema()` returns
	 * only a count and cannot tell us afterwards what it removed. The check, the audit
	 * and the delete share one transaction.
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
	 * @throws Throwable If the register or schema cannot be resolved, or deletion fails.
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

		$result = $this->deleteInOneTransaction(register: $register, schemas: [$schema], hardDelete: $hardDelete, triggeredBy: $triggeredBy);

		return ['deleted_count' => $result['deleted_count'], 'deleted_uuids' => $result['deleted_uuids'], 'schema_id' => $schemaId];
	}//end deleteObjectsBySchema()

	/**
	 * Resolve the schemas a register lists, the way the retention sweep does.
	 *
	 * Mirrors `RetentionRowScanner::magicTableSources()`: the register's own
	 * `schemas` list is the authority for which (register, schema) pairs exist.
	 * A listed id that no schema answers to is skipped with a warning rather than
	 * thrown on, because it cannot have a magic table this service could reach.
	 *
	 * Public so the controller can gate every schema on its manage permission
	 * against exactly the set this service is about to empty.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<int, Schema> The resolvable schemas, in the register's order.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-schema-wide-object-deletion-is-refused-on-an-archival-schema
	 */
	public function resolveSchemasOfRegister(Register $register): array {
		$schemas = [];
		foreach ($register->getSchemas() as $schemaId) {
			$schema = $this->listedSchema(register: $register, schemaId: $schemaId);
			if ($schema !== null) {
				$schemas[] = $schema;
			}
		}

		return $schemas;
	}//end resolveSchemasOfRegister()

	/**
	 * A schema a register lists, or null (logged) when it does not resolve.
	 *
	 * @param Register $register The register.
	 * @param mixed    $schemaId The listed schema id.
	 *
	 * @return Schema|null
	 */
	private function listedSchema(Register $register, mixed $schemaId): ?Schema {
		try {
			return $this->schemaMapper->find(id: (int)$schemaId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaDeletionService] Register lists a schema that does not resolve; skipped',
				context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => (int)$register->getId(), 'schemaId' => (string)$schemaId]
					+ ['error' => $e->getMessage()]
			);
			return null;
		}
	}//end listedSchema()

	/**
	 * Delete every object of every schema a register lists, without deleting either.
	 *
	 * Backs `POST /api/bulk/{register}/delete-register`, which until now called a
	 * stub that threw on every request. It runs the same audited per-pair delete
	 * as {@see self::deleteObjectsBySchema()}, over the pairs
	 * {@see self::resolveSchemasOfRegister()} names.
	 *
	 * ARCHIVAL SCHEMAS REFUSE THE WHOLE REQUEST, AND THEY DO IT FIRST. Every schema
	 * is checked before any row is touched: checked inside the loop instead, a
	 * register listing a plain schema ahead of an archival one would be half emptied
	 * by the time the refusal came. There is no override, for the reason
	 * {@see self::deleteObjectsBySchema()} gives: this is an HTTP door.
	 *
	 * ONE TRANSACTION. The pairs are emptied together or not at all, like phase 1
	 * of {@see self::cascadeDeleteSchema()}.
	 *
	 * @param Register $register The register whose objects are deleted.
	 * @param bool $hardDelete True to remove the rows, false to soft-delete them.
	 * @param string $triggeredBy Audit trigger context.
	 *
	 * @throws ArchivalImmutableException If any schema of the register is archival (nothing is deleted).
	 * @throws Throwable If a delete fails (everything is rolled back).
	 *
	 * @return array{deleted_count: int, deleted_uuids: array<int, string>, register_id: int} The deletion result.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The hard/soft toggle mirrors the mapper primitive it wraps.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-schema-wide-object-deletion-is-refused-on-an-archival-schema
	 */
	public function deleteObjectsByRegister(
		Register $register,
		bool $hardDelete = false,
		string $triggeredBy = self::TRIGGER_BULK_REGISTER_DELETE,
	): array {
		$schemas = $this->resolveSchemasOfRegister(register: $register);

		foreach ($schemas as $schema) {
			$this->rejectIfArchivalImmutable(schema: $schema, operation: 'delete');
		}

		$result = $this->deleteInOneTransaction(register: $register, schemas: $schemas, hardDelete: $hardDelete, triggeredBy: $triggeredBy);

		return ['deleted_count' => $result['deleted_count'], 'deleted_uuids' => $result['deleted_uuids'], 'register_id' => (int)$register->getId()];
	}//end deleteObjectsByRegister()

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
	 * KEPT ROWS ARE REFUSED THE SAME WAY: a frozen, archived or locked row stops the
	 * teardown before any row is touched, and so does a non-empty table nothing can
	 * read. `occ openregister:schemas:prune-retired --force-kept-rows` lifts that. Any
	 * lift that removes something protected writes one
	 * {@see self::ACTION_FORCED_TEARDOWN} entry inside phase 1.
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
	 * @param SchemaTeardownOverride $override What an operator lifted from the CLI; nothing by default.
	 *
	 * @throws ArchivalImmutableException If the schema is archival and no override was given.
	 * @throws Throwable If a kept row refuses, see {@see SchemaTeardownGuard::assertNoneKept()}, or phase 1 fails (nothing is deleted).
	 *
	 * @return array{deletedCount: int, deletedUuids: array<int, string>, tableDropped: bool} The cascade result.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function cascadeDeleteSchema(
		Schema $schema,
		SchemaTeardownOverride $override = new SchemaTeardownOverride(),
	): array {
		if ($override->archivalSchema === false) {
			$this->rejectIfArchivalImmutable(schema: $schema, operation: 'delete');
		}

		$schemaId = (int)$schema->getId();
		$tables = $this->resolveMagicTablesForSchema(schema: $schema);

		// ---- Phase 1: one transaction. ----
		$removed = $this->cascadeInOneTransaction(schema: $schema, override: $override, tables: $tables);

		// ---- Phase 2: post-commit, best effort. ----
		$tableDropped = $this->dropTables(tableNames: array_column($tables, 'tableName'), schemaId: $schemaId);

		$done = ['schemaId' => $schemaId, 'schemaSlug' => $schema->getSlug(), 'deletedCount' => $removed['deletedCount'], 'tableDropped' => $tableDropped];
		$this->logger->info(message: '[SchemaDeletionService] Cascade deleted schema', context: ['file' => __FILE__, 'line' => __LINE__] + $done);

		return ($removed + ['tableDropped' => $tableDropped]);
	}//end cascadeDeleteSchema()

	/**
	 * Phase 1 of a cascade: guard or record kept rows, delete the rows and the schema, commit.
	 *
	 * @param Schema                 $schema   The schema.
	 * @param SchemaTeardownOverride $override The owner's acknowledged lifts.
	 * @param array                  $tables   The schema's magic tables.
	 *
	 * @return array{deletedCount: int, deletedUuids: string[]}
	 *
	 * @throws Throwable After rolling back, when any step fails.
	 */
	private function cascadeInOneTransaction(Schema $schema, SchemaTeardownOverride $override, array $tables): array {
		$this->db->beginTransaction();
		try {
			$this->recordKeptRowsLifted(schema: $schema, override: $override);
			$removed = $this->deleteObjectsOfTables(schema: $schema, tables: $tables);
			// The rows are gone, so the mapper guard naturally counts 0 — no force needed.
			$this->schemaMapper->delete(entity: $schema);
			$this->db->commit();
			return $removed;
		} catch (Throwable $e) {
			$this->db->rollBack();
			$failed = ['schemaId' => (int)$schema->getId(), 'error' => $e->getMessage()];
			$this->logger->error(message: '[SchemaDeletionService] Cascade delete rolled back', context: ['file' => __FILE__, 'line' => __LINE__] + $failed);
			throw $e;
		}
	}//end cascadeInOneTransaction()

	/**
	 * Refuse on a kept row unless the owner lifted that, and record a forced teardown.
	 *
	 * @param Schema                 $schema   The schema.
	 * @param SchemaTeardownOverride $override The owner's acknowledged lifts.
	 *
	 * @return void
	 */
	private function recordKeptRowsLifted(Schema $schema, SchemaTeardownOverride $override): void {
		$forcedRows = $this->keptRowsLifted(schema: $schema, override: $override);
		$this->teardownGuard()->recordForced(schema: $schema, keptRows: $forcedRows);
	}//end recordKeptRowsLifted()

	/**
	 * Audit and hard-delete the objects of every table with a register.
	 *
	 * @param Schema $schema The schema being torn down.
	 * @param array<int, array{registerId: int, register: Register|null, tableName: string}> $tables The tables.
	 *
	 * @return array{deletedCount: int, deletedUuids: array<int, string>} What was removed.
	 */
	private function deleteObjectsOfTables(Schema $schema, array $tables): array {
		$removed = ['deletedCount' => 0, 'deletedUuids' => []];

		foreach ($tables as $entry) {
			if ($entry['register'] === null) {
				// The magic table outlives its register (stale pair). There is no
				// Register entity to read rows through, so nothing can be audited
				// or deleted here — but the table is still dropped in phase 2.
				continue;
			}

			$result = $this->deleteObjectsOfPair(register: $entry['register'], schema: $schema, hardDelete: true, triggeredBy: self::TRIGGER_SCHEMA_DELETION);
			$removed['deletedCount'] += $result['deleted_count'];
			$removed['deletedUuids'] = array_merge($removed['deletedUuids'], $result['deleted_uuids']);
		}

		return $removed;
	}//end deleteObjectsOfTables()

	/**
	 * How many rows of the schema refuse a teardown unless it is forced.
	 *
	 * @param Schema $schema The schema to count for.
	 *
	 * @return int The number of frozen, archived, locked or unreadable rows.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function countKeptRows(Schema $schema): int {
		return $this->teardownGuard()->countKept(
			tables: $this->resolveMagicTablesForSchema(schema: $schema),
			schema: $schema
		);
	}//end countKeptRows()

	/**
	 * Refuse the teardown, or report how many kept rows the override lifts.
	 *
	 * @param Schema                 $schema   The schema to tear down.
	 * @param SchemaTeardownOverride $override What the operator lifted.
	 *
	 * @return int The kept rows that will be destroyed, 0 when none are lifted.
	 */
	private function keptRowsLifted(Schema $schema, SchemaTeardownOverride $override): int {
		if ($override->keptRows === true) {
			return $this->countKeptRows(schema: $schema);
		}

		$this->teardownGuard()->assertNoneKept(
			tables: $this->resolveMagicTablesForSchema(schema: $schema),
			schema: $schema
		);

		return 0;
	}//end keptRowsLifted()

	/**
	 * How many objects `cascadeDeleteSchema()` would destroy.
	 *
	 * THE GUARD AND THE DELETE MUST LOOK AT THE SAME ROWS. They did not: the
	 * prune command counted through the registers that REFERENCE a schema and
	 * through `MagicSearchHandler`, which applies RBAC. Both narrow the set.
	 *
	 * Under `occ` there is no session, so the RBAC filter reads as Anonymous and
	 * rows nobody anonymous may see counted as zero. And a schema referenced by
	 * no register counted zero by construction — which is exactly the state a
	 * half-pruned schema is left in. Either way the operator read "0 objects,
	 * safe to delete" and the delete dropped the table.
	 *
	 * This counts what the delete enumerates: every magic table resolved for the
	 * schema, including ones whose register no longer references it.
	 *
	 * @param Schema $schema The schema to count for.
	 *
	 * @return int The number of objects a cascade delete would remove.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function countObjectsCascadeWouldDelete(Schema $schema): int {
		$total = 0;
		foreach ($this->resolveMagicTablesForSchema(schema: $schema) as $entry) {
			$total += $this->rowsCascadeWouldDelete(entry: $entry, schema: $schema);
		}

		return $total;
	}//end countObjectsCascadeWouldDelete()

	/**
	 * The rows a cascade would delete from one magic table; one when that cannot be known.
	 *
	 * @param array  $entry  The table entry (registerId, register, tableName).
	 * @param Schema $schema The schema.
	 *
	 * @return int
	 */
	private function rowsCascadeWouldDelete(array $entry, Schema $schema): int {
		if ($entry['register'] === null) {
			// A table outliving its register: the delete drops it without
			// reading it, so it removes an unknown number of rows. Counting
			// it as at least one keeps the guard on the side of the data.
			return 1;
		}

		try {
			$query = ['_rbac' => false, '_multitenancy' => false, '_includeDeleted' => true];
			return $this->magicMapper->countObjectsInRegisterSchemaTable(query: $query, register: $entry['register'], schema: $schema);
		} catch (Throwable $e) {
			// An unreadable table cannot be counted. Treat it as non-empty so
			// the guard errs towards keeping data.
			return 1;
		}
	}//end rowsCascadeWouldDelete()

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
			$droppable = $this->emptyTableNames(tables: $tables, schemaId: $schemaId);
			$allDropped = $this->dropTables(tableNames: $droppable, schemaId: $schemaId);

			return ($allDropped === true && count($droppable) === count($tables));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaDeletionService] Could not reclaim magic tables of a deleted schema',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
			);

			return false;
		}
	}//end dropEmptyTablesForSchema()

	/**
	 * The names of the tables that hold no row at all; a non-empty one is kept and logged.
	 *
	 * @param array $tables   The schema's magic tables.
	 * @param int   $schemaId The schema id.
	 *
	 * @return string[]
	 */
	private function emptyTableNames(array $tables, int $schemaId): array {
		$droppable = [];
		foreach ($tables as $entry) {
			$rowCount = $this->teardownGuard()->countAllRows(tableName: $entry['tableName']);
			if ($rowCount > 0) {
				$kept = ['schemaId' => $schemaId, 'tableName' => $entry['tableName'], 'rowCount' => $rowCount];
				$message = '[SchemaDeletionService] Keeping non-empty magic table of a deleted schema';
				$this->logger->warning(message: $message, context: ['file' => __FILE__, 'line' => __LINE__] + $kept);
				continue;
			}

			$droppable[] = $entry['tableName'];
		}

		return $droppable;
	}//end emptyTableNames()

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
			context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => (int)$schema->getId(), 'schemaSlug' => $schema->getSlug()]
				+ ['operation' => $operation]
		);

		throw new ArchivalImmutableException(
			schemaIdentifier: ($schema->getSlug() ?? (string)$schema->getId()),
			operation: $operation
		);
	}//end rejectIfArchivalImmutable()

	/**
	 * The guard of a schema teardown.
	 *
	 * @return SchemaTeardownGuard
	 */
	private function teardownGuard(): SchemaTeardownGuard {
		return new SchemaTeardownGuard(mapper: $this->magicMapper, db: $this->db, audit: $this->auditTrailMapper);
	}//end teardownGuard()

	/**
	 * Check, audit and delete schemas of one register in one transaction.
	 *
	 * Any failure rolls the whole thing back: a committed audit entry always implies a
	 * deleted object, and a row that refuses the delete leaves every row in place.
	 *
	 * @param Register $register The register context.
	 * @param array<Schema> $schemas The schemas to empty.
	 * @param bool $hardDelete True to remove the rows, false to soft-delete them.
	 * @param string $triggeredBy Audit trigger context.
	 *
	 * @return array{deleted_count: int, deleted_uuids: array<int, string>} The deletion result.
	 *
	 * @throws Throwable If a row refuses the delete, or the deletion fails.
	 */
	private function deleteInOneTransaction(Register $register, array $schemas, bool $hardDelete, string $triggeredBy): array {
		$this->db->beginTransaction();
		try {
			$removed = $this->deleteSchemasOfRegister(register: $register, schemas: $schemas, hardDelete: $hardDelete, triggeredBy: $triggeredBy);
			$this->db->commit();
			return $removed;
		} catch (Throwable $e) {
			$this->db->rollBack();
			$failed = ['registerId' => (int)$register->getId(), 'error' => $e->getMessage()];
			$message = '[SchemaDeletionService] Whole-table delete rolled back';
			$this->logger->error(message: $message, context: ['file' => __FILE__, 'line' => __LINE__] + $failed);
			throw $e;
		}
	}//end deleteInOneTransaction()

	/**
	 * Refuse on any kept row first, then delete every schema's rows of the register.
	 *
	 * @param Register $register    The register.
	 * @param Schema[] $schemas     The schemas.
	 * @param bool     $hardDelete  Whether to hard-delete.
	 * @param string   $triggeredBy The trigger recorded in the audit.
	 *
	 * @return array{deleted_count: int, deleted_uuids: string[]}
	 */
	private function deleteSchemasOfRegister(Register $register, array $schemas, bool $hardDelete, string $triggeredBy): array {
		foreach ($schemas as $schema) {
			$this->teardownGuard()->rows->assertNoneKept(register: $register, schema: $schema);
		}

		$removed = ['deleted_count' => 0, 'deleted_uuids' => []];
		foreach ($schemas as $schema) {
			$result = $this->deleteObjectsOfPair(register: $register, schema: $schema, hardDelete: $hardDelete, triggeredBy: $triggeredBy);
			$removed['deleted_count'] += $result['deleted_count'];
			$removed['deleted_uuids'] = array_merge($removed['deleted_uuids'], $result['deleted_uuids']);
		}

		return $removed;
	}//end deleteSchemasOfRegister()

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
			return ['deleted_count' => 0, 'deleted_uuids' => []];
		}

		// Snapshot BEFORE deleting: the mapper primitive returns only a count, so the
		// pre-deletion state has to be captured here or it is unrecoverable.
		$deletedUuids = $this->auditObjectsBeforeDeletion(register: $register, schema: $schema, hardDelete: $hardDelete, triggeredBy: $triggeredBy);
		$deletedCount = $this->magicMapper->deleteObjectsBySchema(register: $register, schema: $schema, hardDelete: $hardDelete);

		return ['deleted_count' => $deletedCount, 'deleted_uuids' => $deletedUuids];
	}//end deleteObjectsOfPair()

	/**
	 * Write one hash-chained audit entry per object, capturing its full pre-delete state.
	 *
	 * Resolves the schema-derived audit context once for the whole operation rather
	 * than once per object. Written through AuditTrailMapper so ADR-003's SHA-256
	 * hash-chaining is applied at insert time, and inside the caller's transaction so
	 * a rollback discards it.
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
	private function auditObjectsBeforeDeletion(Register $register, Schema $schema, bool $hardDelete, string $triggeredBy): array {
		$baseContext = $this->cascadeAuditContext(register: $register, schema: $schema, hardDelete: $hardDelete, triggeredBy: $triggeredBy);

		$uuids = [];
		foreach ($this->teardownGuard()->rows->all(register: $register, schema: $schema) as $object) {
			$context = (['snapshot' => $object->jsonSerialize()] + $baseContext);
			$this->auditTrailMapper->createAuditTrailEntry(object: $object, action: self::ACTION_CASCADE_DELETE, context: $context);
			if ($object->getUuid() !== null) {
				$uuids[] = (string)$object->getUuid();
			}
		}

		return $uuids;
	}//end auditObjectsBeforeDeletion()

	/**
	 * The audit context every row of one register-schema delete shares.
	 *
	 * @param Register $register    The register.
	 * @param Schema   $schema      The schema.
	 * @param bool     $hardDelete  Whether the delete is hard.
	 * @param string   $triggeredBy The trigger.
	 *
	 * @return array<string, mixed>
	 */
	private function cascadeAuditContext(Register $register, Schema $schema, bool $hardDelete, string $triggeredBy): array {
		// Schema-derived values: resolved ONCE for the whole operation (ADR-009).
		return [
			'triggeredBy' => $triggeredBy,
			'hardDelete' => $hardDelete,
			'cascadeContext' => [
				'triggerSchema' => $schema->getSlug(),
				'triggerSchemaId' => (int)$schema->getId(),
				'registerId' => (int)$register->getId(),
			],
		];
	}//end cascadeAuditContext()

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
			$register = $this->registerOfTable(registerId: $registerId, schemaId: $schemaId);
			$tableName = MagicMapperInterface::TABLE_PREFIX . $registerId . '_' . $schemaId;
			$tables[] = ['registerId' => $registerId, 'register' => $register, 'tableName' => $tableName];
		}

		return $tables;
	}//end resolveMagicTablesForSchema()

	/**
	 * The register a magic table belongs to, or null (logged) when it no longer resolves.
	 *
	 * @param int $registerId The register id from the table pair.
	 * @param int $schemaId   The schema id.
	 *
	 * @return Register|null
	 */
	private function registerOfTable(int $registerId, int $schemaId): ?Register {
		try {
			return $this->registerMapper->find(id: $registerId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SchemaDeletionService] Magic table has no register entity',
				context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $registerId, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end registerOfTable()

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
			if ($this->dropTable(tableName: $tableName, schemaId: $schemaId) === false) {
				$allDropped = false;
			}
		}

		return $allDropped;
	}//end dropTables()

	/**
	 * Drop one magic table; a failure is logged and reported, never thrown.
	 *
	 * @param string $tableName The table.
	 * @param int    $schemaId  The schema id.
	 *
	 * @return bool Whether it was dropped.
	 */
	private function dropTable(string $tableName, int $schemaId): bool {
		try {
			$this->magicMapper->dropTable(tableName: $tableName);
			return true;
		} catch (Throwable $e) {
			// The objects and the schema are already gone and committed. Failing the
			// request now would report failure for work that succeeded, and there is
			// nothing to roll back to — DDL is not rollbackable on MySQL/MariaDB.
			// The leftover table is empty and reclaimable later.
			$this->logger->warning(
				message: '[SchemaDeletionService] Could not drop magic table after schema deletion',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'tableName' => $tableName, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end dropTable()
}//end class
