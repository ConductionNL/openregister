<?php
/**
 * RecordRetentionStampsInterface: the retention a save stamps onto a record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\RecordUpdate;

/**
 * The archival metadata, category override and action date a save writes onto a record.
 *
 * The object save path (Core) asks; the retention service (Compliance) answers, because
 * RetentionServiceInterface extends this port and Compliance binds it in its own wiring.
 *
 * @spec openspec/changes/functional-areas-depend-in-one-direction/specs/backend-modularity/spec.md#requirement-areas-reference-only-lower-areas
 */
interface RecordRetentionStampsInterface {

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
}//end interface
