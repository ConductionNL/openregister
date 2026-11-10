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
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use Psr\Log\LoggerInterface;

/**
 * Widens a metadata-only search result set with objects whose attached-file (or
 * object-level) chunk body text matches the query, per
 * `openspec/specs/zoeken-filteren/spec.md`
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
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
class ContentSearchHandler {
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
	 * `uuidSet()` docblock for why — metadata-
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
	 * term and guard flags.
	 *
	 * The unified-search provider calls this handler once per schema chunk
	 * of ONE search (26 calls at 1,272 schemas). The chunk-store query is
	 * the same every time (it is unscoped; scope is applied to the resolved
	 * owners), and so are the resolved owners, because the guard flags and
	 * the acting user do not change within a request. Only the scope and
	 * the dedupe against the metadata arm differ per call, and those stay
	 * per call. Measured 2026-09-07 on the fleet dev instance: run per
	 * chunk, the chunk-store query was 85% of a 55 s top-bar search.
	 *
	 * @var array<string, ObjectEntity[]>
	 */
	private array $candidateCache = [];

	/**
	 * Constructor for ContentSearchHandler.
	 *
	 * @param ChunkMapperInterface $chunkMapper Chunk store mapper (keyword search over body text).
	 * @param FileMapperInterface $fileMapper File mapper, used to resolve a file chunk's owning object.
	 * @param MagicMapperInterface $objectMapper Unified object mapper, used to resolve chunk hits to ObjectEntity rows.
	 * @param LoggerInterface $logger Logger for DEBUG-level unresolvable-chunk diagnostics.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly ChunkMapperInterface $chunkMapper,
		private readonly FileMapperInterface $fileMapper,
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Widen a metadata page with the chunk owners the caller can see, per ZKN-CONTENT-002.
	 *
	 * No-op (the page unchanged) when no `_search` term is present, the page
	 * limit is 0 (count/facets-only request), or no chunk hits are found.
	 *
	 * THE CHUNK ARM IS A SECOND LIST BEHIND THE METADATA ARM, AND IT MUST NOT
	 * CONTAIN THE METADATA ARM'S ROWS. Both arms are paged as ONE list: the
	 * metadata rows come first, the chunk-only rows follow once the metadata
	 * arm is exhausted. That only works when the second list is (a) the same
	 * on every page and (b) disjoint from the first.
	 *
	 * Deduplicating against the page's rows gave neither. The rows are only THIS
	 * page of the metadata arm, so an owner that the metadata arm serves on
	 * page 2 was still counted as a chunk-only owner on page 1, and `total`
	 * moved from page to page. And without an offset into the chunk arm every
	 * page past the metadata rows re-served the same chunk-only rows, so a
	 * client walking `_page` never reached the end.
	 *
	 * Measured 2026-09-17 on a NC 32 rig with OpenCatalogi 2.1.0 in front of
	 * this class: `_search=Klimaatakkoord&_content=true&_limit=1` gave total
	 * 4, 5, 4, 5, ... across pages, and page 4 onward returned the same object
	 * indefinitely (WOO-577). {@see metadataArmOverlap()} asks the metadata
	 * arm which of the resolved owners it matches too, so the answer is a
	 * property of the query, not of the page; {@see pageChunkArm()} slices
	 * the remainder by the offset past the metadata arm.
	 *
	 * @param ContentSearchWidening $widening      The query, the metadata page and the caller's organisation.
	 * @param bool                  $_rbac         Whether to apply RBAC checks when resolving chunk-hit objects.
	 * @param bool                  $_multitenancy Whether to apply multitenancy filtering when resolving chunk-hit objects.
	 *
	 * @return array{results: ObjectEntity[], total: int}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags mirror the
	 *   established QueryHandler/MagicMapper API pattern.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function widen(ContentSearchWidening $widening, bool $_rbac = true, bool $_multitenancy = true): array {
		$page = $widening->page;
		$term = $widening->searchTerm();
		if ($term === null || $page->limit === 0) {
			return $page->unchanged();
		}

		$candidates = $this->resolveCandidates(term: $term, _rbac: $_rbac, _multitenancy: $_multitenancy);
		if (empty($candidates) === true) {
			return $page->unchanged();
		}

		$resolved = $this->ownersInScope(query: $widening->query, candidates: $candidates);

		// Owners the metadata arm matches too are already in its total; the
		// rest is the chunk arm. See the docblock for why this is asked of the
		// metadata arm itself rather than read off this page.
		$overlap   = $this->metadataArmOverlap(widening: $widening, owners: $resolved, _rbac: $_rbac, _multitenancy: $_multitenancy);
		$chunkOnly = array_diff_key($resolved, $overlap);

		return [
			'results' => array_merge($page->results, $this->pageChunkArm(page: $page, chunkOnly: $chunkOnly)),
			'total' => $page->total + count($chunkOnly),
		];
	}//end widen()

	/**
	 * The resolved candidates inside the caller's register/schema scope, keyed by uuid.
	 *
	 * RESOLVE FIRST, THEN COUNT AND PAGE. The total is the number of chunk
	 * owners this caller can actually SEE, and the page is a slice of that
	 * same resolved set.
	 *
	 * This used to count the distinct chunk-owner set BEFORE resolving, as a
	 * deliberate upper bound, to keep the total stable across pages. Stability
	 * was the right goal; the upper bound was the wrong way to reach it,
	 * because scope mismatch, RBAC, tenancy and a soft-deleted owner each drop
	 * a row from `results` without touching the total.
	 *
	 * Measured on the dev instance 2026-09-02, unauthenticated, against
	 * OpenCatalogi's #[PublicPage] search: a document that had been
	 * soft-deleted still answered `{"results":[],"total":1}`. An anonymous
	 * caller could therefore probe a phrase and learn from the count alone that
	 * a document containing it exists, while being correctly refused the
	 * document itself. A count is an answer, so it has to obey the same
	 * visibility rules as the rows.
	 *
	 * Resolving every candidate rather than only the page's room costs at most
	 * CHUNK_CANDIDATE_LIMIT resolves, which is the worst case this class
	 * already budgets for and documents on that constant.
	 *
	 * @param array          $query      The original search query.
	 * @param ObjectEntity[] $candidates The resolved chunk owners in hit order.
	 *
	 * @return array<string, ObjectEntity> The owners in scope, keyed by uuid.
	 *
	 * @psalm-param   array<string, mixed> $query
	 * @phpstan-param array<string, mixed> $query
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function ownersInScope(array $query, array $candidates): array {
		$scope = $this->resolveScope(query: $query);

		$resolved = [];
		foreach ($candidates as $object) {
			if ($this->matchesScope(object: $object, scope: $scope) === false) {
				continue;
			}

			// Two chunks of the same document are one owner; resolveCandidates()
			// already collapsed them, this keeps the invariant local.
			$resolved[$object->getUuid()] = $object;
		}

		return $resolved;
	}//end ownersInScope()

	/**
	 * Slice the chunk arm for this page.
	 *
	 * The metadata arm occupies logical positions 0..total-1 of the combined
	 * list, so the chunk arm starts at `offset - total` once the metadata rows
	 * are exhausted, and at 0 on the page where they run out. A row already on
	 * this page is never shown twice, whatever the overlap probe said (belt and
	 * braces for a probe that under-reports).
	 *
	 * THE ANCHOR TRUSTS THE METADATA TOTAL. It is the metadata arm's own count, from
	 * a separate COUNT query than the one that produced the rows, so the two can
	 * disagree — a write landing between the round-trips, or a count and a fetch
	 * built by different code paths. When the count is too high the chunk arm
	 * repeats its first owner for as many pages as the overstatement; when it is
	 * too low, rows past the stated total are never reached by a client paging on
	 * `total`. Nothing here can detect that: this method sees one page, not the
	 * arm. The fix belongs where the disagreement is — the count and the fetch
	 * agreeing — not in a correction guessed per page.
	 *
	 * @param MetadataArmPage             $page      The metadata page, its total and its window.
	 * @param array<string, ObjectEntity> $chunkOnly The chunk-only owners, keyed by uuid, in hit order.
	 *
	 * @return ObjectEntity[] The chunk-only rows to append to this page.
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private function pageChunkArm(MetadataArmPage $page, array $chunkOnly): array {
		$room = null;
		if ($page->limit > 0) {
			$room = max(0, $page->limit - count($page->results));
		}

		$remaining = array_slice($chunkOnly, max(0, $page->offset - $page->total), null, true);

		return array_values(array_slice(array_diff_key($remaining, self::uuidSet(rows: $page->results)), 0, $room));
	}//end pageChunkArm()


	/**
	 * Ask the metadata arm which of the resolved chunk owners it matches as well.
	 *
	 * Runs the caller's own query — same term, same guards — restricted to the
	 * given owners and without any paging, so the result does not depend on
	 * which page is being served. One probe per (register, schema) the owners
	 * live in, because that is the one search path on which an id restriction
	 * is honoured: MagicMapper's multi-schema UNION path accepts `ids` and
	 * `_ids` and applies neither (measured on the rig — a probe over three
	 * tables came back as `LIMIT 2` without a uuid predicate, and reported the
	 * first two metadata rows as the overlap). Bounded by CHUNK_CANDIDATE_LIMIT
	 * ids in total.
	 *
	 * @param ContentSearchWidening       $widening      The query and the caller's organisation for the tenancy filter.
	 * @param array<string, ObjectEntity> $owners        The resolved chunk owners, keyed by uuid.
	 * @param bool                        $_rbac         Whether RBAC applied to the metadata arm.
	 * @param bool                        $_multitenancy Whether multitenancy applied to the metadata arm.
	 *
	 * @return array<string, true> The uuids the metadata arm matches, as a set.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags mirror the
	 *   established QueryHandler/MagicMapper API pattern.
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private function metadataArmOverlap(ContentSearchWidening $widening, array $owners, bool $_rbac, bool $_multitenancy): array {
		$probe = $this->probeQuery(query: $widening->query);

		$overlap = [];
		foreach ($this->groupOwnersByTable(owners: $owners) as $group) {
			$tableProbe = self::tableProbe(probe: $probe, group: $group);
			$matched    = $this->objectMapper->searchObjectsPaginated(
				searchQuery: $tableProbe,
				countQuery: $tableProbe,
				_activeOrgUuid: $widening->activeOrgUuid,
				_rbac: $_rbac,
				_multitenancy: $_multitenancy
			);

			$overlap += self::uuidSet(rows: ($matched['results'] ?? []));
		}

		return $overlap;
	}//end metadataArmOverlap()

	/**
	 * The probe template aimed at one table's owners.
	 *
	 * @param array                                           $probe The probe template from probeQuery().
	 * @param array{register: int, schema: int, uuids: string[]} $group One table's owners.
	 *
	 * @return array The probe for that table.
	 *
	 * @psalm-param   array<string, mixed> $probe
	 * @phpstan-param array<string, mixed> $probe
	 * @psalm-return  array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private static function tableProbe(array $probe, array $group): array {
		$probe['_register'] = $group['register'];
		$probe['_schema']   = $group['schema'];
		$probe['_ids']      = $group['uuids'];
		$probe['_limit']    = count($group['uuids']);

		return $probe;
	}//end tableProbe()

	/**
	 * The uuids of the ObjectEntity rows in a list, as a set.
	 *
	 * Keyed on UUID, not getId(): searchObjectsInRegisterSchemaTable hydrates
	 * ObjectEntity without populating Entity::$id (the column is `_id`), so
	 * getId() is null on metadata-arm rows. UUID is stable across both arms.
	 *
	 * @param array $rows The rows a search returned.
	 *
	 * @return array<string, true> The uuids, as a set.
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private static function uuidSet(array $rows): array {
		$uuids = [];
		foreach ($rows as $row) {
			if ($row instanceof ObjectEntity && $row->getUuid() !== null) {
				$uuids[$row->getUuid()] = true;
			}
		}

		return $uuids;
	}//end uuidSet()


	/**
	 * Group resolved owners by the (register, schema) table they live in.
	 *
	 * @param array<string, ObjectEntity> $owners The resolved chunk owners, keyed by uuid.
	 *
	 * @return array<string, array{register: int, schema: int, uuids: string[]}>
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private function groupOwnersByTable(array $owners): array {
		$groups = [];
		foreach ($owners as $uuid => $object) {
			$register = $object->getRegister();
			$schema = $object->getSchema();
			if ($register === null || $schema === null) {
				continue;
			}

			$key = $register.'/'.$schema;
			$groups[$key]['register'] = (int) $register;
			$groups[$key]['schema'] = (int) $schema;
			$groups[$key]['uuids'][] = $uuid;
		}

		return $groups;
	}//end groupOwnersByTable()


	/**
	 * The caller's query with paging and scope stripped, ready to be aimed at
	 * one table with `_ids`. The restriction travels as `_ids` on the
	 * single-table path, so the scope keys are always set by the caller before
	 * use.
	 *
	 * @param array $query The original search query.
	 *
	 * @return array The probe template.
	 *
	 * @psalm-param   array<string, mixed> $query
	 * @phpstan-param array<string, mixed> $query
	 * @psalm-return  array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	private function probeQuery(array $query): array {
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
			$query['_ids'],
		);
		if (is_array($query['@self'] ?? null) === true) {
			unset($query['@self']['register'], $query['@self']['registers'], $query['@self']['schema'], $query['@self']['schemas']);
		}

		$query['_offset'] = 0;

		return $query;
	}//end probeQuery()

	/**
	 * Fetch the chunk candidates for a term and resolve each to its owning
	 * object, once per request.
	 *
	 * Returns the resolved owners in chunk-hit order, deduplicated on UUID
	 * (a chunk `entity_id` is a numeric id, not a UUID, so two hits on one
	 * owner are only known to be one owner after the resolve). Hits that do
	 * not resolve (not found, RBAC denial, cross-tenant, unresolvable
	 * file->object join) are dropped silently, per D3. The dedupe against
	 * the caller's metadata arm and the caller's register/schema scope are
	 * NOT applied here: they differ per call and stay in the caller.
	 *
	 * @param string $term The search term.
	 * @param bool $_rbac Whether to apply RBAC checks when resolving.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering when resolving.
	 *
	 * @return ObjectEntity[] The resolved, UUID-deduplicated owners in hit order.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags mirror the
	 *   established QueryHandler/MagicMapper API pattern.
	 */
	private function resolveCandidates(string $term, bool $_rbac, bool $_multitenancy): array {
		$key = json_encode([$term, $_rbac, $_multitenancy]);
		if (is_string($key) === true && array_key_exists($key, $this->candidateCache) === true) {
			return $this->candidateCache[$key];
		}

		$chunkHits = $this->chunkMapper->searchByKeyword(
			search: new KeywordChunkSearch(query: $term, limit: self::CHUNK_CANDIDATE_LIMIT, allowUnrankedFallback: true)
		);

		$owners = [];
		foreach ($chunkHits as $hit) {
			$owners[] = $this->resolveOwningObject(hit: $hit, _rbac: $_rbac, _multitenancy: $_multitenancy);
		}

		$candidates = self::distinctOwners(owners: $owners);

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
	 * @param array $hit One row from {@see ChunkMapper::searchByKeyword()}.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return ObjectEntity|null The owning object, or null when it cannot be resolved.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags mirror the
	 *   established QueryHandler/MagicMapper API pattern.
	 */
	private function resolveOwningObject(array $hit, bool $_rbac, bool $_multitenancy): ?ObjectEntity {
		try {
			$identifier = $this->ownerIdentifier(hit: $hit);
			if ($identifier === null) {
				return null;
			}

			return $this->objectMapper->find(identifier: $identifier, _rbac: $_rbac, _multitenancy: $_multitenancy);
		} catch (\Throwable $e) {
			$this->logUnresolvableHit(hit: $hit, error: $e);
			return null;
		}
	}//end resolveOwningObject()

	/**
	 * The identifier a chunk hit's owner is found by: the object id, or the uuid of the file's owner.
	 *
	 * @param array $hit One row from {@see ChunkMapper::searchByKeyword()}.
	 *
	 * @return int|string|null The identifier, or null when the hit names no owner.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function ownerIdentifier(array $hit): int|string|null {
		$entityId = ($hit['entity_id'] ?? null);
		if ($entityId === null || $entityId === '') {
			return null;
		}

		if (($hit['entity_type'] ?? 'file') === 'object') {
			return (int)$entityId;
		}

		return $this->fileMapper->findOwningObjectUuid(fileId: (int)$entityId);
	}//end ownerIdentifier()

	/**
	 * Log, at DEBUG, a chunk hit whose owner could not be resolved.
	 *
	 * @param array      $hit   One row from {@see ChunkMapper::searchByKeyword()}.
	 * @param \Throwable $error Why it could not be resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function logUnresolvableHit(array $hit, \Throwable $error): void {
		$this->logger->debug(
			message: '[ContentSearchHandler] Unable to resolve owning object for chunk hit; skipping',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityType' => ($hit['entity_type'] ?? 'file'),
				'entityId' => ($hit['entity_id'] ?? null),
				'error' => $error->getMessage(),
			]
		);
	}//end logUnresolvableHit()

	/**
	 * The resolved owners, without the unresolved ones and with each uuid once, in hit order.
	 *
	 * @param array<int, ObjectEntity|null> $owners The owner of each chunk hit, null when unresolved.
	 *
	 * @return ObjectEntity[] The distinct owners.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private static function distinctOwners(array $owners): array {
		$distinct = [];
		foreach ($owners as $object) {
			$uuid = $object?->getUuid();
			if ($uuid !== null && isset($distinct[$uuid]) === false) {
				$distinct[$uuid] = $object;
			}
		}

		return array_values($distinct);
	}//end distinctOwners()

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
	private function resolveScope(array $query): array {
		$registerId = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
		$registerIds = $query['@self']['registers'] ?? $query['_registers'] ?? null;
		$schemaId = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;
		$schemaIds = $query['@self']['schemas'] ?? $query['_schemas'] ?? null;

		// Every key may carry one id OR a list: the unified-search provider
		// passes its searchable allow-list as `@self.schema`. That list used
		// to be `(int)`-cast, which PHP answers with 1 for any non-empty
		// array, so every content-search hit was silently scoped to schema
		// id 1 and dropped (or, if schema 1 had opted out, let through).
		$registers = array_merge($this->idsOf(value: $registerId), $this->idsOf(value: $registerIds));
		$schemas = array_merge($this->idsOf(value: $schemaId), $this->idsOf(value: $schemaIds));

		return [
			'registers' => array_values(array_unique($registers)),
			'schemas' => array_values(array_unique($schemas)),
		];
	}//end resolveScope()

	/**
	 * Normalise one scope value (a single id, a list of ids, or null) to ints.
	 *
	 * @param mixed $value The raw query value.
	 *
	 * @return int[] The ids; empty when the value is null or carries none.
	 */
	private function idsOf(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (is_array($value) === false) {
			return [(int)$value];
		}

		$ids = [];
		foreach ($value as $id) {
			if (is_scalar($id) === true) {
				$ids[] = (int)$id;
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
	 * @param ObjectEntity $object The resolved object.
	 * @param array{registers: int[], schemas: int[]} $scope The caller's scope.
	 *
	 * @return bool True when the object is in scope.
	 */
	private function matchesScope(ObjectEntity $object, array $scope): bool {
		if (empty($scope['registers']) === false
			&& in_array((int)$object->getRegister(), $scope['registers'], true) === false
		) {
			return false;
		}

		if (empty($scope['schemas']) === false
			&& in_array((int)$object->getSchema(), $scope['schemas'], true) === false
		) {
			return false;
		}

		return true;
	}//end matchesScope()
}//end class
