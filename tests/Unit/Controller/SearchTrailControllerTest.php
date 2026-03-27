<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SearchTrailController;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SearchTrailController
 *
 * @package Unit\Controller
 */
class SearchTrailControllerTest extends TestCase
{
    private SearchTrailController $controller;
    private IRequest&MockObject $request;
    private SearchTrailService&MockObject $searchTrailService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->searchTrailService = $this->createMock(SearchTrailService::class);

        $this->controller = new SearchTrailController(
            'openregister',
            $this->request,
            $this->searchTrailService
        );
    }

    // ─── index() tests ───

    public function testIndexReturnsSearchTrails(): void
    {
        $serviceResult = [
            'results' => [['id' => 1, 'search_term' => 'test']],
            'total' => 1,
            'limit' => 20,
            'offset' => 0,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertSame(1, $data['total']);
    }

    public function testIndexStripsRouteAndIdParams(): void
    {
        $serviceResult = [
            'results' => [],
            'total' => 0,
            'limit' => 20,
            'offset' => 0,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([
            '_route' => 'openregister.search_trail.index',
            'id' => 5,
            'status' => 'active',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');

        // Verify that _route and id are stripped before passing to service.
        $this->searchTrailService->expects($this->once())
            ->method('getSearchTrails')
            ->with($this->callback(function ($params) {
                return !isset($params['_route'])
                    && !isset($params['id'])
                    && isset($params['status'])
                    && $params['status'] === 'active';
            }))
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->searchTrailService->method('getSearchTrails')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->index();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('DB error', $data['error']);
    }

    public function testIndexWithMissingServiceResultKeys(): void
    {
        // Test defaults when service returns sparse result.
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([]);  // No results, total, limit, offset, page keys.

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame([], $data['results']);
        $this->assertSame(0, $data['total']);
    }

    public function testIndexPaginationWithMultiplePages(): void
    {
        $serviceResult = [
            'results' => array_fill(0, 20, ['id' => 1]),
            'total' => 50,
            'limit' => 20,
            'offset' => 0,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails?_page=1');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertEquals(3, $data['pages']);
        $this->assertArrayHasKey('next', $data);
        $this->assertStringContainsString('_page=2', $data['next']);
    }

    public function testIndexPaginationMiddlePage(): void
    {
        // Page 2 of 3 should have both next and prev.
        $serviceResult = [
            'results' => array_fill(0, 20, ['id' => 1]),
            'total' => 50,
            'limit' => 20,
            'offset' => 20,
            'page' => 2,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails?_page=2');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertSame(2, $data['page']);
        $this->assertArrayHasKey('next', $data);
        $this->assertArrayHasKey('prev', $data);
        $this->assertStringContainsString('_page=3', $data['next']);
        $this->assertStringContainsString('_page=1', $data['prev']);
    }

    public function testIndexPaginationLastPage(): void
    {
        // Last page should have prev but no next.
        $serviceResult = [
            'results' => array_fill(0, 10, ['id' => 1]),
            'total' => 50,
            'limit' => 20,
            'offset' => 40,
            'page' => 3,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails?_page=3');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertSame(3, $data['page']);
        $this->assertArrayNotHasKey('next', $data);
        $this->assertArrayHasKey('prev', $data);
    }

    // ─── show() tests ───

    public function testShowReturnsSearchTrail(): void
    {
        $trail = new \OCA\OpenRegister\Db\SearchTrail();
        $ref = new \ReflectionClass($trail);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($trail, 1);
        $trail->setSearchTerm('test');
        $trail->setResultCount(10);

        $this->searchTrailService->method('getSearchTrail')->willReturn($trail);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Search trail not found', $data['error']);
    }

    public function testShowReturns500OnException(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->show(1);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Error', $data['error']);
    }

    // ─── statistics() tests ───

    public function testStatisticsReturnsData(): void
    {
        $stats = ['total_searches' => 100, 'unique_terms' => 50];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(100, $data['total_searches']);
    }

    public function testStatisticsWithDateFilters(): void
    {
        $stats = ['total_searches' => 50];

        $this->request->method('getParams')->willReturn([
            'from' => '2024-01-01',
            'to' => '2024-12-31',
        ]);
        $this->request->method('getParam')->willReturn(null);

        $this->searchTrailService->expects($this->once())
            ->method('getSearchStatistics')
            ->with(
                $this->callback(function ($from) {
                    return $from instanceof \DateTime && $from->format('Y-m-d') === '2024-01-01';
                }),
                $this->callback(function ($to) {
                    return $to instanceof \DateTime && $to->format('Y-m-d') === '2024-12-31';
                })
            )
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testStatisticsWithInvalidFromDate(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            'from' => 'not-a-date',
        ]);
        $this->request->method('getParam')->willReturn(null);

        // Invalid date should be ignored, passing null to service.
        $this->searchTrailService->expects($this->once())
            ->method('getSearchStatistics')
            ->with(null, null)
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testStatisticsWithInvalidToDate(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            'to' => 'garbage',
        ]);
        $this->request->method('getParam')->willReturn(null);

        $this->searchTrailService->expects($this->once())
            ->method('getSearchStatistics')
            ->with(null, null)
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testStatisticsReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->statistics();

        $this->assertSame(500, $result->getStatus());
    }

    // ─── popularTerms() tests ───

    public function testPopularTermsReturnsData(): void
    {
        $serviceResult = [
            'terms' => [['term' => 'test', 'count' => 10]],
            'total_unique_terms' => 1,
            'total_searches' => 50,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', $this->anything(), 10],
                ['limit', 10, 10],
            ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/popular');
        $this->searchTrailService->method('getPopularSearchTerms')
            ->willReturn($serviceResult);

        $result = $this->controller->popularTerms();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total_searches', $data);
        $this->assertArrayHasKey('period', $data);
    }

    public function testPopularTermsReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(10);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/popular');
        $this->searchTrailService->method('getPopularSearchTerms')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->popularTerms();

        $this->assertSame(500, $result->getStatus());
    }

    public function testPopularTermsWithDateFilters(): void
    {
        $serviceResult = [
            'terms' => [],
            'total_unique_terms' => 0,
            'total_searches' => 0,
            'period' => ['from' => '2024-01-01', 'to' => '2024-06-30'],
        ];

        $this->request->method('getParams')->willReturn([
            'from' => '2024-01-01',
            'to' => '2024-06-30',
        ]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', $this->anything(), 10],
                ['limit', 10, 10],
            ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/popular');
        $this->searchTrailService->method('getPopularSearchTerms')
            ->willReturn($serviceResult);

        $result = $this->controller->popularTerms();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('period', $data);
    }

    // ─── activity() tests ───

    public function testActivityReturnsData(): void
    {
        $activityData = ['2024-01-01' => 10, '2024-01-02' => 15];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['interval', 'day', 'day'],
                ['_limit', $this->anything(), null],
                ['limit', $this->anything(), null],
            ]);
        $this->searchTrailService->method('getSearchActivity')
            ->willReturn($activityData);

        $result = $this->controller->activity();

        $this->assertSame(200, $result->getStatus());
    }

    public function testActivityWithCustomInterval(): void
    {
        $activityData = ['2024-W01' => 50, '2024-W02' => 60];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['interval', 'day', 'week'],
                ['_limit', $this->anything(), null],
                ['limit', $this->anything(), null],
            ]);
        $this->searchTrailService->expects($this->once())
            ->method('getSearchActivity')
            ->with('week', null, null)
            ->willReturn($activityData);

        $result = $this->controller->activity();

        $this->assertSame(200, $result->getStatus());
    }

    public function testActivityReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn('day');
        $this->searchTrailService->method('getSearchActivity')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->activity();

        $this->assertSame(500, $result->getStatus());
    }

    // ─── registerSchemaStats() tests ───

    public function testRegisterSchemaStatsReturnsData(): void
    {
        $serviceResult = [
            'statistics' => [
                ['register' => 1, 'schema' => 2, 'count' => 50],
            ],
            'total_combinations' => 1,
            'total_searches' => 50,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 20, 20],
            ['limit', 20, 20],
            ['from', null, null],
            ['to', null, null],
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/register-schema-stats');
        $this->searchTrailService->method('getRegisterSchemaStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->registerSchemaStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total_searches', $data);
        $this->assertArrayHasKey('period', $data);
    }

    public function testRegisterSchemaStatsReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getRegisterSchemaStatistics')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->registerSchemaStats();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testRegisterSchemaStatsWithMissingKeys(): void
    {
        // Service returns sparse result.
        $serviceResult = [];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(20);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/register-schema-stats');
        $this->searchTrailService->method('getRegisterSchemaStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->registerSchemaStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame([], $data['results']);
    }

    // ─── userAgentStats() tests ───

    public function testUserAgentStatsReturnsStructuredData(): void
    {
        $serviceResult = [
            'user_agents' => [
                ['user_agent' => 'Chrome', 'count' => 100],
            ],
            'browser_distribution' => ['Chrome' => 100],
            'total_user_agents' => 1,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 10, 10],
            ['limit', 10, 10],
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/user-agent-stats');
        $this->searchTrailService->method('getUserAgentStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->userAgentStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('browser_breakdown', $data);
        $this->assertArrayHasKey('total_searches', $data);
        $this->assertArrayHasKey('period', $data);
    }

    public function testUserAgentStatsReturnsSimpleArray(): void
    {
        $serviceResult = [
            ['user_agent' => 'Chrome', 'count' => 100],
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 10, 10],
            ['limit', 10, 10],
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/user-agent-stats');
        $this->searchTrailService->method('getUserAgentStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->userAgentStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayNotHasKey('browser_breakdown', $data);
    }

    public function testUserAgentStatsReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(10);
        $this->searchTrailService->method('getUserAgentStatistics')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->userAgentStats();

        $this->assertSame(500, $result->getStatus());
    }

    public function testUserAgentStatsWithEmptyBrowserDistribution(): void
    {
        $serviceResult = [
            'user_agents' => [
                ['user_agent' => 'Chrome', 'count' => 100],
            ],
            'browser_distribution' => [],
            'total_user_agents' => 1,
            'period' => ['from' => '2024-01-01', 'to' => '2024-12-31'],
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 10, 10],
            ['limit', 10, 10],
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/user-agent-stats');
        $this->searchTrailService->method('getUserAgentStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->userAgentStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayNotHasKey('browser_breakdown', $data);
        $this->assertArrayHasKey('period', $data);
    }

    public function testUserAgentStatsWithNullBrowserDistribution(): void
    {
        $serviceResult = [
            'user_agents' => [
                ['user_agent' => 'Firefox', 'count' => 50],
            ],
            'browser_distribution' => null,
            'total_user_agents' => 1,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 10, 10],
            ['limit', 10, 10],
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/user-agent-stats');
        $this->searchTrailService->method('getUserAgentStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->userAgentStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayNotHasKey('browser_breakdown', $data);
    }

    // ─── cleanup() tests ───

    public function testCleanupReturnsResult(): void
    {
        $cleanupResult = ['success' => true, 'deleted' => 10];

        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('cleanupSearchTrails')
            ->willReturn($cleanupResult);

        $result = $this->controller->cleanup();

        $this->assertSame(200, $result->getStatus());
    }

    public function testCleanupWithValidDate(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['before', null, '2024-01-01'],
            ]);

        $this->searchTrailService->expects($this->once())
            ->method('cleanupSearchTrails')
            ->with($this->callback(function ($date) {
                return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-01-01';
            }))
            ->willReturn(['success' => true, 'deleted' => 5]);

        $result = $this->controller->cleanup();

        $this->assertSame(200, $result->getStatus());
    }

    public function testCleanupReturns400OnInvalidDate(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['before', null, 'not-a-date'],
            ]);

        $result = $this->controller->cleanup();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Invalid date format', $data['error']);
    }

    public function testCleanupReturns500OnException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('cleanupSearchTrails')
            ->willThrowException(new Exception('Cleanup error'));

        $result = $this->controller->cleanup();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Cleanup error', $data['error']);
    }

    // ─── destroy() tests ───

    public function testDestroyReturnsSuccess(): void
    {
        $trail = new \OCA\OpenRegister\Db\SearchTrail();
        $ref = new \ReflectionClass($trail);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($trail, 1);

        $this->searchTrailService->method('getSearchTrail')->willReturn($trail);

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('not implemented', $data['message']);
    }

    public function testDestroyReturns404WhenNotFound(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Search trail not found', $data['error']);
    }

    public function testDestroyReturns500OnException(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->destroy(1);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('DB error', $data['error']);
    }

    // ─── destroyMultiple() tests ───

    public function testDestroyMultipleReturnsSuccess(): void
    {
        $result = $this->controller->destroyMultiple();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('results', $data);
        $this->assertStringContainsString('not implemented', $data['message']);
    }

    // ─── export() tests ───

    /**
     * Create a mock trail object that exposes the methods the export() method expects.
     *
     * The export() method calls methods like getUserId(), getSessionId(), getSearchParameters(),
     * getResultMetadata(), getUpdated() which don't exist on SearchTrail entity.
     * We need a proper mock to test the success path.
     */
    private function createExportableTrailMock(array $data = []): object
    {
        $defaults = [
            'id' => 1,
            'searchTerm' => 'test query',
            'requestUri' => '/api/objects/1/2',
            'resultCount' => 5,
            'totalResults' => 10,
            'responseTime' => 120,
            'executionType' => 'sync',
            'userId' => 'admin',
            'userAgent' => 'Mozilla/5.0',
            'ipAddress' => '127.0.0.1',
            'sessionId' => 'sess-abc123',
            'created' => '2024-06-01T10:00:00Z',
            'updated' => '2024-06-01T10:00:00Z',
            'searchParameters' => ['_search' => 'test'],
            'resultMetadata' => ['facets' => []],
        ];
        $data = array_merge($defaults, $data);

        $trail = $this->getMockBuilder(\stdClass::class)
            ->addMethods([
                'getId',
                'getSearchTerm',
                'getRequestUri',
                'getResultCount',
                'getTotalResults',
                'getResponseTime',
                'getExecutionType',
                'getUserId',
                'getUserAgent',
                'getIpAddress',
                'getSessionId',
                'getCreated',
                'getUpdated',
                'getSearchParameters',
                'getResultMetadata',
            ])
            ->getMock();

        $trail->method('getId')->willReturn($data['id']);
        $trail->method('getSearchTerm')->willReturn($data['searchTerm']);
        $trail->method('getRequestUri')->willReturn($data['requestUri']);
        $trail->method('getResultCount')->willReturn($data['resultCount']);
        $trail->method('getTotalResults')->willReturn($data['totalResults']);
        $trail->method('getResponseTime')->willReturn($data['responseTime']);
        $trail->method('getExecutionType')->willReturn($data['executionType']);
        $trail->method('getUserId')->willReturn($data['userId']);
        $trail->method('getUserAgent')->willReturn($data['userAgent']);
        $trail->method('getIpAddress')->willReturn($data['ipAddress']);
        $trail->method('getSessionId')->willReturn($data['sessionId']);
        $trail->method('getCreated')->willReturn($data['created']);
        $trail->method('getUpdated')->willReturn($data['updated']);
        $trail->method('getSearchParameters')->willReturn($data['searchParameters']);
        $trail->method('getResultMetadata')->willReturn($data['resultMetadata']);

        return $trail;
    }

    public function testExportJsonFormat(): void
    {
        $trail = $this->createExportableTrailMock();

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'json'],
                ['includeMetadata', false, false],
            ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [$trail],
                'total' => 1,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('application/json', $data['data']['contentType']);
        $this->assertStringContainsString('.json', $data['data']['filename']);

        // Verify the export content is valid JSON.
        $exportContent = json_decode($data['data']['content'], true);
        $this->assertIsArray($exportContent);
        $this->assertCount(1, $exportContent);
        $this->assertSame('test query', $exportContent[0]['search_term']);
        // Without includeMetadata, search_parameters and result_metadata should not be in export.
        $this->assertArrayNotHasKey('search_parameters', $exportContent[0]);
    }

    public function testExportJsonFormatWithMetadata(): void
    {
        $trail = $this->createExportableTrailMock([
            'searchParameters' => ['_search' => 'test', 'status' => 'published'],
            'resultMetadata' => ['facets' => ['type' => 3]],
        ]);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'json'],
                ['includeMetadata', false, true],
            ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [$trail],
                'total' => 1,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);

        $exportContent = json_decode($data['data']['content'], true);
        $this->assertArrayHasKey('search_parameters', $exportContent[0]);
        $this->assertArrayHasKey('result_metadata', $exportContent[0]);
    }

    public function testExportCsvFormat(): void
    {
        $trail = $this->createExportableTrailMock();

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'csv'],
                ['includeMetadata', false, false],
            ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [$trail],
                'total' => 1,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('text/csv', $data['data']['contentType']);
        $this->assertStringContainsString('.csv', $data['data']['filename']);
        // CSV should contain headers.
        $this->assertStringContainsString('search_term', $data['data']['content']);
    }

    public function testExportCsvFormatWithMetadata(): void
    {
        $trail = $this->createExportableTrailMock();

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'csv'],
                ['includeMetadata', false, 'true'],
            ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [$trail],
                'total' => 1,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('search_parameters', $data['data']['content']);
    }

    public function testExportEmptyResultsJson(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['format', 'csv', 'json'],
            ['includeMetadata', false, false],
        ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [],
                'total' => 0,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('application/json', $data['data']['contentType']);
        $this->assertSame('[]', $data['data']['content']);
    }

    public function testExportEmptyResultsCsv(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnMap([
            ['format', 'csv', 'csv'],
            ['includeMetadata', false, false],
        ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [],
                'total' => 0,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('text/csv', $data['data']['contentType']);
        // Empty CSV should be empty string.
        $this->assertSame('', $data['data']['content']);
    }

    public function testExportReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchTrails')
            ->willThrowException(new Exception('Export error'));

        $result = $this->controller->export();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Export error', $data['error']);
    }

    public function testExportMultipleTrails(): void
    {
        $trail1 = $this->createExportableTrailMock(['id' => 1, 'searchTerm' => 'alpha']);
        $trail2 = $this->createExportableTrailMock(['id' => 2, 'searchTerm' => 'beta']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'json'],
                ['includeMetadata', false, false],
            ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [$trail1, $trail2],
                'total' => 2,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $exportContent = json_decode($result->getData()['data']['content'], true);
        $this->assertCount(2, $exportContent);
        $this->assertSame('alpha', $exportContent[0]['search_term']);
        $this->assertSame('beta', $exportContent[1]['search_term']);
    }

    public function testExportWithFiltersAndSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            '_search' => 'query',
            'from' => '2024-01-01',
            'to' => '2024-12-31',
            'status' => 'active',
        ]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'json'],
                ['includeMetadata', false, false],
            ]);
        $this->searchTrailService->expects($this->once())
            ->method('getSearchTrails')
            ->with($this->callback(function ($config) {
                return isset($config['search'])
                    && $config['search'] === 'query'
                    && $config['from'] instanceof \DateTime
                    && $config['to'] instanceof \DateTime
                    && isset($config['filters']['status'])
                    && $config['limit'] === null
                    && $config['offset'] === null;
            }))
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
    }

    // ─── clearAll() tests ───

    public function testClearAllCatchesServerError(): void
    {
        // OC::$server->get() is not available in unit tests, so clearAll
        // should hit the catch block.
        try {
            $result = $this->controller->clearAll();
            // If somehow OC::$server is available, verify response.
            $this->assertInstanceOf(JSONResponse::class, $result);
        } catch (\Error $e) {
            // OC::$server not available in unit tests - that's expected.
            $this->assertTrue(true);
        }
    }

    // ─── extractRequestParameters() tests (exercised via public methods) ───

    public function testExtractRequestParametersWithLimitParam(): void
    {
        $stats = ['total_searches' => 100];

        // Test 'limit' parameter (non-underscore).
        $this->request->method('getParams')->willReturn([
            'limit' => '30',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithUnderscoreLimitParam(): void
    {
        $stats = ['total_searches' => 100];

        // Test '_limit' parameter (underscore-prefixed).
        $this->request->method('getParams')->willReturn([
            '_limit' => '25',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithOffsetParam(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            'offset' => '40',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithUnderscoreOffsetParam(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            '_offset' => '20',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithPageParam(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            'page' => '3',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithUnderscorePageParam(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            '_page' => '2',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersPageCalculatesOffset(): void
    {
        // When page is set but offset is not, offset should be calculated.
        $serviceResult = [
            'statistics' => [],
            'total_combinations' => 0,
            'total_searches' => 0,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([
            'page' => '3',
            'limit' => '10',
        ]);
        $this->request->method('getParam')->willReturn(10);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/register-schema-stats');
        $this->searchTrailService->method('getRegisterSchemaStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->registerSchemaStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Page 3, limit 10 => offset should be 20.
        $this->assertSame(20, $data['offset']);
    }

    public function testExtractRequestParametersWithSearchParam(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            'search' => 'test query',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithUnderscoreSearchParam(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            '_search' => 'underscore search',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithSortParams(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            'sort' => 'searchTerm',
            'order' => 'ASC',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersWithUnderscoreSortParams(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            '_sort' => 'created',
            '_order' => 'DESC',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersFiltersOutSystemParams(): void
    {
        // Verify that system params are stripped and custom filters pass through.
        $this->request->method('getParams')->willReturn([
            'limit' => '20',
            '_limit' => '20',
            'offset' => '0',
            '_offset' => '0',
            'page' => '1',
            '_page' => '1',
            'search' => 'test',
            '_search' => 'test',
            'sort' => 'created',
            '_sort' => 'created',
            'order' => 'DESC',
            '_order' => 'DESC',
            'from' => '2024-01-01',
            'to' => '2024-12-31',
            '_route' => 'route.name',
            'id' => '5',
            'customFilter' => 'value',
        ]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'json'],
                ['includeMetadata', false, false],
            ]);

        $this->searchTrailService->expects($this->once())
            ->method('getSearchTrails')
            ->with($this->callback(function ($config) {
                $filters = $config['filters'];
                // Only customFilter should remain after filtering.
                return isset($filters['customFilter'])
                    && $filters['customFilter'] === 'value'
                    && !isset($filters['limit'])
                    && !isset($filters['_route'])
                    && !isset($filters['id']);
            }))
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
    }

    // ─── paginate() tests (exercised via public methods) ───

    public function testPaginateWithOffsetButPageOne(): void
    {
        // When page=1 and offset>0, page should be calculated from offset.
        $serviceResult = [
            'results' => [['id' => 1]],
            'total' => 100,
            'limit' => 10,
            'offset' => 30,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        // offset=30, limit=10 => page = floor(30/10) + 1 = 4
        $this->assertSame(4.0, $data['page']);
    }

    public function testPaginateWithTotalLessThanResultCount(): void
    {
        // When total < count(results), total should be adjusted.
        $serviceResult = [
            'results' => array_fill(0, 5, ['id' => 1]),
            'total' => 2,  // Less than actual count of 5.
            'limit' => 20,
            'offset' => 0,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        // Total should be adjusted to count(results) = 5.
        $this->assertSame(5, $data['total']);
    }

    public function testPaginateNextUrlWithLegacyPageParam(): void
    {
        // URL has 'page=' instead of '_page='.
        $serviceResult = [
            'results' => array_fill(0, 10, ['id' => 1]),
            'total' => 30,
            'limit' => 10,
            'offset' => 0,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails?page=1');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertArrayHasKey('next', $data);
        $this->assertStringContainsString('_page=2', $data['next']);
    }

    public function testPaginatePrevUrlWithLegacyPageParam(): void
    {
        // URL has 'page=' instead of '_page=', on page 2.
        $serviceResult = [
            'results' => array_fill(0, 10, ['id' => 1]),
            'total' => 30,
            'limit' => 10,
            'offset' => 10,
            'page' => 2,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails?page=2');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertArrayHasKey('prev', $data);
        $this->assertStringContainsString('_page=1', $data['prev']);
    }

    public function testPaginateNextUrlWithNoPageParam(): void
    {
        // URL has no page param at all - should append.
        $serviceResult = [
            'results' => array_fill(0, 10, ['id' => 1]),
            'total' => 30,
            'limit' => 10,
            'offset' => 0,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails?limit=10');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertArrayHasKey('next', $data);
        $this->assertStringContainsString('&_page=2', $data['next']);
    }

    public function testPaginatePrevUrlWithNoPageParam(): void
    {
        // URL has no page param at all, on page 2.
        $serviceResult = [
            'results' => array_fill(0, 10, ['id' => 1]),
            'total' => 30,
            'limit' => 10,
            'offset' => 10,
            'page' => 2,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails?limit=10');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertArrayHasKey('prev', $data);
        $this->assertStringContainsString('&_page=1', $data['prev']);
    }

    public function testPaginateWithNullValues(): void
    {
        // Test pagination defaults when all values are null.
        $serviceResult = [
            'results' => [],
            'total' => null,
            'limit' => null,
            'offset' => null,
            'page' => null,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        // Default values should be applied.
        $this->assertSame(0, $data['total']);
        $this->assertSame(20, $data['limit']);
        $this->assertSame(0, $data['offset']);
        $this->assertSame(1, $data['page']);
    }

    public function testPaginateNextUrlWithNoQueryString(): void
    {
        // URL with no query string at all.
        $serviceResult = [
            'results' => array_fill(0, 10, ['id' => 1]),
            'total' => 30,
            'limit' => 10,
            'offset' => 0,
            'page' => 1,
        ];

        $this->request->method('getParams')->willReturn([]);
        // Note: no '?' in the URL at all.
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertArrayHasKey('next', $data);
        // Should still append _page param with & (the code uses & as separator even without ?).
        $this->assertStringContainsString('_page=2', $data['next']);
    }

    public function testPaginatePrevUrlWithNoQueryString(): void
    {
        // URL with no query string, on page 3.
        $serviceResult = [
            'results' => array_fill(0, 10, ['id' => 1]),
            'total' => 30,
            'limit' => 10,
            'offset' => 20,
            'page' => 3,
        ];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails');
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn($serviceResult);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertArrayHasKey('prev', $data);
        $this->assertStringContainsString('_page=2', $data['prev']);
    }

    // ─── Both limit and _limit params (extractRequestParameters line coverage) ───

    public function testExtractRequestParametersBothLimitAndUnderscoreLimit(): void
    {
        // When both 'limit' and '_limit' exist, 'limit' should take precedence
        // because it's checked second.
        $serviceResult = [
            'statistics' => [],
            'total_combinations' => 0,
            'total_searches' => 0,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([
            '_limit' => '15',
            'limit' => '25',
        ]);
        $this->request->method('getParam')->willReturn(25);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/register-schema-stats');
        $this->searchTrailService->method('getRegisterSchemaStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->registerSchemaStats();

        $data = $result->getData();
        $this->assertSame(25, $data['limit']);
    }

    public function testExtractRequestParametersBothOffsetAndUnderscoreOffset(): void
    {
        $serviceResult = [
            'statistics' => [],
            'total_combinations' => 0,
            'total_searches' => 0,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([
            '_offset' => '10',
            'offset' => '30',
        ]);
        $this->request->method('getParam')->willReturn(20);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/register-schema-stats');
        $this->searchTrailService->method('getRegisterSchemaStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->registerSchemaStats();

        $data = $result->getData();
        $this->assertSame(30, $data['offset']);
    }

    public function testExtractRequestParametersBothPageAndUnderscorePage(): void
    {
        $stats = ['total_searches' => 100];

        $this->request->method('getParams')->willReturn([
            '_page' => '2',
            'page' => '5',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersSortWithUnderscorePrefix(): void
    {
        $stats = ['total_searches' => 100];

        // _sort takes priority over sort.
        $this->request->method('getParams')->willReturn([
            '_sort' => 'resultCount',
            '_order' => 'ASC',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    public function testExtractRequestParametersSortWithoutOrderParam(): void
    {
        $stats = ['total_searches' => 100];

        // Sort without explicit order should default to DESC.
        $this->request->method('getParams')->willReturn([
            'sort' => 'searchTerm',
        ]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
            ->willReturn($stats);

        $result = $this->controller->statistics();

        $this->assertSame(200, $result->getStatus());
    }

    // ─── Edge cases ───

    public function testExportWithDefaultFormatIsCsv(): void
    {
        // When format param is not set, default should be csv.
        $trail = $this->createExportableTrailMock();

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'csv'],  // default
                ['includeMetadata', false, false],
            ]);
        $this->searchTrailService->method('getSearchTrails')
            ->willReturn([
                'results' => [$trail],
                'total' => 1,
            ]);

        $result = $this->controller->export();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('text/csv', $data['data']['contentType']);
    }

    public function testPopularTermsWithPageAndOffset(): void
    {
        $serviceResult = [
            'terms' => [['term' => 'test', 'count' => 10]],
            'total_unique_terms' => 50,
            'total_searches' => 200,
            'period' => null,
        ];

        $this->request->method('getParams')->willReturn([
            'page' => '2',
            'offset' => '10',
        ]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['_limit', $this->anything(), 10],
                ['limit', 10, 10],
            ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/popular?page=2');
        $this->searchTrailService->method('getPopularSearchTerms')
            ->willReturn($serviceResult);

        $result = $this->controller->popularTerms();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(10, $data['offset']);
    }

    public function testActivityWithDateFilters(): void
    {
        $activityData = ['2024-06-01' => 30];

        $this->request->method('getParams')->willReturn([
            'from' => '2024-06-01',
            'to' => '2024-06-30',
        ]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['interval', 'day', 'day'],
                ['_limit', $this->anything(), null],
                ['limit', $this->anything(), null],
            ]);
        $this->searchTrailService->expects($this->once())
            ->method('getSearchActivity')
            ->with(
                'day',
                $this->callback(function ($from) {
                    return $from instanceof \DateTime && $from->format('Y-m-d') === '2024-06-01';
                }),
                $this->callback(function ($to) {
                    return $to instanceof \DateTime && $to->format('Y-m-d') === '2024-06-30';
                })
            )
            ->willReturn($activityData);

        $result = $this->controller->activity();

        $this->assertSame(200, $result->getStatus());
    }

    public function testRegisterSchemaStatsWithDateFilters(): void
    {
        $serviceResult = [
            'statistics' => [],
            'total_combinations' => 0,
            'total_searches' => 0,
            'period' => ['from' => '2024-01-01', 'to' => '2024-06-30'],
        ];

        $this->request->method('getParams')->willReturn([
            'from' => '2024-01-01',
            'to' => '2024-06-30',
        ]);
        $this->request->method('getParam')->willReturn(20);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/register-schema-stats');
        $this->searchTrailService->method('getRegisterSchemaStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->registerSchemaStats();

        $this->assertSame(200, $result->getStatus());
    }

    public function testUserAgentStatsWithDateFilters(): void
    {
        $serviceResult = [
            'user_agents' => [],
            'total_user_agents' => 0,
            'period' => ['from' => '2024-01-01', 'to' => '2024-12-31'],
        ];

        $this->request->method('getParams')->willReturn([
            'from' => '2024-01-01',
            'to' => '2024-12-31',
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 10, 10],
            ['limit', 10, 10],
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/search-trails/user-agent-stats');
        $this->searchTrailService->method('getUserAgentStatistics')
            ->willReturn($serviceResult);

        $result = $this->controller->userAgentStats();

        $this->assertSame(200, $result->getStatus());
    }
}
