<?php
/**
 * SearchController Test
 *
 * Test class for the SearchController to verify search functionality.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit;

use OCA\OpenRegister\Controller\SearchController;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SearchController
 *
 * @package OCA\OpenRegister\Tests\Unit
 */
class SearchControllerTest extends TestCase
{

    /**
     * Test search with single search term
     *
     * @return void
     */
    public function testSearchWithSingleTerm(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $indexService = $this->createMock(IndexService::class);

        // Set up request mock to return parameters.
        $request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test'],
                ['offset', 0, 0],
                ['limit', 25, 25],
            ]);

        // Set up index service mock to return empty results.
        $indexService->expects($this->once())
            ->method('searchObjects')
            ->willReturn([
                'objects' => [],
                'facets' => [],
                'total' => 0
            ]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $indexService);

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
    public function testSearchWithEmptyTerms(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $indexService = $this->createMock(IndexService::class);

        // Set up request mock to return empty search terms.
        $request->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['offset', 0, 0],
                ['limit', 25, 25],
            ]);

        // Set up index service mock to return empty results.
        $indexService->expects($this->once())
            ->method('searchObjects')
            ->willReturn([
                'objects' => [],
                'facets' => [],
                'total' => 0
            ]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $indexService);

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
    public function testSearchWithResults(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $indexService = $this->createMock(IndexService::class);

        // Set up request mock to return a search term.
        $request->method('getParam')
            ->willReturnMap([
                ['query', '', 'customer'],
                ['offset', 0, 0],
                ['limit', 25, 25],
            ]);

        // Create mock search results.
        $mockResults = [
            'objects' => [
                ['id' => '1', 'name' => 'Customer Service'],
                ['id' => '2', 'name' => 'Customer Support'],
            ],
            'facets' => [],
            'total' => 2
        ];

        // Set up index service mock to return results.
        $indexService->expects($this->once())
            ->method('searchObjects')
            ->willReturn($mockResults);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $indexService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);

    }//end testSearchWithResults()


}//end class
