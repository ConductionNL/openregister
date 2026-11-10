<?php

/**
 * EntityRelationMapperInterface: the public surface of EntityRelationMapper, for callers to type against.
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

namespace OCA\OpenRegister\Files\Db;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DecisionConflictException;
use OCP\AppFramework\Db\Entity;
use OCP\IUser;
use OCA\OpenRegister\Db\EntityRelation;

/**
 * Every public method of EntityRelationMapper. Callers depend on this interface, not on the class.
 */
interface EntityRelationMapperInterface {

	/**
	 * Find a single entity relation by its primary id.
	 *
	 * QBMapper exposes `find()` via a `@method` docblock only; concrete
	 * mappers add it themselves when needed. This implementation wraps the
	 * inherited protected `findEntity()` so HTTP/DI callers get a typed
	 * 404 path (`DoesNotExistException`) for unknown ids.
	 *
	 * @param int $id The relation row id.
	 *
	 * @return EntityRelation The matching row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When $id does not resolve.
	 */
	public function find(int $id): EntityRelation;

	/**
	 * Find entity relations by file ID.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return EntityRelation[] Array of entity relations.
	 */
	public function findByFileId(int $fileId): array;

	/**
	 * Find entity relations by entity ID.
	 *
	 * @param int $entityId The entity ID.
	 *
	 * @return EntityRelation[] Array of entity relations.
	 */
	public function findByEntityId(int $entityId): array;

	/**
	 * Find entity relations with entity details by file ID.
	 *
	 * Returns entity relations joined with entity data for anonymization.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return array Array of entity data with type, value, and relation info.
	 */
	public function findEntitiesForFile(int $fileId): array;

	/**
	 * Find anonymised entity relations with the full decision-metadata + entity context for a file.
	 *
	 * Differs from {@see findEntitiesForFile} in two ways:
	 *
	 *   1. Filters to rows where `r.anonymized = true` — the caller wants
	 *      the "what was actually redacted" set, not the "what was detected" set.
	 *   2. Selects `r.bases`, `r.skip_anonymization`, `r.anonymized`,
	 *      `r.anonymized_value` so downstream renderers (DocuDesk's
	 *      `anonymisation-grondslagen-summary` change) can produce a
	 *      grondslagen-traceable audit page without an N+1 of `find($id)`
	 *      calls.
	 *
	 * Returned rows are flat associative arrays. `bases` is JSON-decoded
	 * into an `array` (or null if the column is null in the DB).
	 *
	 * @param int $fileId The Nextcloud file ID.
	 *
	 * @return array<int, array<string, mixed>> Rows shaped as
	 *                                          `{relation_id, entity_id, position_start, position_end, confidence,
	 *                                          entity_type, entity_value, category, bases (array|null),
	 *                                          skip_anonymization (bool), anonymized (bool), anonymized_value (string|null)}`.
	 */
	public function findAnonymisedEntitiesWithBasesForFile(int $fileId): array;

	/**
	 * Build a `(entity_value → {id, type})` map for every entity relation on a file.
	 *
	 * Used by `DocumentProcessingHandler::anonymizeDocument` to look up the
	 * stable `entity_id` for each entity occurrence so the in-file
	 * placeholder format `[<TYPE>: <entity_id>]` matches what downstream
	 * consumers (DocuDesk's grondslagen-summary report) display. Without
	 * this lookup the anonymise step generates a fresh UUID-prefix per
	 * call, and the report's view of "what's in the file" diverges from
	 * the actual substitution.
	 *
	 * Returns a single entry per distinct `entity_value` on the file.
	 * When the same value is detected as multiple `entity_types`, the
	 * first one encountered wins — callers should use their own
	 * `entityType` to discriminate at the substitution site.
	 *
	 * @param int $fileId The Nextcloud file ID whose entity relations are mapped.
	 *
	 * @return array<string, array{id: int, type: string}> Map keyed by entity value.
	 */
	public function findEntityIdsByValueForFile(int $fileId): array;

	/**
	 * Load the entity-relation rows for several files in one query — the
	 * multi-file sibling of `findEntityIdsByValueForFile`.
	 *
	 * Used by the per-dossier placeholder numbering (Decision 3): the dossier
	 * number is recomputed as a pure function of the dossier's stored rows, so
	 * this returns the raw `(entity_id, file_id, position_start)` tuples for
	 * every relation on the given files. The caller (`PlaceholderIdTranslator`)
	 * imposes the total stable order `(file_id, position_start, entity_id)` and
	 * ranks distinct `entity_id`s by first appearance. No join to
	 * `openregister_entities` is needed — the relation row already carries the
	 * `entity_id` that is the numbering key.
	 *
	 * @param array<int, int> $fileIds The Nextcloud file IDs of the dossier's files.
	 *
	 * @return array<int, array{entity_id: int, file_id: int, position_start: int}>
	 *                                                                              One record per relation row across all given files (unordered;
	 *                                                                              the caller imposes the ranking order).
	 */
	public function findEntityIdsByValueForFiles(array $fileIds): array;

