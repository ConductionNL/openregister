<?php

/**
 * SearchQueryHandler - Search and Query Operations Handler
 *
 * Handles all search query building, execution, and pagination operations.
 * This handler separates search-related business logic from the main ObjectService,
 * improving code organization and maintainability.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander;
use OCA\OpenRegister\Support\FilterParams;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * SearchQueryHandler class
 *
 * Handles search query operations including:
 * - Query building and parameter normalization
 * - View-based filtering
 * - Search execution (sync/async/database)
 * - Pagination URL generation
 * - Search trail logging
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex search query building and optimization logic
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Query building reads views, schemas, watchers and references
 */
class SearchQueryHandler {

	/**
	 * The id a `_watching=true` query falls back to when there is no caller.
	 * It is deliberately not a uuid, so no object can ever carry it and the
	 * lens cannot accidentally widen to the whole register.
	 *
	 * @var string
	 */
	private const NO_WATCHED_OBJECTS = '__no-watched-objects__';

	/**
	 * The id an `_unread=true` query falls back to when there is no caller.
	 *
	 * An anonymous reader has no read state, so a naive `NOT EXISTS` would be
	 * true for every row and the lens would answer the WHOLE register instead of
	 * nothing. That is the silent-widening failure the watchers lens guards
	 * against too, and it is guarded the same way: a literal no object can
	 * carry.
	 *
	 * @var string
	 */
	private const NO_UNREAD_READER = '__no-unread-reader__';

	/**
	 * The id a `_favourite=true` or `_recent=true` query falls back to when
	 * there is no caller.
	 *
	 * Anonymous has starred nothing and opened nothing, so an unguarded lens
	 * would drop its own restriction and answer the WHOLE register. Guarded the
	 * same way as the two above: a literal no object can carry.
	 *
	 * @var string
	 */
	private const NO_PERSONAL_LENS_USER = '__no-personal-lens-user__';

	/**
	 * Memoized effective search-trail recording mode for this request.
	 *
	 * The recording mode is read from settings once per request instead of
	 * twice per search (getEffectiveRecordingMode() + the enabled check in
	 * logSearchTrail()). Null until the first read. Long-running processes
	 * (CLI) keep the first value for their lifetime, which is acceptable
	 * for a best-effort analytics trail.
	 *
	 * @var string|null
	 */
	private ?string $recordingModeMemo = null;

	/**
	 * In-request buffer of search-trail entries pending persistence.
	 *
	 * Entries accumulate during the request and are flushed after the
	 * response by a shutdown function (mirrors ProcessingLogService's
	 * buffered emission), keeping the INSERT off the hot search path.
	 * The trail is best-effort: losing buffered rows on a fatal is
	 * acceptable.
	 *
	 * @var array<int, array{query: array, resultCount: int, totalResults: int, responseTime: float, executionType: string}>
	 */
	private array $searchTrailBuffer = [];

	/**
	 * Whether the shutdown flush for the trail buffer is registered.
	 *
	 * @var boolean
	 */
	private bool $trailFlushRegistered = false;

