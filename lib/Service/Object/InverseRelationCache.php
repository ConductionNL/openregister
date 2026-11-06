<?php

/**
 * OpenRegister InverseRelationCache
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

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The objects that point back at the entities being rendered, per entity and inverse property.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
class InverseRelationCache {

	/**
	 * Referencing objects, keyed by entity UUID and property name.
	 *
	 * @var array<string, ObjectEntity[]>
	 */
	private array $inverseRelationCache = [];

	/**
	 * Constructor.
	 *
	 * @param Closure(ObjectEntity): void $remember Keeps an indexed object for extended rendering.
	 */
	public function __construct(private readonly Closure $remember) {
	}//end __construct()

	/**
	 * Forget everything, for a render that admits different objects.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function clear(): void {
		$this->inverseRelationCache = [];
	}//end clear()

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
	 * Pre-initialize inverse relation cache entries for all entities with empty arrays.
	 *
	 * @param array  $entityUuids Array of entity UUIDs
	 * @param string $propName    The inverse property name
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function initializeInverseCacheEntries(array $entityUuids, string $propName): void {
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
	public function indexReferencingObjects(array $referencingObjects, InverseBatch $batch): void {
		foreach ($referencingObjects as $refObject) {
			foreach ($this->matchedEntityUuids(refData: $refObject->getObject(), batch: $batch) as $uuid) {
				$this->addToCache(refObject: $refObject, cacheKey: $uuid . '_' . $batch->propName);
				($this->remember)($refObject);
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