	/**
	 * Probe whether a relation already exists at a specific position
	 * for a (file, entity) pair.
	 *
	 * Used by `ManualEntityService` to make manual-entity adds
	 * idempotent: re-calling the endpoint for the same value on the
	 * same file does NOT create duplicate relation rows. The dedup key
	 * is the full (fileId, entityId, chunkId, positionStart, positionEnd)
	 * tuple — same positions across different entities (e.g. position
	 * 142 has both `"Jan Jansen"` PERSON and `"Jansen"` PERSON entities)
	 * are legitimately distinct rows.
	 *
	 * @param int           $fileId  Nextcloud file id.
	 * @param EntityMention $mention The entity, chunk and span within the chunk.
	 *
	 * @return bool True when a row with exactly these five values exists.
	 */
	public function existsForFileAtPosition(int $fileId, EntityMention $mention): bool;

	/**
	 * Insert multiple relation rows in a single pass.
	 *
	 * The caller is expected to manage the surrounding transaction
	 * (`IDBConnection::beginTransaction()` / `commit()` / `rollBack()`).
	 * `ManualEntityService` does this so the entity-create + batch
	 * relation insert + audit-trail write all commit atomically.
	 *
	 * Each input row is an associative array carrying the fields the
	 * caller wants to persist. Recognised keys (all optional unless
	 * marked):
	 *
	 *   - `entityId`          (required, int)
	 *   - `fileId`            (int|null)
	 *   - `chunkId`           (int|null)
	 *   - `objectId`          (int|null)
	 *   - `emailId`           (int|null)
	 *   - `positionStart`     (int)
	 *   - `positionEnd`       (int)
	 *   - `confidence`        (float)
	 *   - `detectionMethod`   (string)
	 *   - `context`           (string|null)
	 *   - `role`              (string|null)
	 *   - `anonymized`        (bool)
	 *   - `skipAnonymization` (bool)
	 *   - `bases`             (array|null)
	 *   - `createdAt`         (DateTime, defaults to now)
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to insert.
	 *
	 * @return EntityRelation[] The inserted entities with their generated ids,
	 *                          in the same order as the input.
	 *
	 * @throws \Throwable Any DB error is re-thrown verbatim so the caller's
	 *                    transaction can roll back.
	 */
	public function insertBatch(array $rows): array;

	/**
	 * Mark entity relations as anonymized, persisting each entity's EXACT
	 * emitted placeholder into its `anonymized_value`.
	 *
	 * The scope-local placeholder (number + localized label) is computed once,
	 * at anonymise time, inside the run — it is not otherwise recoverable later
	 * (the number depends on the scope and is not derivable from the stored
	 * rows alone). Persisting it here, per relation, is the only durable record
	 * of "what each entity became" — and it lives exactly as long as the
	 * relation does (overwritten on re-anonymise, removed when the relation is
	 * deleted/re-extracted), so there is no separate store to keep in sync or
	 * clean up.
	 *
	 * ONLY the entities actually redacted are marked: each `entity_id` present
	 * in `$placeholders` (i.e. the entities that were substituted into
	 * the document) has all its non-skipped relations on the file set to
	 * `anonymized = true` with `anonymized_value` = its exact placeholder.
	 *
	 * This intentionally does NOT blanket-mark every non-skipped relation:
	 * an entity that was detected but NOT redacted (absent from the map — e.g.
	 * a value matched only under a second type, or filtered out) was never
	 * substituted into the document, so marking it anonymized would make the
	 * grondslagen-summary list a placeholder that isn't in the file and (with
	 * no scope-local number) leak the global entity id. Skip decisions are
	 * honoured (skipped rows untouched).
	 *
	 * @param int $fileId The file ID.
	 * @param array<int|string, string> $placeholders Keys are int|string,
	 *        not string: a stringified entity id is a canonical numeric string,
	 *        which PHP coerces to an int array key. Map of entity id → the
	 *                                                exact placeholder emitted for it
	 *                                                (e.g. "7" => "[PERSOON: 1]").
	 *
	 * @return int Number of relation rows marked anonymized.
	 */
	public function markAsAnonymizedWithPlaceholders(int $fileId, array $placeholders): int;

