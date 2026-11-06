<?php

/**
 * OpenRegister InverseRelationPreload
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Batch-loads the objects that point back at a page of entities, and keeps them per entity and property.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
class InverseRelationPreload {

	/**
	 * Referencing objects, keyed by entity UUID and property name.
	 *
	 * @var array<string, ObjectEntity[]>
	 */
	private array $inverseRelationCache = [];

	/**
	 * Constructor.
	 *
	 * @param InverseRenderSupport $support The renderer's lookups.
	 */
	public function __construct(private readonly InverseRenderSupport $support) {
	}//end __construct()

	/**
	 * Gets the inversed properties from a schema.
	 *
	 * @param Schema $schema The schema to check for inversed properties
	 *
	 * @return array The property configurations that declare inversedBy, by property name
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function getInversedProperties(Schema $schema): array {
		return array_filter(
			$schema->getProperties(),
			function (array $property): bool {
				return (isset($property['inversedBy'])
					&& empty($property['inversedBy']) === false)
					|| (isset($property['items']['inversedBy'])
					&& empty($property['items']['inversedBy']) === false);
			}
		);
	}//end getInversedProperties()

	/**
	 * Batch-load the extended inverse properties of a page of entities of one schema.
	 *
	 * @param array $entities The entities being rendered
	 * @param array $extend   The properties to extend
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function preloadInverseRelationships(array $entities, array $extend): void {
		$firstEntity = self::firstEntity(entities: $entities, extend: $extend);
		if ($firstEntity === null) {
			return;
		}

		$properties = $this->extendedInverseProperties(entity: $firstEntity, extend: $extend);
		$batch = new InverseBatch();
		$batch->entityUuids = $this->collectEntityUuids(entities: $entities);
		$batch->registerId = (int)$firstEntity->getRegister();
		if (empty($properties) === true || empty($batch->entityUuids) === true) {
			return;
		}

		$this->preloadProperties(batch: $batch, properties: $properties);
	}//end preloadInverseRelationships()

	/**
	 * Whether the cache holds an entry for any of the given properties of an entity.
	 *
	 * @param ObjectEntity $entity        The entity being rendered.
	 * @param array        $propertyNames The inverse property names.
	 *
	 * @return bool True when at least one property is cached.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function hasCachedInverse(ObjectEntity $entity, array $propertyNames): bool {
		$entityUuid = $entity->getUuid();
		foreach ($propertyNames as $propName) {
			if (isset($this->inverseRelationCache[$entityUuid . '_' . $propName]) === true) {
				return true;
			}
		}

		return false;
	}//end hasCachedInverse()

	/**
	 * The objects cached for one property of an entity.
	 *
	 * @param string|null $entityUuid The entity's UUID.
	 * @param string      $propName   The inverse property name.
	 *
	 * @return ObjectEntity[] The referencing objects.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function cachedObjects(?string $entityUuid, string $propName): array {
		return $this->inverseRelationCache[$entityUuid . '_' . $propName] ?? [];
	}//end cachedObjects()

	/**
	 * The first entity of a page, or null when there is nothing to preload.
	 *
	 * @param array $entities The entities being rendered
	 * @param array $extend   The properties to extend
	 *
	 * @return ObjectEntity|null The entity that stands for the page's schema and register.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function firstEntity(array $entities, array $extend): ?ObjectEntity {
		if (empty($entities) === true || empty($extend) === true) {
			return null;
		}

		$firstEntity = reset($entities);
		if ($firstEntity instanceof ObjectEntity === false) {
			return null;
		}

		return $firstEntity;
	}//end firstEntity()

	/**
	 * The inverse properties of an entity's schema that are being extended.
	 *
	 * @param ObjectEntity $entity The entity that stands for the page's schema
	 * @param array        $extend The properties to extend
	 *
	 * @return array The inverse property configurations, by property name
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendedInverseProperties(ObjectEntity $entity, array $extend): array {
		$schema = ($this->support->schemaLookup)($entity->getSchema());
		if ($schema === null) {
			return [];
		}

		return $this->filterExtendedInverseProperties(
			inversedProperties: self::getInversedProperties(schema: $schema),
			extend: $extend
		);
	}//end extendedInverseProperties()

	/**
	 * Filter inversed properties to only those being extended.
	 *
	 * @param array $inversedProperties All inversed properties from the schema
	 * @param array $extend             The _extend parameter specifying which properties to extend
	 *
	 * @return array Filtered array of inverse properties that are being extended
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function filterExtendedInverseProperties(array $inversedProperties, array $extend): array {
		$inversePropsExtend = [];
		foreach ($inversedProperties as $propName => $propConfig) {
			if (in_array($propName, $extend, true) === true || in_array('all', $extend, true) === true) {
				$inversePropsExtend[$propName] = $propConfig;
			}
		}

		return $inversePropsExtend;
	}//end filterExtendedInverseProperties()

	/**
	 * Collect UUIDs from an array of entities.
	 *
	 * @param array $entities Array of ObjectEntity instances
	 *
	 * @return array Array of UUID strings
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function collectEntityUuids(array $entities): array {
		$entityUuids = [];
		foreach ($entities as $entity) {
			if ($entity instanceof ObjectEntity === true && $entity->getUuid() !== null) {
				$entityUuids[] = $entity->getUuid();
			}
		}

		return $entityUuids;
	}//end collectEntityUuids()

	/**
	 * Load each inverse property of a batch.
	 *
	 * @param InverseBatch $batch      The entities and their register.
	 * @param array        $properties The inverse property configurations, by property name
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preloadProperties(InverseBatch $batch, array $properties): void {
		$this->support->logger->debug(
			message: '[RenderObject] [INVERSE_PRELOAD] Starting batch inverse preload',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityCount' => count($batch->entityUuids),
				'inverseProperties' => array_keys($properties),
			]
		);

		foreach ($properties as $propName => $propConfig) {
			$batch->propName = $propName;
			$this->preloadSingleInverseProperty(batch: $batch, propConfig: $propConfig);
		}
	}//end preloadProperties()

	/**
	 * Extract inverse configuration (target schema ref and inversedBy fields) from a property config.
	 *
	 * Returns null if the configuration is incomplete (missing $ref or inversedBy).
	 *
	 * @param array $propConfig The property configuration array
	 *
	 * @return array|null Array with keys 'targetSchemaRef' and 'inversedByFields', or null if invalid
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extractInverseConfig(array $propConfig): ?array {
		$targetSchemaRef = $propConfig['items']['$ref'] ?? $propConfig['$ref'] ?? null;
		$inversedByField = $propConfig['items']['inversedBy'] ?? $propConfig['inversedBy'] ?? null;

		if ($targetSchemaRef === null || $inversedByField === null) {
			return null;
		}

		// Normalize inversedBy to an array to support multi-field inverse relations.
		// Example: "inversedBy": ["moduleA", "moduleB"] means the entity can appear in either field.
		$inversedByFields = [$inversedByField];
		if (is_array($inversedByField) === true) {
			$inversedByFields = $inversedByField;
		}

		return [
			'targetSchemaRef' => $targetSchemaRef,
			'inversedByFields' => $inversedByFields,
		];
	}//end extractInverseConfig()

	/**
	 * Preload inverse objects for a single inverse property.
	 *
	 * @param InverseBatch $batch      The entities, their register and the property name.
	 * @param array        $propConfig The property configuration array
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preloadSingleInverseProperty(InverseBatch $batch, array $propConfig): void {
		$inverseConfig = $this->extractInverseConfig(propConfig: $propConfig);
		if ($inverseConfig === null) {
			return;
		}

		$targetSchemaId = ($this->support->schemaIdLookup)($inverseConfig['targetSchemaRef']);
		if (empty($targetSchemaId) === true || ($this->support->schemaLookup)($targetSchemaId) === null) {
			return;
		}

		$batch->targetSchemaId = $targetSchemaId;
		$batch->inversedByFields = $inverseConfig['inversedByFields'];
		$this->loadBatch(batch: $batch);
	}//end preloadSingleInverseProperty()

	/**
	 * Load and index one property's referencing objects; a failure leaves the per-entity lookup to answer.
	 *
	 * @param InverseBatch $batch The property to load.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function loadBatch(InverseBatch $batch): void {
		try {
			$referencingObjects = $this->batchLoadReferencingObjects(batch: $batch);

			// Empty entries for every entity keep the render off the slow per-entity
			// findByRelation() path, which scans all magic tables with LIKE.
			$this->initializeInverseCacheEntries(entityUuids: $batch->entityUuids, propName: $batch->propName);
			$this->indexReferencingObjects(referencingObjects: $referencingObjects, batch: $batch);
			$this->logBatchLoaded(batch: $batch, found: count($referencingObjects));
		} catch (Exception $e) {
			$this->support->logger->warning(
				message: '[RenderObject] [INVERSE_PRELOAD] Batch preload failed, falling back to per-entity lookup',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $batch->propName, 'error' => $e->getMessage()]
			);
		}
	}//end loadBatch()

	/**
	 * Log a loaded batch.
	 *
	 * @param InverseBatch $batch The loaded property.
	 * @param int          $found The number of referencing objects.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function logBatchLoaded(InverseBatch $batch, int $found): void {
		$this->support->logger->debug(
			message: '[RenderObject] [INVERSE_PRELOAD] Batch loaded inverse relationships',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'property' => $batch->propName,
				'targetSchema' => $batch->targetSchemaId,
				'foundObjects' => $found,
			]
		);
	}//end logBatchLoaded()

	/**
	 * Batch load the objects of the target schema that reference any of the batch's entities.
	 *
	 * @param InverseBatch $batch The property to load.
	 *
	 * @return array Array of ObjectEntity instances that reference the given UUIDs
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function batchLoadReferencingObjects(InverseBatch $batch): array {
		if (empty($batch->entityUuids) === true || empty($batch->inversedByFields) === true) {
			return [];
		}

		$schemaIdInt = (int)$batch->targetSchemaId;
		if ($schemaIdInt <= 0 || $batch->registerId <= 0) {
			return [];
		}

		// Later fields go along so the SQL also searches columns that may store
		// references in {"value": "uuid"} format not in _relations.
		return $this->support->mapper->findByRelationBatchInSchema(
			uuids: $batch->entityUuids,
			schemaId: $schemaIdInt,
			registerId: $batch->registerId,
			fieldName: $batch->inversedByFields[0],
			additionalFieldNames: array_slice($batch->inversedByFields, 1)
		);
	}//end batchLoadReferencingObjects()

	/**
	 * Pre-initialize inverse relation cache entries for all entities with empty arrays.
	 *
	 * @param array  $entityUuids Array of entity UUIDs
	 * @param string $propName    The inverse property name
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function initializeInverseCacheEntries(array $entityUuids, string $propName): void {
		foreach ($entityUuids as $entityUuid) {
			$cacheKey = $entityUuid . '_' . $propName;
			if (isset($this->inverseRelationCache[$cacheKey]) === false) {
				$this->inverseRelationCache[$cacheKey] = [];
			}
		}
	}//end initializeInverseCacheEntries()

	/**
	 * Index referencing objects into the cache by the entity they point at.
	 *
	 * @param array        $referencingObjects Array of ObjectEntity instances to index
	 * @param InverseBatch $batch              The fields to read, the entities to match and the property name.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function indexReferencingObjects(array $referencingObjects, InverseBatch $batch): void {
		foreach ($referencingObjects as $refObject) {
			foreach ($this->matchedEntityUuids(refData: $refObject->getObject(), batch: $batch) as $uuid) {
				$this->addToCache(refObject: $refObject, cacheKey: $uuid . '_' . $batch->propName);
				($this->support->remember)($refObject);
			}
		}
	}//end indexReferencingObjects()

	/**
	 * The batch's entities that a referencing object's data points at, through any inversedBy field.
	 *
	 * @param array        $refData The referencing object's data
	 * @param InverseBatch $batch   The fields to read and the entities to match.
	 *
	 * @return string[] The matched UUIDs, one per reference.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function matchedEntityUuids(array $refData, InverseBatch $batch): array {
		$matched = [];
		foreach ($batch->inversedByFields as $field) {
			foreach ($this->resolveReferencedUuids(refData: $refData, field: $field) as $uuid) {
				if ($uuid !== null && in_array($uuid, $batch->entityUuids, true) === true) {
					$matched[] = $uuid;
				}
			}
		}

		return $matched;
	}//end matchedEntityUuids()

	/**
	 * Add an object to one cache entry, once, even when it matches through several fields.
	 *
	 * @param ObjectEntity $refObject The referencing object.
	 * @param string       $cacheKey  The cache entry.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function addToCache(ObjectEntity $refObject, string $cacheKey): void {
		$existingUuids = array_map(
			fn (ObjectEntity $obj) => $obj->getUuid(),
			$this->inverseRelationCache[$cacheKey] ?? []
		);
		if (in_array($refObject->getUuid(), $existingUuids, true) === false) {
			$this->inverseRelationCache[$cacheKey][] = $refObject;
		}
	}//end addToCache()

	/**
	 * Resolve referenced UUIDs from a referencing object's data field.
	 *
	 * Handles a simple UUID string, an object reference {"value": "uuid"} and an array of UUIDs.
	 *
	 * @param array  $refData The referencing object's data array
	 * @param string $field   The field name to extract referenced UUIDs from
	 *
	 * @return array Array of UUID strings (may contain nulls which should be filtered by caller)
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveReferencedUuids(array $refData, string $field): array {
		$referencedUuid = $refData[$field] ?? null;

		if (is_array($referencedUuid) === true && isset($referencedUuid['value']) === true) {
			$referencedUuid = $referencedUuid['value'];
		}

		if (is_array($referencedUuid) === true) {
			return $referencedUuid;
		}

		return [$referencedUuid];
	}//end resolveReferencedUuids()
}//end class
