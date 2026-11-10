<?php

/**
 * Characterisation tests for the query ViewObjectSourceProvider composes from a
 * view and a caller query: which caller keys pass (never `_rbac` /
 * `_multitenancy`), the facet intersection and `@self` facets, the search
 * terms, paging, and the views that cannot be read.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\ObjectSource\ViewObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ViewSourceMappers;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins the composed view query before combine() and resolve() are split.
 */
class ViewObjectSourceProviderQueryGoldenTest extends TestCase {

	/**
	 * The query the row reader last received.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $received = null;

	/**
	 * A provider over a view with the given query.
	 *
	 * @param array<string, mixed>|null $viewQuery The view's query; null makes the view unreadable.
	 *
	 * @return ViewObjectSourceProvider
	 */
	private function provider(?array $viewQuery): ViewObjectSourceProvider {
		$views = $this->createMock(ViewMapperInterface::class);
		if ($viewQuery === null) {
			$views->method('find')->willThrowException(new RuntimeException('no view'));
		} else {
			$view = new View();
			$view->setQuery($viewQuery);
			$views->method('find')->willReturn($view);
		}

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		$this->received = null;
		$reader         = $this->createMock(MagicMapperInterface::class);
		$reader->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			function (RegisterSchema $table, array $query): array {
				$this->received = $query;
				return [];
			}
		);
		$reader->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query): int {
				$this->received = $query;
				return 7;
			}
		);

		return new ViewObjectSourceProvider(
			new ViewSourceMappers(viewMapper: $views, catalog: new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $reader)),
			new NullLogger()
		);
	}//end provider()

	/**
	 * The query composed for a caller query over a view.
	 *
	 * @param array<string, mixed>|null $viewQuery The view's query.
	 * @param array<string, mixed>      $query     The caller query.
	 *
	 * @return array<string, mixed>|null The composed query, or null when nothing was searched.
	 */
	private function composed(?array $viewQuery, array $query): ?array {
		$this->provider(viewQuery: $viewQuery)->findAll(register: new Register(), schema: new Schema(), query: $query, config: ['view' => 'v']);
		return $this->received;
	}//end composed()

	/**
	 * A view over one register and schema.
	 *
	 * @param array<string, mixed> $extra More view query keys.
	 *
	 * @return array<string, mixed>
	 */
	private function view(array $extra = []): array {
		return array_merge(['registers' => [3], 'schemas' => [5]], $extra);
	}//end view()

	public function testCallerKeysPassExceptReservedAndMetaKeys(): void {
		$this->assertSame(
			[
				'_rbac' => true,
				'_multitenancy' => true,
				'status' => 'open',
				'7' => 'numeric',
				'_ids' => ['a'],
				'_limit' => 5,
				'_offset' => 10,
				'_order' => ['name' => 'asc'],
			],
			$this->composed(
				viewQuery: $this->view(),
				query: [
					'_rbac' => false,
					'_multitenancy' => false,
					'@self' => ['owner' => 'x'],
					'' => 'empty',
					'limit' => '5',
					'offset' => 10,
					'page' => 2,
					'sort' => ['name' => 'asc'],
					'filters' => ['a' => 1],
					'extend' => 'x',
					'fields' => 'y',
					'register' => 1,
					'schema' => 2,
					'status' => 'open',
					7 => 'numeric',
					'_ids' => ['a'],
				]
			)
		);
	}//end testCallerKeysPassExceptReservedAndMetaKeys()

	public function testFacetsIntersectAndSelfFacetsNest(): void {
		$this->assertSame(
			[
				'_rbac' => true,
				'_multitenancy' => true,
				'status' => ['open'],
				'@self' => ['owner' => ['bob']],
				'kind' => ['a', 'b'],
				'_search' => 'north east',
			],
			$this->composed(
				viewQuery: $this->view(
					extra: [
						'facetFilters' => ['status' => ['open', 'closed'], 'empty' => [], '@self.owner' => 'bob', 'kind' => ['a', 'b']],
						'searchTerms' => ['east'],
					]
				),
				query: ['status' => ['open', 'draft'], '_search' => 'north']
			)
		);

		$this->assertSame(
			['_rbac' => true, '_multitenancy' => true, 'n' => ['1'], '_search' => 'plain'],
			$this->composed(viewQuery: $this->view(extra: ['facetFilters' => ['n' => [1, 2]], 'searchTerms' => 'plain']), query: ['n' => 1])
		);
	}//end testFacetsIntersectAndSelfFacetsNest()

	public function testDisjointFacetFindsNothing(): void {
		$this->assertNull($this->composed(viewQuery: $this->view(extra: ['facetFilters' => ['status' => ['closed']]]), query: ['status' => 'open']));
		$this->assertSame(
			0,
			$this->provider(viewQuery: $this->view(extra: ['facetFilters' => ['status' => ['closed']]]))->count(
				register: new Register(),
				schema: new Schema(),
				query: ['status' => 'open'],
				config: ['view' => 'v']
			)
		);
	}//end testDisjointFacetFindsNothing()

	public function testUnreadableViewsFindNothing(): void {
		$this->assertNull($this->composed(viewQuery: null, query: []));
		$this->assertNull($this->composed(viewQuery: ['registers' => [1, 2], 'schemas' => [5]], query: []));
		$this->assertNull($this->composed(viewQuery: ['registers' => [1], 'schemas' => []], query: []));
	}//end testUnreadableViewsFindNothing()

	public function testCountUsesTheComposedQuery(): void {
		$count = $this->provider(viewQuery: $this->view())->count(
			register: new Register(),
			schema: new Schema(),
			query: ['_multitenancy' => false, 'a' => 1],
			config: ['view' => 'v']
		);
		$this->assertSame([7, ['_rbac' => true, '_multitenancy' => true, 'a' => 1]], [$count, $this->received]);
	}//end testCountUsesTheComposedQuery()
}//end class
