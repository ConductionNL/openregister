<?php

/**
 * The relation rows on an object, as the object relations API reads and writes them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;

/**
 * The relation rows on an object, as the object relations API reads and writes them.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see ObjectRelationService} in ContractBindings.
 */
interface ObjectRelationServiceInterface {

	/**
	 * Every stored relation row touching an object, with its labels resolved.
	 *
	 * Rows where the object is the SOURCE read with the near label; rows where
	 * it is the TARGET read with the inverse. Both are returned together
	 * because that is how a panel shows them, and asking the caller to make
	 * two calls and merge them is asking the caller to get the direction wrong.
	 *
	 * @param string $objectUuid The object.
	 * @param string $language The BCP-47 tag to resolve labels in.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function relationsFor(string $objectUuid, string $language = 'nl'): array;

	/**
	 * Add an address outside the product as a relation on an object.
	 *
	 * A URL pasted into a description is invisible to the reverse view, the
	 * graph and the export. As a row with a title and a type it is in all
	 * three, and it costs no new concept: a relation target that happens not
	 * to be an object.
	 *
	 * @param string $sourceUuid The object the link hangs off.
	 * @param string $url The address.
	 * @param string|null $title What to call it.
	 * @param string|null $relationType The vocabulary key, when the schema names one.
	 * @param string|null $label What the link reads as, when no schema can answer.
	 * @param int|null $register The source object's register.
	 * @param int|null $schema The source object's schema.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @throws InvalidArgumentException When the address is not one.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function addExternalLink(
		string $sourceUuid,
		string $url,
		?string $title = null,
		?string $relationType = null,
		?string $label = null,
		?int $register = null,
		?int $schema = null,
	): ObjectRelation;

	/**
	 * Record a reference somebody wrote in prose, on both sides.
	 *
	 * The seam with the timeline change: it resolves the pattern out of the
	 * text and renders the link, and calls this to write the row. One row per
	 * direction, both carrying the same anchor, so withdrawing the text
	 * withdraws both and leaves neither half of a link behind.
	 *
	 * Idempotent per anchor and target: saving the same note twice does not
	 * write the mention twice.
	 *
	 * @param string $sourceUuid The object the text belongs to.
	 * @param string $targetUuid The object the text names.
	 * @param string $anchor What identifies the text, so it can be withdrawn.
	 * @param string|null $relationType The vocabulary key, when there is one.
	 * @param array<string, int|null> $scope Source and target register/schema ids.
	 *
	 * @return array<int, ObjectRelation> The rows written, one per direction.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordProseReference(
		string $sourceUuid,
		string $targetUuid,
		string $anchor,
		?string $relationType = null,
		array $scope = [],
	): array;

	/**
	 * Withdraw every row one piece of text wrote, on both sides.
	 *
	 * @param string $sourceUuid The object the text belongs to.
	 * @param string $anchor The anchor.
	 *
	 * @return int The number of rows withdrawn.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function withdrawProseReferences(string $sourceUuid, string $anchor): int;

	/**
	 * Remove one relation row by its uuid.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return boolean True when a row was removed.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function remove(string $uuid): bool;

	/**
	 * One stored row as a client reads it.
	 *
	 * The vocabulary key is resolved against the schema that declares it, not
	 * against what the row was stored with, so renaming a relation type in a
	 * schema renames it everywhere at once rather than only on rows written
	 * after the rename.
	 *
	 * @param ObjectRelation $row The stored row.
	 * @param string $direction One of RelationTypeResolver::DIRECTION_*.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return array<string, mixed> The rendered row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function render(ObjectRelation $row, string $direction, string $language = 'nl'): array;

	/**
	 * The relation declaration one property of one schema carries.
	 *
	 * The caller asking is a controller that holds a schema id and a property
	 * name and needs the inheritance and the type the schema declares for it.
	 *
	 * @param int|null $schemaId The schema holding the property.
	 * @param string $property The property name.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return array<string, mixed>|null The descriptor.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function declarationFor(?int $schemaId, string $property, string $language = 'nl'): ?array;

	/**
	 * What a child takes from its parent at creation, under one relation type.
	 *
	 * Taken ONCE, here, and recorded. Live inheritance of a classification
	 * would mean that changing the parent silently reclassifies every child,
	 * which is an access change nobody authorised; the child takes the values
	 * as they stand, the row says it did, and a later change to the parent is
	 * a decision somebody makes again.
	 *
	 * Only properties the parent actually carries are inherited, and the record
	 * holds only what was really taken. A role mapped onto a property the
	 * parent does not have inherits nothing rather than inheriting null, so
	 * "the child carries the parent's confidentiality" and "the child has no
	 * confidentiality" never look the same afterwards.
	 *
	 * @param ObjectEntity $parent The parent object.
	 * @param array<string, mixed> $childData The child's data as written so far.
	 * @param array<string, string> $inherits Role to property name.
	 *
	 * @return array{data: array<string, mixed>, inherited: array<string, mixed>}
	 *         The child's data with the inherited values applied, and the record.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function applyInheritance(ObjectEntity $parent, array $childData, array $inherits): array;

	/**
	 * Record a child created under a parent, with what it inherited.
	 *
	 * @param ObjectEntity $parent The parent object.
	 * @param ObjectEntity $child The created child.
	 * @param string|null $relationType The vocabulary key naming the link.
	 * @param array<string, mixed> $inherited What the child took, as recorded.
	 * @param string|null $entry The entry of the parent it came out of, when it
	 *                           came out of one. A derivation and a split are
	 *                           the same act seen twice: a sub-case started
	 *                           from a timeline entry is both.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordDerivation(
		ObjectEntity $parent,
		ObjectEntity $child,
		?string $relationType = null,
		array $inherited = [],
		?string $entry = null,
	): ObjectRelation;

	/**
	 * Record that one object was created out of another.
	 *
	 * The entry is the whole point. Zammad splits a ticket and keeps no
	 * provenance column, and the lane said so; copying the act without copying
	 * the omission costs one row and answers "why does this zaak exist" for
	 * the rest of its life. One melding that turns out to be two zaken is
	 * routine, and the second zaak with no trail back is a record nobody can
	 * account for.
	 *
	 * @param ObjectEntity $source The object the new one came out of.
	 * @param ObjectEntity $created The new object.
	 * @param string|null $entry The entry of the source it came out of.
	 * @param string|null $relationType The vocabulary key naming the link.
	 *
	 * @return ObjectRelation The provenance row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordSplit(
		ObjectEntity $source,
		ObjectEntity $created,
		?string $entry = null,
		?string $relationType = null,
	): ObjectRelation;
}//end interface
