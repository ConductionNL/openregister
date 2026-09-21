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
     *
     * Perf note: the earlier "cheap pre-dedupe on 'object' hits" branch was
     * intentionally removed when dedup switched to UUID keying (see the
     * `$seenUuids` block in `augmentWithChunkMatches()` for why — metadata-
     * arm rows come from `searchObjectsInRegisterSchemaTable` which does not
     * populate `Entity::$id`). Every 'object' chunk hit therefore now pays
     * one `MagicMapper::find()` call before dedup even when it fully
     * overlaps the metadata arm. The clean restore path is to extend
     * `ChunkMapper::searchByKeyword()` to return the owning-object UUID for
     * 'object' chunks so the pre-check keys on UUID directly; folds into
     * the same deferred batch-resolve refactor above.
     */
    private const CHUNK_CANDIDATE_LIMIT = 50;

    /**
     * Request-scoped memo of the resolved chunk candidates, keyed by search
     * term and guard flags (ported from OpenRegister development, WOO-577).
     *
     * The chunk-store query is the same for every call within one request (it
     * is unscoped; scope is applied to the resolved owners), and so are the
     * resolved owners, because the guard flags and the acting user do not change
     * within a request. Only the scope and the dedupe against the metadata arm
     * differ per call, and those stay per call.
     *
     * @var array<string, ObjectEntity[]>
     */
    private array $candidateCache = [];

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
     * PORTED TO THE 1.1.5 HOTFIX LINE (1.1.5-woo-1, WOO-577) from OpenRegister
     * development PRs #3333 and #3856. The 1.1.5 original counted the distinct
     * chunk-owner set BEFORE resolving, as a deliberate upper bound ("ACCEPTED
     * OVER-COUNT"), which is why `/api/search` on the WOO stable line reported
     * `total 31` for 12 objects (WOO-577). It also deduplicated the chunk arm
     * only against the metadata rows of the CURRENT page, so an object that
     * matched both arms could come back on two different pages.
     *
     * Now: RESOLVE FIRST, THEN COUNT AND PAGE. `$total` is the number of chunk
     * owners this caller can actually SEE that the metadata arm did not already
     * count, and the page is a slice of that same resolved set. The overlap with
     * the metadata arm is asked of the metadata arm itself (one probe per
     * (register, schema) the owners live in, restricted with `_ids`, no paging),
     * so the answer does not depend on the page being served: `total` is the
     * same on every page and equals the number of distinct objects a consumer
     * gets when paging to the end.
     *
     * @param array          $query         The original search query (read for `_search` and
     *                                      register/schema scope; see {@see
     *                                      resolveScope()}).
     * @param ObjectEntity[] $results       The metadata-match rows already resolved by the
     *                                      pre-change search path.
     * @param int            $total         The metadata-match total already computed by the
     *                                      pre-change search path.
     * @param int            $limit         The page's `_limit` (0 = unlimited/count-only).
     * @param int            $offset        The page's global `_offset` into the combined
     *                                      metadata+chunk result stream (metadata rows
     *                                      first, chunk-only rows behind them).
     * @param bool           $_rbac         Whether to apply RBAC checks when resolving chunk-hit objects.
     * @param bool           $_multitenancy Whether to apply multitenancy filtering when resolving chunk-hit objects.
     * @param string|null    $activeOrgUuid The caller's active organisation, forwarded to the
     *                                      overlap probe so its tenancy filter matches the
     *                                      metadata arm's.
     *
     * @return array{results: ObjectEntity[], total: int}
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags mirror the
     *   established QueryHandler/MagicMapper API pattern.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Early-exit no-op guards (no _search,
     *   count-only, no candidates) plus one scope loop; each branch is a single-line
     *   guard clause, not nested logic.
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
        int $offset=0,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?string $activeOrgUuid=null
    ): array {
        $searchTerm = $query['_search'] ?? null;
        if (is_string($searchTerm) === false || trim($searchTerm) === '') {
            return ['results' => $results, 'total' => $total];
        }

        if ($limit === 0) {
            return ['results' => $results, 'total' => $total];
        }

        $candidates = $this->resolveCandidates(term: $searchTerm, _rbac: $_rbac, _multitenancy: $_multitenancy);
        if (empty($candidates) === true) {
            return ['results' => $results, 'total' => $total];
        }

        // Dedup key is UUID, not getId(): searchObjectsInRegisterSchemaTable
        // hydrates ObjectEntity without populating Entity::$id (the underlying
        // column is `_id`, not `id`), so getId() returns null on metadata-arm
        // rows. UUID is populated and stable across both arms.
        $seenOnPage = [];
        foreach ($results as $object) {
            if ($object instanceof ObjectEntity && $object->getUuid() !== null) {
                $seenOnPage[$object->getUuid()] = true;
            }
        }

        // RESOLVE FIRST, THEN COUNT AND PAGE. A count is an answer, so it has to
        // obey the same visibility rules as the rows: scope mismatch, RBAC,
        // tenancy and a soft-deleted owner each drop a row from `results`, and
        // must drop it from `total` too. Resolving every candidate rather than
        // only `$room` of them costs at most CHUNK_CANDIDATE_LIMIT resolves,
        // which is the worst case this class already budgets for.
        $scope    = $this->resolveScope(query: $query);
        $resolved = [];
        foreach ($candidates as $object) {
            if ($this->matchesScope(object: $object, scope: $scope) === false) {
                continue;
            }

            // Two chunks of the same document are one owner; resolveCandidates()
            // already collapsed them, this keeps the invariant local.
            $resolved[$object->getUuid()] = $object;
        }

        // Owners the metadata arm matches too are already in its total; the
        // rest is the chunk arm. Asked of the metadata arm itself rather than
        // read off this page, so the answer is the same on every page.
        $overlap = $this->metadataArmOverlap(
            query: $query,
            owners: $resolved,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            activeOrgUuid: $activeOrgUuid
        );

        $chunkOnly = array_diff_key($resolved, $overlap);
        $appended  = $this->pageChunkArm(
            chunkOnly: $chunkOnly,
            seenOnPage: $seenOnPage,
            results: $results,
            limit: $limit,
            offset: $offset,
            metadataTotal: $total
        );

        return [
            'results' => array_merge($results, $appended),
            'total'   => $total + count($chunkOnly),
        ];
    }//end augmentWithChunkMatches()

    /**
     * Slice the chunk arm for this page.
     *
     * The metadata arm occupies logical positions 0..metadataTotal-1 of the
     * combined list, so the chunk arm starts at `offset - metadataTotal` once
     * the metadata rows are exhausted, and at 0 on the page where they run
     * out. A row already on this page is never shown twice, whatever the
     * overlap probe said (belt and braces for a probe that under-reports).
     *
     * THE ANCHOR TRUSTS `metadataTotal`. It is the metadata arm's own count, from
     * a separate COUNT query than the one that produced the rows, so the two can
     * disagree. When the count is too high the chunk arm repeats its first owner
     * for as many pages as the overstatement; when it is too low, rows past the
     * stated total are never reached by a client paging on `total`. Nothing here
     * can detect that: this method sees one page, not the arm.
     *
     * @param array<string, ObjectEntity> $chunkOnly     The chunk-only owners, keyed by uuid, in hit order.
     * @param array<string, true>         $seenOnPage    The uuids of this page's metadata rows.
     * @param ObjectEntity[]              $results       This page's metadata rows.
     * @param int                         $limit         The page's `_limit` (0 = unlimited).
     * @param int                         $offset        The page's `_offset` over the combined list.
     * @param int                         $metadataTotal The metadata arm's total.
     *
     * @return ObjectEntity[] The chunk-only rows to append to this page.
     */
    private function pageChunkArm(
        array $chunkOnly,
        array $seenOnPage,
        array $results,
        int $limit,
        int $offset,
        int $metadataTotal
    ): array {
        $room = null;
        if ($limit > 0) {
            $room = max(0, $limit - count($results));
        }

        $remaining = array_slice($chunkOnly, max(0, $offset - $metadataTotal), null, true);

        return array_values(array_slice(array_diff_key($remaining, $seenOnPage), 0, $room));
    }//end pageChunkArm()

    /**
     * Ask the metadata arm which of the resolved chunk owners it matches as well.
     *
     * Runs the caller's own query — same term, same guards — restricted to the
     * given owners and without any paging, so the result does not depend on
     * which page is being served. One probe per (register, schema) the owners
     * live in, because that is the one search path on which an id restriction
     * is honoured: MagicMapper's multi-schema UNION path accepts `ids` and
     * `_ids` and applies neither (measured on the 2.x rig — a probe over three
     * tables came back as `LIMIT 2` without a uuid predicate). On 1.1.5 the
     * single-table path reads `_ids` in MagicSearchHandler::buildFilteredQuery().
     * Bounded by CHUNK_CANDIDATE_LIMIT ids in total.
     *
     * @param array                       $query         The original search query.
     * @param array<string, ObjectEntity> $owners        The resolved chunk owners, keyed by uuid.
     * @param bool                        $_rbac         Whether RBAC applied to the metadata arm.
     * @param bool                        $_multitenancy Whether multitenancy applied to the metadata arm.
     * @param string|null                 $activeOrgUuid The caller's active organisation for the tenancy filter.
     *
     * @return array<string, true> The uuids the metadata arm matches, as a set.
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags mirror the
     *   established QueryHandler/MagicMapper API pattern.
     */
    private function metadataArmOverlap(
        array $query,
        array $owners,
        bool $_rbac,
        bool $_multitenancy,
        ?string $activeOrgUuid
    ): array {
        $probe   = $this->probeQuery(query: $query);
        $overlap = [];

        foreach ($this->groupOwnersByTable(owners: $owners) as $group) {
            $tableProbe = $probe;
            $tableProbe['_register'] = $group['register'];
            $tableProbe['_schema']   = $group['schema'];
            $tableProbe['_ids']      = $group['uuids'];
            $tableProbe['_limit']    = count($group['uuids']);

            $matched = $this->objectMapper->searchObjectsPaginated(
                searchQuery: $tableProbe,
                countQuery: $tableProbe,
                _activeOrgUuid: $activeOrgUuid,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            foreach ($matched['results'] ?? [] as $row) {
                if ($row instanceof ObjectEntity && $row->getUuid() !== null) {
                    $overlap[$row->getUuid()] = true;
                }
            }
        }//end foreach

        return $overlap;
    }//end metadataArmOverlap()

    /**
     * Group resolved owners by the (register, schema) table they live in.
     *
     * @param array<string, ObjectEntity> $owners The resolved chunk owners, keyed by uuid.
     *
     * @return array<string, array{register: int, schema: int, uuids: string[]}>
     */
    private function groupOwnersByTable(array $owners): array
    {
        $groups = [];
        foreach ($owners as $uuid => $object) {
            $register = $object->getRegister();
            $schema   = $object->getSchema();
            if ($register === null || $schema === null) {
                continue;
            }

            $key = $register.'/'.$schema;
            $groups[$key]['register'] = (int) $register;
            $groups[$key]['schema']   = (int) $schema;
            $groups[$key]['uuids'][]  = $uuid;
        }

        return $groups;
    }//end groupOwnersByTable()

    /**
     * The caller's query with paging and scope stripped, ready to be aimed at
     * one table with `_ids`. The restriction travels as `_ids` on the
     * single-table path; a `_ids` key on an UNSCOPED query would switch
     * MagicMapper to its id-lookup path, which ignores `_search`, so the scope
     * keys are always set by the caller before use.
     *
     * @param array $query The original search query.
     *
     * @return array The probe template.
     *
     * @psalm-param    array<string, mixed> $query
     * @phpstan-param  array<string, mixed> $query
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    private function probeQuery(array $query): array
    {
        unset(
            $query['_limit'],
            $query['_offset'],
            $query['_page'],
            $query['_facetable'],
            $query['_facets'],
            $query['_aggregations'],
            $query['_extend'],
            $query['_fields'],
            $query['_content_search'],
            $query['_register'],
            $query['_registers'],
            $query['_schema'],
            $query['_schemas'],
            $query['register'],
            $query['schema'],
            $query['_ids']
        );

        if (is_array($query['@self'] ?? null) === true) {
            unset(
                $query['@self']['register'],
                $query['@self']['registers'],
                $query['@self']['schema'],
                $query['@self']['schemas']
            );
        }

        $query['_offset'] = 0;

        return $query;
    }//end probeQuery()

    /**
     * Fetch the chunk candidates for a term and resolve each to its owning
     * object, once per request.
     *
     * @param string $term          The search term.
     * @param bool   $_rbac         Whether to apply RBAC checks when resolving.
     * @param bool   $_multitenancy Whether to apply multitenancy filtering when resolving.
     *
     * @return ObjectEntity[] The resolved, UUID-deduplicated owners in hit order.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags mirror the
     *   established QueryHandler/MagicMapper API pattern.
     */
    private function resolveCandidates(string $term, bool $_rbac, bool $_multitenancy): array
    {
        $key = json_encode([$term, $_rbac, $_multitenancy]);
        if (is_string($key) === true && array_key_exists($key, $this->candidateCache) === true) {
            return $this->candidateCache[$key];
        }

        $chunkHits = $this->chunkMapper->searchByKeyword(
            query: $term,
            limit: self::CHUNK_CANDIDATE_LIMIT,
            filters: [],
            allowUnrankedFallback: true
        );

        $candidates = [];
        $seen       = [];
        foreach ($chunkHits as $hit) {
            $object = $this->resolveOwningObject(hit: $hit, _rbac: $_rbac, _multitenancy: $_multitenancy);
            if ($object === null) {
                continue;
            }

            $uuid = $object->getUuid();
            if ($uuid === null || isset($seen[$uuid]) === true) {
                continue;
            }

            $seen[$uuid]  = true;
            $candidates[] = $object;
        }

        if (is_string($key) === true) {
            $this->candidateCache[$key] = $candidates;
        }

        return $candidates;
    }//end resolveCandidates()

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
     * SCOPE LIMITATION (documented per review #476 🟡 filter parity):
     * only `register(s)` / `schema(s)` are honoured here. Any other filter the
     * metadata arm applied (property filters, `_status`, `_created`/`_updated`
     * date ranges, magic-column filters) is INTENTIONALLY DROPPED when appending
     * chunk-only matches. This means an object the caller filtered out on a
     * property predicate can resurface via a file-text match on the same query.
     *
     * This is a correctness/consistency gap, NOT a data leak — RBAC + multitenancy
     * still pass through {@see MagicMapper::find()} on every appended row, so no
     * caller ever sees an object it lacks read permission on. The chunk arm
     * intentionally trades filter parity for the simpler resolve-then-append
     * pipeline; threading arbitrary schema-property predicates onto the resolved
     * ObjectEntity is a follow-up (would require re-applying the metadata-arm
     * filter engine to individual objects post-resolve, or pre-filtering
     * `$chunkHits` by owner via a batched `findMany()` — same batch-resolve
     * refactor as {@see CHUNK_CANDIDATE_LIMIT}).
     *
     * Callers relying on filter parity should either omit `_content_search=true`
     * or narrow their register/schema scope to keep the chunk arm's fan-out
     * aligned with the metadata arm.
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

        // Every key may carry one id OR a list (ported from development): the
        // unified-search provider passes its searchable allow-list as
        // `@self.schema`. A `(int)`-cast of a non-empty array is 1 in PHP, so
        // every content-search hit used to be silently scoped to schema id 1.
        $registers = array_merge($this->idsOf(value: $registerId), $this->idsOf(value: $registerIds));
        $schemas   = array_merge($this->idsOf(value: $schemaId), $this->idsOf(value: $schemaIds));

        return [
            'registers' => array_values(array_unique($registers)),
            'schemas'   => array_values(array_unique($schemas)),
        ];
    }//end resolveScope()

    /**
     * Normalise one scope value (a single id, a list of ids, or null) to ints.
     *
     * @param mixed $value The raw query value.
     *
     * @return int[] The ids; empty when the value is null or carries none.
     */
    private function idsOf(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value) === false) {
            return [(int) $value];
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_scalar($id) === true) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }//end idsOf()

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
