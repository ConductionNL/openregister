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
 * Batch-loads the objects that point back at a page of entities into the inverse relation cache.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
class InverseRelationPreload {

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
	public function getInversedProperties(Schema $schema): array {
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
	 * The objects of a batch's schema that hold one of its entities in its first field or a later one.
	 *
	 * Later fields go along so the SQL also searches columns that may store
	 * references in {"value": "uuid"} format not in _relations.
	 *
	 * @param InverseBatch $batch The property to load.
	 *
	 * @return ObjectEntity[] The referencing objects.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function queryReferencingObjects(InverseBatch $batch): array {
		return $this->support->mapper->findByRelationBatchInSchema(
			uuids: $batch->entityUuids,
			schemaId: (int)$batch->targetSchemaId,
			registerId: $batch->registerId,
			fieldName: $batch->inversedByFields[0],
			additionalFieldNames: array_slice($batch->inversedByFields, 1)
		);
	}//end queryReferencingObjects()

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
		$schema = ($this->support->schemas->find)($entity->getSchema());
		if ($schema === null) {
			return [];
		}

		return $this->filterExtendedInverseProperties(
			inversedProperties: $this->getInversedProperties(schema: $schema),
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

		$targetSchemaId = ($this->support->schemas->resolveId)($inverseConfig['targetSchemaRef']);
		if (empty($targetSchemaId) === true || ($this->support->schemas->find)($targetSchemaId) === null) {
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
			$referencingObjects = ($this->support->objects->readable)($this->batchLoadReferencingObjects(batch: $batch));

			// Empty entries for every entity keep the render off the slow per-entity
			// findByRelation() path, which scans all magic tables with LIKE.
			$this->support->objects->cache->initializeInverseCacheEntries(entityUuids: $batch->entityUuids, propName: $batch->propName);
			$this->support->objects->cache->indexReferencingObjects(referencingObjects: $referencingObjects, batch: $batch);
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

		if ((int)$batch->targetSchemaId <= 0 || $batch->registerId <= 0) {
			return [];
		}

		return $this->queryReferencingObjects(batch: $batch);
	}//end batchLoadReferencingObjects()

}//end class
