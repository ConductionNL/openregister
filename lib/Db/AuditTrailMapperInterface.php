<?php

/**
 * AuditTrailMapperInterface: the public surface of AuditTrailMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail\ExportOutcome;
use OCA\OpenRegister\Db\AuditTrail\HardeningChange;
use OCA\OpenRegister\Db\AuditTrail\PurposeRefusal;
use OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusal;
use OCA\OpenRegister\Db\AuditTrail\SlugBackfill;
use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
use OCA\OpenRegister\Db\AuditTrail\ActionScope;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCP\AppFramework\Db\Entity;

/**
 * Every public method of AuditTrailMapper. Callers depend on this interface, not on the class.
 */
interface AuditTrailMapperInterface {

	/**
	 * Set the request-scoped import-job UUID stamped on all
	 * `createAuditTrail()` rows for the duration of an import. Pass
	 * `null` to clear (typically in the import's `finally` block).
	 *
	 * @param string|null $importJobId UUID v4 of the active import job, or null to clear.
	 *
	 * @return void
	 */
	public function setRequestImportJobId(?string $importJobId): void;

	/**
	 * Get the currently-active request-scoped import-job UUID. Returns
	 * null when no import is in flight.
	 *
	 * @return string|null
	 */
	public function getRequestImportJobId(): ?string;

	/**
	 * Find audit-trail entries tagged with the given import-job UUID.
	 *
	 * Used by `ImportService::softDeleteByImportJobId()` to identify
	 * the objects created during a specific import for rollback.
	 *
	 * @param string $importJobId UUID of the import job to look up.
	 * @param string|null $action Optional action filter (e.g. `'create'`); null returns all actions.
	 *
	 * @return AuditTrail[] Matching audit trail rows.
	 */
	public function findByImportJobId(string $importJobId, ?string $action = 'create'): array;

	/**
	 * Count the audit rows tagged with an import-job UUID.
	 *
	 * The cheap question behind "did this import create anything that can be
	 * removed by job": it reads the same rows softDeleteByImportJobId() reads,
	 * without loading their payloads.
	 *
	 * @param string      $importJobId UUID of the import job.
	 * @param string|null $action      Action filter (e.g. `'create'`); null counts every action.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-job-id-of-an-app-import-that-created-objects-must-be-recorded-per-app
	 */
	public function countByImportJobId(string $importJobId, ?string $action = 'create'): int;

	/**
	 * The UUIDs of the objects an import job created, oldest first.
	 *
	 * @param string $importJobId UUID of the import job.
	 *
	 * @return array<int, string> Distinct object UUIDs from the job's `create` rows.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-the-cli-purge-must-accept-an-import-job-instead-of-a-list-of-uuids
	 */
	public function objectUuidsByImportJobId(string $importJobId): array;

	/**
	 * The change history of one object, oldest first, for deriving a projection.
	 *
	 * Purged rows are excluded, not skipped afterwards: a tombstoned row's
	 * `changed` is an empty object, so including it would read as "every field
	 * became nothing at that moment" and write an interval that never happened.
	 *
	 * @param string $objectUuid The object.
	 * @param int    $limit      Most rows to read.
	 *
	 * @return array<int, array{created: string, changed: array}> The changes.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function findChangesForObject(string $objectUuid, int $limit = 1000): array;

	/**
	 * Object uuids carrying audit rows, in uuid order, after a cursor.
	 *
	 * The cursor is what makes a rebuild resumable: a run takes the next batch
	 * and stops, and the next run starts where it left off rather than at the
	 * beginning of a table with millions of rows in it.
	 *
	 * @param string $afterUuid The cursor; '' starts at the beginning.
	 * @param int    $limit     Most uuids to return.
	 *
	 * @return string[] The uuids.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function findObjectUuidsAfter(string $afterUuid, int $limit): array;

	/**
	 * The newest purged moment per object, for pruning what derives from it.
	 *
	 * A projection is derived data. When the payload it was derived from is
	 * destroyed, the derivation has to go too, or a filter answers about a
	 * record nothing else can show.
	 *
	 * @param int $limit Most objects to report on.
	 *
	 * @return array<string, string> object uuid => newest purged row's `created`.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function findPurgedHorizons(int $limit): array;

	/**
	 * Finds an audit trail by id
	 *
	 * @param int $id The id of the audit trail
	 *
	 * @return AuditTrail The audit trail
	 */
	public function find(int $id): AuditTrail;

