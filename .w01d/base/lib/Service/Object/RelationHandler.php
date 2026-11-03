<?php

/**
 * RelationHandler
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

namespace OCA\OpenRegister\Service\Object;

use Adbar\Dot;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DraftCallerRule;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles relationship operations for ObjectService.
 *
 * This handler is responsible for:
 * - Extracting relationship IDs from objects
 * - Bulk loading relationships with performance optimizations
 * - Applying inversedBy filters
 * - Managing relationship batching and circuit breakers
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * Reason: Relationship resolution requires complex multi-path logic for performance optimization
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex relationship resolution logic
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * Reason: the class was already 977 lines and 11 collaborators before the
 * label enrichment added a resolver and three helpers
 * (relation-types-with-inverses). It crosses both thresholds now, and the
 * honest fix is to split the reverse-lookup half out of it, which is a change
 * to a file three other lanes are also inside. Reported in the PR body as
 * debt rather than done here.
 */
class RelationHandler {

	/**
	 * Registers and schemas loaded for relation lookups, keyed "registerId_schemaId".
	 *
	 * @var array<string, array{register: Register, schema: Schema}|null>
	 */
	private array $pairCache = [];

	/**
	 * Constructor for RelationHandler.
	 *
	 * @param MagicMapper $objectEntityMapper Mapper for object entities.
	 * @param SchemaMapper $schemaMapper Mapper for schemas.
	 * @param PerformanceHandler $performanceHandler Handler for performance operations.
	 * @param MagicRbacHandler $rbacHandler Handler for RBAC operations.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param RegisterMapper $registerMapper Mapper for registers.
	 * @param RelationTypeResolver|null $relationTypes Resolves what a relation is called from each side.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $objectEntityMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly PerformanceHandler $performanceHandler,
		private readonly MagicRbacHandler $rbacHandler,
		private readonly LoggerInterface $logger,
		private readonly RegisterMapper $registerMapper,
		private readonly ?RelationTypeResolver $relationTypes = null,
	) {
	}//end __construct()

	/**
	 * Apply inversedBy filter to find objects by their inverse relations.
	 *
	 * @param array $filters The filters array (passed by reference).
	 * @param callable $findAllCallback Callback to findAll method.
	 *
	 * @psalm-param array<string, mixed> &$filters
	 * @psalm-param callable(array): array $findAllCallback
	 *
	 * @phpstan-param array<string, mixed> &$filters
	 * @phpstan-param callable(array): array $findAllCallback
	 *
	 * @return ((mixed|null|string)[]|mixed|null|string)[]|null
	 *
	 * @psalm-return   array<array{name: mixed|null|string,...}|mixed|null|string>|null
	 * @phpstan-return array<int, string>|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          Uuid::isValid is standard Symfony UID pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex inverse relation filter logic with multiple conditions
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple conditional paths for schema property handling
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Inverse filter resolution requires comprehensive handling
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function applyInversedByFilter(array &$filters, callable $findAllCallback): ?array {
		if ($filters['schema'] === false) {
			return [];
		}

		$schema = $this->schemaMapper->find($filters['schema']);

		$filterKeysWithSub = array_filter(
			array_keys($filters),
			function ($filter) {
				if (str_contains($filter, '_') === true) {
					return true;
				}

				return false;
			}
		);

		$filtersWithSub = array_intersect_key($filters, array_flip($filterKeysWithSub));

		if (empty($filtersWithSub) === true) {
			return [];
		}

		$filterDot = new Dot(items: $filtersWithSub, parse: true, delimiter: '_');

		$ids = [];

		$iterator = 0;
		foreach ($filterDot as $key => $value) {
			if (isset($schema->getProperties()[$key]['inversedBy']) === false) {
				continue;
			}

			$iterator++;
			$schemaProperties = $schema->getProperties();
			if (is_array($schemaProperties) === false || isset($schemaProperties[$key]) === false) {
				continue;
			}

			$property = $schemaProperties[$key];

			$value = (new Dot($value))->flatten(delimiter: '_');

			// @TODO fix schema finder.
			$value['schema'] = $property['$ref'] ?? null;

			$objects = $findAllCallback(['filters' => $value]);
			$foundIds = array_map(
				function (ObjectEntity $object) use ($property, $key) {
					$serialized = $object->jsonSerialize();
					$idRaw = null;
					if (is_array($property) === true
						&& isset($property['inversedBy']) === true
					) {
						$idRaw = $serialized[$property['inversedBy']];
					}

					if (Uuid::isValid($idRaw) === true) {
						return $idRaw;
					}

					if (filter_var($idRaw, FILTER_VALIDATE_URL) !== false) {
						$path = explode(separator: '/', string: parse_url($idRaw, PHP_URL_PATH));

						return end($path);
					}

					return null;
				},
				$objects
			);

			if ($ids === []) {
				$ids = $foundIds;
			}

			if ($ids !== []) {
				$ids = array_intersect($ids, $foundIds);
			}

			foreach (array_keys($value) as $k) {
				unset($filters[$key . '_' . $k]);
			}
		}//end foreach

		if ($iterator > 0 && $ids === []) {
			return null;
		}

		return $ids;
	}//end applyInversedByFilter()

	/**
	 * Extract related data from results (delegates to PerformanceHandler).
	 *
	 * @param array $results The search results.
	 * @param bool $includeRelated Whether to include related objects.
	 * @param bool $includeRelatedNames Whether to include related names.
	 *
	 * @return string[][]
	 *
	 * @psalm-param array<string, mixed> $results
	 *
	 * @phpstan-param array<string, mixed> $results
	 *
	 * @psalm-return   array{related?: list<string>, relatedNames?: array<string, string>}
	 * @phpstan-return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control optional extraction features
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function extractRelatedData(array $results, bool $includeRelated, bool $includeRelatedNames): array {
		return $this->performanceHandler->extractRelatedData(
			results: $results,
			includeRelated: $includeRelated,
			includeRelatedNames: $includeRelatedNames
		);
	}//end extractRelatedData()

	/**
	 * Extract all relationship IDs from objects with circuit breaker.
	 *
	 * @param array $objects Objects to extract relationships from.
	 * @param array $_extend Properties to extend.
	 *
	 * @psalm-param array<int, ObjectEntity> $objects
	 * @psalm-param array<int, string> $_extend
	 *
	 * @phpstan-param array<int, ObjectEntity> $objects
	 * @phpstan-param array<int, string> $_extend
	 *
	 * @return string[]
	 *
	 * @psalm-return   array<int<0, max>, string>
	 * @phpstan-return array<int, string>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Nested loops with circuit breaker logic for performance protection
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple execution paths for relationship extraction limits
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Circuit breaker logic requires comprehensive safeguards
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function extractAllRelationshipIds(array $objects, array $_extend): array {
		$allIds = [];
		$maxIds = 200;
		// **CIRCUIT BREAKER**: Hard limit to prevent massive relationship loading.
		$extractedCount = 0;

		foreach ($objects as $objectIndex => $object) {
			// **PERFORMANCE BYPASS**: Stop early if we've extracted enough.
			if ($extractedCount >= $maxIds) {
				$this->logger->info(
					message: '[RelationHandler] 🛑 RELATIONSHIP EXTRACTION: Stopped early to prevent timeout',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'extractedIds' => $extractedCount,
						'maxIds' => $maxIds,
						'processedObjects' => $objectIndex,
						'totalObjects' => count($objects),
						'reason' => 'performance_protection',
					]
				);
				break;
			}

			$objectData = $object->getObject();

			foreach ($_extend as $extendProperty) {
				if (isset($objectData[$extendProperty]) === true) {
					$value = $objectData[$extendProperty];

					if (is_array($value) === true) {
						// **PERFORMANCE LIMIT**: Limit array relationships per object.
						$limitedArray = array_slice($value, 0, 10);
						// Max 10 relationships per array.
						foreach ($limitedArray as $id) {
							if (empty($id) === false && is_string($id) === true) {
								$allIds[] = $id;
								$extractedCount++;

								// **CIRCUIT BREAKER**: Stop if we hit the limit.
								if ($extractedCount >= $maxIds) {
									// Break out of all loops.
								}
							}
						}

						// Log if we had to limit the array.
						if (count($value) > 10) {
							$this->logger->debug(
								message: '[RelationHandler] 🔪 PERFORMANCE: Limited relationship array',
								context: [
									'file' => __FILE__,
									'line' => __LINE__,
									'property' => $extendProperty,
									'originalCount' => count($value),
									'limitedTo' => count($limitedArray),
									'reason' => 'prevent_timeout',
								]
							);
						}
					} elseif (is_string($value) === true && empty($value) === false) {
						// Handle single relationship ID.
						$allIds[] = $value;
						$extractedCount++;

						// **CIRCUIT BREAKER**: Stop if we hit the limit.
						if ($extractedCount >= $maxIds) {
							// Break out of both loops.
						}
					}//end if
				}//end if
			}//end foreach
		}//end foreach

		// Remove duplicates and return unique IDs.
		$uniqueIds = array_unique($allIds);

		$this->logger->info(
			message: '[RelationHandler] 🔍 RELATIONSHIP EXTRACTION: Completed with limits',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalExtracted' => count($allIds),
				'uniqueIds' => count($uniqueIds),
				'maxAllowed' => $maxIds,
				'efficiency' => 'limited_for_performance',
			]
		);

		return $uniqueIds;
	}//end extractAllRelationshipIds()

	/**
	 * Bulk load relationships in batches to prevent timeouts.
	 *
	 * @param array $relationshipIds Array of all relationship IDs to load.
	 *
	 * @return array<int|string, ObjectEntity>
	 *
	 * @psalm-param array<string> $relationshipIds
	 *
	 * @phpstan-param array<string> $relationshipIds
	 *
	 * @psalm-return   array<int|string, ObjectEntity>
	 * @phpstan-return array<string, ObjectEntity>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Batch processing with error handling requires multiple conditions
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function bulkLoadRelationshipsBatched(array $relationshipIds): array {
		if (count($relationshipIds) === 0) {
			return [];
		}

		// **HARD LIMIT**: Cap at 200 relationships total for safety.
		if (count($relationshipIds) > 200) {
			$this->logger->warning(
				message: '[RelationHandler] ⚠️ RELATIONSHIP LOADING: Capping at 200 relationships',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'requested' => count($relationshipIds),
					'capped' => 200,
					'reason' => 'prevent_timeout',
				]
			);
			$relationshipIds = array_slice($relationshipIds, 0, 200);
		}

		$startTime = microtime(true);
		$batchSize = 50;
		// Load 50 relationships at a time.
		$batches = array_chunk($relationshipIds, $batchSize);
		$loadedObjects = [];

		$this->logger->info(
			message: '[RelationHandler] 🔄 BULK RELATIONSHIP LOADING: Starting batched load',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalRelationships' => count($relationshipIds),
				'batchSize' => $batchSize,
				'totalBatches' => count($batches),
				'strategy' => 'batched_sequential',
			]
		);

		foreach ($batches as $batchIndex => $batch) {
			$batchStart = microtime(true);

			try {
				$chunkObjects = $this->loadRelationshipChunkOptimized(relationshipIds: $batch);

				foreach ($chunkObjects as $obj) {
					// Index by both UUID and ID for flexible lookup.
					$loadedObjects[$obj->getUuid()] = $obj;
					$loadedObjects[$obj->getId()] = $obj;
				}

				$batchTime = (microtime(true) - $batchStart) * 1000;

				$this->logger->debug(
					message: '[RelationHandler] ✅ Batch loaded',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'batch' => ($batchIndex + 1),
						'idsInBatch' => count($batch),
						'objectsLoaded' => count($chunkObjects),
						'batchTime' => round($batchTime, 2) . 'ms',
					]
				);
			} catch (\Exception $e) {
				$this->logger->error(
					message: '[RelationHandler] ❌ BATCH LOADING FAILED',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'batch' => ($batchIndex + 1),
						'error' => $e->getMessage(),
						'idsInBatch' => count($batch),
					]
				);
				// Continue with next batch instead of failing completely.
			}//end try
		}//end foreach

		$totalTime = (microtime(true) - $startTime) * 1000;

		$this->logger->info(
			message: '[RelationHandler] ✅ BULK RELATIONSHIP LOADING: Completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalRequested' => count($relationshipIds),
				'totalLoaded' => count($loadedObjects),
				'totalTime' => round($totalTime, 2) . 'ms',
				'avgPerBatch' => round($totalTime / count($batches), 2) . 'ms',
			]
		);

		return $loadedObjects;
	}//end bulkLoadRelationshipsBatched()

	/**
	 * Load a chunk of relationships optimized.
	 *
	 * @param array $relationshipIds Array of relationship IDs to load.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-param array<string> $relationshipIds
	 *
	 * @phpstan-param array<string> $relationshipIds
	 *
	 * @psalm-return   list<ObjectEntity>
	 * @phpstan-return array<int, ObjectEntity>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function loadRelationshipChunkOptimized(array $relationshipIds): array {
		if (empty($relationshipIds) === true) {
			return [];
		}

		try {
			// Use the mapper's optimized bulk fetch.
			return $this->objectEntityMapper->findAll(ids: $relationshipIds, includeDeleted: false);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to load relationship chunk',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'idsCount' => count($relationshipIds),
				]
			);
			return [];
		}
	}//end loadRelationshipChunkOptimized()

	/**
	 * Get object contracts.
	 *
	 * This method retrieves contracts associated with an object.
	 * Contracts are typically stored as relations in the object's data.
	 *
	 * @param string $objectId Object ID or UUID.
	 * @param array $filters Optional filters for pagination.
	 *
	 * @return (array|int|mixed)[] Contracts data with pagination info.
	 *
	 * @psalm-return array{results: array|mixed, total: int<0, max>, limit: 30|mixed, offset: 0|mixed}
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getContracts(string $objectId, array $filters = []): array {
		try {
			// Find the object.
			$object = $this->objectEntityMapper->find(identifier: $objectId);
			$objectData = $object->getObject();

			// Extract contracts from object data (typically stored in 'contracts' property).
			$contracts = $objectData['contracts'] ?? [];

			// Apply pagination.
			$limit = $filters['_limit'] ?? 30;
			$offset = $filters['_offset'] ?? 0;
			$total = 0;
			if (is_array($contracts) === true) {
				$total = count($contracts);
				$contracts = array_slice($contracts, $offset, $limit);
			}

			return [
				'results' => $contracts,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to get contracts',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'objectId' => $objectId,
				]
			);
			return [
				'results' => [],
				'total' => 0,
				'limit' => $filters['_limit'] ?? 30,
				'offset' => $filters['_offset'] ?? 0,
			];
		}//end try
	}//end getContracts()

	/**
	 * Get objects that this object uses (outgoing relations).
	 *
	 * This method finds all objects that are referenced by the given object.
	 *
	 * @param string $objectId Object ID or UUID.
	 * @param array $query Search query parameters.
	 * @param bool $_rbac Apply RBAC filters.
	 * @param bool $_multitenancy Apply multitenancy filters.
	 * @param int|null $_registerId Register ID for magic table lookup.
	 * @param int|null $_schemaId Schema ID for magic table lookup.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int|mixed, offset: int|mixed}
	 *
	 * Rows are serialised rather than entities, and each carries a `relation`
	 * block naming the property the link came in through and what it is called
	 * from each side. The change that introduced it is
	 * openspec/changes/relation-types-with-inverses.
	 *
	 * @psalm-return array{results: list<array<string, mixed>>, total: int<0, max>, limit: 30|mixed, offset: 0|mixed}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getUses(
		string $objectId,
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		?int $_registerId = null,
		?int $_schemaId = null,
	): array {
		try {
			// Register and schema are loaded without RBAC/multitenancy: the user's
			// access to the related objects is checked by filterByRbac() below.
			$pinned = $this->usesPin(registerId: $_registerId, schemaId: $_schemaId);
			$object = $this->objectEntityMapper->find(
				identifier: $objectId,
				register: $pinned->register,
				schema: $pinned->schema
			);

			// Pre-scanned relations, populated during save/import by scanForRelations().
			$relations = $object->getRelations() ?? [];
			$uniqueIds = $this->relatedUuids(relations: $relations, ownUuid: $object->getUuid());
			if (empty($uniqueIds) === true) {
				return $this->emptyPage(query: $query);
			}

			$relatedObjects = $this->locateRelated(uuids: $uniqueIds);

			// The per-table reads apply RBAC at query level, but the fallback rows
			// were located without it, so every result is filtered here.
			if ($_rbac === true) {
				$relatedObjects = $this->filterByRbac(objects: $relatedObjects);
			}

			$this->logger->debug(
				message: '[RelationHandler::getUses] Found related objects',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'searchedIds' => $uniqueIds,
					'foundCount' => count($relatedObjects),
				]
			);

			$limit = $query['_limit'] ?? 30;
			$offset = $query['_offset'] ?? 0;
			$total = count($relatedObjects);
			$page = array_slice($relatedObjects, $offset, $limit);

			// Rows leave serialised because the relation descriptor has nowhere
			// to live on an entity; ObjectsController::stampObjectUrls() accepts either shape.
			$source = new RelationSource(
				relations: $relations,
				schema: ($pinned->schema ?? $this->schemaOf(schemaId: $object->getSchema()))
			);

			return [
				'results' => array_map(fn ($related) => $this->outgoingRow(related: $related, source: $source), $page),
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to get uses',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'objectId' => $objectId,
				]
			);
			return $this->emptyPage(query: $query);
		}//end try
	}//end getUses()

	/**
	 * Load the register and schema a lookup was pinned to, without RBAC or multitenancy.
	 *
	 * Nothing is loaded unless both ids are given. Loading stops at the first failure,
	 * which is kept on the result for the caller to log.
	 *
	 * @param int|null $registerId The register id.
	 * @param int|null $schemaId   The schema id.
	 *
	 * @return PinnedTable
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function pinnedTable(?int $registerId, ?int $schemaId): PinnedTable {
		$pinned = new PinnedTable();
		if ($registerId === null || $schemaId === null) {
			return $pinned;
		}

		try {
			$pinned->register = $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false);
			$pinned->schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			$pinned->failure = $e;
		}

		return $pinned;
	}//end pinnedTable()

	/**
	 * The pinned register and schema of an outgoing lookup; a load failure is logged at debug level.
	 *
	 * @param int|null $registerId The register id.
	 * @param int|null $schemaId   The schema id.
	 *
	 * @return PinnedTable
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function usesPin(?int $registerId, ?int $schemaId): PinnedTable {
		$pinned = $this->pinnedTable(registerId: $registerId, schemaId: $schemaId);
		if ($pinned->failure !== null) {
			$this->logger->debug(
				message: '[RelationHandler::getUses] Could not load register/schema for magic table lookup',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'registerId' => $registerId,
					'schemaId' => $schemaId,
					'error' => $pinned->failure->getMessage(),
				]
			);
		}

		return $pinned;
	}//end usesPin()

	/**
	 * An empty page that echoes the requested limit and offset.
	 *
	 * @param array<string, mixed> $query The request's paging parameters.
	 *
	 * @return array{results: list<never>, total: 0, limit: mixed, offset: mixed}
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function emptyPage(array $query): array {
		return [
			'results' => [],
			'total' => 0,
			'limit' => $query['_limit'] ?? 30,
			'offset' => $query['_offset'] ?? 0,
		];
	}//end emptyPage()

	/**
	 * The distinct uuids a relation map names, without the object's own uuid.
	 *
	 * Relations are stored as ['field' => 'uuid'] or as lists of uuids; empty
	 * strings and non-string values are skipped. Keys are kept as array_unique() leaves them.
	 *
	 * @param array<mixed, mixed> $relations The stored relation map.
	 * @param string|null         $ownUuid   The object's own uuid.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function relatedUuids(array $relations, ?string $ownUuid): array {
		$ids = [];
		foreach ($relations as $value) {
			$candidates = [$value];
			if (is_array($value) === true) {
				$candidates = $value;
			}

			foreach ($candidates as $candidate) {
				if (is_string($candidate) === true && empty($candidate) === false) {
					$ids[] = $candidate;
				}
			}
		}

		$uniqueIds = array_unique($ids);
		if ($ownUuid !== null) {
			$uniqueIds = array_filter($uniqueIds, fn ($id) => $id !== $ownUuid);
		}

		return $uniqueIds;
	}//end relatedUuids()

	/**
	 * Load the related objects: locate them in one UNION query, then read each table.
	 *
	 * The UNION replaced loading every register and schema and querying the tables
	 * one by one (/uses on a pipelinq lead took 4.5 to 7 seconds). Each table that
	 * holds a match is read through the filtered query, and a uuid no table read
	 * returns falls back to the located row.
	 *
	 * @param array<mixed, string> $uuids The related uuids.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function locateRelated(array $uuids): array {
		$located = $this->objectEntityMapper->findMultipleAcrossAllMagicTables(uuids: array_values($uuids));
		$found = new RelatedObjectSet(wanted: $uuids);
		foreach ($this->tablesOf(objects: $located) as $table) {
			$this->readRelatedTable(table: $table, found: $found);
		}

		return $found->withFallback(located: $located);
	}//end locateRelated()

	/**
	 * Read one table's related objects into the set; a table that cannot be read is skipped.
	 *
	 * @param array{registerId: int, schemaId: int, uuids: array<int, string>} $table The table and its uuids.
	 * @param RelatedObjectSet                                                $found The set being filled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function readRelatedTable(array $table, RelatedObjectSet $found): void {
		$pair = $this->registerSchemaPair(registerId: $table['registerId'], schemaId: $table['schemaId']);
		if ($pair === null) {
			return;
		}

		try {
			$results = $this->objectEntityMapper->findAllInRegisterSchemaTable(
				register: $pair['register'],
				schema: $pair['schema'],
				filters: ['_ids' => $table['uuids'], '_limit' => 200]
			);
			foreach ($results as $obj) {
				$found->offer(object: $obj);
			}
		} catch (\Exception $e) {
			// Table might not exist or query failed, continue.
		}
	}//end readRelatedTable()

	/**
	 * One related object as a serialised row carrying its outgoing relation block.
	 *
	 * @param mixed          $related The related object.
	 * @param RelationSource $source  The source object's relation map and schema.
	 *
	 * @return mixed The row, or the object itself when it does not serialise to an array.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function outgoingRow(mixed $related, RelationSource $source): mixed {
		$row = $related;
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return $related;
		}

		return $this->withRelation(
			row: $row,
			link: new RelationLink(
				schema: $source->schema,
				path: $this->pathHolding(relations: $source->relations, uuid: (string)$related->getUuid())
			)
		);
	}//end outgoingRow()


	/**
	 * Filter objects by RBAC (schema-level authorization).
	 *
	 * Checks each object's schema authorization rules and removes objects
	 * the current user does not have read access to.
	 *
	 * @param ObjectEntity[] $objects The objects to filter.
	 *
	 * @return ObjectEntity[] Filtered objects the user has access to.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function filterByRbac(array $objects): array {
		if ($this->rbacHandler->isAdmin() === true) {
			return $objects;
		}

		$schemaCache = [];
		$filtered = [];
		$drafts = new DraftCallerRule();

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				$filtered[] = $object;
				continue;
			}

			$schemaId = $object->getSchema();
			if ($schemaId === null) {
				$filtered[] = $object;
				continue;
			}

			// Cache schema lookups.
			if (isset($schemaCache[$schemaId]) === false) {
				try {
					$schemaCache[$schemaId] = $this->schemaMapper->find(
						(int)$schemaId,
						_multitenancy: false,
						_rbac: false
					);
				} catch (\Exception $e) {
					// Schema not found, skip this object.
					continue;
				}
			}

			$schema = $schemaCache[$schemaId];

			// Another user's draft is kept from this caller, as on every other
			// path. The candidates come from a lookup that applied no access
			// rule, so a draft the per-table query left out reaches this list
			// through the fallback above; hasPermission() has no draft rule.
			if ($drafts->keptFromCaller(
				object: $object,
				schema: $schema,
				userId: $this->rbacHandler->getCurrentUserId(),
				isAdmin: false
			) === true
			) {
				continue;
			}

			// Build object data with metadata for condition evaluation.
			$objectData = $object->getObject() ?? [];
			$objectData['_organisation'] = $object->getOrganisation();
			$objectData['_owner'] = $object->getOwner();

			if ($this->rbacHandler->hasPermission(
				schema: $schema,
				action: 'read',
				objectOwner: $object->getOwner(),
				objectData: $objectData,
				objectAuthorization: $object->getAuthorization(),
				objectUuid: $object->getUuid()
			) === true
			) {
				$filtered[] = $object;
			}
		}//end foreach

		return $filtered;
	}//end filterByRbac()

	/**
	 * The stored relation path that holds a given uuid, if any.
	 *
	 * Relations are stored as a map of property path to reference value:
	 * `['blocks.0' => 'uuid-b', 'owner' => 'https://…/uuid-o']`. That map
	 * already records which property a reference came in through, so neither
	 * direction has to walk the schema's `$ref` properties guessing which one
	 * holds the value. The walk stays as the fallback for rows written before
	 * the map carried paths.
	 *
	 * @param array<mixed, mixed> $relations The object's stored relation map.
	 * @param string $uuid The uuid to find.
	 *
	 * @return string|null The path, or null when the map does not name it.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function pathHolding(array $relations, string $uuid): ?string {
		if ($uuid === '') {
			return null;
		}

		foreach ($relations as $path => $value) {
			if (is_string($path) === false) {
				continue;
			}

			if (is_string($value) === true && ($value === $uuid || str_contains($value, $uuid) === true)) {
				return $path;
			}

			if (is_array($value) === false) {
				continue;
			}

			foreach ($value as $index => $sub) {
				if (is_string($sub) === true && ($sub === $uuid || str_contains($sub, $uuid) === true)) {
					return $path.'.'.((string)$index);
				}
			}
		}

		return null;
	}//end pathHolding()

	/**
	 * Group located objects by the table they came from.
	 *
	 * @param ObjectEntity[] $objects Objects found by a cross-table lookup.
	 *
	 * @return array<int, array{registerId: int, schemaId: int, uuids: array<int, string>}> One entry per table.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/linked-entity-types/spec.md#requirement-related-objects-must-be-found-without-scanning-every-schema
	 */
	private function tablesOf(array $objects): array {
		$tables = [];
		foreach ($objects as $object) {
			$registerId = $object->getRegister();
			$schemaId = $object->getSchema();
			if (is_numeric($registerId) === false || is_numeric($schemaId) === false) {
				continue;
			}

			$key = $registerId.'_'.$schemaId;
			if (isset($tables[$key]) === false) {
				$tables[$key] = ['registerId' => (int)$registerId, 'schemaId' => (int)$schemaId, 'uuids' => []];
			}

			$tables[$key]['uuids'][] = (string)$object->getUuid();
		}

		return array_values($tables);
	}//end tablesOf()

