<?php

/**
 * Writes and reads the relation rows a schema property cannot hold.
 *
 * Four acts land here, and each one is an act somebody performs rather than a
 * side effect of a save. That is deliberate: a split is a decision, and an
 * inheritance that happened invisibly during an ordinary write is exactly the
 * kind of access change nobody remembers authorising.
 *
 *  - splitting a record out of one entry of another, keeping the provenance
 *  - deriving a child under a parent, taking the parent's access triad once
 *  - adding an address outside the product as a relation in its own right
 *  - recording, and later withdrawing, a reference somebody wrote in prose
 *
 * The prose half is a SEAM, not a feature: the timeline change owns resolving
 * a pattern out of text and rendering it as a link, and calls
 * {@see self::recordProseReference()} / {@see self::withdrawProseReferences()}
 * to write and withdraw the row. Both sides writing rows would double every
 * mention.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\Schema;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Records and reads typed relation rows between objects and beyond them.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
class ObjectRelationService implements ObjectRelationServiceInterface {
	/**
	 * Shapes the fields of the rows this service writes and reads.
	 *
	 * @var RelationRowFields
	 */
	private readonly RelationRowFields $fields;

	/**
	 * Constructor.
	 *
	 * @param ObjectRelationStores $stores The relation row mapper, the schema mapper and the vocabulary.
	 * @param LoggerInterface $logger Logger.
	 * @param IUserSession|null $userSession Who is writing the row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		private readonly ObjectRelationStores $stores,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
	) {
		$this->fields = new RelationRowFields();
	}//end __construct()

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
	 * @param ProvenanceLink|null $link The entry of the source it came out of and the vocabulary key naming the link.
	 *
	 * @return ObjectRelation The provenance row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordSplit(
		ObjectEntity $source,
		ObjectEntity $created,
		?ProvenanceLink $link = null,
	): ObjectRelation {
		$link ??= new ProvenanceLink();
		return $this->stores->mapper->createFromArray(
			$this->fields->splitLink(from: $created, to: $source)
			+ ['relationType' => $link->relationType, 'sourceEntry' => $link->entry, 'createdBy' => $this->actor()]
		);
	}//end recordSplit()

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
	public function applyInheritance(ObjectEntity $parent, array $childData, array $inherits): array {
		return $this->inherit(inheritance: new ParentInheritance(parent: $parent, childData: $childData, inherits: $inherits));
	}//end applyInheritance()

	/**
	 * What a child takes from its parent at creation, as {@see applyInheritance()} describes.
	 *
	 * @param ParentInheritance $inheritance The parent, the child's data so far and the roles inherited.
	 *
	 * @return array{data: array<string, mixed>, inherited: array<string, mixed>}
	 *         The child's data with the inherited values applied, and the record.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function inherit(ParentInheritance $inheritance): array {
		$parentData = $inheritance->parent->getObject();
		$childData = $inheritance->childData;
		$record = [];

		foreach ($inheritance->inherits as $role => $property) {
			// A value the child already carries is the child's own. Inheritance
			// fills a gap; it does not overrule what somebody typed.
			if ($this->fields->hasValue(data: $parentData, property: $property) === false
				|| $this->fields->hasValue(data: $childData, property: $property) === true
			) {
				continue;
			}

			$childData[$property] = $parentData[$property];
			$record[$role] = ['property' => $property, 'value' => $parentData[$property]];
		}

		return ['data' => $childData, 'inherited' => $record];
	}//end inherit()

	/**
	 * Record a child created under a parent, with what it inherited (positional form of recordDerived()).
	 *
	 * @param ObjectEntity $parent The parent object.
	 * @param ObjectEntity $child The created child.
	 * @param ProvenanceLink|null $link The vocabulary key naming the link, and the
	 *                                  entry of the parent it came out of, when it
	 *                                  came out of one. A derivation and a split are
	 *                                  the same act seen twice: a sub-case started
	 *                                  from a timeline entry is both.
	 * @param array<string, mixed> $inherited What the child took, as recorded.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordDerivation(
		ObjectEntity $parent,
		ObjectEntity $child,
		?ProvenanceLink $link = null,
		array $inherited = [],
	): ObjectRelation {
		return $this->recordDerived(derivation: new Derivation(parent: $parent, child: $child, link: ($link ?? new ProvenanceLink()), inherited: $inherited));
	}//end recordDerivation()

	/**
	 * Record a child created under a parent, with what it inherited.
	 *
	 * A derivation and a split are the same act seen twice: a sub-case started
	 * from a timeline entry is both.
	 *
	 * @param Derivation $derivation The parent, the child, the link naming it and what the child inherited.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordDerived(Derivation $derivation): ObjectRelation {
		return $this->stores->mapper->createFromArray(
			$this->fields->derivedLink(from: $derivation->child, to: $derivation->parent)
			+ [
				'relationType' => $derivation->link->relationType,
				'sourceEntry' => $derivation->link->entry,
				'inherited' => $this->fields->recordOf(inherited: $derivation->inherited),
			]
			+ ['createdBy' => $this->actor()]
		);
	}//end recordDerived()

	/**
	 * Add an address outside the product as a relation on an object.
	 *
	 * A URL pasted into a description is invisible to the reverse view, the
	 * graph and the export. As a row with a title and a type it is in all
	 * three, and it costs no new concept: a relation target that happens not
	 * to be an object.
	 *
	 * @param RelationEndpoint $source The object the link hangs off, in its register and schema.
	 * @param ExternalLink $link The address, what to call it, and its type or label.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @throws InvalidArgumentException When the address is not one.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function addExternalLink(RelationEndpoint $source, ExternalLink $link): ObjectRelation {
		return $this->stores->mapper->createFromArray(
			['sourceUuid' => $source->uuid, 'sourceRegister' => $source->register, 'sourceSchema' => $source->schema]
			+ $this->fields->externalTarget(url: $link->url, title: $link->title)
			+ $this->externalDetail(link: $link)
		);
	}//end addExternalLink()

	/**
	 * The kind, origin, type, label and author of a manually added external link.
	 *
	 * @param ExternalLink $link The link, with its relation type and label.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function externalDetail(ExternalLink $link): array {
		return [
			'kind' => ObjectRelation::KIND_EXTERNAL,
			'origin' => ObjectRelation::ORIGIN_MANUAL,
			'relationType' => $link->relationType,
			'label' => $link->label,
			'createdBy' => $this->actor(),
		];
	}//end externalDetail()

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
	 * @param ProseMention $mention The object the text belongs to and the one it names, each in its
	 *                              register and schema; the anchor that identifies the text so it can
	 *                              be withdrawn; and the vocabulary key, when there is one.
	 *
	 * @return array<int, ObjectRelation> The rows written, one per direction.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordProseReference(ProseMention $mention): array {
		if ($this->isNewProseReference(mention: $mention) === false) {
			return [];
		}

		// The second row is the far side. A mention is a link somebody reading
		// the OTHER object should see, which is the whole reason the corpus
		// counts it as a relation rather than as formatting.
		return [
			$this->proseRow(mention: $mention),
			$this->proseRow(mention: $mention->reversed()),
		];
	}//end recordProseReference()

	/**
	 * Whether a mention links two different objects and is not already recorded under its anchor.
	 *
	 * @param ProseMention $mention The object whose text carries the mention, the object it mentions and the anchor.
	 *
	 * @return bool True when the mention should be written.
	 */
	private function isNewProseReference(ProseMention $mention): bool {
		$sourceUuid = $mention->source->uuid;
		$targetUuid = $mention->target->uuid;
		if ($sourceUuid === '' || $targetUuid === '' || $sourceUuid === $targetUuid) {
			return false;
		}

		return $this->isRecorded(mention: $mention) === false;
	}//end isNewProseReference()

	/**
	 * Whether a mention of the target is already recorded under the anchor.
	 *
	 * @param ProseMention $mention The object whose text carries the mention, the object it mentions and the anchor.
	 *
	 * @return bool True when it is.
	 */
	private function isRecorded(ProseMention $mention): bool {
		foreach ($this->stores->mapper->findByAnchor(sourceUuid: $mention->source->uuid, anchor: $mention->anchor) as $existing) {
			if ($existing->getTargetUuid() === $mention->target->uuid) {
				return true;
			}
		}

		return false;
	}//end isRecorded()

	/**
	 * Write one side of a prose reference.
	 *
	 * @param ProseMention $mention The mention, seen from the row's source.
	 *
	 * @return ObjectRelation The row.
	 */
	private function proseRow(ProseMention $mention): ObjectRelation {
		$from = $mention->source;
		$to   = $mention->target;
		return $this->stores->mapper->createFromArray(
			[
				'sourceUuid' => $from->uuid,
				'sourceRegister' => $from->register,
				'sourceSchema' => $from->schema,
				'targetUuid' => $to->uuid,
				'targetRegister' => $to->register,
				'targetSchema' => $to->schema,
				'kind' => ObjectRelation::KIND_OBJECT,
				'origin' => ObjectRelation::ORIGIN_PROSE,
			] + ['relationType' => $mention->relationType, 'anchor' => $mention->anchor, 'createdBy' => $this->actor()]
		);
	}//end proseRow()

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
	public function withdrawProseReferences(string $sourceUuid, string $anchor): int {
		$removed = 0;
		foreach ($this->stores->mapper->findByAnchor(sourceUuid: $sourceUuid, anchor: $anchor) as $row) {
			$target = (string)$row->getTargetUuid();
			if ($target !== '') {
				$removed += $this->stores->mapper->deleteByAnchor(sourceUuid: $target, anchor: $anchor);
			}
		}

		return ($removed + $this->stores->mapper->deleteByAnchor(sourceUuid: $sourceUuid, anchor: $anchor));
	}//end withdrawProseReferences()

	/**
	 * Remove one relation row by its uuid.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return boolean True when a row was removed.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function remove(string $uuid): bool {
		try {
			$row = $this->stores->mapper->findByUuid(uuid: $uuid);
		} catch (\Exception $e) {
			return false;
		}

		$this->stores->mapper->delete($row);

		return true;
	}//end remove()

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
	public function declarationFor(?int $schemaId, string $property, string $language = 'nl'): ?array {
		// No schema reads as no declaration: the resolver answers null for it.
		return $this->stores->relationTypes->descriptorFor(schema: $this->schemaOrNull(schemaId: $schemaId), property: $property, language: $language);
	}//end declarationFor()

	/**
	 * The schema behind an id, or null when there is none or it cannot be found.
	 *
	 * Metadata only, so the read is not scoped to the caller.
	 *
	 * @param int|null $schemaId The schema id.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOrNull(?int $schemaId): ?Schema {
		if ($schemaId === null) {
			return null;
		}

		try {
			return $this->stores->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			return null;
		}
	}//end schemaOrNull()

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
	public function relationsFor(string $objectUuid, string $language = 'nl'): array {
		$rows = [];

		foreach ($this->stores->mapper->findBySource(sourceUuid: $objectUuid) as $row) {
			$rows[] = $this->render(row: $row, direction: RelationTypeResolver::DIRECTION_OUTGOING, language: $language);
		}

		foreach ($this->stores->mapper->findByTarget(targetUuid: $objectUuid) as $row) {
			$rows[] = $this->render(row: $row, direction: RelationTypeResolver::DIRECTION_INCOMING, language: $language);
		}

		return $rows;
	}//end relationsFor()

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
	public function render(ObjectRelation $row, string $direction, string $language = 'nl'): array {
		$relation = $this->stores->relationTypes->row(descriptor: $this->descriptorOf(row: $row, language: $language), direction: $direction, path: null);

		return $this->withRelation(row: $row, relation: $relation);
	}//end render()

	/**
	 * The row as JSON with its relation reading, origin and kind under `relation`.
	 *
	 * @param ObjectRelation       $row      The relation row.
	 * @param array<string, mixed> $relation The reading of the relation in one direction.
	 *
	 * @return array<string, mixed> The rendered row.
	 */
	private function withRelation(ObjectRelation $row, array $relation): array {
		$rendered = $row->jsonSerialize();
		$relation['origin'] = $row->getOrigin();
		$relation['kind']   = $row->getKind();
		$rendered['relation'] = $relation;

		return $rendered;
	}//end withRelation()

	/**
	 * Resolve a row's labels: from the schema when it names a type, from the
	 * row itself otherwise.
	 *
	 * A stored row ALWAYS resolves to a descriptor, never to null: a row that
	 * names no type and carries no label still reads by its origin, because
	 * "split from" is a better answer than an empty line, and because a caller
	 * that has to handle null is a caller that will render nothing.
	 *
	 * @param ObjectRelation $row The stored row.
	 * @param string $language The BCP-47 tag.
	 *
	 * @return array<string, mixed> The descriptor.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function descriptorOf(ObjectRelation $row, string $language): array {
		return ($this->vocabularyDescriptor(row: $row, language: $language) ?? $this->storedDescriptor(row: $row));
	}//end descriptorOf()

	/**
	 * The descriptor the row's relation type has in its source schema's vocabulary, when it resolves.
	 *
	 * @param ObjectRelation $row      The stored row.
	 * @param string         $language The BCP-47 tag.
	 *
	 * @return array<string, mixed>|null The descriptor.
	 */
	private function vocabularyDescriptor(ObjectRelation $row, string $language): array|null {
		if ($row->getRelationType() === null || $row->getSourceSchema() === null) {
			return null;
		}

		return $this->typeInSchema(row: $row, language: $language);
	}//end vocabularyDescriptor()

	/**
	 * The descriptor of a row's relation type in its source schema's vocabulary; null when it or the schema does not resolve.
	 *
	 * @param ObjectRelation $row      The stored row, naming a relation type and a source schema.
	 * @param string         $language The BCP-47 tag.
	 *
	 * @return array<string, mixed>|null The descriptor.
	 */
	private function typeInSchema(ObjectRelation $row, string $language): array|null {
		$schemaId = (int)$row->getSourceSchema();
		$type     = (string)$row->getRelationType();
		try {
			$schema = $this->stores->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
			return $this->fields->descriptorOfType(descriptors: $this->stores->relationTypes->descriptors(schema: $schema, language: $language), type: $type);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[ObjectRelationService] Could not resolve a relation type against its schema',
				context: ['file' => __FILE__, 'line' => __LINE__, 'type' => $type]
			);
			return null;
		}
	}//end typeInSchema()

	/**
	 * The descriptor a stored row carries itself, for a type no vocabulary resolves.
	 *
	 * @param ObjectRelation $row The stored row.
	 *
	 * @return array<string, mixed> The descriptor.
	 */
	private function storedDescriptor(ObjectRelation $row): array {
		$label = $row->getLabel();
		if ($label === null || trim($label) === '') {
			$label = $this->defaultLabelFor(origin: (string)$row->getOrigin());
		}

		return [
			'property' => null,
			'type' => $row->getRelationType(),
			'label' => $label,
			'inverseLabel' => ($row->getInverseLabel() ?? RelationTypeResolver::FALLBACK_INVERSE_LABEL),
			'symmetric' => (bool)$row->getSymmetric(),
			'inherits' => [],
		];
	}//end storedDescriptor()

	/**
	 * What a row reads as when nothing named it.
	 *
	 * @param string $origin The row's origin.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function defaultLabelFor(string $origin): string {
		return match ($origin) {
			ObjectRelation::ORIGIN_SPLIT => 'split from',
			ObjectRelation::ORIGIN_DERIVE => 'part of',
			ObjectRelation::ORIGIN_PROSE => 'mentions',
			default => 'related to',
		};
	}//end defaultLabelFor()

	/**
	 * Who is writing the row, when anybody is.
	 *
	 * @return string|null The user id.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function actor(): ?string {
		return $this->userSession?->getUser()?->getUID();
	}//end actor()

}//end class
