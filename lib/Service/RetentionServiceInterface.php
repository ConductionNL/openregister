<?php

/**
 * RetentionServiceInterface: the public surface of RetentionService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;

/**
 * Every public method of RetentionService. Callers depend on this interface, not on the class.
 */
interface RetentionServiceInterface {

	/**
	 * Apply archival metadata to an object based on its schema's archive configuration.
	 *
	 * Called during object creation to populate retention fields from schema defaults
	 * and selectielijst mappings.
	 *
	 * @param ObjectEntity $object The object entity to populate
	 * @param Schema $schema The schema with archive configuration
	 *
	 * @return ObjectEntity The object with archival metadata applied
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-objects-must-carry-mdto-compliant-archival-metadata-in-the-retention-field
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function applyArchivalMetadata(ObjectEntity $object, Schema $schema): ObjectEntity;

	/**
	 * Refuse a record whose category override names no selectielijst row.
	 *
	 * @param Schema               $schema The record's schema
	 * @param array<string, mixed> $data   The record's data as it will be stored
	 *
	 * @return void
	 *
	 * @throws ValidationException When the override is not text or matches no selectielijst row
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function guardClassificationOverride(Schema $schema, array $data): void;

	/**
	 * Follow a changed category override on an update.
	 *
	 * A record that is still active is re-derived from its new category. A
	 * record already nominated at its terminal state keeps that decision:
	 * changing its category is refused, because re-nominating it is the
	 * explicit recompute's job, which records who asked and why.
	 *
	 * Only a change of the override itself counts: an edit that leaves it
	 * alone never re-derives or refuses, even when the schema's own category
	 * has moved since the record was created.
	 *
	 * @param RecordUpdate $update The record with its updated data, its schema and its data before this update
	 *
	 * @return ObjectEntity The record, re-derived when its category changed
	 *
	 * @throws ObjectStateWriteException When a nominated record's category changes
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function applyClassification(RecordUpdate $update): ObjectEntity;

	/**
	 * Calculate archiefactiedatum based on the schema's afleidingswijze.
	 *
	 * Delegates to {@see ArchiveActionDateCalculator}, which owns the whole
	 * question of where a retention period starts counting from and what to do
	 * when it cannot be answered. Kept here because callers and the spec both
	 * name this method.
	 *
	 * @param RetentionClock $clock The object, its schema (with the afleidingswijze config) and the ISO 8601 retention period
	 *
	 * @return string|null ISO 8601 date string or null if calculation not possible
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function calculateArchiveActionDate(RetentionClock $clock): ?string;

	/**
	 * Recalculate archiefactiedatum when a source property changes.
	 *
	 * The source is the property the schema's derivation method reads: the
	 * `sourceDateProperty` under `ander_datumkenmerk`, the reference under the
	 * relation methods, `bronEigenschap` under `eigenschap` and the closure
	 * field under `afgehandeld` and `termijn`.
	 *
	 * @param RecordUpdate $update The object being updated, its schema and its previous data
	 *
	 * @return ObjectEntity The object with recalculated dates
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function recalculateActionDate(RecordUpdate $update): ObjectEntity;

	/**
	 * Recalculate a record's archiefactiedatum from its current source date.
	 *
	 * Used for a record whose own data did not change but whose source date
	 * did, such as a contact person whose client's end date moved.
	 *
	 * @param ObjectEntity $object The record
	 * @param Schema       $schema Its schema
	 *
	 * @return bool True when the archiefactiedatum changed
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function followSourceDate(ObjectEntity $object, Schema $schema): bool;

	/**
	 * Look up a selectielijst entry by categorie code.
	 *
	 * Delegates to {@see \OCA\OpenRegister\Service\Archival\SelectielijstResolver},
	 * which owns the whole question of WHICH revision of a category applies now
	 * that a list is imported with a version. Kept here because callers and the
	 * spec both name this method, the same arrangement
	 * {@see calculateArchiveActionDate()} has with ArchiveActionDateCalculator.
	 *
	 * @param string $category The selectielijst category code (e.g., B1, A1)
	 *
	 * @return array|null The selectielijst entry data or null if not found
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function lookupSelectielijstEntry(string $category): ?array;

	/**
	 * Validate that an object is not in an immutable archival status.
	 *
	 * @param ObjectEntity $object The object to check
	 *
	 * @return string|null Error code if immutable, null if mutable
	 *
	 * @spec exclude Archival-status immutability guard (vernietigd/overgebracht → error code); sibling of the
	 *              already-annotated archival methods, owned by archival-destruction-workflow. No distinct contract.
	 */
	public function validateNotImmutable(ObjectEntity $object): ?string;