	/**
	 * Load a register and schema, once per request.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId   The schema id.
	 *
	 * @return array{register: Register, schema: Schema}|null The pair, or null when either cannot be loaded.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/linked-entity-types/spec.md#requirement-related-objects-must-be-found-without-scanning-every-schema
	 */
	private function registerSchemaPair(int $registerId, int $schemaId): ?array {
		$key = $registerId.'_'.$schemaId;
		if (array_key_exists($key, $this->pairCache) === false) {
			try {
				$this->pairCache[$key] = [
					'register' => $this->registerMapper->find($registerId),
					'schema' => $this->schemaMapper->find($schemaId),
				];
			} catch (\Exception $e) {
				$this->pairCache[$key] = null;
			}
		}

		return $this->pairCache[$key];
	}//end registerSchemaPair()

	/**
	 * The schema an object belongs to, loaded without RBAC.
	 *
	 * Reading a label is not reading the schema's data, and the objects the
	 * labels describe have already been filtered by {@see self::filterByRbac()}.
	 * A label that silently disappears because the reader may not read the
	 * schema row would leave the far side saying "referenced by" with no way
	 * to tell that apart from a schema that declares nothing.
	 *
	 * @param mixed $schemaId The object's schema id.
	 *
	 * @return Schema|null The schema, or null when it cannot be loaded.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function schemaOf(mixed $schemaId): ?Schema {
		if (is_numeric($schemaId) === false) {
			return null;
		}

		try {
			return $this->schemaMapper->find((int)$schemaId, _rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[RelationHandler] Could not load a schema to resolve relation labels',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId],
			);

			return null;
		}
	}//end schemaOf()

	/**
	 * Attach the relation descriptor to one serialised row.
	 *
	 * Every row gets a `relation` block, annotated or not. A caller that has
	 * to ask "did this one come with labels" is a caller that will forget to
	 * on the path that matters, and the fallback the spec names — the
	 * property's title, and "referenced by" — is a perfectly good answer.
	 *
	 * @param array<string, mixed> $row  The serialised object.
	 * @param RelationLink         $link The declaring schema, stored path and direction.
	 *
	 * @return array<string, mixed> The row, with `relation` attached.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function withRelation(array $row, RelationLink $link): array {
		if ($this->relationTypes === null) {
			return $row;
		}

		$descriptor = null;
		if ($link->path !== null) {
			$descriptor = $this->relationTypes->descriptorFor(schema: $link->schema, property: $link->path);
		}

		$row['relation'] = $this->relationTypes->row(
			descriptor: $descriptor,
			direction: $link->direction,
			path: $link->path
		);

		return $row;
	}//end withRelation()

	/**
	 * Get objects that use this object (incoming relations).
	 *
	 * This method finds all objects that reference the given object.
	 *
	 * @param string $objectId Object ID or UUID.
	 * @param array $query Search query parameters.
	 * @param bool $_rbac Apply RBAC filters.
	 * @param bool $_multitenancy Apply multitenancy filters.
	 * @param int|null $_registerId Register ID for filtering.
	 * @param int|null $_schemaId Schema ID for filtering.
	 *
	 * @return (array|int|mixed|string)[] Paginated results with referencing objects.
	 *
	 * @psalm-return array{results: list<array<string, mixed>>, total: int<0, max>,
	 *     limit: mixed, offset: mixed,
	 *     message?: 'Reverse relationship lookup not yet implemented'}
	 *
	 * The previous annotation said `results: array<never, never>, total: 0` —
	 * inferred back when this method was a stub that returned nothing and the
	 * "not yet implemented" message. It has since been implemented and returns
	 * real rows; only the `message` key is still optional/legacy.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getUsedBy(
		string $objectId,
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		?int $_registerId = null,
		?int $_schemaId = null,
	): array {
		try {
			// Register and schema are loaded without RBAC/multitenancy; access to
			// the referrers is applied by the per-table reads.
			$pinned = $this->pinnedTable(registerId: $_registerId, schemaId: $_schemaId);
			if ($pinned->failure !== null) {
				$this->logger->warning(
					message: '[RelationHandler] Failed to load register/schema for getUsedBy magic table support',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $pinned->failure->getMessage()]
				);
			}

			$object = $this->objectEntityMapper->find(
				identifier: $objectId,
				register: $pinned->register,
				schema: $pinned->schema
			);
			$page = new ReferrerPage(targetUuid: $object->getUuid(), query: $query);

			// One UNION query says which tables hold an object whose _relations name
			// this uuid. It replaced a JSON query in every magic table (/used on a
			// pipelinq lead took 6.4 to 8 seconds).
			$tables = $this->tablesOf(
				objects: $this->objectEntityMapper->findByRelationAcrossAllMagicTables(uuid: (string)$page->targetUuid)
			);
			foreach ($tables as $tableInfo) {
				if ($page->isFull() === true) {
					break;
				}

				$this->readReferrerTable(table: $tableInfo, page: $page);
			}

			return [
				'results' => $page->rows(),
				'total' => count($page->rows()),
				'limit' => $page->limit,
				'offset' => $page->offset,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to get used by',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'objectId' => $objectId,
				]
			);
			return $this->emptyPage(query: $query);
		}//end try
	}//end getUsedBy()

	/**
	 * Read one table's referrers into the page, newest first.
	 *
	 * Each referrer's own relation map says which of ITS properties holds the uuid,
	 * and that property lives on ITS schema, so the label is resolved from the table's
	 * schema rather than from the schema of the object being read. A table that cannot
	 * be read is logged and skipped.
	 *
	 * @param array{registerId: int, schemaId: int, uuids: array<int, string>} $table The table.
	 * @param ReferrerPage                                                    $page  The page being filled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function readReferrerTable(array $table, ReferrerPage $page): void {
		try {
			$pair = $this->registerSchemaPair(registerId: $table['registerId'], schemaId: $table['schemaId']);
			if ($pair === null) {
				return;
			}

			$searchResults = $this->objectEntityMapper->findAllInRegisterSchemaTable(
				register: $pair['register'],
				schema: $pair['schema'],
				limit: $page->remaining(),
				offset: $page->nextOffset(),
				filters: ['_relations_contains' => $page->targetUuid],
				sort: ['_updated' => 'DESC']
			);

			foreach ($searchResults as $resultObject) {
				if ($resultObject->getUuid() === $page->targetUuid) {
					continue;
				}

				$page->add(
					row: $this->withRelation(
						row: $resultObject->jsonSerialize(),
						link: (new RelationLink(
							schema: $pair['schema'],
							path: $this->pathHolding(
								relations: ($resultObject->getRelations() ?? []),
								uuid: (string)$page->targetUuid
							)
						))->incoming()
					)
				);
			}

			$page->countRead(count: count($searchResults));
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[RelationHandler] Error searching magic table for usedBy',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => $table['registerId'].'_'.$table['schemaId'],
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end readReferrerTable()

}//end class
