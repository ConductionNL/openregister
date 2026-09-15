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
	 * @param ChunkMapper $chunkMapper Chunk store mapper (keyword search over body text).
	 * @param FileMapper $fileMapper File mapper, used to resolve a file chunk's owning object.
	 * @param MagicMapper $objectMapper Unified object mapper, used to resolve chunk hits to ObjectEntity rows.
	 * @param LoggerInterface $logger Logger for DEBUG-level unresolvable-chunk diagnostics.
	 *
	 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
	 */
	public function __construct(
		private readonly ChunkMapper $chunkMapper,
		private readonly FileMapper $fileMapper,
		private readonly MagicMapper $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Widen a metadata-match result set with chunk-body-text matches, per ZKN-CONTENT-002.
	 *
	 * No-op (returns `$results`/`$total` unchanged) when: no `_search` term is present,
	 * the page limit is 0 (count/facets-only request), or no chunk hits are found.
	 *
	 * @param array $query The original search query (read for `_search` and
	 *                     register/schema scope; see {@see
	 *                     resolveScope()}).
	 * @param ObjectEntity[] $results The metadata-match rows already resolved by the
	 *                                pre-change search path.
	 * @param int $total The metadata-match total already computed by the
	 *                   pre-change search path.
	 * @param int $limit The page's `_limit` (0 = unlimited/count-only).
	 * @param bool $_rbac Whether to apply RBAC checks when resolving chunk-hit objects.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering when resolving chunk-hit objects.
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
		bool $_rbac = true,
		bool $_multitenancy = true,
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
		$seenUuids = [];
		foreach ($results as $object) {
			if ($object instanceof ObjectEntity && $object->getUuid() !== null) {
				$seenUuids[$object->getUuid()] = true;
			}
		}

		// RESOLVE FIRST, THEN COUNT AND PAGE. `$total` is the number of chunk
		// owners this caller can actually SEE, and the page is a slice of that
		// same resolved set.
		//
		// This used to count the distinct chunk-owner set BEFORE resolving, as
		// a deliberate upper bound, to keep `$total` stable across pages.
		// Stability was the right goal; the upper bound was the wrong way to
		// reach it, because scope mismatch, RBAC, tenancy and a soft-deleted
		// owner each drop a row from `results` without touching `$total`.
		//
		// Measured on the dev instance 2026-09-02, unauthenticated, against
		// OpenCatalogi's #[PublicPage] search: a document that had been
		// soft-deleted still answered `{"results":[],"total":1}`. An anonymous
		// caller could therefore probe a phrase and learn from the count alone
		// that a document containing it exists, while being correctly refused
		// the document itself. A count is an answer, so it has to obey the same
		// visibility rules as the rows.
		//
		// Resolving every candidate rather than only `$room` of them costs at
		// most CHUNK_CANDIDATE_LIMIT resolves, which is the worst case this
		// class already budgets for and documents on that constant. `$total`
		// stays stable across pages because the resolved set is a property of
		// the query, not of the page: page 1 and page 3 resolve the same
		// candidates and report the same number.
		$scope = $this->resolveScope(query: $query);

		$resolved = [];
		foreach ($candidates as $object) {
			if (isset($seenUuids[$object->getUuid()]) === true) {
				continue;
			}

			if ($this->matchesScope(object: $object, scope: $scope) === false) {
				continue;
			}

			// Seed the dedupe set as we go: two chunks of the same document
			// are one owner, and must be counted once.
			$seenUuids[$object->getUuid()] = true;
			$resolved[] = $object;
		}//end foreach

		$room = PHP_INT_MAX;
		if ($limit > 0) {
			$room = max(0, $limit - count($results));
		}

		$appended = array_slice($resolved, 0, $room);

		return [
			'results' => array_merge($results, $appended),
			'total' => $total + count($resolved),
		];
	}//end augmentWithChunkMatches()

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
			query: $term,
			limit: self::CHUNK_CANDIDATE_LIMIT,
			filters: [],
			allowUnrankedFallback: true
		);

		$candidates = [];
		$seen = [];
		foreach ($chunkHits as $hit) {
			$object = $this->resolveOwningObject(hit: $hit, _rbac: $_rbac, _multitenancy: $_multitenancy);
			if ($object === null) {
				continue;
			}

			$uuid = $object->getUuid();
			if ($uuid === null || isset($seen[$uuid]) === true) {
				continue;
			}

			$seen[$uuid] = true;
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
		$entityType = $hit['entity_type'] ?? 'file';
		$entityId = $hit['entity_id'] ?? null;

		if ($entityId === null || $entityId === '') {
			return null;
		}

		try {
			if ($entityType === 'object') {
				return $this->objectMapper->find(
					identifier: (int)$entityId,
					_rbac: $_rbac,
					_multitenancy: $_multitenancy
				);
			}

			$uuid = $this->fileMapper->findOwningObjectUuid(fileId: (int)$entityId);
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
					'file' => __FILE__,
					'line' => __LINE__,
					'entityType' => $entityType,
					'entityId' => $entityId,
					'error' => $e->getMessage(),
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