	/**
	 * SearchQueryHandler constructor.
	 *
	 * @param ViewScopeApplier $viewScope Merges a view's stored query into a search.
	 * @param SchemaMapper $schemaMapper Mapper for schema operations.
	 * @param SettingsService $settingsService Service for settings operations.
	 * @param LoggerInterface $logger Logger for performance monitoring.
	 * @param IRequest $request Request object.
	 * @param SearchTrailService $searchTrailService Service for recording search trails.
	 * @param IUserSession|null $userSession Resolves the caller for that lens.
	 * @param CodedFilterExpander|null $codedFilters Expands a branch filter into the concepts under it.
	 * @param SearchReferenceResolver|null $referenceResolver Resolves a register/schema slug or uuid to its id.
	 * @param ReadHistoryService|null $readHistory The read history behind the `_recent` lens.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly ViewScopeApplier $viewScope,
		private readonly SchemaMapper $schemaMapper,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IRequest $request,
		private readonly SearchTrailService $searchTrailService,
		private readonly ?IUserSession $userSession = null,
		// The branch-filter expander. Nullable with a null default so the many
		// unit tests that build this handler positionally keep working; the
		// container resolves the real instance by type in production.
		private readonly ?CodedFilterExpander $codedFilters = null,
		// The register/schema reference resolver. Nullable and last for the same
		// reason as the expander above. Absent, buildSearchQuery() still refuses
		// a reference it cannot read rather than int-casting it into an empty
		// page; present, it resolves a slug or uuid the way the write path does.
		private readonly ?SearchReferenceResolver $referenceResolver = null,
		// The read history behind the `_recent` lens. Nullable and last for the
		// same reason; absent, the lens answers an empty page that says the
		// history could not be read, never the whole register.
		private readonly ?ReadHistoryService $readHistory = null,
	) {
	}//end __construct()

	/**
	 * Narrow a query to the objects the calling user follows.
	 *
	 * `_watching=true` is a LENS, not a filter: it does not ask the object a
	 * question, it restricts the result to this user's follows and lets every
	 * other filter, the RBAC gate and the paging run over what is left.
	 *
	 * It is resolved INSIDE the query (`merge-follow-and-favourites` D-4): the
	 * caller's uid lands on `_watchingFor`, which the mapper turns into a
	 * correlated `EXISTS` against `openregister_watchers`, like the unread
	 * lens. It used to load every followed uuid onto `_ids`, a list that grew
	 * with every follow and, once favourites moved in, with every star too.
	 * An explicit `_ids` therefore composes by AND, so "these five cases, of
	 * which I follow two" still answers two.
	 *
	 * `_favourite=true` is the deprecated alias of this lens for one release:
	 * a favourite IS a follow now, so both flags ask the same question and
	 * either one turns the lens on.
	 *
	 * Anonymous callers get a literal id no object carries, so the answer is
	 * an honest empty page rather than the whole register.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 *
	 * @return array<string, mixed> The query, narrowed when the lens was asked for.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	private function applyWatchingLens(array $query): array {
		// Never trust a resolved key from the outside: a caller must not hand
		// in somebody else's uid.
		unset($query['_watchingFor']);

		$asked = false;
		foreach (['_watching', '_favourite'] as $flag) {
			if (array_key_exists($flag, $query) === true) {
				$asked = ($asked || filter_var($query[$flag], FILTER_VALIDATE_BOOLEAN));
				unset($query[$flag]);
			}
		}

		if ($asked === false) {
			return $query;
		}

		$uid = $this->userSession?->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			$query['_ids'] = [self::NO_WATCHED_OBJECTS];
			return $query;
		}

		$query['_watchingFor'] = $uid;

		return $query;
	}//end applyWatchingLens()

	/**
	 * Resolve `_unread=true` into the uid whose read state the query reads.
	 *
	 * The lens is resolved INSIDE the query, not applied to a fetched page: the
	 * mapper turns `_unreadFor` into a correlated `NOT EXISTS` against the
	 * read-state table, so the page, the total and the facets all see the same
	 * restriction. A post-filter would give a first page of 25 with a total of
	 * 120 and a second page that skipped rows, which reads as a paging bug and
	 * is not one.
	 *
	 * Identity is resolved HERE, at the edge, and never in the query builder:
	 * ADR-005 wants the principal named where the request arrives, and it also
	 * means the mapper can be tested with a uid rather than a session.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 *
	 * @return array<string, mixed> The query, carrying the resolved reader.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	private function applyUnreadLens(array $query): array {
		if (array_key_exists('_unread', $query) === false) {
			return $query;
		}

		$asked = filter_var($query['_unread'], FILTER_VALIDATE_BOOLEAN);
		unset($query['_unread']);
		if ($asked === false) {
			return $query;
		}

		$uid = $this->userSession?->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			// No reader, so nothing can be unread FOR them. An honest empty
			// page, never the whole register.
			$query['_ids'] = [self::NO_UNREAD_READER];
			return $query;
		}

		$query['_unreadFor'] = $uid;

		return $query;
	}//end applyUnreadLens()

	/**
	 * Resolve the personal lenses still handled here: `_recent=true`.
	 *
	 * `_favourite=true` used to be resolved here too. Since
	 * `merge-follow-and-favourites` it is an alias of `_watching=true` and is
	 * consumed by applyWatchingLens(), which runs first.
	 *
	 * Resolved INSIDE the query, never on a fetched page, so the page, the
	 * total and the facets see one restriction. Identity is resolved here, at
	 * the edge (ADR-005), never in the query builder.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 *
	 * @return array<string, mixed> The query, carrying the resolved user.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-favourites-and-recent-are-lenses-on-the-object-query
	 */
	private function applyPersonalLenses(array $query): array {
		return $this->applyRecentLens(query: $query);
	}//end applyPersonalLenses()

