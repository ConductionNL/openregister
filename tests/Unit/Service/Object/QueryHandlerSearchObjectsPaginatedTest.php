<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\ObjectSelection;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What QueryHandler::searchObjectsPaginated() hands the database search and how it annotates the answer.
 *
 * @covers \OCA\OpenRegister\Service\Object\QueryHandler
 */
class QueryHandlerSearchObjectsPaginatedTest extends TestCase {

	/**
	 * The arguments the database search was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	public function testWithoutViewsTheQueryIsSearchedAsGivenWithoutSource(): void {
		$result = $this->handler(views: null)->searchObjectsPaginated(['_limit' => 5, '_source' => 'index'], false, true);

		$this->assertSame([['query' => ['_limit' => 5], 'rbac' => false, 'multitenancy' => true, 'ids' => null, 'uses' => null]], $this->calls);
		$this->assertSame(
			['source' => 'magic_mapper', 'query' => ['_limit' => 5], 'rbac' => false, 'multi' => true, 'deleted' => false],
			$result['@self']
		);
	}//end testWithoutViewsTheQueryIsSearchedAsGivenWithoutSource()

	public function testViewsAreAppliedAndTheSelectionIsForwarded(): void {
		$result = $this->handler(views: ['v-1'], source: null)->searchObjectsPaginated(
			['_search' => 'x'],
			true,
			false,
			new SearchOptions(selection: new ObjectSelection(ids: ['id-1'], uses: 'uses-1'), views: ['v-1'])
		);

		$query = ['_search' => 'x', 'viewed' => ['v-1']];
		$this->assertSame([['query' => $query, 'rbac' => true, 'multitenancy' => false, 'ids' => ['id-1'], 'uses' => 'uses-1']], $this->calls);
		$this->assertSame(
			['source' => 'database', 'query' => $query, 'rbac' => true, 'multi' => false, 'deleted' => false],
			$result['@self']
		);
	}//end testViewsAreAppliedAndTheSelectionIsForwarded()

	/**
	 * A handler whose database search records its arguments.
	 *
	 * @param array<int, string>|null $views  The views the view double expects, or null for none.
	 * @param string|null             $source The source the database search reports.
	 *
	 * @return QueryHandler
	 */
	private function handler(?array $views, ?string $source = 'magic_mapper'): QueryHandler {
		$searchQueries = $this->createMock(SearchQueryHandler::class);
		$expected = $this->once();
		if ($views === null) {
			$expected = $this->never();
		}

		$searchQueries->expects($expected)
			->method('applyViewsToQuery')
			->willReturnCallback(fn (array $query, array $viewIds): array => $query + ['viewed' => $viewIds]);

		$args = [];
		foreach ((new ReflectionClass(QueryHandler::class))->getConstructor()->getParameters() as $param) {
			$type   = $param->getType()->getName();
			$args[] = match (true) {
				$type === SearchQueryHandler::class => $searchQueries,
				$param->isOptional() === true => $param->getDefaultValue(),
				default => $this->createStub($type),
			};
		}

		$handler = $this->getMockBuilder(QueryHandler::class)
			->setConstructorArgs($args)
			->onlyMethods(['searchObjectsPaginatedDatabase'])
			->getMock();
		$handler->method('searchObjectsPaginatedDatabase')->willReturnCallback(
			function (array $query, bool $_rbac, bool $_multitenancy, bool $deleted, ?array $ids, ?string $uses) use ($source): array {
				$this->calls[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'ids' => $ids, 'uses' => $uses];
				$self = [];
				if ($source !== null) {
					$self['source'] = $source;
				}

				return ['results' => [], '@self' => $self];
			}
		);

		return $handler;
	}//end handler()
}//end class
