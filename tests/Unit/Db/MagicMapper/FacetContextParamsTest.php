<?php

/**
 * Facets and search exclude the same context parameters from property filters.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit and reflection helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Support\FilterParams;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Context parameters on the facet path.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Service\Object\SearchQueryHandler
 * @uses   \OCA\OpenRegister\Support\FilterParams
 */
class FacetContextParamsTest extends TestCase {

	/**
	 * A list request's `registers`, `schemas` and `extend` reach the facet base query as top-level keys.
	 *
	 * `register` and `schema` move into `@self`, but any caller of the facet entry point may
	 * still pass them at the top level: MagicMapper::getSimpleFacets() reads `$query['register']`.
	 *
	 * @return void
	 */
	public function testSearchQueryCarriesContextParamsIntoTheFacetBaseQuery(): void {
		$handler = new SearchQueryHandler(
			$this->createMock(ViewScopeApplier::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SearchTrailService::class)
		);

		$query = $handler->buildSearchQuery(
			['extend' => 'owner', 'registers' => ['1'], 'schemas' => ['2'], '_facets' => ['title' => ['type' => 'terms']]]
		);

		$withoutFacets = new ReflectionMethod(MagicFacetHandler::class, 'withoutFacets');
		$facets        = new MagicFacetHandler(db: $this->createMock(IDBConnection::class), logger: $this->createMock(LoggerInterface::class));
		$baseQuery     = $withoutFacets->invoke($facets, $query);

		foreach (['extend', 'registers', 'schemas'] as $key) {
			$this->assertArrayHasKey($key, $baseQuery, $key . ' reaches the facet base query');
		}
	}//end testSearchQueryCarriesContextParamsIntoTheFacetBaseQuery()

	/**
	 * A key is a facet property filter exactly when it is a search property filter.
	 *
	 * @return void
	 */
	public function testFacetPropertyFiltersMatchSearchPropertyFilters(): void {
		$keys = array_merge(
			FilterParams::OBJECT_CONTEXT_PARAMS,
			['@self', '_limit', '_search', '_schemas', 'title', 'status', 'firstName', 'registered', 'extended']
		);

		$facetTerms = new ReflectionMethod(MagicFacetHandler::class, 'objectFieldFilterTerms');
		$facets     = new MagicFacetHandler(db: $this->createMock(IDBConnection::class), logger: $this->createMock(LoggerInterface::class));
		$terms      = $facetTerms->invoke($facets, array_fill_keys($keys, 'x'));

		$isSearchFilter = new ReflectionMethod(MagicSearchHandler::class, 'isObjectFieldFilterKey');
		$search         = (new ReflectionClass(MagicSearchHandler::class))->newInstanceWithoutConstructor();

		foreach ($keys as $key) {
			$this->assertSame(
				$isSearchFilter->invoke($search, $key),
				array_key_exists($key, $terms),
				$key . ': facet and search disagree on whether it is a property filter'
			);
		}

		$this->assertSame(['title', 'status', 'firstName', 'registered', 'extended'], array_keys($terms));
	}//end testFacetPropertyFiltersMatchSearchPropertyFilters()
}//end class
