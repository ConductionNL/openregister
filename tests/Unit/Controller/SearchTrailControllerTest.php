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
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
     * Search trail service instance
     *
     * @var SearchTrailService
     */
    private SearchTrailService $searchTrailService;

    /**
     * Mock search trail mapper
     *
     * @var MockObject|SearchTrailMapper
     */
    private MockObject $searchTrailMapper;

    /**
     * Mock register mapper
     *
     * @var MockObject|RegisterMapper
     */
    private MockObject $registerMapper;

    /**
     * Mock schema mapper
     *
     * @var MockObject|SchemaMapper
     */
    private MockObject $schemaMapper;

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

        // Set up $_SERVER for tests
        $_SERVER['REQUEST_URI'] = '/test/uri';

        // Create mock objects for all dependencies
        $this->request = $this->createMock(IRequest::class);
        $this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        
        // Create the search trail service with mocked dependencies
        $this->searchTrailService = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper
        );

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
        $searchTrail1 = $this->createMock(\OCA\OpenRegister\Db\SearchTrail::class);
        $searchTrail2 = $this->createMock(\OCA\OpenRegister\Db\SearchTrail::class);
        $searchTrails = [$searchTrail1, $searchTrail2];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn([]);

        $this->searchTrailMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($searchTrails);

        $this->searchTrailMapper->expects($this->once())
            ->method('count')
            ->willReturn(count($searchTrails));

        $registerMock = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schemaMock = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        
        $this->registerMapper->expects($this->any())
            ->method('find')
            ->willReturn($registerMock);

        $this->schemaMapper->expects($this->any())
            ->method('find')
            ->willReturn($schemaMock);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals($searchTrails, $data['results']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('limit', $data);
        $this->assertArrayHasKey('offset', $data);
        $this->assertArrayHasKey('page', $data);
    }

    /**
     * Test index method with filters
     *
     * @return void
     */
    public function testIndexWithFilters(): void
    {
        $filters = ['user_id' => 'user1', 'date_from' => '2024-01-01'];
        $searchTrail1 = $this->createMock(\OCA\OpenRegister\Db\SearchTrail::class);
        $searchTrails = [$searchTrail1];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($filters);

        $this->searchTrailMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($searchTrails);

        $this->searchTrailMapper->expects($this->once())
            ->method('count')
            ->willReturn(count($searchTrails));

        $registerMock = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $schemaMock = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        
        $this->registerMapper->expects($this->any())
            ->method('find')
            ->willReturn($registerMock);

        $this->schemaMapper->expects($this->any())
            ->method('find')
            ->willReturn($schemaMock);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals($searchTrails, $data['results']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('limit', $data);
        $this->assertArrayHasKey('offset', $data);
        $this->assertArrayHasKey('page', $data);
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

        $this->searchTrailMapper->expects($this->once())
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

        $this->searchTrailMapper->expects($this->once())
            ->method('find')
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
            'non_empty_searches' => 80,
            'total_results' => 500,
            'popular_queries' => [
                ['query' => 'test', 'count' => 10],
                ['query' => 'search', 'count' => 8]
            ],
            'searches_by_day' => [
                '2024-01-01' => 15,
                '2024-01-02' => 20
            ]
        ];

        $this->searchTrailMapper->expects($this->once())
            ->method('getSearchStatistics')
            ->willReturn($statistics);

        $this->searchTrailMapper->expects($this->once())
            ->method('getUniqueSearchTermsCount')
            ->willReturn(10);

        $response = $this->controller->statistics();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('total_searches', $data);
        $this->assertEquals(100, $data['total_searches']);
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
        $fromDate = new \DateTime($from);
        $toDate = new \DateTime($to);
        $statistics = [
            'total_searches' => 50,
            'unique_users' => 15,
            'non_empty_searches' => 40,
            'total_results' => 200,
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

        $this->searchTrailMapper->expects($this->once())
            ->method('getSearchStatistics')
            ->with($fromDate, $toDate)
            ->willReturn($statistics);

        $this->searchTrailMapper->expects($this->once())
            ->method('getUniqueSearchTermsCount')
            ->with($fromDate, $toDate)
            ->willReturn(10);

        $response = $this->controller->statistics();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('total_searches', $data);
        $this->assertEquals(50, $data['total_searches']);
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
            ['query' => 'test', 'count' => 25, 'avg_results' => 5],
            ['query' => 'search', 'count' => 20, 'avg_results' => 3],
            ['query' => 'data', 'count' => 15, 'avg_results' => 2]
        ];

        $this->request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['_limit', 10, $limit],
                ['limit', 10, $limit]
            ]);

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn([]);

        $this->searchTrailMapper->expects($this->once())
            ->method('getPopularSearchTerms')
            ->with($limit, null, null)
            ->willReturn($popularQueries);

        $response = $this->controller->popularTerms();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(3, $data['results']);
        $this->assertEquals('test', $data['results'][0]['query']);
        $this->assertEquals(25, $data['results'][0]['count']);
        $this->assertEquals(5, $data['results'][0]['avg_results']);
    }

    /**
     * Test cleanup method with successful cleanup
     *
     * @return void
     */
    public function testCleanupSuccessful(): void
    {
        $before = '2024-01-01';
        $deletedCount = 50;

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('before', null)
            ->willReturn($before);

        // Create a mock service for this test
        $mockService = $this->createMock(SearchTrailService::class);
        $mockService->expects($this->once())
            ->method('cleanupSearchTrails')
            ->willReturn(['deleted' => $deletedCount]);

        // Create a new controller with the mock service
        $controller = new SearchTrailController(
            'openregister',
            $this->request,
            $mockService
        );

        $response = $controller->cleanup();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('deleted', $data);
        $this->assertEquals($deletedCount, $data['deleted']);
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
            ->with('before', null)
            ->willReturn('2024-01-01');

        // Create a mock service for this test
        $mockService = $this->createMock(SearchTrailService::class);
        $mockService->expects($this->once())
            ->method('cleanupSearchTrails')
            ->willThrowException(new \Exception('Cleanup failed'));

        // Create a new controller with the mock service
        $controller = new SearchTrailController(
            'openregister',
            $this->request,
            $mockService
        );

        $response = $controller->cleanup();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
        $this->assertEquals(['error' => 'Cleanup failed: Cleanup failed'], $response->getData());
    }
}
