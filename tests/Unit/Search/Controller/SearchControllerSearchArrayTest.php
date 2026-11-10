<?php

/**
 * SearchController merges _search[] terms with the main query.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
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

use OCA\OpenRegister\Search\Controller\SearchController;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The `_search[]` array branch of the query processing, which the existing
 * tests (all with an empty `_search`) never enter.
 */
class SearchControllerSearchArrayTest extends TestCase {

	private function searchedFor(string $query, mixed $searchArray): string {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap(
			[
				['query', '', $query],
				['_search', [], $searchArray],
				['offset', 0, 0],
				['limit', 25, 25],
			]
		);
		$captured = null;
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use (&$captured): array {
				$captured = $query['_search'];
				return ['results' => [], 'total' => 0];
			}
		);

		(new SearchController($request, $objects))->search();

		return $captured;
	}

	public function testArrayTermsAreAddedToTheQueryWithoutSplittingOnCommas(): void {
		$this->assertSame('*zaak, boom* OR *bos* OR %huis%', $this->searchedFor('Zaak, Boom', ['Bos', '%huis%']));
	}

	public function testANonArraySearchParamFallsBackToCommaSplitting(): void {
		$this->assertSame('*zaak* OR *boom*', $this->searchedFor('Zaak, Boom', 'ignored'));
	}
}
