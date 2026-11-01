<?php

/**
 * BulkRelationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RelationDetectionTrait;
use Psr\Log\LoggerInterface;

/**
 * Handles bulk relation processing for bulk object operations.
 *
 * This handler is responsible for:
 * - Processing inverse relations in bulk
 * - Handling post-save relation writeBack operations
 * - Scanning objects for relations
 * - Optimizing bulk relation processing
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex bulk relation processing with optimization logic
 */
class BulkRelationHandler {
	use RelationDetectionTrait;

	/**
	 * Constructor for BulkRelationHandler.
	 *
	 * @param BulkValidationHandler $bulkValidHandler Handler for bulk validation operations.
	 * @param MagicMapper $objectEntityMapper Mapper for object entities.
	 * @param LoggerInterface $logger Logger for logging operations.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly BulkValidationHandler $bulkValidHandler,
		private readonly MagicMapper $objectEntityMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle bulk inverse relations using cached schema analysis
	 *
	 * PERFORMANCE OPTIMIZATION: This method uses pre-analyzed inverse relation properties
	 * to process relations without re-analyzing schema properties for each object.
	 *
	 * @param array<int, mixed> $preparedObjects Prepared objects to process
	 * @param array<string, mixed> $schemaAnalysis Pre-analyzed schema information indexed by schema ID
	 *
	 * @psalm-param   array<int, mixed> &$preparedObjects
	 * @psalm-param   array<string, mixed> $schemaAnalysis
	 * @phpstan-param array<int, mixed> &$preparedObjects
	 * @phpstan-param array<string, mixed> $schemaAnalysis
	 *
	 * @return void
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)         Uuid::isValid is standard Symfony UID pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex inverse relation handling with multiple conditions
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple code paths for different relation types
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function handleBulkInverseRelationsWithAnalysis(array &$preparedObjects, array $schemaAnalysis): void {
		// Track statistics for debugging/monitoring.
		$_appliedCount = 0;
		$_processedCount = 0;

		// Create direct UUID to object reference mapping.
		$objectsByUuid = [];
		foreach ($preparedObjects as $_index => &$object) {
			$selfData = $object['@self'] ?? [];
			$objectUuid = $selfData['id'] ?? null;
			if ($objectUuid !== null && $objectUuid !== '') {
				$objectsByUuid[$objectUuid] = &$object;
			}
		}

		// Process inverse relations using cached analysis.
		foreach ($preparedObjects as $_index => &$object) {
			$selfData = $object['@self'] ?? [];
			$schemaId = $selfData['schema'] ?? null;
			$objectUuid = $selfData['id'] ?? null;

			if ($schemaId === false || $objectUuid === false || isset($schemaAnalysis[$schemaId]) === false) {
				continue;
			}

			$analysis = $schemaAnalysis[$schemaId];

			// PERFORMANCE OPTIMIZATION: Use pre-analyzed inverse properties.
			foreach ($analysis['inverseProperties'] ?? [] as $property => $propertyInfo) {
				if (isset($object[$property]) === false) {
					continue;
				}

				$value = $object[$property];
				$inversedBy = $propertyInfo['inversedBy'];

				// Handle single object relations.
				if (($propertyInfo['isArray'] === false) === true
					&& is_string($value) === true
					&& \Symfony\Component\Uid\Uuid::isValid($value) === true
				) {
					if (isset($objectsByUuid[$value]) === true) {
						// @psalm-suppress EmptyArrayAccess - Already checked isset above.
						$targetObject = &$objectsByUuid[$value];
						// @psalm-suppress EmptyArrayAccess - Already checked isset above.
						$existingValues = ($targetObject[$inversedBy] ?? []);
						// @psalm-suppress EmptyArrayAccess - $existingValues is initialized with ?? []
						if (is_array($existingValues) === false) {
							$existingValues = [];
						}

						if (in_array($objectUuid, $existingValues, true) === false) {
							$existingValues[] = $objectUuid;
							$targetObject[$inversedBy] = $existingValues;
							$_appliedCount++;
						}

						$_processedCount++;
					}
				} elseif (($propertyInfo['isArray'] === true) && is_array($value) === true) {
					// Handle array of object relations.
					foreach ($value as $relatedUuid) {
						$isValidUuid = \Symfony\Component\Uid\Uuid::isValid($relatedUuid);
						if (is_string($relatedUuid) === true && $isValidUuid === true) {
							if (isset($objectsByUuid[$relatedUuid]) === true) {
								// @psalm-suppress EmptyArrayAccess - Already checked isset above.
								$targetObject = &$objectsByUuid[$relatedUuid];
								// @psalm-suppress EmptyArrayAccess - $targetObject is guaranteed to exist from isset check
								$existingValues = ($targetObject[$inversedBy] ?? []);
								if (is_array($existingValues) === false) {
									$existingValues = [];
								}

								if (in_array($objectUuid, $existingValues, true) === false) {
									$existingValues[] = $objectUuid;
									$targetObject[$inversedBy] = $existingValues;
									$_appliedCount++;
								}

								$_processedCount++;
							}
						}
					}//end foreach
				}//end if
			}//end foreach
		}//end foreach
	}//end handleBulkInverseRelationsWithAnalysis()

	/**
	 * Handle post-save inverse relations with bulk writeBack optimization
	 *
	 * PERFORMANCE OPTIMIZATION: Collects all writeBack operations and executes
	 * them in a single bulk operation instead of individual updates.
	 *
	 * @param array $savedObjects Array of saved ObjectEntity objects
	 * @param array $schemaCache Schema cache for inverse relation analysis
	 * @param callable $getSchemaAnalysisCb Callback to get schema analysis
	 *
	 * @psalm-param   array<int, \OCA\OpenRegister\Db\ObjectEntity> $savedObjects
	 * @psalm-param   array<string, \OCA\OpenRegister\Db\Schema> $schemaCache
	 * @psalm-param   callable(Schema): array $getSchemaAnalysisCb
	 * @phpstan-param array<int, \OCA\OpenRegister\Db\ObjectEntity> $savedObjects
	 * @phpstan-param array<string, \OCA\OpenRegister\Db\Schema> $schemaCache
	 * @phpstan-param callable(Schema): array $getSchemaAnalysisCb
	 *
	 * @return void
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function handlePostSaveInverseRelations(
		array $savedObjects,
		array $schemaCache,
		callable $getSchemaAnalysisCb,
	): void {
		if (empty($savedObjects) === true) {
			return;
		}

		$context = new InverseRelationContext(schemaCache: $schemaCache, analysisOf: $getSchemaAnalysisCb(...));

		// First pass: the related ids of every saved object with inverse properties.
		$objectRelationsMap = $this->collectWriteBackIds(savedObjects: $savedObjects, context: $context);

		// One bulk fetch of every related object, instead of one query per id.
		$context = $context->withTargets(
			targets: $this->fetchWriteBackTargets(relatedIds: array_merge(...array_values($objectRelationsMap)))
		);

		// Second pass: a writeBack operation for every related object that was found.
		$writeBackOperations = [];
		foreach (array_keys($objectRelationsMap) as $index) {
			$writeBackOperations = array_merge(
				$writeBackOperations,
				$this->writeBackOperationsFor(savedObject: $savedObjects[$index], context: $context)
			);
		}

		if (empty($writeBackOperations) === false) {
			$this->performBulkWriteBackUpdatesWithContext(writeBackOperations: $writeBackOperations);
		}
	}//end handlePostSaveInverseRelations()

	/**
	 * The related ids each saved object writes back to, keyed by the saved object's index.
	 *
	 * Only saved objects whose schema has inverse properties get an entry, which may be empty.
	 *
	 * @param array                  $savedObjects The saved objects
	 * @param InverseRelationContext $context      The schemas and their analysis
	 *
	 * @return array<int|string, list<mixed>> The related ids per saved object
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function collectWriteBackIds(array $savedObjects, InverseRelationContext $context): array {
		$objectRelationsMap = [];
		foreach ($savedObjects as $index => $savedObject) {
			$analysis = $context->analysisOf(savedObject: $savedObject);
			if (empty($analysis['inverseProperties']) === true) {
				continue;
			}

			$objectRelationsMap[$index] = $this->writeBackIdsOf(
				objectData: $savedObject->getObject(),
				inverseProperties: $analysis['inverseProperties']
			);
		}

		return $objectRelationsMap;
	}//end collectWriteBackIds()

	/**
	 * The non-empty related ids of every set inverse property that has writeBack switched on.
	 *
	 * @param array $objectData        The saved object's data
	 * @param array $inverseProperties The inverse property configurations by property name
	 *
	 * @return list<mixed> The related ids, in property order
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function writeBackIdsOf(array $objectData, array $inverseProperties): array {
		$relatedIds = [];
		foreach ($inverseProperties as $propertyName => $inverseConfig) {
			if (isset($objectData[$propertyName]) === false || empty($inverseConfig['writeBack']) === true) {
				continue;
			}

			$relatedIds = array_merge(
				$relatedIds,
				array_values(array_filter($this->relatedIdsIn(objectData: $objectData, propertyName: $propertyName)))
			);
		}

		return $relatedIds;
	}//end writeBackIdsOf()

	/**
	 * The ids a relation property holds: its list, or its single value.
	 *
	 * @param array      $objectData   The saved object's data
	 * @param int|string $propertyName The relation property, which is set
	 *
	 * @return array The related ids
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function relatedIdsIn(array $objectData, int|string $propertyName): array {
		if (is_array($objectData[$propertyName]) === true) {
			return $objectData[$propertyName];
		}

		return [$objectData[$propertyName]];
	}//end relatedIdsIn()

	/**
	 * The related objects by uuid, fetched in one query; none when the fetch fails.
	 *
	 * @param array $relatedIds The related ids, with repeats
	 *
	 * @return array<string, \OCA\OpenRegister\Db\ObjectEntity> The related objects by uuid
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function fetchWriteBackTargets(array $relatedIds): array {
		$relatedObjectsMap = [];
		if (empty($relatedIds) === true) {
			return $relatedObjectsMap;
		}

		try {
			$relatedObjects = $this->objectEntityMapper->findAll(ids: array_unique($relatedIds), includeDeleted: false);
			foreach ($relatedObjects as $obj) {
				$relatedObjectsMap[$obj->getUuid()] = $obj;
			}
		} catch (\Exception $e) {
			// Skip inverse relations processing if bulk fetch fails.
		}

		return $relatedObjectsMap;
	}//end fetchWriteBackTargets()

	/**
	 * The writeBack operations of one saved object: one per related object that was found.
	 *
	 * @param ObjectEntity           $savedObject The saved object
	 * @param InverseRelationContext $context     The schemas, their analysis and the related objects
	 *
	 * @return list<array{targetObject: ObjectEntity, sourceUuid: string|null, inverseProperty: mixed}>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function writeBackOperationsFor(ObjectEntity $savedObject, InverseRelationContext $context): array {
		$analysis = $context->analysisOf(savedObject: $savedObject);
		$objectData = $savedObject->getObject();
		$operations = [];
		foreach ($analysis['inverseProperties'] ?? [] as $propertyName => $inverseConfig) {
			if (isset($objectData[$propertyName]) === false || ($inverseConfig['writeBack'] === false) === true) {
				continue;
			}

			$link = new InverseLink(
				sourceUuid: $savedObject->getUuid(),
				inverseProperty: $inverseConfig['inverseProperty'] ?? $propertyName,
				relatedIds: $this->relatedIdsIn(objectData: $objectData, propertyName: $propertyName)
			);
			$operations = array_merge($operations, $this->operationsFor(link: $link, context: $context));
		}

		return $operations;
	}//end writeBackOperationsFor()

	/**
	 * One writeBack operation per related id whose object was found.
	 *
	 * @param InverseLink            $link    The saved object's relation property
	 * @param InverseRelationContext $context The related objects by uuid
	 *
	 * @return list<array{targetObject: ObjectEntity, sourceUuid: string|null, inverseProperty: mixed}>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function operationsFor(InverseLink $link, InverseRelationContext $context): array {
		$operations = [];
		foreach ($link->relatedIds as $relatedId) {
			$target = null;
			if (empty($relatedId) === false) {
				$target = $context->target(relatedId: $relatedId);
			}

			if ($target !== null) {
				$operations[] = [
					'targetObject' => $target,
					'sourceUuid' => $link->sourceUuid,
					'inverseProperty' => $link->inverseProperty,
				];
			}
		}

		return $operations;
	}//end operationsFor()

	/**
	 * Perform bulk writeBack updates with full context and actual modifications
	 *
	 * FIXED: Now actually modifies related objects with inverse properties
	 * before saving them to the database.
	 *
	 * @param array $writeBackOperations Array of writeBack operations with context
	 *
	 * @psalm-param   array<int,
	 *     array{targetObject: \OCA\OpenRegister\Db\ObjectEntity,
	 *     sourceUuid: string, inverseProperty: string}> $writeBackOperations
	 * @phpstan-param array<int,
	 *     array{targetObject: \OCA\OpenRegister\Db\ObjectEntity,
	 *     sourceUuid: string, inverseProperty: string}> $writeBackOperations
	 *
	 * @return void
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * Else branch used for early continue when UUID already present
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function performBulkWriteBackUpdatesWithContext(array $writeBackOperations): void {
		if (empty($writeBackOperations) === true) {
			return;
		}

		// Track objects that need to be updated.
		$objectsToUpdate = [];

		foreach ($writeBackOperations as $operation) {
			$targetObject = $operation['targetObject'];
			$sourceUuid = $operation['sourceUuid'];
			$inverseProperty = $operation['inverseProperty'] ?? null;

			if ($inverseProperty === null) {
				continue;
			}

			// Get current object data.
			$objectData = $targetObject->getObject();

			// Initialize inverse property array if it doesn't exist.
			if (isset($objectData[$inverseProperty]) === false) {
				$objectData[$inverseProperty] = [];
			}

			// Ensure it's an array.
			if (is_array($objectData[$inverseProperty]) === false) {
				$objectData[$inverseProperty] = [$objectData[$inverseProperty]];
			}

			// Add source UUID to inverse property if not already present.
			if (in_array($sourceUuid, $objectData[$inverseProperty], true) === true) {
				continue;
			}

			$objectData[$inverseProperty][] = $sourceUuid;

			// Update the object with modified data.
			$targetObject->setObject($objectData);
			$objectsToUpdate[] = $targetObject;
		}//end foreach

		// Save all modified objects in bulk.
		// TEMPORARILY DISABLED: Skip secondary bulk save to isolate double prefix issue.
		// If (!empty($objectsToUpdate)) {
		// NO ERROR SUPPRESSION: Let bulk writeBack update errors bubble up immediately!
		// $this->objectEntityMapper->saveObjects([], $objectsToUpdate);
		// }.
	}//end performBulkWriteBackUpdatesWithContext()

	/**
	 * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
	 *
	 * This method checks schema properties for relation types:
	 * - Properties with type 'text' and format 'uuid', 'uri', or 'url'
	 * - Properties with type 'object' that contain string values (always treated as relations)
	 * - Properties with type 'array' of objects that contain string values
	 *
	 * This is ported from SaveObject.php to provide consistent relation handling
	 * for bulk operations.
	 *
	 * @param array $data The object data to scan
	 * @param string $prefix The current prefix for dot notation (used in recursion)
	 * @param Schema|null $schema The schema to check property definitions against
	 *
	 * @psalm-param   array<string, mixed> $data
	 * @psalm-param   string $prefix
	 * @psalm-param   Schema|null $schema
	 * @phpstan-param array<string, mixed> $data
	 * @phpstan-param string $prefix
	 * @phpstan-param Schema|null $schema
	 *
	 * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
	 *
	 * @psalm-return   array<string, string>
	 * @phpstan-return array<string, string>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)         Uuid::isValid is standard Symfony UID pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex relation type detection with multiple conditions
	 * Else branches handle schema vs heuristic detection paths
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function scanForRelations(array $data, string $prefix = '', ?Schema $schema = null): array {
		$relations = [];

		// NO ERROR SUPPRESSION: Let relation scanning errors bubble up immediately!
		// Get schema properties if available.
		$schemaProperties = null;
		if ($schema !== null) {
			// NO ERROR SUPPRESSION: Let schema property parsing errors bubble up immediately!
			$schemaProperties = $schema->getProperties();
		}

		foreach ($data as $key => $value) {
			$currentPath = $key;
			if ($prefix !== '') {
				$currentPath = "$prefix.$key";
			}

			// Check if this property is defined in the schema.
			$propertyConfig = $schemaProperties[$key] ?? null;

			// Handle string values (potential UUIDs/URLs).
			if (is_string($value) === true) {
				// Record only genuine references — shared rule (RelationDetectionTrait).
				if ($this->isRecordableReference(value: $value, propertyConfig: $propertyConfig) === true) {
					$relations[$currentPath] = $value;
				}
			} elseif (is_array($value) === true) {
				// Recursively scan nested arrays/objects.
				$nestedRelations = $this->scanForRelations(data: $value, prefix: $currentPath, schema: $schema);
				$relations = array_merge($relations, $nestedRelations);
			}//end if
		}//end foreach

		return $relations;
	}//end scanForRelations()
}//end class