	/**
	 * Find all audit trails with filters and sorting
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $sort The sort to apply
	 * @param string|null $search Optional search term to filter by ext fields
	 *
	 * @return AuditTrail[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\AuditTrail>
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Complex query building requires many conditional paths
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function findAll(
		?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = ['created' => 'DESC'], ?string $search = null
	): array;

	/**
	 * Creates an audit trail for object changes
	 *
	 * @param ObjectEntity|null $old The old state of the object
	 * @param ObjectEntity|null $new The new state of the object
	 * @param string|null $action The action to record. NULL (the default) infers it from which
	 *                            entities are present: no `new` is a delete, no `old` is a create,
	 *                            otherwise an update. A NAMED action is used verbatim — the
	 *                            inference used to overwrite an explicit 'update' because it was
	 *                            indistinguishable from the old default (#2217).
	 * @param array|null $cascadeContext Optional referential-integrity cascade context. When
	 *                                   non-null, the context is folded into the `changed`
	 *                                   column of the initial INSERT (previously this required
	 *                                   a second UPDATE per row, which also invalidated the
	 *                                   hash-chain seal computed at insert time).
	 *
	 * @return AuditTrail The created audit trail
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          Uuid::v4 is standard Symfony UID pattern
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Audit trail creation requires handling many optional fields
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function createAuditTrail(
		?ObjectEntity $old = null, ?ObjectEntity $new = null, ?string $action = null, ?array $cascadeContext = null
	): AuditTrail;

	/**
	 * Build (but do not persist) an audit trail entity for object changes.
	 *
	 * Contains the complete row-building logic shared by {@see createAuditTrail()}
	 * (single insert) and {@see insertAuditTrails()} (batched multi-row insert),
	 * so both paths produce identical audit rows.
	 *
	 * @param AuditedChange $change The old and new state of the object; the action to record (NULL
	 *                              infers it from which entities are present: no `new` is a delete,
	 *                              no `old` is a create, otherwise an update; a NAMED action is used
	 *                              verbatim, #2217); and the optional referential-integrity cascade
	 *                              context folded into the `changed` column (keys: triggerObject,
	 *                              triggerSchema, action_type, property).
	 *
	 * @return AuditTrail The populated (unpersisted) audit trail entity
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function buildEntry(AuditedChange $change): AuditTrail;

	/**
	 * Stamp an audit row's expiry and retention source from the retention of
	 * the object it describes.
	 *
	 * The one place every audit writer takes its expiry from. Rows built here
	 * got it in or#2265; the referential-integrity rows and the file audit
	 * rows kept a flat `+30 days` until or#4101, so the record of why a
	 * reference was cleared, or which file of a record under legal hold was
	 * renamed, was purged a month later. A writer that builds its own row
	 * calls this before inserting it, so the expiry is part of the sealed
	 * canonical JSON.
	 *
	 * Without an object (it could not be found) the row is retained
	 * indefinitely: the failure being guarded against is evidence
	 * disappearing, not disk filling.
	 *
	 * @param AuditTrail $auditTrail The row to stamp.
	 * @param ObjectEntity|null $objectEntity The object the row describes, or null when it could not be found.
	 *
	 * @return AuditTrail The same row, stamped.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function applyRetentionExpiry(AuditTrail $auditTrail, ?ObjectEntity $objectEntity): AuditTrail;

	/**
	 * Create audit trail rows for many object changes with batched inserts.
	 *
	 * Each entry is an array with keys `old` (?ObjectEntity), `new` (?ObjectEntity)
	 * and `action` (string). Rows are built through the exact same
	 * {@see buildEntry()} logic as {@see createAuditTrail()} so their content
	 * is identical, but they are persisted with one multi-row INSERT per chunk of
	 * 100 instead of one INSERT (plus 3 hash-chain queries) per row. After each
	 * chunk the rows are sealed into the SHA-256 hash chain in a single batched
	 * pass ({@see AuditHashService::sealRows()}). Sealing stays fail-soft exactly
	 * like the single-row path: a sealing hiccup logs and leaves rows unhashed
	 * rather than losing the audit records.
	 *
	 * @param array $entries Mixed list: ['old' => ?ObjectEntity, 'new' => ?ObjectEntity, 'action' => string,
	 *                       'cascadeContext' => ?array] entries built through {@see buildEntry()}, and/or
	 *                       pre-built AuditTrail entities (each must carry a uuid) persisted as-is
	 * @param int $chunkSize Number of rows per multi-row INSERT statement
	 *
	 * @return AuditTrail[] The persisted audit trail entities (ids populated)
	 *
	 * @throws \OCP\DB\Exception If a database error occurs during insert
	 *
	 * @psalm-return list<AuditTrail>
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function insertAuditTrails(array $entries, int $chunkSize = 100): array;

	/**
	 * Get the audit trail entries made after a point in an object's history
	 *
	 * These are the entries a revert to that point undoes, newest first. The
	 * object is matched on `object_uuid`: the table has no `object_id` column,
	 * and a filter on one made every revert fail (#4161).
	 *
	 * - A DateTime returns the entries created at or after it.
	 * - An audit trail id (int or numeric string) returns this object's entries
	 *   after that entry, so a revert to it restores the state it recorded.
	 * - A semantic version returns the entries after the last one that recorded
	 *   that version.
	 * - Null returns every entry of the object.
	 *
	 * @param string                   $objectUuid The object UUID
	 * @param DateTime|int|string|null $until      DateTime, AuditTrail ID, or semantic version
	 *
	 * @return AuditTrail[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\AuditTrail>
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function findByObjectUntil(string $objectUuid, DateTime|int|string|null $until = null): array;

	/**
	 * Revert an object to a previous state
	 *
	 * @param ObjectEntity|string|int $identifier The object itself, or its ID, UUID or URI to look it up by
	 * @param DateTime|string|null $until DateTime or AuditTrail ID to revert to
	 * @param bool $overwriteVersion Whether to overwrite the version or increment it
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
	 * @throws \Exception If revert fails
	 *
	 * @return ObjectEntity The reverted object (unsaved)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Controls version handling strategy on revert
	 */
	public function revertObject($identifier, $until = null, bool $overwriteVersion = false): ObjectEntity;

