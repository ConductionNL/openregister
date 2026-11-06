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

use OCA\OpenRegister\AppHost\SchemaScopedProvider;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	use SchemaScopedProvider;

	/**
	 * Maximum number of results returned per unified-search page, matching
	 * the generic `ObjectsProvider`.
	 *
	 * @var int
	 */
	private const PAGE_LIMIT = 25;

	/**
	 * The name this provider's debug lines carry.
	 *
	 * @var string
	 */
	private const LOG_NAME = 'AbstractSchemaSearchProvider';

	/**
	 * Constructor for AbstractSchemaSearchProvider.
	 *
	 * @param ObjectOperationsInterface $objectService The object service for search operations
	 * @param ObjectSearchResultFormatter $resultFormatter Shared result-formatting service
	 * @param RegisterMapperInterface $registerMapper Register mapper for slug-to-id resolution
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for slug-to-id resolution and the `smartPickerEnabled` flag
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d3
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly ObjectSearchResultFormatter $resultFormatter,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
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
		return $this->schemaTitle();
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
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		unset($user);

		$scope = $this->searchScope();
		if ($scope === []) {
			return $this->resultOf(entries: []);
		}

		$page  = new SchemaSearchPage(term: $query->getTerm(), limit: $this->limitOf(query: $query), offset: $this->offsetOf(query: $query));
		$found = $this->find(query: $this->searchQuery(scope: $scope, page: $page));
		if ($found === null) {
			return $this->resultOf(entries: []);
		}

		return $this->pageResult(results: ($found['results'] ?? []), page: $page);
	}//end search()

	/**
	 * The `@self` filter of this provider's schema, or empty when it may not search.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	private function searchScope(): array {
		if ($this->isSmartPickerEnabled() === false) {
			return [];
		}

		$registerId = $this->resolveRegisterId();
		$schemaId   = $this->resolveSchemaId();
		if ($registerId === false || $schemaId === false) {
			return [];
		}

		return ['register' => $registerId, 'schema' => $schemaId];
	}//end searchScope()

	/**
	 * The page size: the query's when that is positive and below the cap, else the cap.
	 *
	 * @param ISearchQuery $query The search query from Nextcloud.
	 *
	 * @return int
	 */
	private function limitOf(ISearchQuery $query): int {
		$limit = $query->getLimit();
		if ($limit > 0 && $limit < self::PAGE_LIMIT) {
			return $limit;
		}

		return self::PAGE_LIMIT;
	}//end limitOf()

	/**
	 * The offset a numeric cursor names, never below zero.
	 *
	 * @param ISearchQuery $query The search query from Nextcloud.
	 *
	 * @return int
	 */
	private function offsetOf(ISearchQuery $query): int {
		$cursor = $query->getCursor();
		if (is_numeric($cursor) === true) {
			return max(0, (int)$cursor);
		}

		return 0;
	}//end offsetOf()

	/**
	 * The query for one page of this provider's schema.
	 *
	 * Widens the match to attached-file text under the same guard rails as the
	 * generic provider (ZKN-CONTENT-001): `_rbac`/`_multitenancy` are forwarded into
	 * the chunk fan-out, so this never becomes a second, less-guarded search path.
	 *
	 * @param array<string, int> $scope The `@self` filter.
	 * @param SchemaSearchPage   $page  The page asked for.
	 *
	 * @return array<string, mixed>
	 */
	private function searchQuery(array $scope, SchemaSearchPage $page): array {
		$searchQuery = [];
		if ($page->term !== '') {
			$searchQuery['_search'] = $page->term;
		}

		$searchQuery['@self'] = $scope;
		$searchQuery['_content_search'] = true;
		$searchQuery['_limit'] = $page->limit;
		$searchQuery['_offset'] = $page->offset;

		return $searchQuery;
	}//end searchQuery()

	/**
	 * Run the search under RBAC and multitenancy, or log why it failed.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>|null The result, or null when the search failed.
	 */
	private function find(array $query): ?array {
		try {
			return $this->objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: true);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[AbstractSchemaSearchProvider] Search failed for schema "{schema}", returning empty result: {error}',
				['schema' => $this->getSchemaSlug(), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end find()

	/**
	 * One page of results: complete when it is not full, else with the next offset as its cursor.
	 *
	 * @param array<int, mixed> $results The matched objects.
	 * @param SchemaSearchPage  $page    The page asked for.
	 *
	 * @return SearchResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchResult::paginated is the standard Nextcloud search pattern.
	 */
	private function pageResult(array $results, SchemaSearchPage $page): SearchResult {
		$entries = [];
		foreach ($results as $result) {
			$entries[] = $this->resultFormatter->format(result: $result, term: $page->term);
		}

		if (count($entries) >= $page->limit) {
			return SearchResult::paginated($this->getName(), $entries, ($page->offset + $page->limit));
		}

		return $this->resultOf(entries: $entries);
	}//end pageResult()

	/**
	 * A complete result under this provider's name.
	 *
	 * @param array<int, mixed> $entries The entries.
	 *
	 * @return SearchResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchResult::complete is the standard Nextcloud search pattern.
	 */
	private function resultOf(array $entries): SearchResult {
		return SearchResult::complete($this->getName(), $entries);
	}//end resultOf()
}//end class
