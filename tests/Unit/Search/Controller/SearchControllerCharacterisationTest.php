<?php

/**
 * Characterisation tests for SearchController::search().
 *
 * Pins the paging arithmetic (offset/limit to _page/_limit), the wildcard
 * wrapping of each term, the empty-query passthrough, and the result shape
 * for entity rows, array rows (uuid/id and name/@self.name fallbacks) and
 * rows of any other type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Controller\SearchController;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class SearchControllerCharacterisationTest extends TestCase {
	private ?array $captured = null;

	private function search(array $params, array $result): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default
		);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($result): array {
				$this->captured = $query;
				return $result;
			}
		);

		$response = (new SearchController($request, $objects))->search();
		$this->assertSame(200, $response->getStatus());

		return $response->getData();
	}

	public function testOffsetAndLimitBecomePageAndLimit(): void {
		$this->search(['query' => 'a', 'offset' => '50', 'limit' => '20'], ['results' => [], 'total' => 0]);

		$this->assertSame(['_search' => '*a*', '_page' => 3, '_limit' => 20], $this->captured);
	}

	public function testDefaultsArePageOneOfTwentyFive(): void {
		$this->search(['query' => 'a'], []);

		$this->assertSame(['_search' => '*a*', '_page' => 1, '_limit' => 25], $this->captured);
	}

	public function testAZeroLimitPagesAsIfItWereOne(): void {
		$this->search(['query' => 'a', 'offset' => 4, 'limit' => 0], []);

		$this->assertSame(['_search' => '*a*', '_page' => 5, '_limit' => 0], $this->captured);
	}

	public function testWildcardsAlreadyPresentAreKept(): void {
		$this->search(['query' => 'Ab*, %cd, *EF%,  ,g'], []);

		$this->assertSame('*ab* OR %cd* OR *ef% OR *g*', $this->captured['_search']);
	}

	public function testAQueryWithoutTermsIsPassedThrough(): void {
		$this->search(['query' => ' , '], []);

		$this->assertSame(' , ', $this->captured['_search']);
	}

	public function testAnEmptySearchArrayFallsBackToTheQuery(): void {
		$this->search(['query' => 'x', '_search' => []], []);

		$this->assertSame('*x*', $this->captured['_search']);
	}

	public function testTheResultShapeForEveryKindOfRow(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$entity->setName('Zaak');

		$data = $this->search(
			['query' => 'a'],
			[
				'results' => [
					$entity,
					['uuid' => 'u-2', 'id' => 9, 'name' => 'Boom', 'url' => 'https://x/2'],
					['id' => 10, '@self' => ['name' => 'Bos']],
					[],
					'not a row',
				],
				'total' => 5,
			]
		);

		$this->assertSame(
			[
				'results' => [
					['id' => 'u-1', 'name' => 'Zaak', 'type' => 'object', 'url' => null, 'source' => 'openregister'],
					['id' => 'u-2', 'name' => 'Boom', 'type' => 'object', 'url' => 'https://x/2', 'source' => 'openregister'],
					['id' => 10, 'name' => 'Bos', 'type' => 'object', 'url' => null, 'source' => 'openregister'],
					['id' => null, 'name' => 'Unknown', 'type' => 'object', 'url' => null, 'source' => 'openregister'],
					['id' => null, 'name' => 'Unknown', 'type' => 'object', 'url' => null, 'source' => 'openregister'],
				],
				'total' => 5,
				'facets' => [],
			],
			$data
		);
	}

	public function testAResultWithoutTotalOrResultsIsEmpty(): void {
		$this->assertSame(['results' => [], 'total' => 0, 'facets' => []], $this->search(['query' => 'a'], []));
	}
}
