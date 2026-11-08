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

use Closure;
use OCA\OpenRegister\Db\MagicMapper\RelatedRowRbacInterface;
use OCA\OpenRegister\Db\MagicMapper\PurposeBoundTables;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Object\DraftCallerRule;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

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
	 * The registers and schemas relation lookups have loaded, created on first use.
	 *
	 * @var RelationTables|null
	 */
	private ?RelationTables $tables = null;

	/**
	 * Constructor for RelationHandler.
	 *
	 * @param RelationHandlerStores $stores Objects, schemas, registers and relation names.
	 * @param RelatedRowRbacInterface $rbacHandler Handler for RBAC operations.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param PurposeGuard|null $purposeGuard The doelbinding guard the referrer tables are read under.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		private readonly RelationHandlerStores $stores,
		private readonly RelatedRowRbacInterface $rbacHandler,
		private readonly LoggerInterface $logger,
		private readonly ?PurposeGuard $purposeGuard = null,
	) {
	}//end __construct()

	/**
	 * The registers and schemas relation lookups have loaded; each pair is loaded once per handler.
	 *
	 * @return RelationTables
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function tables(): RelationTables {
		$this->tables ??= new RelationTables(registers: $this->stores->registers, schemas: $this->stores->schemas, logger: $this->logger);
		return $this->tables;
	}//end tables()

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

		$relationshipIds = $this->cappedRelationshipIds(relationshipIds: $relationshipIds);
		$batches = array_chunk($relationshipIds, 50);
		$run = new RelationLoadRun(requested: count($relationshipIds), batches: count($batches), start: microtime(true));
		$context = ['file' => __FILE__, 'line' => __LINE__];
		$this->logger->info(message: '[RelationHandler] 🔄 BULK RELATIONSHIP LOADING: Starting batched load', context: $context + $run->startContext());
		$loadedObjects = $this->loadInBatches(batches: $batches);
		$done = $context + $run->doneContext(loaded: count($loadedObjects));
		$this->logger->info(message: '[RelationHandler] ✅ BULK RELATIONSHIP LOADING: Completed', context: $done);
		return $loadedObjects;
	}//end bulkLoadRelationshipsBatched()

	/**
	 * The relationship ids, capped at 200 with a warning, so a load cannot time out.
	 *
	 * @param array<string> $relationshipIds The requested ids.
	 *
	 * @return array<string>
	 */
	private function cappedRelationshipIds(array $relationshipIds): array {
		if (count($relationshipIds) <= 200) {
			return $relationshipIds;
		}

		$this->logger->warning(
			message: '[RelationHandler] ⚠️ RELATIONSHIP LOADING: Capping at 200 relationships',
			context: ['file' => __FILE__, 'line' => __LINE__, 'requested' => count($relationshipIds), 'capped' => 200, 'reason' => 'prevent_timeout']
		);
		return array_slice($relationshipIds, 0, 200);
	}//end cappedRelationshipIds()

	/**
	 * Load the batches in turn; later batches win on a shared key.
	 *
	 * @param array<int, array<string>> $batches The ids, 50 per batch.
	 *
	 * @return array<int|string, ObjectEntity>
	 */
	private function loadInBatches(array $batches): array {
		$loadedObjects = [];
		foreach ($batches as $batchIndex => $batch) {
			$loadedObjects = array_replace($loadedObjects, $this->loadBatch(batch: $batch, number: $batchIndex + 1));
		}

		return $loadedObjects;
	}//end loadInBatches()

	/**
	 * Load one batch, indexed by uuid and by id; a failed batch is logged and loads nothing.
	 *
	 * @param array<string> $batch  The ids in the batch.
	 * @param int           $number The batch number, from 1.
	 *
	 * @return array<int|string, ObjectEntity>
	 */
	private function loadBatch(array $batch, int $number): array {
		$batchStart = microtime(true);
		try {
			$chunkObjects = $this->loadRelationshipChunkOptimized(relationshipIds: $batch);
			$loaded = $this->indexedByUuidAndId(objects: $chunkObjects);
		} catch (\Exception $e) {
			$failure = ['batch' => $number, 'error' => $e->getMessage(), 'idsInBatch' => count($batch)];
			$this->logger->error(message: '[RelationHandler] ❌ BATCH LOADING FAILED', context: ['file' => __FILE__, 'line' => __LINE__] + $failure);
			return [];
		}

		$batchTime = round((microtime(true) - $batchStart) * 1000, 2) . 'ms';
		$loadedStats = ['batch' => $number, 'idsInBatch' => count($batch), 'objectsLoaded' => count($chunkObjects), 'batchTime' => $batchTime];
		$this->logger->debug(message: '[RelationHandler] ✅ Batch loaded', context: ['file' => __FILE__, 'line' => __LINE__] + $loadedStats);
		return $loaded;
	}//end loadBatch()

	/**
	 * Objects keyed by uuid and by id, both pointing at the same object.
	 *
	 * @param ObjectEntity[] $objects The objects.
	 *
	 * @return array<int|string, ObjectEntity>
	 */
	private function indexedByUuidAndId(array $objects): array {
		$indexed = [];
		foreach ($objects as $obj) {
			$indexed[$obj->getUuid()] = $obj;
			$indexed[$obj->getId()] = $obj;
		}

		return $indexed;
	}//end indexedByUuidAndId()

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
			return $this->stores->objects->findAll(ids: $relationshipIds, includeDeleted: false);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to load relationship chunk',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'idsCount' => count($relationshipIds)]
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
			$contracts = ($this->stores->objects->find(identifier: $objectId)->getObject()['contracts'] ?? []);
			return $this->contractsPage(contracts: $contracts, filters: $filters);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to get contracts',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'objectId' => $objectId]
			);
			return ['results' => [], 'total' => 0, 'limit' => $filters['_limit'] ?? 30, 'offset' => $filters['_offset'] ?? 0];
		}
	}//end getContracts()

	/**
	 * One page of an object's contracts, with the total before paging.
	 *
	 * @param mixed $contracts The object's `contracts` value.
	 * @param array $filters   `_limit` (default 30) and `_offset` (default 0).
	 *
	 * @return array{results: mixed, total: int, limit: mixed, offset: mixed}
	 */
	private function contractsPage(mixed $contracts, array $filters): array {
		$limit = $filters['_limit'] ?? 30;
		$offset = $filters['_offset'] ?? 0;
		$total = 0;
		if (is_array($contracts) === true) {
			$total = count($contracts);
			$contracts = array_slice($contracts, $offset, $limit);
		}

		return ['results' => $contracts, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
	}//end contractsPage()

	/**
	 * Get objects that this object uses (outgoing relations).
	 *
	 * Rows are serialised rather than entities, and each carries a `relation`
	 * block naming the property the link came in through and what it is called
	 * from each side (openspec/changes/relation-types-with-inverses).
	 *
	 * @param RelationLookup $lookup The object, paging, access filters and pinned register and schema.
	 *
	 * @return array{results: array<int, mixed>, total: int, limit: mixed, offset: mixed}
	 *
	 * @throws MultipleObjectsReturnedException When the identifier is ambiguous.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getUses(RelationLookup $lookup): array {
		try {
			return $this->usesPage(lookup: $lookup);
		} catch (\Exception $e) {
			return $this->failedPage(error: $e, lookup: $lookup->listing(listing: 'uses'));
		}
	}//end getUses()

	/**
	 * The page of objects the lookup's object uses.
	 *
	 * Register and schema are loaded without RBAC/multitenancy: the user's
	 * access to the related objects is checked by the lookup's filters. Rows
	 * leave serialised because the relation descriptor has nowhere to live on
	 * an entity; ObjectsController::stampObjectUrls() accepts either shape.
	 *
	 * @param RelationLookup $lookup The lookup.
	 *
	 * @return array{results: array<int, mixed>, total: int, limit: mixed, offset: mixed}
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function usesPage(RelationLookup $lookup): array {
		$pinned = $this->tables()->pinnedForUses(lookup: $lookup);
		$object = $this->pinnedObject(lookup: $lookup, pinned: $pinned);
		$uniqueIds = (new RelationMap(relations: ($object->getRelations() ?? [])))->uuidsBesides(ownUuid: $object->getUuid());
		if (empty($uniqueIds) === true) {
			return $this->emptyPage(query: $lookup->query);
		}

		$related = $this->readableRelated(uuids: $uniqueIds, lookup: $lookup);
		$source = $this->tables()->sourceOf(object: $object, pinned: $pinned);
		$page = $this->paged(items: $related, query: $lookup->query);
		$page['results'] = array_map(fn ($row) => $this->outgoingRow(related: $row, source: $source), $page['results']);
		return $page;
	}//end usesPage()

	/**
	 * The lookup's object, found in the pinned register and schema when both loaded.
	 *
	 * @param RelationLookup $lookup The lookup.
	 * @param PinnedTable    $pinned The pinned register and schema.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function pinnedObject(RelationLookup $lookup, PinnedTable $pinned): ObjectEntity {
		return $this->stores->objects->find(
			identifier: $lookup->objectId,
			register: $pinned->register,
			schema: $pinned->schema
		);
	}//end pinnedObject()

	/**
	 * The related objects the lookup's access filters admit, logged at debug level.
	 *
	 * @param array<int, string> $uuids  The related uuids.
	 * @param RelationLookup     $lookup The lookup.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function readableRelated(array $uuids, RelationLookup $lookup): array {
		$related = $this->locateRelated(
			uuids: $uuids,
			admitsFallback: fn (ObjectEntity $candidate): bool => $lookup->appliesMultitenancy() === false
				|| $this->withinOrganisationBoundary(candidate: $candidate, lookup: $lookup)
		);

		// The per-table reads apply RBAC at query level, but the fallback rows
		// were located without it, so every result is filtered here.
		if ($lookup->appliesRbac() === true) {
			$related = (new RelatedReadFilter(rbac: $this->rbacHandler, schemas: $this->stores->schemas))->admitted(objects: $related);
		}

		$this->logger->debug(
			message: '[RelationHandler::getUses] Found related objects',
			context: ['file' => __FILE__, 'line' => __LINE__, 'searchedIds' => $uuids, 'foundCount' => count($related)]
		);
		return $related;
	}//end readableRelated()

	/**
	 * The empty page: the limit and offset as requested, whatever their type.
	 *
	 * @param array<string, mixed> $query The request's paging parameters.
	 *
	 * @return array{results: array<int, mixed>, total: int, limit: mixed, offset: mixed}
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
	 * One page of the items, with the total and the limit and offset as requested.
	 *
	 * @param array<int, mixed>    $items The items.
	 * @param array<string, mixed> $query The request's paging parameters.
	 *
	 * @return array{results: array<int, mixed>, total: int, limit: mixed, offset: mixed}
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function paged(array $items, array $query): array {
		$limit = $query['_limit'] ?? 30;
		$offset = $query['_offset'] ?? 0;
		return [
			'results' => array_slice($items, $offset, $limit),
			'total' => count($items),
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end paged()

	/**
	 * Load the related objects: locate them in one UNION query, then read each table.
	 *
	 * The UNION replaced loading every register and schema and querying the tables
	 * one by one (/uses on a pipelinq lead took 4.5 to 7 seconds). Each table that
	 * holds a match is read through the filtered query, and a uuid no table read
	 * returns falls back to the located row.
	 *
	 * @param array<mixed, string>         $uuids          The related uuids.
	 * @param Closure(ObjectEntity): bool $admitsFallback Whether a located row may fall back.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function locateRelated(array $uuids, Closure $admitsFallback): array {
		$located = $this->stores->objects->findMultipleAcrossAllMagicTables(uuids: array_values($uuids));
		$found = new RelatedObjectSet(wanted: $uuids);
		foreach ($this->tables()->groups(objects: $located) as $table) {
			foreach ($this->relatedRows(table: $table) as $object) {
				$found->offer(object: $object);
			}
		}

		return $found->withFallback(located: $located, admits: $admitsFallback);
	}//end locateRelated()

	/**
	 * One table's related objects; a table that cannot be read gives none.
	 *
	 * @param array{registerId: int, schemaId: int, uuids: array<int, string>} $table The table and its uuids.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function relatedRows(array $table): array {
		$pair = $this->tables()->pair(registerId: $table['registerId'], schemaId: $table['schemaId']);
		if ($pair === null) {
			return [];
		}

		try {
			return $this->stores->objects->findAllInRegisterSchemaTable(
				register: $pair['register'],
				schema: $pair['schema'],
				filters: ['_ids' => $table['uuids'], '_limit' => 200]
			);
		} catch (\Exception $e) {
			return [];
		}
	}//end relatedRows()

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
		$row = $this->serialised(object: $related);
		if (is_array($row) === false) {
			return $related;
		}

		$path = (new RelationMap(relations: $source->relations))->pathHolding(uuid: (string)$related->getUuid());
		return $this->withRelation(row: $row, link: new RelationLink(schema: $source->schema, path: $path));
	}//end outgoingRow()

	/**
	 * The object's jsonSerialize() answer, or the value itself when it has none.
	 *
	 * @param mixed $object The value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function serialised(mixed $object): mixed {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return $object->jsonSerialize();
		}

		return $object;
	}//end serialised()

	/**
	 * Whether a row the cross-table lookup found lies inside the caller's organisation boundary.
	 *
	 * That lookup applies no access rule, so the row is read again through the
	 * single-object read, which applies the same organisation filter as the
	 * per-table query.
	 *
	 * @param ObjectEntity   $candidate The row found by the cross-table lookup.
	 * @param RelationLookup $lookup    The lookup; its RBAC flag applies to the read.
	 *
	 * @return bool True when the caller's read admits the row.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function withinOrganisationBoundary(ObjectEntity $candidate, RelationLookup $lookup): bool {
		$pair = $this->tables()->pair(registerId: (int)$candidate->getRegister(), schemaId: (int)$candidate->getSchema());
		if ($pair === null) {
			return false;
		}

		$read = (new RelationLookup(objectId: (string)$candidate->getUuid()))->filteredBy(rbac: $lookup->appliesRbac(), multitenancy: true);
		return $this->readsIn(read: $read, pair: $pair);
	}//end withinOrganisationBoundary()

	/**
	 * Whether the single-object read finds the object in the table, under the read's access filters.
	 *
	 * @param RelationLookup                            $read The object and the filters to read it under.
	 * @param array{register: Register, schema: Schema} $pair The table's register and schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function readsIn(RelationLookup $read, array $pair): bool {
		try {
			$this->stores->objects->find(
				identifier: $read->objectId,
				register: $pair['register'],
				schema: $pair['schema'],
				_rbac: $read->appliesRbac(),
				_multitenancy: $read->appliesMultitenancy()
			);
		} catch (\Exception $e) {
			return false;
		}

		return true;
	}//end readsIn()

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
		if ($this->stores->relationTypes === null) {
			return $row;
		}

		$descriptor = null;
		if ($link->path !== null) {
			$descriptor = $this->stores->relationTypes->descriptorFor(schema: $link->schema, property: $link->path);
		}

		$row['relation'] = $this->stores->relationTypes->row(
			descriptor: $descriptor,
			direction: $link->direction,
			path: $link->path
		);

		return $row;
	}//end withRelation()

	/**
	 * Get objects that use this object (incoming relations).
	 *
	 * @param RelationLookup $lookup The object, paging, access filters and pinned register and schema.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int<0, max>, limit: mixed, offset: mixed}
	 *
	 * @throws MultipleObjectsReturnedException When the identifier is ambiguous.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getUsedBy(RelationLookup $lookup): array {
		try {
			return $this->usedByPage(lookup: $lookup);
		} catch (\Exception $e) {
			return $this->failedPage(error: $e, lookup: $lookup->listing(listing: 'used by'));
		}
	}//end getUsedBy()

	/**
	 * The page of objects that reference the lookup's object, newest first per table.
	 *
	 * Register and schema are loaded without RBAC/multitenancy; access to the
	 * referrers is applied by the per-table reads. One UNION query says which
	 * tables hold an object whose _relations name this uuid. It replaced a JSON
	 * query in every magic table (/used on a pipelinq lead took 6.4 to 8 seconds).
	 *
	 * @param RelationLookup $lookup The lookup.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int<0, max>, limit: mixed, offset: mixed}
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function usedByPage(RelationLookup $lookup): array {
		$object = $this->pinnedObject(lookup: $lookup, pinned: $this->tables()->pinnedForUsedBy(lookup: $lookup));
		$page = new ReferrerPage(targetUuid: $object->getUuid(), query: $lookup->query);

		$located = $this->stores->objects->findByRelationAcrossAllMagicTables(uuid: (string)$page->targetUuid);
		foreach ($this->tables()->groups(objects: $located) as $table) {
			if ($page->isFull() === true) {
				break;
			}

			$this->readReferrerTable(table: $table, page: $page);
		}

		return ['results' => $page->rows(), 'total' => count($page->rows()), 'limit' => $page->limit, 'offset' => $page->offset];
	}//end usedByPage()

	/**
	 * The answer of a relation listing that failed: an empty page, logged.
	 *
	 * An ambiguous identifier is the exception: a slug two objects of the register
	 * and schema hold is an answer the caller must see (409, #4037), not an empty list.
	 *
	 * @param \Exception     $error  What went wrong.
	 * @param RelationLookup $lookup The lookup, labelled with what it lists.
	 *
	 * @return array{results: array<int, mixed>, total: int, limit: mixed, offset: mixed} The empty page.
	 *
	 * @throws MultipleObjectsReturnedException When the identifier is ambiguous.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function failedPage(\Exception $error, RelationLookup $lookup): array {
		if ($error instanceof MultipleObjectsReturnedException) {
			throw $error;
		}

		$this->logger->error(
			message: '[RelationHandler] Failed to get ' . $lookup->lists(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $error->getMessage(), 'objectId' => $lookup->objectId]
		);
		return $this->emptyPage(query: $lookup->query);
	}//end failedPage()

	/**
	 * Read one table's referrers into the page; a table that cannot be read is logged and skipped.
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
			$this->readReferrers(table: $table, page: $page);
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
		}
	}//end readReferrerTable()

	/**
	 * Read one table's referrers into the page, newest first, skipping the target itself.
	 *
	 * Each referrer's own relation map says which of ITS properties holds the uuid,
	 * and that property lives on ITS schema, so the label is resolved from the table's
	 * schema rather than from the schema of the object being read.
	 *
	 * @param array{registerId: int, schemaId: int, uuids: array<int, string>} $table The table.
	 * @param ReferrerPage                                                    $page  The page being filled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function readReferrers(array $table, ReferrerPage $page): void {
		$pair = $this->tables()->pair(registerId: $table['registerId'], schemaId: $table['schemaId']);
		if ($pair === null || $this->purposeAdmits(pair: $pair) === false) {
			return;
		}

		$referrers = $this->referrerSearch(pair: $pair, page: $page);
		foreach ($referrers as $referrer) {
			if ($referrer->getUuid() !== $page->targetUuid) {
				$path = (new RelationMap(relations: ($referrer->getRelations() ?? [])))->pathHolding(uuid: (string)$page->targetUuid);
				$link = (new RelationLink(schema: $pair['schema'], path: $path))->incoming();
				$page->add(row: $this->withRelation(row: $referrer->jsonSerialize(), link: $link));
			}
		}

		$page->countRead(count: count($referrers));
	}//end readReferrers()

	/**
	 * Whether the doelbinding guard admits a read of the table.
	 *
	 * The same rule the cross-table `_relations_contains` search applies: a
	 * table that asks for a purpose the request does not carry is left out.
	 *
	 * @param array{register: Register, schema: Schema} $pair The table's register and schema.
	 *
	 * @return bool True when no guard is wired or the guard admits the read.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function purposeAdmits(array $pair): bool {
		if ($this->purposeGuard === null) {
			return true;
		}

		return (new PurposeBoundTables(guard: $this->purposeGuard))->readable(pairs: [$pair]) !== [];
	}//end purposeAdmits()

	/**
	 * The next slice of one table's objects whose relations name the page's target, newest first.
	 *
	 * @param array{register: Register, schema: Schema} $pair The table's register and schema.
	 * @param ReferrerPage                              $page The page being filled.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function referrerSearch(array $pair, ReferrerPage $page): array {
		return $this->stores->objects->findAllInRegisterSchemaTable(
			register: $pair['register'],
			schema: $pair['schema'],
			limit: $page->remaining(),
			offset: $page->nextOffset(),
			filters: ['_relations_contains' => $page->targetUuid],
			sort: ['_updated' => 'DESC']
		);
	}//end referrerSearch()

}//end class
