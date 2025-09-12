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

        $this->searchTrailService->expects($this->once())
            ->method('findAll')
            ->willReturn($searchTrails);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($searchTrails, $response->getData());
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
            ->method('findAll')
            ->with($filters)
            ->willReturn($searchTrails);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($searchTrails, $response->getData());
    }

    /**
     * Test show method with successful search trail retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $id = 1;
        $searchTrail = ['id' => 1, 'query' => 'test search', 'user_id' => 'user1'];

        $this->searchTrailService->expects($this->once())
            ->method('find')
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
            ->method('find')
            ->with($id)
            ->willThrowException(new \Exception('Search trail not found'));

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Search trail not found'], $response->getData());
    }

    /**
     * Test create method with successful search trail creation
     *
     * @return void
     */
    public function testCreateSuccessful(): void
    {
        $data = ['query' => 'new search', 'user_id' => 'user1'];
        $createdSearchTrail = ['id' => 1, 'query' => 'new search', 'user_id' => 'user1'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->searchTrailService->expects($this->once())
            ->method('create')
            ->with($data)
            ->willReturn($createdSearchTrail);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($createdSearchTrail, $response->getData());
    }

    /**
     * Test create method with validation error
     *
     * @return void
     */
    public function testCreateWithValidationError(): void
    {
        $data = ['query' => ''];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->searchTrailService->expects($this->once())
            ->method('create')
            ->willThrowException(new \InvalidArgumentException('Query is required'));

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(['error' => 'Query is required'], $response->getData());
    }

    /**
     * Test update method with successful search trail update
     *
     * @return void
     */
    public function testUpdateSuccessful(): void
    {
        $id = 1;
        $data = ['query' => 'updated search'];
        $updatedSearchTrail = ['id' => 1, 'query' => 'updated search'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->searchTrailService->expects($this->once())
            ->method('update')
            ->with($id, $data)
            ->willReturn($updatedSearchTrail);

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($updatedSearchTrail, $response->getData());
    }

    /**
     * Test update method with search trail not found
     *
     * @return void
     */
    public function testUpdateSearchTrailNotFound(): void
    {
        $id = 999;
        $data = ['query' => 'updated search'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->searchTrailService->expects($this->once())
            ->method('update')
            ->willThrowException(new \Exception('Search trail not found'));

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Search trail not found'], $response->getData());
    }

    /**
     * Test destroy method with successful search trail deletion
     *
     * @return void
     */
    public function testDestroySuccessful(): void
    {
        $id = 1;

        $this->searchTrailService->expects($this->once())
            ->method('delete')
            ->with($id)
            ->willReturn(true);

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['success' => true], $response->getData());
    }

    /**
     * Test destroy method with search trail not found
     *
     * @return void
     */
    public function testDestroySearchTrailNotFound(): void
    {
        $id = 999;

        $this->searchTrailService->expects($this->once())
            ->method('delete')
            ->willThrowException(new \Exception('Search trail not found'));

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Search trail not found'], $response->getData());
    }

    /**
     * Test getByUser method with successful user search trail retrieval
     *
     * @return void
     */
    public function testGetByUserSuccessful(): void
    {
        $userId = 'user1';
        $searchTrails = [
            ['id' => 1, 'query' => 'search 1', 'user_id' => 'user1'],
            ['id' => 2, 'query' => 'search 2', 'user_id' => 'user1']
        ];

        $this->searchTrailService->expects($this->once())
            ->method('findByUser')
            ->with($userId)
            ->willReturn($searchTrails);

        $response = $this->controller->getByUser($userId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($searchTrails, $response->getData());
    }

    /**
     * Test getByQuery method with successful query search trail retrieval
     *
     * @return void
     */
    public function testGetByQuerySuccessful(): void
    {
        $query = 'test search';
        $searchTrails = [
            ['id' => 1, 'query' => 'test search', 'user_id' => 'user1'],
            ['id' => 2, 'query' => 'test search', 'user_id' => 'user2']
        ];

        $this->searchTrailService->expects($this->once())
            ->method('findByQuery')
            ->with($query)
            ->willReturn($searchTrails);

        $response = $this->controller->getByQuery($query);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($searchTrails, $response->getData());
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
            ->method('getStatistics')
            ->willReturn($statistics);

        $response = $this->controller->getStatistics();

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

        $this->request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['from', null, $from],
                ['to', null, $to]
            ]);

        $this->searchTrailService->expects($this->once())
            ->method('getStatistics')
            ->with($from, $to)
            ->willReturn($statistics);

        $response = $this->controller->getStatistics();

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
            ->method('getPopularQueries')
            ->with($limit)
            ->willReturn($popularQueries);

        $response = $this->controller->getPopularQueries();

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
            ->method('cleanup')
            ->with($days)
            ->willReturn($deletedCount);

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
            ->method('cleanup')
            ->willThrowException(new \Exception('Cleanup failed'));

        $response = $this->controller->cleanup();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Cleanup failed'], $response->getData());
    }
}
