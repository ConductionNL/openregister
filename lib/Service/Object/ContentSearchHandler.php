<?php

/**
 * OpenRegister ContentSearchHandler
 *
 * Handler class responsible for the opt-in `_content_search` fan-out that widens
 * `ObjectService::searchObjectsPaginated()` to match on attached-file body text, not
 * just object metadata/properties.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Widens a metadata-only search result set with objects whose attached-file (or
 * object-level) chunk body text matches the query, per
 * `openspec/changes/expose-content-search-in-object-service/specs/zoeken-filteren/spec.md`
 * (ZKN-CONTENT-001/-002/-003).
 *
 * Design (see the change's design.md decisions D1-D5):
 * - Runs AFTER the metadata-match query, never inline in the same SQL statement.
 * - Deduplicates on object id; metadata-match rows are never touched or reordered,
 *   chunk-only matches are appended.
 * - Skips chunks whose owning object cannot be resolved or falls outside the
 *   caller's `_register` / `_schemas` scope — silently, never an error.
 * - Never leaks chunk-shaped fields (id, text_content, score) into the response;
 *   this handler only ever returns real {@see ObjectEntity} rows.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
 */
class ContentSearchHandler
{
    /**
     * Candidate pool size fetched from the chunk store per call. Opt-in only path
     * (design.md Risk: "Extra query cost on _content_search=true") — bounded so a
     * single call cannot force an unbounded chunk-table scan.
     *
     * Cost note: the constant bounds BOTH the chunk-fetch SQL AND the per-hit
     * resolve loop below. Each hit costs up to two additional round-trips
     * (`FileMapper::findOwningObjectUuid()` for file-source chunks, then
     * `MagicMapper::find($uuid)` for both source branches). Worst case = 2 × N
     * sequential DB calls. Kept at 50 pending the batch-resolve refactor
     * discussed on the PR — bulk `findOwningObjectUuidsByFileIds()` /
     * `findMany($uuids)` variants would let us raise this safely.
     */
    private const CHUNK_CANDIDATE_LIMIT = 50;