	/**
	 * Resolve `_recent=true` from the caller's read history on the audit trail.
	 *
	 * The history lands on `_ids` (intersected, history order kept), so every
	 * search path honours it and RBAC still decides. `_recentViews` (uuid =>
	 * ISO 8601) drives the order and `@self.viewedAt`; `_recentLens` is the
	 * response report. Audit trail off: empty, with a reason, no shadow log.
	 * Caller-supplied copies of the internal keys are stripped first.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 *
	 * @return array<string, mixed> The query, narrowed when the lens was asked for.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-the-recent-lens-says-why-it-is-empty
	 */
	private function applyRecentLens(array $query): array {
		unset($query['_recentFor'], $query['_recentViews'], $query['_recentLens']);

		if (array_key_exists('_recent', $query) === false) {
			return $query;
		}

		$asked = filter_var($query['_recent'], FILTER_VALIDATE_BOOLEAN);
		unset($query['_recent']);
		if ($asked === false) {
			return $query;
		}

		$uid = $this->userSession?->getUser()?->getUID();
		$lens = [
			'available' => false,
			'reason' => ReadHistoryService::REASON_UNAVAILABLE,
			'views' => [],
		];
		if ($this->readHistory !== null) {
			$lens = $this->readHistory->resolveRecentLens(userId: $uid);
		}

		$query['_recentLens'] = ['available' => $lens['available'], 'reason' => $lens['reason']];

		$views = $lens['views'];
		$existing = ($query['_ids'] ?? null);
		if (is_array($existing) === true && $existing !== []) {
			// Intersect, keeping the history's order.
			$views = array_intersect_key($views, array_flip(array_map('strval', $existing)));
		}

		if ($views === []) {
			// Nothing to show: an honest empty page, never the whole register.
			$query['_ids'] = [self::NO_PERSONAL_LENS_USER];
			return $query;
		}

		$query['_ids'] = array_keys($views);
		$query['_recentViews'] = $views;

		return $query;
	}//end applyRecentLens()

