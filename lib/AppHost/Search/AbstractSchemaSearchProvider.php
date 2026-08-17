<?php

/**
 * OpenRegister AppHost — Abstract Schema Search Provider
 *
 * Engine-owned base class that lets a consuming app expose a single
 * (register, schema) pair as its own unified-search provider, reusing
 * OpenRegister's object search pipeline under the same RBAC/multitenancy
 * contract as the generic `openregister_objects` provider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\AppHost\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Search;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Base class for a schema-scoped unified-search provider.
 *
 * A concrete subclass implements only {@see self::getRegisterSlug()} and
 * {@see self::getSchemaSlug()} — no id, display name, or constructor
 * boilerplate. `search()` forces the configured schema/register into the
 * query rather than exposing them as user-selectable filters (unlike the
 * generic `openregister_objects` provider's `IFilteringProvider` filters):
 * a schema-scoped provider is already narrow by construction, so exposing
 * those filters again would let a caller ask it to search a DIFFERENT
 * schema, which this class must refuse anyway.
 *
 * Gated by the schema's `smartPickerEnabled` flag: when `false`, `search()`
 * returns an empty but successfully completed `SearchResult` — the provider
 * remains listed/callable, it simply never returns matches while the flag
 * is off (design.md D2a's "Known limitation").
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractSchemaSearchProvider implements IProvider {

	/**
	 * Maximum number of results returned per unified-search page, matching
	 * the generic `ObjectsProvider`.
	 *
	 * @var int
	 */
	private const PAGE_LIMIT = 25;

	/**
	 * Memoized resolved register database ID. `false` means resolution was
	 * attempted and failed (distinct from `null`, meaning not yet attempted).
	 *
	 * @var int|false|null
	 */
	private int|false|null $registerId = null;

	/**
	 * Memoized resolved schema database ID. `false` means resolution was
	 * attempted and failed (distinct from `null`, meaning not yet attempted).
	 *
	 * @var int|false|null
	 */
	private int|false|null $schemaId = null;

	/**
	 * Constructor for AbstractSchemaSearchProvider.
	 *
	 * @param ObjectService $objectService The object service for search operations
	 * @param ObjectSearchResultFormatter $resultFormatter Shared result-formatting service
	 * @param RegisterMapper $registerMapper Register mapper for slug-to-id resolution
	 * @param SchemaMapper $schemaMapper Schema mapper for slug-to-id resolution and the `smartPickerEnabled` flag
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d3
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ObjectSearchResultFormatter $resultFormatter,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The register slug this provider is scoped to.
	 *
	 * @return string The register slug
	 */
	abstract public function getRegisterSlug(): string;

	/**
	 * The schema slug this provider is scoped to.
	 *
	 * @return string The schema slug
	 */
	abstract public function getSchemaSlug(): string;

	/**
	 * Computed id: `openregister_objects_{registerSlug}_{schemaSlug}`,
	 * matching the underscore-style naming of the existing generic
	 * `openregister_objects` search provider, and the id the paired
	 * `AbstractSchemaReferenceProvider` subclass declares in
	 * `getSupportedSearchProviderIds()`. Declared `final`.
	 *
	 * @return string Search provider ID
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	final public function getId(): string {
		return 'openregister_objects_' . $this->getRegisterSlug() . '_' . $this->getSchemaSlug();
	}//end getId()

	/**
	 * Read live from `SchemaMapper` — same source as
	 * `AbstractSchemaReferenceProvider::getTitle()`. Falls back to the raw
	 * schema slug when the schema cannot be resolved. Declared `final`.
	 *
	 * @return string The schema's current title, or its slug as fallback
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	final public function getName(): string {
		$schemaId = $this->resolveSchemaId();
		if ($schemaId === false) {
			return $this->getSchemaSlug();
		}

		try {
			$title = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->getTitle();
			if ($title !== null && $title !== '') {
				return $title;
			}
		} catch (\Exception $e) {
			// Fall through to slug fallback.
		}

		return $this->getSchemaSlug();
	}//end getName()

	/**
	 * Returns the order/priority of this search provider, matching the
	 * generic provider's order.
	 *
	 * @param string $route The route/context for which to get the order
	 * @param array $routeParameters Parameters for the route
	 *
	 * @return int|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function getOrder(string $route, array $routeParameters): ?int {
		unset($route, $routeParameters);
		return 10;
	}//end getOrder()

	/**
	 * Search within this provider's configured schema only.
	 *
	 * Forces `@self.schema`/`@self.register` into the query rather than
	 * reading them from a caller-supplied filter, delegates to
	 * `ObjectService::searchObjectsPaginated()` under the same RBAC
	 * (`_rbac: true`) / multitenancy (`_multitenancy: true`) contract the
	 * generic provider uses, and applies no second access filter.
	 *
	 * @param IUser $user The user performing the search
	 * @param ISearchQuery $query The search query from Nextcloud
	 *
	 * @return SearchResult The search results, scoped to this schema
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.StaticAccess)          SearchResult::complete/paginated is standard Nextcloud search pattern
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Mirrors ObjectsProvider::search(), which forces the same filter/pagination handling
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Mirrors ObjectsProvider::search()'s filter/pagination branching
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		unset($user);

		if ($this->isSmartPickerEnabled() === false) {
			return SearchResult::complete($this->getName(), []);
		}

		$registerId = $this->resolveRegisterId();
		$schemaId = $this->resolveSchemaId();
		if ($registerId === false || $schemaId === false) {
			return SearchResult::complete($this->getName(), []);
		}

		$term = $query->getTerm();

		$searchQuery = [];
		if ($term !== '') {
			$searchQuery['_search'] = $term;
		}

		$searchQuery['@self']['register'] = $registerId;
		$searchQuery['@self']['schema'] = $schemaId;

		// Widen the match to attached-file text under the same guard rails
		// as the generic provider (ZKN-CONTENT-001): `_rbac`/`_multitenancy`
		// are forwarded into the chunk fan-out, so this never becomes a
		// second, less-guarded search path.
		$searchQuery['_content_search'] = true;

		$limit = self::PAGE_LIMIT;
		$queryLimit = $query->getLimit();
		if ($queryLimit > 0 && $queryLimit < $limit) {
			$limit = $queryLimit;
		}

		$offset = 0;
		$cursor = $query->getCursor();
		if (is_numeric($cursor) === true) {
			$offset = max(0, (int)$cursor);
		}

		$searchQuery['_limit'] = $limit;
		$searchQuery['_offset'] = $offset;

		try {
			$searchResults = $this->objectService->searchObjectsPaginated(
				query: $searchQuery,
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[AbstractSchemaSearchProvider] Search failed for schema "{schema}", returning empty result: {error}',
				['schema' => $this->getSchemaSlug(), 'error' => $e->getMessage()]
			);
			return SearchResult::complete($this->getName(), []);
		}

		$entries = [];
		if (empty($searchResults['results']) === false) {
			foreach ($searchResults['results'] as $result) {
				$entries[] = $this->resultFormatter->format(result: $result, term: $term);
			}
		}

		if (count($entries) >= $limit) {
			return SearchResult::paginated($this->getName(), $entries, ($offset + $limit));
		}

		return SearchResult::complete($this->getName(), $entries);
	}//end search()

	/**
	 * Whether this provider's configured schema has opted in to its own
	 * Smart Picker entry's functionality. `false` for an unresolvable
	 * schema slug, matching the fail-closed default.
	 *
	 * @return bool True when the schema's `smartPickerEnabled` flag is set
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	protected function isSmartPickerEnabled(): bool {
		$schemaId = $this->resolveSchemaId();
		if ($schemaId === false) {
			return false;
		}

		try {
			return $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->isSmartPickerEnabled();
		} catch (\Exception $e) {
			return false;
		}
	}//end isSmartPickerEnabled()

	/**
	 * Lazily resolve {@see self::getRegisterSlug()} to its database ID,
	 * memoized per-instance for the lifetime of the request.
	 *
	 * @return int|false The resolved register ID, or `false` when the slug
	 *                    could not be resolved
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d3
	 */
	private function resolveRegisterId(): int|false {
		if ($this->registerId === null) {
			try {
				$this->registerId = (int)$this->registerMapper->find(
					$this->getRegisterSlug(),
					_rbac: false,
					_multitenancy: false
				)->getId();
			} catch (\Exception $e) {
				$this->logger->debug(
					'[AbstractSchemaSearchProvider] Failed to resolve register slug "{slug}": {error}',
					['slug' => $this->getRegisterSlug(), 'error' => $e->getMessage()]
				);
				$this->registerId = false;
			}
		}

		return $this->registerId;
	}//end resolveRegisterId()

	/**
	 * Lazily resolve {@see self::getSchemaSlug()} to its database ID,
	 * memoized per-instance for the lifetime of the request.
	 *
	 * @return int|false The resolved schema ID, or `false` when the slug
	 *                    could not be resolved
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d3
	 */
	private function resolveSchemaId(): int|false {
		if ($this->schemaId === null) {
			try {
				$this->schemaId = (int)$this->schemaMapper->find(
					$this->getSchemaSlug(),
					_multitenancy: false,
					_rbac: false
				)->getId();
			} catch (\Exception $e) {
				$this->logger->debug(
					'[AbstractSchemaSearchProvider] Failed to resolve schema slug "{slug}": {error}',
					['slug' => $this->getSchemaSlug(), 'error' => $e->getMessage()]
				);
				$this->schemaId = false;
			}
		}

		return $this->schemaId;
	}//end resolveSchemaId()
}//end class