    /**
     * Constructor for ContentSearchHandler.
     *
     * @param ChunkMapper     $chunkMapper  Chunk store mapper (keyword search over body text).
     * @param FileMapper      $fileMapper   File mapper, used to resolve a file chunk's owning object.
     * @param MagicMapper     $objectMapper Unified object mapper, used to resolve chunk hits to ObjectEntity rows.
     * @param LoggerInterface $logger       Logger for DEBUG-level unresolvable-chunk diagnostics.
     *
     * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
     */
    public function __construct(
        private readonly ChunkMapper $chunkMapper,
        private readonly FileMapper $fileMapper,
        private readonly MagicMapper $objectMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Widen a metadata-match result set with chunk-body-text matches, per ZKN-CONTENT-002.
     *
     * No-op (returns `$results`/`$total` unchanged) when: no `_search` term is present,
     * the page limit is 0 (count/facets-only request), or no chunk hits are found.
     *
     * @param array          $query         The original search query (read for `_search` and
     *                                      register/schema scope; see {@see
     *                                      resolveScope()}).
     * @param ObjectEntity[] $results       The metadata-match rows already resolved by the
     *                                      pre-change search path.
     * @param int            $total         The metadata-match total already computed by the
     *                                      pre-change search path.
     * @param int            $limit         The page's `_limit` (0 = unlimited/count-only).
     * @param bool           $_rbac         Whether to apply RBAC checks when resolving chunk-hit objects.
     * @param bool           $_multitenancy Whether to apply multitenancy filtering when resolving chunk-hit objects.
     *
     * @return array{results: ObjectEntity[], total: int}
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags mirror the
     *   established QueryHandler/MagicMapper API pattern.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Four independent early-exit no-op
     *   guards (no _search, count-only, no chunk hits, no appends) plus the bounded
     *   candidate loop; each branch is a single-line guard clause, not nested logic.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same early-exit guards multiply paths
     *   without adding real decision complexity.
     *
     * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
     */
    public function augmentWithChunkMatches(
        array $query,
        array $results,
        int $total,
        int $limit,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        $searchTerm = $query['_search'] ?? null;
        if (is_string($searchTerm) === false || trim($searchTerm) === '') {
            return ['results' => $results, 'total' => $total];
        }

        if ($limit === 0) {
            return ['results' => $results, 'total' => $total];
        }

        $chunkHits = $this->chunkMapper->searchByKeyword(
            query: $searchTerm,
            limit: self::CHUNK_CANDIDATE_LIMIT,
            filters: [],
            allowUnrankedFallback: true
        );

        if (empty($chunkHits) === true) {
            return ['results' => $results, 'total' => $total];
        }

        // Dedup key is UUID, not getId(): searchObjectsInRegisterSchemaTable
        // hydrates ObjectEntity without populating Entity::$id (the underlying
        // column is `_id`, not `id`), so getId() returns null on metadata-arm
        // rows. UUID is populated and stable across both arms.
        $seenUuids = [];
        foreach ($results as $object) {
            if ($object instanceof ObjectEntity && $object->getUuid() !== null) {
                $seenUuids[$object->getUuid()] = true;
            }
        }

        // Stable total across pages: use the distinct-chunk-owner set (grouped
        // by entity_type+entity_id) as the chunk-arm upper bound BEFORE the
        // room-clamped resolve loop. Without this, `$total` drifts across
        // pages (page 1 appends 0 → reports metaTotal; page 3 appends N →
        // reports metaTotal+N). Accepts over-counting by the metadata/chunk
        // overlap size and by unresolvable/out-of-scope chunks — the last
        // page may render short but pagination math stays consistent for the
        // client. See review discussion on pagination drift concern.
        $distinctChunkOwners = [];
        foreach ($chunkHits as $hit) {
            $key = ($hit['entity_type'] ?? 'file') . ':' . ($hit['entity_id'] ?? '');
            $distinctChunkOwners[$key] = true;
        }
        $chunkOwnerUpperBound = count($distinctChunkOwners);

        $scope    = $this->resolveScope(query: $query);
        $appended = [];
        $room     = PHP_INT_MAX;
        if ($limit > 0) {
            $room = max(0, $limit - count($results));
        }

        foreach ($chunkHits as $hit) {
            if (count($appended) >= $room) {
                break;
            }

            $object = $this->resolveAndDedupeHit(
                hit: $hit,
                seenUuids: $seenUuids,
                scope: $scope,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
            if ($object === null) {
                continue;
            }

            $seenUuids[$object->getUuid()] = true;
            $appended[] = $object;
        }//end foreach

        return [
            'results' => array_merge($results, $appended),
            'total'   => $total + $chunkOwnerUpperBound,
        ];
    }//end augmentWithChunkMatches()

    /**
     * Resolve one chunk hit and apply the dedupe/scope rules from ZKN-CONTENT-002,
     * returning the object to append or null when the hit should be skipped.
     *
     * Skip reasons (all silent, per D3): already present via the metadata-match arm
     * (cheap pre-check on 'object' hits, or post-resolve check on 'file' hits — the
     * owning object id is only known after the file->uuid->object join), unresolvable
     * owning object, or the object falls outside the caller's register/schema scope.
     *
     * @param array $hit           One row from {@see ChunkMapper::searchByKeyword()}.
     * @param array $seenUuids     Object UUIDs already present in the result set, keyed by uuid.
     * @param array $scope         The caller's register/schema scope (see {@see resolveScope()}).
     * @param bool  $_rbac         Whether to apply RBAC checks.
     * @param bool  $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return ObjectEntity|null The object to append, or null to skip this hit.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags mirror the
     *   established QueryHandler/MagicMapper API pattern.
     */
    private function resolveAndDedupeHit(array $hit, array $seenUuids, array $scope, bool $_rbac, bool $_multitenancy): ?ObjectEntity
    {
        $object = $this->resolveOwningObject(hit: $hit, _rbac: $_rbac, _multitenancy: $_multitenancy);
        if ($object === null || $object->getUuid() === null || isset($seenUuids[$object->getUuid()]) === true) {
            return null;
        }

        if ($this->matchesScope(object: $object, scope: $scope) === false) {
            return null;
        }

        return $object;
    }//end resolveAndDedupeHit()

    /**
     * Resolve a single chunk hit to its owning {@see ObjectEntity}, per ZKN-CONTENT-002.
     *
     * `source_type='object'` chunks map to the object directly by id; `source_type='file'`
     * chunks are resolved via {@see FileMapper::findOwningObjectUuid()}. Any failure
     * (not found, RBAC denial, cross-tenant, unresolvable file->object join) is caught
     * and logged at DEBUG level — never surfaced as an error to the caller.
     *
     * @param array $hit           One row from {@see ChunkMapper::searchByKeyword()}.
     * @param bool  $_rbac         Whether to apply RBAC checks.
     * @param bool  $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return ObjectEntity|null The owning object, or null when it cannot be resolved.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags mirror the
     *   established QueryHandler/MagicMapper API pattern.
     */
    private function resolveOwningObject(array $hit, bool $_rbac, bool $_multitenancy): ?ObjectEntity
    {
        $entityType = $hit['entity_type'] ?? 'file';
        $entityId   = $hit['entity_id'] ?? null;

        if ($entityId === null || $entityId === '') {
            return null;
        }

        try {
            if ($entityType === 'object') {
                return $this->objectMapper->find(
                    identifier: (int) $entityId,
                    _rbac: $_rbac,
                    _multitenancy: $_multitenancy
                );
            }

            $uuid = $this->fileMapper->findOwningObjectUuid(fileId: (int) $entityId);
            if ($uuid === null) {
                return null;
            }

            return $this->objectMapper->find(
                identifier: $uuid,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                message: '[ContentSearchHandler] Unable to resolve owning object for chunk hit; skipping',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'entityType' => $entityType,
                    'entityId'   => $entityId,
                    'error'      => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end resolveOwningObject()

    /**
     * Extract the caller's register/schema scope from the search query, mirroring the
     * key-precedence chain {@see MagicMapper::getSimpleFacets()} already uses.
     *
     * @param array $query The search query.
     *
     * @return array{registers: int[], schemas: int[]}
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     */
    private function resolveScope(array $query): array
    {
        $registerId  = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
        $registerIds = $query['@self']['registers'] ?? $query['_registers'] ?? null;
        $schemaId    = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;
        $schemaIds   = $query['@self']['schemas'] ?? $query['_schemas'] ?? null;

        $registers = [];
        if ($registerId !== null) {
            $registers[] = (int) $registerId;
        }

        if (is_array($registerIds) === true) {
            foreach ($registerIds as $id) {
                $registers[] = (int) $id;
            }
        }

        $schemas = [];
        if ($schemaId !== null) {
            $schemas[] = (int) $schemaId;
        }

        if (is_array($schemaIds) === true) {
            foreach ($schemaIds as $id) {
                $schemas[] = (int) $id;
            }
        }

        return [
            'registers' => array_values(array_unique($registers)),
            'schemas'   => array_values(array_unique($schemas)),
        ];
    }//end resolveScope()

    /**
     * Check whether a resolved object falls within the caller's register/schema scope.
     *
     * An empty scope list (no `_register`/`_schemas` on the query) matches everything,
     * consistent with an unscoped cross-schema search.
     *
     * @param ObjectEntity                            $object The resolved object.
     * @param array{registers: int[], schemas: int[]} $scope  The caller's scope.
     *
     * @return bool True when the object is in scope.
     */
    private function matchesScope(ObjectEntity $object, array $scope): bool
    {
        if (empty($scope['registers']) === false
            && in_array((int) $object->getRegister(), $scope['registers'], true) === false
        ) {
            return false;
        }

        if (empty($scope['schemas']) === false
            && in_array((int) $object->getSchema(), $scope['schemas'], true) === false
        ) {
            return false;
        }

        return true;
    }//end matchesScope()
}//end class