	/**
	 * Whether the target schema is served by an external object-source (DBAL
	 * virtual register), whose columns are flat snake_case and must not be run
	 * through the dot-un-mangling in {@see buildSearchQuery()}.
	 *
	 * Resolves the schema id/slug via the mapper (request-cached). A
	 * multi-schema (array) or absent schema, or any resolution failure, yields
	 * false so the legacy magic-table behaviour is preserved.
	 *
	 * This is a SYSTEM-level structural lookup (does the schema declare an
	 * object-source?), used only to decide how query params are parsed — it
	 * returns no schema data to the caller, so it MUST bypass RBAC and
	 * multitenancy. Otherwise, when saasMode is active and the schema lives in
	 * a different organisation than the caller's active org, the tenant-scoped
	 * find() cannot see it, this returns false, and snake_case column filters
	 * (e.g. `app_id`) are wrongly dot-un-mangled — silently emptying every
	 * per-object filter on a cross-org DBAL register. The actual data read is
	 * still RBAC/tenant-checked downstream in paginateObjectSource() (mirrors
	 * the object-source Source lookup, openregister#2089).
	 *
	 * @param int|string|array|null $schema The schema id/slug (single value only).
	 *
	 * @return bool True when the schema declares an x-openregister-object-source.
	 */
	private function schemaHasObjectSource(int|string|array|null $schema): bool {
		if ($schema === null || is_array($schema) === true) {
			return false;
		}

		try {
			$entity = $this->schemaMapper->find(id: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return false;
		}

		return ($entity->getObjectSource() !== null);
	}//end schemaHasObjectSource()

	/**
	 * Whether the target schema declares a property literally named `filter`.
	 *
	 * Only such a schema can mean `filter[x]=v` as a filter on its own `filter`
	 * property, so only there is the bracket spelling left alone. A list of
	 * schemas (cross-table search) counts when any one of them declares it. A
	 * schema that cannot be resolved does not, so the lift applies.
	 *
	 * A SYSTEM-level structural lookup, like {@see schemaHasObjectSource()}: it
	 * decides how the parameters are parsed and returns no schema data, so it
	 * bypasses RBAC and multitenancy. The data read stays checked downstream.
	 *
	 * @param int|string|array|null $schema The schema id/slug, or a list of them.
	 *
	 * @return bool True when a target schema declares a `filter` property.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	private function schemaDeclaresFilterProperty(int|string|array|null $schema): bool {
		if ($schema === null) {
			return false;
		}

		$schemaRefs = [$schema];
		if (is_array($schema) === true) {
			$schemaRefs = $schema;
		}

		foreach ($schemaRefs as $schemaRef) {
			try {
				$entity = $this->schemaMapper->find(id: $schemaRef, _rbac: false, _multitenancy: false);
			} catch (\Throwable $e) {
				continue;
			}

			if (array_key_exists(FilterParams::FILTER_KEY, ($entity->getProperties() ?? [])) === true) {
				return true;
			}
		}

		return false;
	}//end schemaDeclaresFilterProperty()

	/**
	 * Resolve a register or schema reference for the query being built.
	 *
	 * Delegates to {@see SearchReferenceResolver} when the container wired one.
	 * Without it — a handler built positionally in a unit test — the reference
	 * is still never int-cast into an empty page: what can be read as an id is
	 * read as one, what says nothing becomes `null` (which reaches the global
	 * fallbacks), and everything else is refused by name. That is the whole
	 * point of openregister#3990: a reference nobody can resolve must not look
	 * like a register with no objects in it.
	 *
	 * @param int|string|array|null $reference The register/schema id, uuid, slug, or a list.
	 * @param string $kind Either `register` or `schema`.
	 *
	 * @return int|array|null The numeric id(s), or null when the reference says nothing.
	 *
	 * @psalm-return int|array<int, int>|null
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When a register reference names no register.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function resolveReference(int|string|array|null $reference, string $kind): int|array|null {
		if ($reference === null) {
			return null;
		}

		if ($this->referenceResolver !== null) {
			if ($kind === 'register') {
				return $this->referenceResolver->register(reference: $reference);
			}

			return $this->referenceResolver->schema(reference: $reference);
		}

		if (is_array($reference) === true) {
			return $this->readReferenceList(references: $reference, kind: $kind);
		}

		return $this->readReference(reference: $reference, kind: $kind);
	}//end resolveReference()

	/**
	 * Read a list of references without a resolver.
	 *
	 * @param array<int|string, mixed> $references The references.
	 * @param string $kind Either `register` or `schema`.
	 *
	 * @return array<int, int> The ids the list names.
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When a register reference names no register.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function readReferenceList(array $references, string $kind): array {
		$ids = [];
		foreach ($references as $item) {
			if (is_int($item) === false && is_string($item) === false) {
				continue;
			}

			$id = $this->readReference(reference: $item, kind: $kind);
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end readReferenceList()

	/**
	 * Read one reference without a resolver.
	 *
	 * Only what can be read as an id is read as one. Nothing is int-cast into
	 * an empty page, and nothing is resolved either, because there is no mapper
	 * here to ask.
	 *
	 * @param int|string $reference The id, uuid or slug.
	 * @param string $kind Either `register` or `schema`.
	 *
	 * @return int|null The id, or null when the reference says nothing.
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When a register reference names no register.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function readReference(int|string $reference, string $kind): ?int {
		if (is_int($reference) === true && $reference > 0) {
			return $reference;
		}

		$trimmed = trim((string)$reference);
		if ($trimmed === '') {
			return null;
		}

		if (ctype_digit($trimmed) === true && (int)$trimmed > 0) {
			return (int)$trimmed;
		}

		if ($kind === 'register') {
			throw new RegisterNotFoundException(registerSlugOrId: $trimmed);
		}

		throw new SchemaNotFoundException(schemaSlugOrId: $trimmed);
	}//end readReference()

	/**
	 * Build search query from request parameters
	 *
	 * Converts HTTP request parameters into a structured query array for searchObjectsPaginated.
	 * Handles PHP's dot-to-underscore parameter name conversion, extracts metadata filters,
	 * and separates object field filters from system parameters.
	 *
	 * @param array $requestParams Request parameters from HTTP request.
	 * @param int|string|array|null $register Optional register ID(s) to filter by.
	 * @param int|string|array|null $schema Optional schema ID(s) to filter by.
	 * @param array|null $ids Optional array of object IDs to filter by.
	 *
	 * @return ((int[]|mixed)[]|mixed)[]
	 *
	 * @psalm-return array{'@self': array<string, array<int>|int|mixed>|mixed, _ids?: array|mixed,...}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function buildSearchQuery(
		array $requestParams,
		int|string|array|null $register = null,
		int|string|array|null $schema = null,
		?array $ids = null,
	): array {
		$params = $this->filterableParams(
			params: $this->liftBracketFilterUnlessDeclared(
				params: $this->restoreDottedParams(requestParams: $requestParams, schema: $schema),
				schema: $schema
			)
		);

		$parts = $this->splitSearchParams(
			params: $params,
			self: $this->scopeFilters(register: $register, schema: $schema)
		);

		// STEP 2b: expand a branch filter into the concepts it stands for.
		// `?categorie[branch]=<uri>` becomes `categorie` IN (the branch root
		// plus every narrower concept under it), walked at query time and
		// bounded by depth, so moving a concept in the scheme changes what the
		// filter matches with no reindex anywhere.
		$objectFilters = $parts->objectFilters;
		if ($this->codedFilters !== null) {
			$objectFilters = $this->codedFilters->expand(filters: $objectFilters, schemaRef: $schema);
		}

		$query = array_merge(['@self' => $parts->self], $objectFilters);

		if ($ids !== null) {
			$query['_ids'] = $ids;
		}

		$query = $this->mergeSpecialParams(query: $query, special: $parts->special);

		// The `_watching=true` lens, applied AFTER `_ids` is normalised to an
		// array so the two can intersect rather than fight.
		$query = $this->applyWatchingLens(query: $query);

		// The `_unread=true` lens, after the watching lens so that "unread among
		// what I follow" narrows twice rather than one overwriting the other.
		$query = $this->applyUnreadLens(query: $query);

		// The `_favourite=true` and `_recent=true` lenses, last, so that every
		// earlier lens has already narrowed `_ids` and these two compose with it
		// rather than replacing it.
		return $this->applyPersonalLenses(query: $query);
	}//end buildSearchQuery()

	/**
	 * Undo PHP's dot-to-underscore mangling of parameter names (`@self_register` back to `@self.register`).
	 *
	 * Underscore-prefixed system parameters (`_limit`, `_offset`) stay as they are. A schema served
	 * from an external object source has FLAT, snake_case columns (`product_line`), so for it only
	 * `@…` metadata keys are un-mangled; splitting its column names would silently drop the filter.
	 *
	 * @param array                 $requestParams The request parameters.
	 * @param int|string|array|null $schema        The schema reference of the search.
	 *
	 * @return array<string, mixed> The parameters with nested paths rebuilt.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function restoreDottedParams(array $requestParams, int|string|array|null $schema): array {
		$isObjectSourceSchema = $this->schemaHasObjectSource(schema: $schema);

		$tree = new DottedParamTree();
		foreach ($requestParams as $key => $value) {
			if (str_starts_with(haystack: $key, needle: '_') === true) {
				$tree->set(key: $key, value: $value);
				continue;
			}

			if (str_contains($key, '_') === true
				&& ($isObjectSourceSchema === false || str_starts_with(haystack: $key, needle: '@') === true)
			) {
				$tree->nest(key: $key, value: $value);
				continue;
			}

			$tree->set(key: $key, value: $value);
		}

		return $tree->params;
	}//end restoreDottedParams()

	/**
	 * Read the bracket spelling `filter[x]=v` as `x=v`, as the aggregation endpoints do (openregister#3611).
	 *
	 * Not for a schema that really declares a `filter` property: there the property filter is meant.
	 *
	 * @param array<string, mixed>  $params The parameters.
	 * @param int|string|array|null $schema The schema reference of the search.
	 *
	 * @return array<string, mixed> The parameters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function liftBracketFilterUnlessDeclared(array $params, int|string|array|null $schema): array {
		if (is_array($params[FilterParams::FILTER_KEY] ?? null) === true
			&& $this->schemaDeclaresFilterProperty(schema: $schema) === false
		) {
			return FilterParams::liftBracketFilter(params: $params);
		}

		return $params;
	}//end liftBracketFilterUnlessDeclared()

	/**
	 * The parameters without the route and the system parameters that are never filters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return array<string, mixed> The remaining parameters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function filterableParams(array $params): array {
		unset($params['_route']);
		foreach (FilterParams::OBJECT_SYSTEM_PARAMS as $systemParam) {
			unset($params[$systemParam]);
		}

		return $params;
	}//end filterableParams()

	/**
	 * The `@self` register and schema filters of the call, single values or lists.
	 *
	 * A reference is resolved the way the write path resolves it, and refused the way the write
	 * path refuses it; an int cast turned a slug into register 0 and an empty page
	 * (openregister#3990).
	 *
	 * @param int|string|array|null $register The register reference.
	 * @param int|string|array|null $schema   The schema reference.
	 *
	 * @return array<string, mixed> The `@self` filters.
	 *
	 * @throws RegisterNotFoundException When the register reference cannot be read.
	 * @throws SchemaNotFoundException When the schema reference cannot be read.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function scopeFilters(int|string|array|null $register, int|string|array|null $schema): array {
		$self = [];
		$registerId = $this->resolveReference(reference: $register, kind: 'register');
		if ($registerId !== null) {
			$self['register'] = $registerId;
		}

		$schemaId = $this->resolveReference(reference: $schema, kind: 'schema');
		if ($schemaId !== null) {
			$self['schema'] = $schemaId;
		}

		return $self;
	}//end scopeFilters()

	/**
	 * Sort the parameters into `@self` metadata filters, underscore parameters and object field filters.
	 *
	 * A metadata parameter never overrides the call's own register or schema. An underscore
	 * parameter is kept both as a special parameter and among the object field filters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 * @param array<string, mixed> $self   The call's `@self` filters.
	 *
	 * @return SearchQueryParts The sorted parameters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function splitSearchParams(array $params, array $self): SearchQueryParts {
		$metadataFields = [
			'register',
			'schema',
			'uuid',
			'organisation',
			'owner',
			'application',
			'created',
			'updated',
			'deleted',
		];
		$specialParams = [];
		$objectFilters = [];

		foreach ($params as $key => $value) {
			if (str_starts_with(haystack: $key, needle: '_') === true) {
				$specialParams[$key] = $value;
			} elseif (in_array(needle: $key, haystack: $metadataFields) === true) {
				if (isset($self[$key]) === false) {
					$self[$key] = $value;
				}

				continue;
			}

			$objectFilters[$key] = $value;
		}

		return new SearchQueryParts(self: $self, special: $specialParams, objectFilters: $objectFilters);
	}//end splitSearchParams()

	/**
	 * Add the underscore parameters to the query, with `_ids` normalised to an array.
	 *
	 * URL parameters like `_ids=uuid1,uuid2` arrive as one string, but MagicMapper expects an array.
	 *
	 * @param array<string, mixed> $query   The query.
	 * @param array<string, mixed> $special The underscore parameters.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function mergeSpecialParams(array $query, array $special): array {
		if (isset($special['ids']) === true) {
			$query['_ids'] = $special['ids'];
			unset($special['ids']);
		}

		$query = array_merge($query, $special);

		if (isset($query['_ids']) === true && is_string($query['_ids']) === true) {
			$query['_ids'] = array_filter(array_map('trim', explode(',', $query['_ids'])));
		}

		return $query;
	}//end mergeSpecialParams()

	/**
	 * Apply view filters to a query.
	 *
	 * The merge itself lives in {@see ViewScopeApplier}, which owns the
	 * ViewMapper and the fail-closed contract around `$_viewScopeRequired`.
	 * This handler keeps the entry point its callers already use.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param array<int|string> $viewIds View IDs to apply (can be int or string IDs).
	 * @param bool $_viewScopeRequired Whether the view filter is load-bearing for this
	 *                                 caller, making any failure to apply it fatal.
	 *
	 * @return array<string, mixed> Query with view filters applied
	 *
	 * @throws Exception When `$_viewScopeRequired` is true and a view cannot be applied.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the fail-closed contract, not a mode switch
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function applyViewsToQuery(array $query, array $viewIds, bool $_viewScopeRequired = false): array {
		return $this->viewScope->apply(
			query: $query,
			viewIds: $viewIds,
			_viewScopeRequired: $_viewScopeRequired
		);
	}//end applyViewsToQuery()

	/**
	 * Add pagination URLs to search results
	 *
	 * Generates next and previous page URLs based on current page and total pages.
	 * Only adds URLs when pagination is needed (pages > 1).
	 *
	 * @param array<string, mixed> $paginatedResults Search results array (passed by reference).
	 * @param int $page Current page number.
	 * @param int $pages Total number of pages.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function addPaginationUrls(array &$paginatedResults, int $page, int $pages): void {
		// **PERFORMANCE OPTIMIZATION**: Only generate URLs if pagination is needed.
		if ($pages <= 1) {
			return;
		}

		$currentUrl = $this->request->getRequestUri();

		// Add next page link if there are more pages.
		if ($page < $pages) {
			$nextPage = ($page + 1);
			$nextUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $nextPage, $currentUrl);
			if (strpos($nextUrl, 'page=') === false) {
				$nextUrl .= $this->getUrlSeparator(url: $nextUrl) . 'page=' . $nextPage;
			}

			$paginatedResults['next'] = $nextUrl;
		}

		// Add previous page link if not on first page.
		if ($page > 1) {
			$prevPage = ($page - 1);
			$prevUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $prevPage, $currentUrl);
			if (strpos($prevUrl, 'page=') === false) {
				$prevUrl .= $this->getUrlSeparator(url: $prevUrl) . 'page=' . $prevPage;
			}

			$paginatedResults['prev'] = $prevUrl;
		}
	}//end addPaginationUrls()

	/**
	 * Get URL separator character (? or &)
	 *
	 * Determines whether to use '?' or '&' when adding query parameters to a URL.
	 *
	 * @param string $url URL to check.
	 *
	 * @return string '?' if URL has no query string, '&' otherwise
	 *
	 * @psalm-return '&'|'?'
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function getUrlSeparator(string $url): string {
		if (strpos($url, '?') === false) {
			return '?';
		}

		return '&';
	}//end getUrlSeparator()

	/**
	 * Buffer a search trail entry for deferred persistence
	 *
	 * Records a search trail entry if search trails are enabled in settings.
	 * The entry is buffered in-request and persisted after the response by a
	 * shutdown function (see flushSearchTrails()), so the trail INSERT never
	 * adds latency to the search itself. The trail is best-effort by
	 * contract: buffered rows lost on a fatal are acceptable.
	 *
	 * @param array<string, mixed> $_query Search query array.
	 * @param int $_resultCount Number of results returned.
	 * @param int $_totalResults Total number of matching results.
	 * @param float $_executionTime Execution time in milliseconds.
	 * @param string $_executionType Type of execution (sync, async, optimized, etc.).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	public function logSearchTrail(
		array $_query,
		int $_resultCount,
		int $_totalResults,
		float $_executionTime,
		string $_executionType = 'sync',
	): void {
		// Only record when trails are enabled. Reuses the memoized recording
		// mode so the settings are read at most once per request instead of a
		// second time here.
		if ($this->getEffectiveRecordingMode() === 'none') {
			return;
		}

		$this->searchTrailBuffer[] = [
			'query' => $_query,
			'resultCount' => $_resultCount,
			'totalResults' => $_totalResults,
			'responseTime' => $_executionTime,
			'executionType' => $_executionType,
		];

		// Register the deferred flush once per request; it runs after the
		// response has been generated so the write cost is off the hot path.
		// The callback is a closure rather than the `[$this, 'flushSearchTrails']`
		// array form it replaced: a string method name is invisible to every
		// static tool, so renaming the flush would break the registration with
		// no error anywhere — trails would just stop being written.
		if ($this->trailFlushRegistered === false) {
			$this->trailFlushRegistered = true;
			register_shutdown_function(
				function (): void {
					$this->flushSearchTrails();
				}
			);
		}
	}//end logSearchTrail()

	/**
	 * Flush buffered search-trail entries to storage
	 *
	 * Runs as a shutdown function after the response, and may be called
	 * directly (tests, CLI) to force persistence. Fail-soft per entry: a
	 * failed insert is logged and dropped — the trail is best-effort and
	 * must never surface an error to the request.
	 *
	 * @return int Number of entries persisted.
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	public function flushSearchTrails(): int {
		$persisted = 0;
		$entries = $this->searchTrailBuffer;

		// Clear the buffer up front so re-entrant calls never double-write.
		$this->searchTrailBuffer = [];

		foreach ($entries as $entry) {
			try {
				// The mapper extracts the search term from query['_search'] into
				// the search_term column that the popular-terms stats aggregate.
				$this->searchTrailService->createSearchTrail(
					query: $entry['query'],
					resultCount: $entry['resultCount'],
					totalResults: $entry['totalResults'],
					responseTime: $entry['responseTime'],
					executionType: $entry['executionType']
				);
				$persisted++;
			} catch (\Throwable $e) {
				// Log the error but never fail: the trail is best-effort.
				$this->logger->warning(
					message: '[SearchQueryHandler] Failed to record search trail',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $persisted;
	}//end flushSearchTrails()

	/**
	 * Resolve the effective search-trail recording mode.
	 *
	 * Returns 'none' when search trails are disabled (master switch /
	 * back-compat), otherwise the configured `searchTrailRecordingMode`
	 * ('all', '_search', or 'none'; default '_search'). Falls back to
	 * '_search' if settings cannot be read. The resolved mode is memoized
	 * per request so repeated calls (mode gate + logSearchTrail) read the
	 * settings once.
	 *
	 * @return string One of 'all', '_search', 'none'.
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	public function getEffectiveRecordingMode(): string {
		if ($this->recordingModeMemo !== null) {
			return $this->recordingModeMemo;
		}

		$this->recordingModeMemo = $this->resolveRecordingMode();

		return $this->recordingModeMemo;
	}//end getEffectiveRecordingMode()

	/**
	 * Read the recording mode from settings (uncached).
	 *
	 * @return string One of 'all', '_search', 'none'.
	 */
	private function resolveRecordingMode(): string {
		try {
			$retentionSettings = $this->settingsService->getRetentionSettingsOnly();
			if (($retentionSettings['searchTrailsEnabled'] ?? true) === false) {
				return 'none';
			}

			$mode = $retentionSettings['searchTrailRecordingMode'] ?? '_search';
			if (in_array($mode, ['all', '_search', 'none'], true) === true) {
				return $mode;
			}

			return '_search';
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SearchQueryHandler] Failed to read recording mode, defaulting to _search',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return '_search';
		}//end try
	}//end resolveRecordingMode()
}//end class