	/**
	 * Get statistics for audit trails with optional filtering
	 *
	 * @param int|null $registerId The register ID (null for all registers)
	 * @param int|null $schemaId The schema ID (null for all schemas)
	 * @param array $exclude Array of register/schema combinations to exclude,
	 *                       format: [['register' => id, 'schema' => id], ...]
	 *
	 * @return int[] Array containing total count and size of audit trails:
	 *               - total: Total number of audit trails
	 *               - size: Total size of all audit trails in bytes
	 *
	 * @psalm-return array{total: int, size: int}
	 */
	public function getStatistics(?int $registerId = null, ?int $schemaId = null, array $exclude = []): array;

	/**
	 * Updates an entity in the database
	 *
	 * @param AuditTrail $entity The entity to update
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
	 *
	 * @return AuditTrail The updated entity
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function update(Entity $entity): AuditTrail;

	/**
	 * Get chart data for audit trail actions over time
	 *
	 * @param DateTime|null $from Start date for the chart data
	 * @param DateTime|null $till End date for the chart data
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 *
	 * @return ((int[]|string)[]|(int|string))[][]
	 *
	 * @psalm-return array{labels: list<array-key>,
	 *     series: list<array{data: list<int>, name: string}>}
	 */
	public function getActionChartData(?\DateTime $from = null, ?\DateTime $till = null, ?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get detailed statistics for audit trails including action counts within timeframe
	 *
	 * @param AuditTrail\AuditStatisticsScope $scope The register and schema to filter by (each optional) and the hours to look
	 *                                    back for recent activity (default: 24).
	 *
	 * @return int[]
	 *
	 * @psalm-return array{total: int, creates: int, updates: int,
	 *     deletes: int, reads: int}
	 */
	public function detailedStatistics(AuditTrail\AuditStatisticsScope $scope): array;

	/**
	 * Lifetime row counts per action, for the actions that start with a prefix
	 *
	 * An app that writes its own audit actions, such as portaliq's
	 * `portaliq.login`, counts them here in one grouped query instead of loading
	 * every row. The prefix is matched literally: `_` and `%` are not wildcards.
	 *
	 * @param string $prefix The action prefix, for example `portaliq.`.
	 *
	 * @return array<string, int> Keyed by the full action, e.g. ['portaliq.login' => 1204].
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md
	 */
	public function countByActionPrefix(string $prefix): array;

	/**
	 * Get lifetime audit trail counts grouped by action
	 *
	 * Unlike detailedStatistics(), which windows the per-action counts to
	 * the last N hours, this returns lifetime counts so the audit-trail page
	 * statistics line up with the (unwindowed) audit-trail list beside them.
	 * Keys are singular to match the audit-trail REST surface; the windowed
	 * dashboard surface uses plural keys.
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 *
	 * @return int[] Total plus a lifetime count per action. The total counts
	 *               every row, including rows whose action is outside the four
	 *               known ones.
	 *
	 * @psalm-return array{total: int, create: int, update: int,
	 *     delete: int, read: int}
	 */
	public function getActionCounts(?int $registerId = null, ?int $schemaId = null): array;

	/**
	 * Get action distribution data with percentages
	 *
	 * @param AuditTrail\AuditStatisticsScope $scope The register and schema to filter by (each optional) and the hours to look
	 *                                    back (default: 24).
	 *
	 * @return (int|mixed)[][][]
	 *
	 * @psalm-return array{actions: list<array{count: int, name: mixed}>}
	 */
	public function actionDistribution(AuditTrail\AuditStatisticsScope $scope): array;

	/**
	 * Get most active objects based on audit trail activity
	 *
	 * @param AuditTrail\AuditStatisticsScope $scope The register and schema to filter by (each optional), the number of results
	 *                                    (default: 10) and the hours to look back (default: 24).
	 *
	 * @return (int|mixed|string)[][][]
	 *
	 * @psalm-return array{objects: list<array{count: int, id: mixed, name: string}>}
	 */
	public function mostActiveObjects(AuditTrail\AuditStatisticsScope $scope): array;

	/**
	 * Tombstone every audit-trail entry.
	 *
	 * ⚠️ Tombstones rather than deletes: the payload is destroyed but the row and
	 * its chain links survive, because the hash chain is what makes the trail
	 * evidence. Deleting rows would break the chain and leave nothing to show
	 * that anything was ever there.
	 *
	 * @return bool True when the tombstone write succeeded.
	 */
	public function clearLogs(): bool;

	/**
	 * Count the audit rows that belong to one object, excluding the evidence
	 * of its own destruction.
	 *
	 * Already-tombstoned rows are excluded: their payload is gone, so there is
	 * nothing left to destroy and counting them would make a preview promise
	 * work it will not do.
	 *
	 * @param string      $objectUuid The object's UUID.
	 * @param ActionScope $scope      The actions included (null for every action) and the actions never
	 *                                touched, such as the destruction record.
	 *
	 * @return int The number of rows that would be tombstoned.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countObjectTrail(string $objectUuid, ActionScope $scope): int;

	/**
	 * Read the audit rows of one object, by action, newest first.
	 *
	 * Keyed on `object_uuid` rather than the numeric `object` id, because the
	 * caller this exists for reads a destruction record AFTER the object row
	 * is gone and the numeric id resolves to nothing.
	 *
	 * @param string $objectUuid The object's UUID.
	 * @param array<int, string> $actions Actions to return; every action when empty.
	 * @param int $limit Maximum rows.
	 *
	 * @return array<int, AuditTrail> The matching rows, newest first.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function findForObjectByAction(string $objectUuid, array $actions = [], int $limit = 50): array;

	/**
	 * Tombstone the audit rows that belong to one object.
	 *
	 * ⚠️ Tombstones rather than deletes, for the same reason {@see clearLogs()}
	 * does: the table carries a SHA-256 chain and physically removing a row
	 * mid-chain is indistinguishable from tampering (or#2265). The payload and
	 * the personal identifiers go; the row, its `created` and its hash pair
	 * stay, so the destruction stays provable.
	 *
	 * Rows whose action is listed in `$excludeActions` are never touched. That
	 * is how the evidence of a destruction survives the destruction it
	 * describes.
	 *
	 * @param string      $objectUuid The object's UUID.
	 * @param ActionScope $scope      The actions included (null for every action) and the actions never
	 *                                touched, such as the destruction record.
	 *
	 * @return int The number of rows tombstoned.
	 *
	 * @throws \Exception When the statement fails.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function tombstoneObjectTrail(string $objectUuid, ActionScope $scope): int;

	/**
	 * Clear all audit trail logs (not just expired ones)
	 *
	 * This method deletes all audit trail logs from the database
	 *
	 * @return bool True if any logs were deleted, false otherwise
	 *
	 * @throws \Exception If the deletion fails
	 */
	public function clearAllLogs(): bool;

	/**
	 * Set expiry dates for audit trails based on retention period in milliseconds
	 *
	 * Updates the expires column for audit trails based on their creation date plus the retention period.
	 * Only affects audit trails that don't already have an expiry date set.
	 *
	 * @param int $retentionMs Retention period in milliseconds
	 *
	 * @return int Number of audit trails updated
	 *
	 * @throws \Exception Database operation exceptions
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-support-minimum-10-year-retention
	 */
	public function setExpiryDate(int $retentionMs): int;

	/**
	 * Get audit trail statistics grouped by schema for multiple schemas in a single query
	 *
	 * Returns per-schema statistics using GROUP BY instead of one query per schema.
	 * This replaces N individual getStatistics() calls with 1 query.
	 *
	 * @param int[] $schemaIds Array of schema IDs to get statistics for
	 *
	 * @return array<int, array{total: int, size: int}> Map of schemaId => statistics array
	 */
	public function getStatisticsGroupedBySchema(array $schemaIds): array;

	/**
	 * Create a custom audit trail entry for archival operations, or for any
	 * other caller that needs a non-CRUD action recorded with an explicit
	 * actor.
	 *
	 * `$actorId`/`$actorName` exist for callers acting on behalf of a
	 * non-human principal — e.g. `registry-subscriptions`' inbound update
	 * endpoint, which authenticates as a connector's app-password account
	 * but must audit the REGISTRY (`registry:brp`) as the actor, not
	 * whichever Nextcloud account the app password happens to belong to.
	 * Omit both to keep the previous session-derived behavior unchanged.
	 *
	 * @param ObjectEntity $object The object the entry relates to
	 * @param string $action The archival action (e.g., archival.destroyed)
	 * @param array $context Additional context data
	 * @param string|null $actorId Explicit actor id, bypassing the session user. Null uses the session.
	 * @param string|null $actorName Explicit actor display name, paired with $actorId.
	 * @param string|null $ipAddress The calling address, for callers that have one and must record it.
	 *
	 * `$ipAddress` exists for a principal that is not a session at all: an
	 * access link is opened by somebody with no account, and reconstructing a
	 * wrong publication needs where it was opened from as much as when. Omit it
	 * and the column is left exactly as before, so no existing caller changes.
	 *
	 * @return AuditTrail The created audit trail entry
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function createAuditTrailEntry(
		ObjectEntity $object, string $action, array $context = [], ?string $actorId = null, ?string $actorName = null,
		?string $ipAddress = null
	): AuditTrail;

	/**
	 * Record a non-CRUD action on one object, with an explicit actor when one is named.
	 *
	 * @param AuditTrail\ObjectAuditEvent $event The object, action, context, and the explicit actor and address.
	 *
	 * @return AuditTrail The created audit trail entry
	 */
	public function recordObjectEvent(AuditTrail\ObjectAuditEvent $event): AuditTrail;

	/**
	 * Create one immutable, hash-chained audit record for an MCP tool
	 * invocation (ADR-063 chain 2/3, EU AI Act art.12/14).
	 *
	 * Reuses the existing `AuditTrail` table and `AuditHashService` hash
	 * chain unchanged — `action` is namespaced `mcp.{verb}` (e.g.
	 * `mcp.search`) so it never collides with the plain `create` / `update`
	 * / `delete` / `read` object-CRUD actions that statistics code buckets
	 * on. Acting identity is resolved from the ambient Nextcloud session,
	 * exactly like {@see createAuditTrail()} — the IMcpToolProvider ABI does
	 * not thread an acting-agent identity into `invokeTool()`, so a
	 * non-human agent principal cannot be distinguished from the session's
	 * human user yet (tracked as a DEFERRED_QUESTION coordinated with
	 * Hermiq's `agent-tool-governance-and-disclosure` change).
	 *
	 * @param ToolInvocation $invocation The tool id, the SHA-256 digest of its canonical arguments (never raw
	 *                                   values), the outcome summary and the register, schema and object it touched.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-006 — Every invocation is audited)
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 */
	public function recordToolInvocation(ToolInvocation $invocation): AuditTrail;

	/**
	 * Get processing activities from audit trail entries.
	 *
	 * Groups audit trail entries by processing activity ID and returns
	 * summary statistics for each activity.
	 *
	 * @param string|null $organisationId Optional organisation filter
	 *
	 * @return array List of processing activity summaries
	 */
	public function getProcessingActivities(?string $organisationId = null): array;

	/**
	 * Find audit trail entries by identifier.
	 *
	 * Searches the changed JSON field for entries matching the given identifier,
	 * grouped by schema.
	 *
	 * @param string $identifier The identifier to search for
	 *
	 * @return array Matching audit trail entries grouped by schema
	 */
	public function findByIdentifier(string $identifier): array;

	/**
	 * Find audit trail entries by the actor (user UID) that produced them.
	 *
	 * Returns an associative array with `results` (array of AuditTrail entities)
	 * and `total` (unbounded count matching the filters).
	 *
	 * @param string $userId The actor UID to filter on.
	 * @param int $limit Maximum number of rows to return.
	 * @param int $offset Row offset for pagination.
	 * @param string|null $action Optional action filter (create|update|delete).
	 * @param string|null $from Optional ISO-8601 start date (inclusive).
	 * @param string|null $to Optional ISO-8601 end date (inclusive).
	 *
	 * @return array{results: array<int, AuditTrail>, total: int}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function findByActor(
		string $userId, int $limit = 25, int $offset = 0, ?string $action = null, ?string $from = null, ?string $to = null
	): array;

	/**
	 * Create one immutable, hash-chained audit record for a write that was
	 * refused because the resource is shared master data held by another
	 * organisation.
	 *
	 * A gemeenschappelijke regeling shares its code lists, its case types and
	 * its parties across several legal entities, and the read path now hands a
	 * consumer the holder's rows. The moment a consumer can SEE the holder's
	 * code list, "they tried to change it" becomes a question an auditor asks
	 * and a 403 alone cannot answer. This is the answer.
	 *
	 * Object-less by construction, following
	 * {@see recordPartyQueryRefusal()}: there is no object, because the
	 * write never happened. `object` has been nullable since
	 * Version1Date20260423100000 for exactly this shape of row.
	 *
	 * 🔑 THE ACTOR IS PSEUDONYMOUS AND THE CONTEXT IS REDACTED BEFORE IT
	 * ARRIVES. This method persists what it is handed; REQ-SLE-003 is enforced
	 * by {@see \OCA\OpenRegister\Service\TenantLogRedactor} at the call site,
	 * because the same context also goes to the log and the two must not
	 * disagree about what a line may carry.
	 *
	 * @param SharedMasterDataRefusal $refusal The holding and the attempting organisation, the resource type
	 *                                         (`register` or `schema`), and the already-redacted context, the
	 *                                         target and the pseudonymous actor.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern, as recordPartyQueryRefusal.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function recordSharedMasterDataRefusal(SharedMasterDataRefusal $refusal): AuditTrail;

	/**
	 * Create one immutable, hash-chained audit record for a party query that
	 * was refused for exceeding the administered cap.
	 *
	 * A refusal is the interesting event, not the search: proportionality is
	 * the duty a functionaris gegevensbescherming asks about, and "who tried
	 * to pull four hundred people out of the register" is the question the
	 * trail has to answer. The entry carries no result, because there was
	 * none — that is the point of a refusal rather than a truncation.
	 *
	 * The query text is recorded verbatim so the attempt can be read back.
	 * It is a search term an authenticated caller typed, not a record about a
	 * person, and the trail is already the instance's protected surface.
	 *
	 * @param AuditTrail\PartyQueryRefusal $refusal The query that was refused, the administered maximum result count, how many
	 *                                   parties it would have matched, and the schema searched when it named one.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern, as recordToolInvocation.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function recordPartyQueryRefusal(AuditTrail\PartyQueryRefusal $refusal): AuditTrail;

	/**
	 * Create one immutable, hash-chained audit record for a read that was
	 * refused because it named no usable purpose.
	 *
	 * The refusal is the interesting event, exactly as it is for a party query
	 * over the cap: "who tried to read the BRP without naming a grondslag" is
	 * a question the functionaris gegevensbescherming asks, and a refusal that
	 * leaves no trace answers it with silence.
	 *
	 * The purpose is quoted back verbatim. It is a short administered code the
	 * caller itself sent, not a record about a person.
	 *
	 * @param PurposeRefusal $refusal Which refusal it was (one of PurposeRefusedException's RULE_ constants),
	 *                                the purpose the caller named, and the register and schema being read.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern, as recordPartyQueryRefusal.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function recordPurposeRefusal(PurposeRefusal $refusal): AuditTrail;

	/**
	 * Count audit rows per purpose over a period.
	 *
	 * This is the report the doelbinding requirement exists for: "a month of
	 * queries under three purposes, each purpose carrying its own count". It
	 * groups on the `purpose` COLUMN rather than on the sealed copy in
	 * `result_summary`, because a `GROUP BY` inside a JSON document is not
	 * portable across the databases this app supports, which is exactly why
	 * the column exists.
	 *
	 * Rows with no purpose are counted too, under the key `null`. An
	 * unattributed read is a finding, not a row to hide from the report.
	 *
	 * @param DateTime|null $from Start of the period, inclusive.
	 * @param DateTime|null $to   End of the period, inclusive.
	 *
	 * @return array<string, int> Purpose code to count, unattributed rows under `null`.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function countByPurpose(?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Record one change to a security control, or one refused attempt at a change.
	 *
	 * WHY THE REFUSAL IS AUDITED TOO. A weakening that was refused is the most
	 * interesting row on the page: somebody tried to turn the lockout down, and
	 * the instance said no. An audit trail that only holds the changes that
	 * succeeded cannot tell a security officer that anybody tried.
	 *
	 * No object, no register and no schema: a control is instance-wide, and
	 * attaching it to an arbitrary object would put it on that object's timeline
	 * where it does not belong. Same shape as
	 * {@see recordPartyQueryRefusal()}.
	 *
	 * @param HardeningChange $change   The control, the value before, the value asked for, and the refusal.
	 * @param bool            $accepted Whether the change was made.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 */
	public function recordHardeningChange(HardeningChange $change, bool $accepted): AuditTrail;

	/**
	 * Create one immutable, hash-chained audit record for an export.
	 *
	 * An export is the moment data leaves the instance, so it belongs on the
	 * trail beside the writes. The entry names the actor, the profile, the row
	 * count and the time, which is the set an incident is reconstructed from.
	 *
	 * A REFUSAL is recorded on the same terms and for the same reason: "who
	 * tried to take this register off the instance" is a question a functionaris
	 * gegevensbescherming asks, and a trail that only holds the successes cannot
	 * answer it.
	 *
	 * The entry hangs on a register and a schema rather than on an object,
	 * because an export has no single object. Same shape as
	 * {@see recordPartyQueryRefusal()}.
	 *
	 * @param ExportOutcome $export `completed` or `refused`; the profile, format, value mode, row count and, on a
	 *                              refusal, the reason; the register and schema; and the principal the export ran as
	 *                              when it is not the session user.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern, as recordToolInvocation.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function recordExport(ExportOutcome $export): AuditTrail;

	/**
	 * Create one immutable, hash-chained audit record for a slug backfill run.
	 *
	 * The backfill (`occ openregister:backfill-slugs`) writes the slug column directly: no per-object version and no
	 * per-object audit row. This single entry per run keeps the change
	 * reconstructable: it lists the objects whose slug changed with the old and
	 * the new value (bounded, see slugBackfillSummary()). Hangs on the register and schema, not on an object, like
	 * {@see recordExport()}.
	 *
	 * The run mode is always `fill`: a set slug is never rewritten.
	 *
	 * @param SlugBackfill $backfill Every slug the run wrote (the entry keeps the first 1,000), the run's counters,
	 *                               and the register and schema.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern, as recordExport.
	 */
	public function recordSlugBackfill(SlugBackfill $backfill): AuditTrail;

	/**
	 * What a slug backfill entry stores: the counters and a bounded change list.
	 *
	 * The entry is hash-chained and cannot be edited afterwards, and the slugs
	 * come from the schema's `objectSlugField`, which can be a name. So only the
	 * first {@see SLUG_BACKFILL_MAX_CHANGES} changes are kept, each reduced to
	 * uuid, old and new slug, next to the total count; the caller logs the full
	 * list.
	 *
	 * @param SlugBackfill $backfill The slugs the run wrote and its counters.
	 * @param string       $mode     The run mode, `fill`.
	 *
	 * @return array<string, mixed> The result summary of the entry.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function slugBackfillSummary(SlugBackfill $backfill, string $mode): array;

	/**
	 * The objects one user read most recently, newest first.
	 *
	 * One row per object: the latest `read` entry this user has on the audit
	 * trail, capped at `$limit`. The (user, action, object_uuid, created)
	 * index narrows the rows; the cause is read from each candidate row.
	 *
	 * Only a read the person made counts: cause `person`, or empty for a row
	 * written before causes were recorded. A `lookup` (a guard, a relation
	 * lookup, an agent tool) and a read inside an import, rule, migration,
	 * scheduled job or cascade stay on the audit trail and stay out of the
	 * history (`recently-opened-means-opened`).
	 *
	 * A tombstoned row (retention purge) carries an empty `user`, so it never
	 * matches a uid and drops out of every history by itself.
	 *
	 * @param string $userId The reader's uid.
	 * @param int    $limit  How many objects to answer at most.
	 *
	 * @return array<string, string> Object uuid => ISO 8601 moment of the latest read, newest first.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 */
	public function findLatestReadsByUser(string $userId, int $limit = 100): array;

	/**
	 * Insert through the mapper (QBMapper).
	 *
	 * @param AuditTrail $entity The entity.
	 *
	 * @return AuditTrail The stored entity.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Delete through the mapper (QBMapper).
	 *
	 * @param AuditTrail $entity The entity.
	 *
	 * @return AuditTrail The stored entity.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * InsertOrUpdate through the mapper (QBMapper).
	 *
	 * @param AuditTrail $entity The entity.
	 *
	 * @return AuditTrail The stored entity.
	 */
	public function insertOrUpdate(Entity $entity): Entity;
}//end interface
