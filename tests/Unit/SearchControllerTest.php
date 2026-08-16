<?php

/**
 * SearchController Test
 *
 * Test class for the SearchController to verify search functionality.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit;

use OCA\OpenRegister\Controller\SearchController;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SearchController
 *
 * @package OCA\OpenRegister\Tests\Unit
 */
class SearchControllerTest extends TestCase {

	/**
	 * Test search with single search term
	 *
	 * @return void
	 */
	public function testSearchWithSingleTerm(): void {
		// Create mock objects.
		$request = $this->createMock(IRequest::class);
		$objectService = $this->createMock(ObjectService::class);

		// Set up request mock to return parameters.
		$request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		// Set up object service mock to return empty results.
		$objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->willReturn([
				'results' => [],
				'total' => 0,
			]);

		// Create controller instance.
		$controller = new SearchController('openregister', $request, $objectService);

		// Execute search.
		$response = $controller->search();

		// Verify response.
		$this->assertInstanceOf(JSONResponse::class, $response);

	}//end testSearchWithSingleTerm()

	/**
	 * Test search with empty terms
	 *
	 * @return void
	 */
	public function testSearchWithEmptyTerms(): void {
		// Create mock objects.
		$request = $this->createMock(IRequest::class);
		$objectService = $this->createMock(ObjectService::class);

		// Set up request mock to return empty search terms.
		$request->method('getParam')
			->willReturnMap([
				['query', '', ''],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		// Set up object service mock to return empty results.
		$objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->willReturn([
				'results' => [],
				'total' => 0,
			]);

		// Create controller instance.
		$controller = new SearchController('openregister', $request, $objectService);

		// Execute search.
		$response = $controller->search();

		// Verify response.
		$this->assertInstanceOf(JSONResponse::class, $response);

	}//end testSearchWithEmptyTerms()

	/**
	 * Test search with actual results
	 *
	 * @return void
	 */
	public function testSearchWithResults(): void {
		// Create mock objects.
		$request = $this->createMock(IRequest::class);
		$objectService = $this->createMock(ObjectService::class);

		// Set up request mock to return a search term.
		$request->method('getParam')
			->willReturnMap([
				['query', '', 'customer'],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		// Create mock search results.
		$mockResults = [
			'results' => [
				['id' => '1', 'name' => 'Customer Service'],
				['id' => '2', 'name' => 'Customer Support'],
			],
			'total' => 2,
		];

		// Set up object service mock to return results.
		$objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->willReturn($mockResults);

		// Create controller instance.
		$controller = new SearchController('openregister', $request, $objectService);

		// Execute search.
		$response = $controller->search();

		// Verify response.
		$this->assertInstanceOf(JSONResponse::class, $response);

	}//end testSearchWithResults()

}//end class
