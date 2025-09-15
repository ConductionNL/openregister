<?php

declare(strict_types=1);

/**
 * SearchTrailControllerTest
 * 
 * Unit tests for the SearchTrailController
 *
 * @category   Test
 * @package    OCA\OpenRegister\Tests\Unit\Controller
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SearchTrailController;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the SearchTrailController
 *
 * This test class covers all functionality of the SearchTrailController
 * including search trail management and analytics.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class SearchTrailControllerTest extends TestCase
{
    /**
     * The SearchTrailController instance being tested
     *
     * @var SearchTrailController
     */
    private SearchTrailController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock search trail service
     *
     * @var MockObject|SearchTrailService
     */
    private MockObject $searchTrailService;

    /**
     * Set up test environment before each test
     *
     * This method initializes all mocks and the controller instance
     * for testing purposes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for all dependencies
        $this->request = $this->createMock(IRequest::class);
        $this->searchTrailService = $this->createMock(SearchTrailService::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new SearchTrailController(
            'openregister',
            $this->request,
            $this->searchTrailService
        );
    }

    /**
     * Test index method with successful search trail listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $searchTrails = [
            ['id' => 1, 'query' => 'test search', 'user_id' => 'user1'],
            ['id' => 2, 'query' => 'another search', 'user_id' => 'user2']
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn([]);

        $this->searchTrailService->expects($this->once())
            ->method('getSearchTrails')
            ->willReturn([
                'results' => $searchTrails,
                'total' => count($searchTrails),
                'limit' => 20,
                'offset' => 0,
                'page' => 1
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals($searchTrails, $data['results']);
    }

    /**
     * Test index method with filters
     *
     * @return void
     */
    public function testIndexWithFilters(): void
    {
        $filters = ['user_id' => 'user1', 'date_from' => '2024-01-01'];
        $searchTrails = [
            ['id' => 1, 'query' => 'test search', 'user_id' => 'user1']
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($filters);

        $this->searchTrailService->expects($this->once())
            ->method('getSearchTrails')
            ->with($filters)
            ->willReturn([
                'results' => $searchTrails,
                'total' => count($searchTrails),
                'limit' => 20,
                'offset' => 0,
                'page' => 1
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals($searchTrails, $data['results']);
    }

    /**
     * Test show method with successful search trail retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $id = 1;
        $searchTrail = $this->createMock(\OCA\OpenRegister\Db\SearchTrail::class);

        $this->searchTrailService->expects($this->once())
            ->method('getSearchTrail')
            ->with($id)
            ->willReturn($searchTrail);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($searchTrail, $response->getData());
    }

    /**
     * Test show method with search trail not found
     *
     * @return void
     */
    public function testShowSearchTrailNotFound(): void
    {
        $id = 999;

        $this->searchTrailService->expects($this->once())
            ->method('getSearchTrail')
            ->with($id)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Search trail not found'));

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Search trail not found'], $response->getData());
    }





    /**
     * Test getStatistics method with successful statistics retrieval
     *
     * @return void
     */
    public function testGetStatisticsSuccessful(): void
    {
        $statistics = [
            'total_searches' => 100,
            'unique_users' => 25,
            'popular_queries' => [
                ['query' => 'test', 'count' => 10],
                ['query' => 'search', 'count' => 8]
            ],
            'searches_by_day' => [
                '2024-01-01' => 15,
                '2024-01-02' => 20
            ]
        ];

        $this->searchTrailService->expects($this->once())
            ->method('getSearchStatistics')
            ->willReturn($statistics);

        $response = $this->controller->statistics();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($statistics, $response->getData());
    }

    /**
     * Test getStatistics method with date range
     *
     * @return void
     */
    public function testGetStatisticsWithDateRange(): void
    {
        $from = '2024-01-01';
        $to = '2024-01-31';
        $statistics = [
            'total_searches' => 50,
            'unique_users' => 15,
            'popular_queries' => [
                ['query' => 'test', 'count' => 5]
            ]
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn([
                'from' => $from,
                'to' => $to
            ]);

        $this->searchTrailService->expects($this->once())
            ->method('getSearchStatistics')
            ->with($from, $to)
            ->willReturn($statistics);

        $response = $this->controller->statistics();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($statistics, $response->getData());
    }

    /**
     * Test getPopularQueries method with successful popular queries retrieval
     *
     * @return void
     */
    public function testGetPopularQueriesSuccessful(): void
    {
        $limit = 10;
        $popularQueries = [
            ['query' => 'test', 'count' => 25],
            ['query' => 'search', 'count' => 20],
            ['query' => 'data', 'count' => 15]
        ];

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('limit', 10)
            ->willReturn($limit);

        $this->searchTrailService->expects($this->once())
            ->method('getPopularSearchTerms')
            ->with($limit)
            ->willReturn($popularQueries);

        $response = $this->controller->popularTerms();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($popularQueries, $response->getData());
    }

    /**
     * Test cleanup method with successful cleanup
     *
     * @return void
     */
    public function testCleanupSuccessful(): void
    {
        $days = 30;
        $deletedCount = 50;

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('days', 30)
            ->willReturn($days);

        $this->searchTrailService->expects($this->once())
            ->method('clearExpiredSearchTrails')
            ->with($days)
            ->willReturn(['deleted' => $deletedCount]);

        $response = $this->controller->cleanup();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['deleted' => $deletedCount], $response->getData());
    }

    /**
     * Test cleanup method with exception
     *
     * @return void
     */
    public function testCleanupWithException(): void
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('days', 30)
            ->willReturn(30);

        $this->searchTrailService->expects($this->once())
            ->method('clearExpiredSearchTrails')
            ->willThrowException(new \Exception('Cleanup failed'));

        $response = $this->controller->cleanup();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Cleanup failed'], $response->getData());
    }
}
