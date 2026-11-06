<?php

/**
 * OpenRegister InverseRelationRender
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Fills an object's inverse properties with the rendered objects that point back at it.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
class InverseRelationRender {

	/**
	 * Constructor.
	 *
	 * @param InverseRenderSupport   $support The renderer's lookups.
	 * @param InverseRelationPreload $preload The batch loader and its cache.
	 */
	public function __construct(
		private readonly InverseRenderSupport $support,
		private readonly InverseRelationPreload $preload,
	) {
	}//end __construct()

	/**
	 * Handles inversed properties for an object.
	 *
	 * @param ObjectEntity $entity     The entity to process
	 * @param array        $objectData The current object data
	 *
	 * @return array The updated object data with inversed properties
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function handleInversedProperties(ObjectEntity $entity, array $objectData): array {
		$lookup = $this->lookupFor(entity: $entity);
		if (empty($lookup->inversedProperties) === true) {
			return $objectData;
		}

		if ($this->warmCache(lookup: $lookup) === true) {
			return $this->handleInversedPropertiesFromCache(lookup: $lookup, objectData: $objectData);
		}

		return $this->fromReferencingObjects(lookup: $lookup, objectData: $objectData);
	}//end handleInversedProperties()

	/**
	 * An entity with the inverse properties its schema declares; none when the schema is unknown.
	 *
	 * @param ObjectEntity $entity The entity to process.
	 *
	 * @return InverseLookup The lookup.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function lookupFor(ObjectEntity $entity): InverseLookup {
		$lookup = new InverseLookup();
		$lookup->entity = $entity;
		$schema = ($this->support->schemas->find)($entity->getSchema());
		if ($schema !== null) {
			$lookup->inversedProperties = $this->preload->getInversedProperties(schema: $schema);
		}

		return $lookup;
	}//end lookupFor()

	/**
	 * Route a single read through the batched machinery the list path uses.
	 *
	 * The batch preload only runs on the list path, so the cache is cold on a
	 * single render. ALL inverse properties are preloaded, not just the extended
	 * ones: a single read has always resolved every inverse property once any one
	 * of them is extended, and a subset would silently empty the others.
	 *
	 * @param InverseLookup $lookup The entity and its inverse properties.
	 *
	 * @return bool True when the cache can answer.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function warmCache(InverseLookup $lookup): bool {
		$propertyNames = array_keys($lookup->inversedProperties);
		if ($this->support->objects->cache->hasCachedInverse(entity: $lookup->entity, propertyNames: $propertyNames) === true) {
			return true;
		}

		$this->preload->preloadInverseRelationships(entities: [$lookup->entity], extend: $propertyNames);

		return $this->support->objects->cache->hasCachedInverse(entity: $lookup->entity, propertyNames: $propertyNames);
	}//end warmCache()

	/**
	 * Fill the inverse properties through the slower cross-table query.
	 *
	 * Only reached when the batched preload could not populate the cache, such as
	 * an unresolvable target schema reference or a failed batch query.
	 *
	 * @param InverseLookup $lookup     The entity and its inverse properties.
	 * @param array         $objectData The current object data.
	 *
	 * @return array The object data with its inverse properties filled.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function fromReferencingObjects(InverseLookup $lookup, array $objectData): array {
		$lookup->referencingObjects = ($this->support->objects->readable)(
			$this->findInverseReferencingObjects(entity: $lookup->entity, inversedProperties: $lookup->inversedProperties)
		);
		($this->support->objects->rememberBehind)($lookup->referencingObjects);

		return $this->applyInverseLookup(lookup: $lookup, objectData: $objectData);
	}//end fromReferencingObjects()

	/**
	 * The objects that reference an entity, through _relations and through every extra inversedBy field.
	 *
	 * For multi-field inversedBy, columns are also searched directly, since
	 * _relations may not contain UUIDs stored in object-format fields
	 * (e.g., {"value": "uuid"}).
	 *
	 * @param ObjectEntity $entity             The entity being rendered.
	 * @param array        $inversedProperties The inverse property configurations by name.
	 *
	 * @return ObjectEntity[] The referencing objects, deduplicated by UUID.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function findInverseReferencingObjects(ObjectEntity $entity, array $inversedProperties): array {
		$referencingObjects = $this->support->mapper->findByRelation($entity->getUuid());
		foreach ($inversedProperties as $propConfig) {
			$additionalResults = $this->findByExtraInverseFields(entity: $entity, propConfig: $propConfig);
			$existingUuids = array_map(fn (ObjectEntity $obj) => $obj->getUuid(), $referencingObjects);
			foreach ($additionalResults as $obj) {
				if (in_array($obj->getUuid(), $existingUuids, true) === false) {
					$referencingObjects[] = $obj;
					$existingUuids[] = $obj->getUuid();
				}
			}
		}

		return $referencingObjects;
	}//end findInverseReferencingObjects()

	/**
	 * The objects that reference an entity through the second and later fields of a multi-field inversedBy.
	 *
	 * @param ObjectEntity $entity     The entity being rendered.
	 * @param array        $propConfig One inverse property configuration.
	 *
	 * @return ObjectEntity[] The referencing objects, empty for a single-field inversedBy.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function findByExtraInverseFields(ObjectEntity $entity, array $propConfig): array {
		$inversedByValue = $propConfig['items']['inversedBy'] ?? $propConfig['inversedBy'] ?? null;
		if (is_array($inversedByValue) === false || count($inversedByValue) <= 1) {
			return [];
		}

		$targetSchemaId = $this->targetSchemaIdOf(propConfig: $propConfig);
		if (empty($targetSchemaId) === true) {
			return [];
		}

		$batch = self::entityBatch(entity: $entity, fields: $inversedByValue);
		$batch->targetSchemaId = $targetSchemaId;

		return $this->preload->queryReferencingObjects(batch: $batch);
	}//end findByExtraInverseFields()

	/**
	 * A batch of one entity.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 * @param array        $fields The fields that may hold the reference back.
	 *
	 * @return InverseBatch The batch, without its target schema.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function entityBatch(ObjectEntity $entity, array $fields): InverseBatch {
		$batch = new InverseBatch();
		$batch->entityUuids = [$entity->getUuid()];
		$batch->registerId = (int)$entity->getRegister();
		$batch->inversedByFields = $fields;

		return $batch;
	}//end entityBatch()

	/**
	 * The schema id a property's $ref resolves to.
	 *
	 * @param array $propConfig One inverse property configuration.
	 *
	 * @return string The schema id, empty when the property has no $ref.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function targetSchemaIdOf(array $propConfig): string {
		$targetSchemaRef = $propConfig['items']['$ref'] ?? $propConfig['$ref'] ?? null;
		if ($targetSchemaRef === null) {
			return '';
		}

		return ($this->support->schemas->resolveId)($targetSchemaRef);
	}//end targetSchemaIdOf()

	/**
	 * Fill every inverse property of the object data from the referencing objects of a lookup.
	 *
	 * For a property like 'deelnemers' with inversedBy='deelnames', the objects
	 * that hold our UUID in THEIR 'deelnames' field land in OUR 'deelnemers'.
	 *
	 * @param InverseLookup $lookup     The entity, its inverse properties and its referencing objects.
	 * @param array         $objectData The current object data.
	 *
	 * @return array The object data with its inverse properties filled.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyInverseLookup(InverseLookup $lookup, array $objectData): array {
		foreach ($lookup->inversedProperties as $propertyName => $propertyConfig) {
			$target = $this->inverseTarget(entity: $lookup->entity, propertyConfig: $propertyConfig);
			if ($target === null) {
				continue;
			}

			$matching = self::matchingInverseObjects(lookup: $lookup, target: $target);
			$objectData[$propertyName] = $this->inverseValue(objects: $matching, isArray: $target->isArray);
		}

		return $objectData;
	}//end applyInverseLookup()

	/**
	 * Handle inversed properties using the preloaded cache.
	 *
	 * @param InverseLookup $lookup     The entity and its inverse properties.
	 * @param array         $objectData The current object data
	 *
	 * @return array The updated object data with inversed properties populated
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleInversedPropertiesFromCache(InverseLookup $lookup, array $objectData): array {
		$entityUuid = $lookup->entity->getUuid();
		foreach ($lookup->inversedProperties as $propertyName => $propertyConfig) {
			if (self::declaration(propertyConfig: $propertyConfig)['field'] === null) {
				continue;
			}

			$objectData[$propertyName] = $this->inverseValue(
				objects: $this->support->objects->cache->cachedObjects(entityUuid: $entityUuid, propName: $propertyName),
				isArray: ($propertyConfig['type'] ?? null) === 'array'
			);
		}

		return $objectData;
	}//end handleInversedPropertiesFromCache()

	/**
	 * Render objects to their full data, as a regular _extend does: all of them, or the last one.
	 *
	 * @param ObjectEntity[] $objects The objects for one property.
	 * @param bool           $isArray Whether the property holds a list.
	 *
	 * @return array|null The property's value.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function inverseValue(array $objects, bool $isArray): ?array {
		$renderedObjects = array_map($this->support->objects->render, $objects);
		if ($isArray === true) {
			return $renderedObjects;
		}

		if (empty($renderedObjects) === true) {
			return null;
		}

		return end($renderedObjects);
	}//end inverseValue()

	/**
	 * Where one inverse property is declared: on its items, or on the property itself.
	 *
	 * @param array $propertyConfig One inverse property configuration.
	 *
	 * @return array{field: mixed, schema: mixed} The inversedBy value and the $ref next to it.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function declaration(array $propertyConfig): array {
		$source = $propertyConfig;
		if (($propertyConfig['type'] ?? null) === 'array' && ($propertyConfig['items']['inversedBy'] ?? null) !== null) {
			$source = $propertyConfig['items'];
		}

		return ['field' => $source['inversedBy'] ?? null, 'schema' => $source['$ref'] ?? null];
	}//end declaration()

	/**
	 * Where one inverse property looks: the fields that point back, the schema they live in, and its cardinality.
	 *
	 * @param ObjectEntity $entity         The entity being rendered.
	 * @param array        $propertyConfig One inverse property configuration.
	 *
	 * @return InverseTarget|null The target, or null when the property has no inversedBy.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function inverseTarget(ObjectEntity $entity, array $propertyConfig): ?InverseTarget {
		$declared = self::declaration(propertyConfig: $propertyConfig);
		if ($declared['field'] === null) {
			return null;
		}

		$target = new InverseTarget();
		$target->isArray = ($propertyConfig['type'] ?? null) === 'array';
		$target->fields = (array)$declared['field'];
		$target->entityUuid = $entity->getUuid();

		// Use the current schema if no target is specified.
		$target->schemaId = $entity->getSchema();
		if ($declared['schema'] !== null) {
			$target->schemaId = ($this->support->schemas->resolveId)($declared['schema']);
		}

		return $target;
	}//end inverseTarget()

	/**
	 * The referencing objects that hold the entity's UUID in ANY of the target's fields and live in its schema.
	 *
	 * Supports multiple field names, for cases like koppelingen where the
	 * entity can appear as either moduleA or moduleB.
	 *
	 * @param InverseLookup $lookup The entity and its referencing objects.
	 * @param InverseTarget $target The fields and schema to match.
	 *
	 * @return ObjectEntity[] The matching objects, reindexed.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function matchingInverseObjects(InverseLookup $lookup, InverseTarget $target): array {
		return array_values(
			array_filter(
				$lookup->referencingObjects,
				static fn (ObjectEntity $object): bool => self::pointsBack(object: $object, target: $target)
			)
		);
	}//end matchingInverseObjects()

	/**
	 * Whether an object lives in the target's schema and holds the entity's UUID in one of its fields.
	 *
	 * @param ObjectEntity  $object The referencing object.
	 * @param InverseTarget $target The fields, schema and entity to match.
	 *
	 * @return bool True when the object points back.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function pointsBack(ObjectEntity $object, InverseTarget $target): bool {
		if ($object->getSchema() !== $target->schemaId) {
			return false;
		}

		$data = $object->getObject();
		foreach ($target->fields as $field) {
			if (isset($data[$field]) === true
				&& self::referencePointsAt(referenceValue: $data[$field], uuid: $target->entityUuid) === true
			) {
				return true;
			}
		}

		return false;
	}//end pointsBack()

	/**
	 * Whether a stored reference value points at a UUID.
	 *
	 * Handles object references in {"value": "uuid"} format, arrays of
	 * references, and single values that end in the UUID (such as a URL).
	 *
	 * @param mixed       $referenceValue The stored reference value.
	 * @param string|null $uuid           The UUID to look for.
	 *
	 * @return bool True when the value references the UUID.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function referencePointsAt(mixed $referenceValue, ?string $uuid): bool {
		if (is_array($referenceValue) === true && isset($referenceValue['value']) === true) {
			$referenceValue = $referenceValue['value'];
		}

		if (is_array($referenceValue) === true) {
			return in_array($uuid, $referenceValue, true);
		}

		return str_ends_with(haystack: $referenceValue, needle: (string)$uuid);
	}//end referencePointsAt()
}//end class