	/**
	 * Place a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to place the hold on
	 * @param HoldOrder    $order  Why, and for which matter; no matter for a manual hold
	 *
	 * @return ObjectEntity The object with legal hold applied
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function placeHold(ObjectEntity $object, HoldOrder $order): ObjectEntity;

	/**
	 * Release a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to release the hold from
	 * @param HoldOrder    $order  Why, and for which matter; no matter releases every hold
	 *
	 * @return ObjectEntity The object with legal hold released
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseHold(ObjectEntity $object, HoldOrder $order): ObjectEntity;

	/**
	 * Check if an object has an active legal hold.
	 *
	 * Delegates to {@see ObjectEntity::hasActiveLegalHold()}, the single
	 * definition of "held". Kept as a service method because the destruction
	 * and sweep jobs already ask the question here.
	 *
	 * @param ObjectEntity $object The object to check
	 *
	 * @return bool True if object has active legal hold
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function hasActiveLegalHold(ObjectEntity $object): bool;

	/**
	 * Extend archiefactiedatum by a period for excluded/rejected objects.
	 *
	 * @param ObjectEntity $object The object to extend
	 * @param string|null $extensionPeriod ISO 8601 duration (default from settings)
	 *
	 * @return ObjectEntity The object with extended archiefactiedatum
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function extendArchiveActionDate(ObjectEntity $object, ?string $extensionPeriod = null): ObjectEntity;

	/**
	 * Visit every object carrying a retention decision, wherever it lives.
	 *
	 * Delegates to {@see RetentionRowScanner}, which owns the whole question of
	 * WHERE a retention decision is stored: the per-schema magic tables and the
	 * legacy blob table both, paged, with a cap per run. Kept here because both
	 * archival background jobs already ask this service for the sweep.
	 *
	 * @param callable $accept     Predicate answering whether one object is a match.
	 * @param int|null $maxMatches Cap for this run; defaults to the scanner's own.
	 *
	 * @return array{objects: ObjectEntity[], scanned: int, truncated: bool} The matches,
	 *               how many rows were inspected, and whether the cap stopped the run.
	 *
	 * @psalm-param callable(ObjectEntity): bool $accept
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 */
	public function scanObjectsWithRetention(callable $accept, ?int $maxMatches = null): array;

	/**
	 * Find objects eligible for destruction.
	 *
	 * Objects whose appraisal is destroy, whose archiefactiedatum has passed,
	 * whose record state is still live, that are not immutable, carry no active
	 * legal hold, and are not already on a pending destruction list.
	 *
	 * @param array $excludeUuids UUIDs to exclude (already on pending lists)
	 *
	 * @return ObjectEntity[] Array of eligible objects
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function findEligibleForDestruction(array $excludeUuids = []): array;

	/**
	 * The first destruction rule that refuses one object, or null when none does.
	 *
	 * The one rule, read by the daily sweep (through isEligibleForDestruction())
	 * and by an app that asks for a list of its own (DestructionListCreator),
	 * so the two cannot drift apart.
	 *
	 * @param ObjectEntity  $object The object to judge.
	 * @param DisposalSweep $sweep  Today (as Y-m-d) and the UUIDs already on a pending destruction list.
	 *
	 * @return string|null One of the REFUSAL_* reasons, or null when the object may be destroyed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One rule per branch; collapsing
	 *              them would hide which rule rejected an object.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function destructionRefusalIn(ObjectEntity $object, DisposalSweep $sweep): ?string;

	/**
	 * Find objects eligible for transfer to an e-Depot.
	 *
	 * Objects whose appraisal is retain-permanently, whose archiefactiedatum has
	 * passed, whose record state is not already transferred or destroyed, that
	 * carry no active legal hold, and are not already on an active transfer
	 * list.
	 *
	 * @param array $excludeUuids UUIDs to exclude (already on active transfer lists)
	 *
	 * @return ObjectEntity[] Array of eligible objects
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function findEligibleForTransfer(array $excludeUuids = []): array;

	/**
	 * Get UUIDs of objects already on pending destruction lists.
	 *
	 * @return string[] Array of object UUIDs
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 */
	public function getObjectsOnPendingDestructionLists(): array;

	/**
	 * Create a destruction list as a register object.
	 *
	 * @param ObjectEntity[] $objects The objects to include in the destruction list
	 *
	 * @return array|null The destruction list data or null on failure
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function createDestructionList(array $objects): ?array;

	/**
	 * Generate a destruction certificate after execution.
	 *
	 * @param ExecutedDestruction $execution The destruction list data, the number destroyed and the ISO 8601 execution time
	 *
	 * @return array The destruction certificate data
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-generate-destruction-lists-via-a-background-job
	 * @spec openspec/specs/retention-management/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function certify(ExecutedDestruction $execution): array;
}//end interface
