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
use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Records and reads typed relation rows between objects and beyond them.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
class ObjectRelationService {
	/**
	 * Constructor.
	 *
	 * @param ObjectRelationMapper $mapper The relation row mapper.
	 * @param SchemaMapper $schemaMapper Mapper for schemas.
	 * @param RelationTypeResolver $relationTypes Resolves what a relation is called.
	 * @param LoggerInterface $logger Logger.
	 * @param IUserSession|null $userSession Who is writing the row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		private readonly ObjectRelationMapper $mapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RelationTypeResolver $relationTypes,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
	) {
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
	): ObjectRelation {
		return $this->mapper->createFromArray(
			[
				'sourceUuid' => (string)$created->getUuid(),
				'sourceRegister' => $this->asInt(value: $created->getRegister()),
				'sourceSchema' => $this->asInt(value: $created->getSchema()),
				'targetUuid' => (string)$source->getUuid(),
				'targetRegister' => $this->asInt(value: $source->getRegister()),
				'targetSchema' => $this->asInt(value: $source->getSchema()),
				'kind' => ObjectRelation::KIND_OBJECT,
				'origin' => ObjectRelation::ORIGIN_SPLIT,
				'relationType' => $relationType,
				'sourceEntry' => $entry,
				'createdBy' => $this->actor(),
			]
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
		$parentData = $parent->getObject();
		$record = [];

		foreach ($inherits as $role => $property) {
			if (array_key_exists($property, $parentData) === false) {
				continue;
			}

			$value = $parentData[$property];
			if ($value === null || $value === '') {
				continue;
			}

			// A value the child already carries is the child's own. Inheritance
			// fills a gap; it does not overrule what somebody typed.
			if (array_key_exists($property, $childData) === true
				&& $childData[$property] !== null
				&& $childData[$property] !== ''
			) {
				continue;
			}

			$childData[$property] = $value;
			$record[$role] = ['property' => $property, 'value' => $value];
		}

		return ['data' => $childData, 'inherited' => $record];
	}//end applyInheritance()

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
	): ObjectRelation {
		$record = null;
		if ($inherited !== []) {
			$record = $inherited;
		}

		return $this->mapper->createFromArray(
			[
				'sourceUuid' => (string)$child->getUuid(),
				'sourceRegister' => $this->asInt(value: $child->getRegister()),
				'sourceSchema' => $this->asInt(value: $child->getSchema()),
				'targetUuid' => (string)$parent->getUuid(),
				'targetRegister' => $this->asInt(value: $parent->getRegister()),
				'targetSchema' => $this->asInt(value: $parent->getSchema()),
				'kind' => ObjectRelation::KIND_OBJECT,
				'origin' => ObjectRelation::ORIGIN_DERIVE,
				'relationType' => $relationType,
				'sourceEntry' => $entry,
				'inherited' => $record,
				'createdBy' => $this->actor(),
			]
		);
	}//end recordDerivation()

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
	): ObjectRelation {
		$url = trim($url);
		if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new InvalidArgumentException('An external relation needs a resolvable web address.');
		}

		// A title nobody gave falls back to the address, so the row is never
		// nameless in a list.
		$named = $url;
		if ($title !== null && trim($title) !== '') {
			$named = trim($title);
		}

		return $this->mapper->createFromArray(
			[
				'sourceUuid' => $sourceUuid,
				'sourceRegister' => $register,
				'sourceSchema' => $schema,
				'targetUrl' => $url,
				'targetTitle' => $named,
				'kind' => ObjectRelation::KIND_EXTERNAL,
				'origin' => ObjectRelation::ORIGIN_MANUAL,
				'relationType' => $relationType,
				'label' => $label,
				'createdBy' => $this->actor(),
			]
		);
	}//end addExternalLink()

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
	): array {
		if ($sourceUuid === '' || $targetUuid === '' || $sourceUuid === $targetUuid) {
			return [];
		}

		foreach ($this->mapper->findByAnchor(sourceUuid: $sourceUuid, anchor: $anchor) as $existing) {
			if ($existing->getTargetUuid() === $targetUuid) {
				return [];
			}
		}

		$rows = [];
		$rows[] = $this->mapper->createFromArray(
			[
				'sourceUuid' => $sourceUuid,
				'sourceRegister' => ($scope['sourceRegister'] ?? null),
				'sourceSchema' => ($scope['sourceSchema'] ?? null),
				'targetUuid' => $targetUuid,
				'targetRegister' => ($scope['targetRegister'] ?? null),
				'targetSchema' => ($scope['targetSchema'] ?? null),
				'kind' => ObjectRelation::KIND_OBJECT,
				'origin' => ObjectRelation::ORIGIN_PROSE,
				'relationType' => $relationType,
				'anchor' => $anchor,
				'createdBy' => $this->actor(),
			]
		);

		// The row on the far side. A mention is a link somebody reading the
		// OTHER object should see, which is the whole reason the corpus counts
		// it as a relation rather than as formatting.
		$rows[] = $this->mapper->createFromArray(
			[
				'sourceUuid' => $targetUuid,
				'sourceRegister' => ($scope['targetRegister'] ?? null),
				'sourceSchema' => ($scope['targetSchema'] ?? null),
				'targetUuid' => $sourceUuid,
				'targetRegister' => ($scope['sourceRegister'] ?? null),
				'targetSchema' => ($scope['sourceSchema'] ?? null),
				'kind' => ObjectRelation::KIND_OBJECT,
				'origin' => ObjectRelation::ORIGIN_PROSE,
				'relationType' => $relationType,
				'anchor' => $anchor,
				'createdBy' => $this->actor(),
			]
		);

		return $rows;
	}//end recordProseReference()

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
		foreach ($this->mapper->findByAnchor(sourceUuid: $sourceUuid, anchor: $anchor) as $row) {
			$target = (string)$row->getTargetUuid();
			if ($target !== '') {
				$removed += $this->mapper->deleteByAnchor(sourceUuid: $target, anchor: $anchor);
			}
		}

		return ($removed + $this->mapper->deleteByAnchor(sourceUuid: $sourceUuid, anchor: $anchor));
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
			$row = $this->mapper->findByUuid(uuid: $uuid);
		} catch (\Exception $e) {
			return false;
		}

		$this->mapper->delete($row);

		return true;
	}//end remove()

	/**
	 * Persist a row whose caller adjusted it after it was written.
	 *
	 * @param ObjectRelation $row The row.
	 *
	 * @return ObjectRelation The persisted row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function saveRow(ObjectRelation $row): ObjectRelation {
		return $this->mapper->save(relation: $row);
	}//end saveRow()

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
		if ($schemaId === null) {
			return null;
		}

		try {
			$schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			return null;
		}

		return $this->relationTypes->descriptorFor(
			schema: $schema,
			property: $property,
			language: $language
		);
	}//end declarationFor()

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

		foreach ($this->mapper->findBySource(sourceUuid: $objectUuid) as $row) {
			$rows[] = $this->render(
				row: $row,
				direction: RelationTypeResolver::DIRECTION_OUTGOING,
				language: $language
			);
		}

		foreach ($this->mapper->findByTarget(targetUuid: $objectUuid) as $row) {
			$rows[] = $this->render(
				row: $row,
				direction: RelationTypeResolver::DIRECTION_INCOMING,
				language: $language
			);
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
		$rendered = $row->jsonSerialize();
		$descriptor = $this->descriptorOf(row: $row, language: $language);

		$rendered['relation'] = $this->relationTypes->row(
			descriptor: $descriptor,
			direction: $direction,
			path: null
		);
		$rendered['relation']['origin'] = $row->getOrigin();
		$rendered['relation']['kind'] = $row->getKind();

		return $rendered;
	}//end render()

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
		$type = $row->getRelationType();
		$schemaId = $row->getSourceSchema();

		if ($type !== null && $schemaId !== null) {
			try {
				$schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
				foreach ($this->relationTypes->descriptors(schema: $schema, language: $language) as $descriptor) {
					if (($descriptor['type'] ?? null) === $type) {
						return $descriptor;
					}
				}
			} catch (\Exception $e) {
				$this->logger->debug(
					message: '[ObjectRelationService] Could not resolve a relation type against its schema',
					context: ['file' => __FILE__, 'line' => __LINE__, 'type' => $type]
				);
			}
		}

		$label = $row->getLabel();
		if ($label === null || trim($label) === '') {
			$label = $this->defaultLabelFor(origin: (string)$row->getOrigin());
		}

		return [
			'property' => null,
			'type' => $type,
			'label' => $label,
			'inverseLabel' => ($row->getInverseLabel() ?? RelationTypeResolver::FALLBACK_INVERSE_LABEL),
			'symmetric' => (bool)$row->getSymmetric(),
			'inherits' => [],
		];
	}//end descriptorOf()

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

	/**
	 * Read a register or schema reference as an int, when it is one.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return int|null The id.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function asInt(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end asInt()
}//end class
