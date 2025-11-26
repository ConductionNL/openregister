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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISearch;
use OCP\Search\Result;
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
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return a single search term.
        $request->expects($this->once())
            ->method('getParam')
            ->with('query', '')
            ->willReturn('test');

        // Set up search service mock to return empty results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('*test*')
            ->willReturn([]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());

    }//end testSearchWithSingleTerm()


    /**
     * Test search with comma-separated multiple terms
     *
     * @return void
     */
    public function testSearchWithCommaSeparatedTerms(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return comma-separated search terms.
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['query', '', 'customer,service,important'],
                ['_search', [], []]
            ]);

        // Set up search service mock to return empty results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('*customer* OR *service* OR *important*')
            ->willReturn([]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());

    }//end testSearchWithCommaSeparatedTerms()


    /**
     * Test search with array parameter
     *
     * @return void
     */
    public function testSearchWithArrayParameter(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return array search terms.
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['_search', [], ['customer', 'service', 'important']]
            ]);

        // Set up search service mock to return empty results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('*customer* OR *service* OR *important*')
            ->willReturn([]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());

    }//end testSearchWithArrayParameter()


    /**
     * Test search with case-insensitive terms
     *
     * @return void
     */
    public function testSearchWithCaseInsensitiveTerms(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return mixed case search terms.
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['query', '', 'Test,USER,Admin'],
                ['_search', [], []]
            ]);

        // Set up search service mock to return empty results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('*test* OR *user* OR *admin*')
            ->willReturn([]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());

    }//end testSearchWithCaseInsensitiveTerms()


    /**
     * Test search with empty terms
     *
     * @return void
     */
    public function testSearchWithEmptyTerms(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return empty search terms.
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['_search', [], []]
            ]);

        // Set up search service mock to return empty results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('')
            ->willReturn([]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());

    }//end testSearchWithEmptyTerms()


    /**
     * Test search with partial matches
     *
     * @return void
     */
    public function testSearchWithPartialMatches(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return partial search terms.
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['query', '', 'tes,use,adm'],
                ['_search', [], []]
            ]);

        // Set up search service mock to return empty results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('*tes* OR *use* OR *adm*')
            ->willReturn([]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());

    }//end testSearchWithPartialMatches()


    /**
     * Test search with existing wildcards
     *
     * @return void
     */
    public function testSearchWithExistingWildcards(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return search terms with existing wildcards.
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['query', '', '*test*,user*,*admin'],
                ['_search', [], []]
            ]);

        // Set up search service mock to return empty results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('*test* OR *user* OR *admin*')
            ->willReturn([]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());

    }//end testSearchWithExistingWildcards()


    /**
     * Test search with actual results
     *
     * @return void
     */
    public function testSearchWithResults(): void
    {
        // Create mock objects.
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Set up request mock to return a search term.
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['query', '', 'customer'],
                ['_search', [], []]
            ]);

        // Create mock search results.
        $mockResult1 = $this->createMock(Result::class);
        $mockResult1->method('getId')->willReturn('1');
        $mockResult1->method('getName')->willReturn('Customer Service');
        $mockResult1->method('getType')->willReturn('object');
        $mockResult1->method('getUrl')->willReturn('/objects/1');
        $mockResult1->method('getSource')->willReturn('openregister');

        $mockResult2 = $this->createMock(Result::class);
        $mockResult2->method('getId')->willReturn('2');
        $mockResult2->method('getName')->willReturn('Customer Support');
        $mockResult2->method('getType')->willReturn('object');
        $mockResult2->method('getUrl')->willReturn('/objects/2');
        $mockResult2->method('getSource')->willReturn('openregister');

        // Set up search service mock to return results.
        $searchService->expects($this->once())
            ->method('search')
            ->with('*customer*')
            ->willReturn([$mockResult1, $mockResult2]);

        // Create controller instance.
        $controller = new SearchController('openregister', $request, $searchService);

        // Execute search.
        $response = $controller->search();

        // Verify response.
        $this->assertInstanceOf(JSONResponse::class, $response);
        $expectedData = [
            [
                'id' => '1',
                'name' => 'Customer Service',
                'type' => 'object',
                'url' => '/objects/1',
                'source' => 'openregister',
            ],
            [
                'id' => '2',
                'name' => 'Customer Support',
                'type' => 'object',
                'url' => '/objects/2',
                'source' => 'openregister',
            ],
        ];
        $this->assertEquals($expectedData, $response->getData());

    }//end testSearchWithResults()


}//end class 