	/**
	 * Find entity relations for the anonymise pass — skip-aware.
	 *
	 * Same shape as `findEntitiesForFile` but filters out rows the operator
	 * has flagged with `skip_anonymization = true`. The anonymise flow
	 * (`FileTextController::anonymizeFile`) uses this method to build the
	 * replacements list so skipped occurrences are excluded from redaction.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return array<int, array<string, mixed>> Array of entity rows joined with their EntityRelation record.
	 */
	public function findEntitiesForAnonymization(int $fileId): array;

	/**
	 * Collect distinct `Entity.value` strings for relations on a file flagged
	 * with `skip_anonymization = true`.
	 *
	 * Used by the anonymise text-replacement code path as a defensive filter:
	 * even when the caller (e.g. DocuDesk) passes an entities[] payload that
	 * includes skipped occurrences, the redaction step removes those entries
	 * by matching their `text` against the values returned here. Per the
	 * `entity-relation-grondslagen` spec: "skipped relations are never
	 * redacted, full stop", regardless of caller behaviour.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return array<int, string> Distinct entity values for skipped relations.
	 */
	public function findSkippedEntityValuesForFile(int $fileId): array;

	/**
	 * Update operator-decision metadata on an EntityRelation row.
	 *
	 * Single audited write path for the two decision-only fields:
	 *   - `bases` (?array) — UUIDs of legal grondslagen
	 *   - `skipAnonymization` (bool) — opt-out from the next anonymise pass
	 *
	 * Strict whitelist: any other key in `$fields` causes a
	 * `CustomValidationException`. Shape validation: `bases` MUST be
	 * null or array<string>; `skipAnonymization` MUST be a bool.
	 *
	 * Diff-aware: if every supplied value matches the current row state,
	 * the method returns the unchanged row and writes NO audit entry
	 * (semantic no-op). Empty `$fields` is also a no-op.
	 *
	 * Otherwise the row is updated and exactly one audit-trail entry is
	 * emitted via the OpenRegister immutable audit subsystem (ADR-022),
	 * capturing the acting user UID (per ADR-005, the UID — NOT the
	 * display name in the structured changed-fields payload), the
	 * timestamp, the subject (table + row id), and only the fields that
	 * actually changed.
	 *
	 * @param EntityRelation $relation Pre-loaded relation row (callers handle find/404).
	 * @param array $fields Subset of whitelist keys with new values.
	 * @param IUser|null $actingUser Optional explicit acting user; falls back
	 *                               to the session user. If null and no
	 *                               session user, the audit entry records the
	 *                               actor as 'system'.
	 *
	 * @return EntityRelation The (possibly unchanged) row.
	 *
	 * @throws CustomValidationException When a whitelist or shape violation
	 *                                   is detected.
	 */
	public function updateDecisionMetadata(EntityRelation $relation, array $fields, ?IUser $actingUser = null): EntityRelation;

	/**
	 * Link a relation to its current decision object.
	 *
	 * The decision itself (who, when, verdict, note) is its own object in
	 * `openregister_decisions` (decision 197); the relation only points at it.
	 * The audit trail of the verdict stays with `updateDecisionMetadata()`.
	 *
	 * With `$guarded`, the link is a compare-and-swap: it is written only while
	 * the stored link is still `$expectedUuid` (null: none), so two reviewers
	 * taking a first decision at once cannot both link theirs.
	 *
	 * @param EntityRelation $relation     The relation.
	 * @param string         $decisionUuid The decision's uuid.
	 * @param string|null    $expectedUuid The link the caller read before deciding; only read when guarded.
	 * @param bool           $guarded      Whether to write only while the stored link is still `$expectedUuid`.
	 *
	 * @return EntityRelation The stored relation.
	 *
	 * @throws DecisionConflictException When guarded and the stored link changed in the meantime.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The default keeps every unguarded caller unchanged; the guard is one
	 * extra statement, not a second behaviour.
	 */
	public function linkDecision(EntityRelation $relation, string $decisionUuid, ?string $expectedUuid = null, bool $guarded = false): EntityRelation;

	/**
	 * Insert through the mapper (QBMapper).
	 *
	 * @param EntityRelation $entity The entity.
	 *
	 * @return EntityRelation The stored entity.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update through the mapper (QBMapper).
	 *
	 * @param EntityRelation $entity The entity.
	 *
	 * @return EntityRelation The stored entity.
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete through the mapper (QBMapper).
	 *
	 * @param EntityRelation $entity The entity.
	 *
	 * @return EntityRelation The stored entity.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * InsertOrUpdate through the mapper (QBMapper).
	 *
	 * @param EntityRelation $entity The entity.
	 *
	 * @return EntityRelation The stored entity.
	 */
	public function insertOrUpdate(Entity $entity): Entity;
}//end interface
