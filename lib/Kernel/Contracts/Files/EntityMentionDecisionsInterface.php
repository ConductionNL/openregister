<?php

/**
 * EntityMentionDecisionsInterface: the entity mentions found in files, and the decisions linked to them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Files
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Files;

use OCA\OpenRegister\Db\DecisionMetadataEdit;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DecisionConflictException;

/**
 * Reads the entity mentions of a file or an entity, and records the decision taken on one.
 *
 * Files answers: the entity relation store implements it through EntityRelationMapperInterface,
 * which extends this port. Higher areas (the entity-relation and decision endpoints) ask.
 *
 * @spec openspec/changes/functional-areas-depend-in-one-direction/contracts/files.md
 */
interface EntityMentionDecisionsInterface {

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
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function find(int $id): EntityRelation;

	/**
	 * Find entity relations by file ID.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return EntityRelation[] Array of entity relations.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function findByFileId(int $fileId): array;

	/**
	 * Find entity relations by entity ID.
	 *
	 * @param int $entityId The entity ID.
	 *
	 * @return EntityRelation[] Array of entity relations.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function findByEntityId(int $entityId): array;

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
	 * @param DecisionMetadataEdit $edit The pre-loaded relation row (callers handle find/404), the
	 *                                   whitelist keys with new values and the optional explicit acting
	 *                                   user; without one it falls back to the session user, and without
	 *                                   a session user the audit entry records the actor as 'system'.
	 *
	 * @return EntityRelation The (possibly unchanged) row.
	 *
	 * @throws CustomValidationException When a whitelist or shape violation
	 *                                   is detected.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md#requirement-entityrelationmapperupdatedecisionmetadata-must-be-the-single-audited-write-path-for-the-whitelist-fields
	 */
	public function updateDecisionMetadata(DecisionMetadataEdit $edit): EntityRelation;
}//end interface
