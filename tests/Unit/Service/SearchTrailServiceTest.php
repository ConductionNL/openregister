<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class SearchTrailServiceTest extends TestCase
{
    private SearchTrailService $service;
    private SearchTrailMapper&MockObject $searchTrailMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;

    protected function setUp(): void
    {
        $this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        $this->service = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper
        );
    }

    // ── createSearchTrail ──

    public function testCreateSearchTrailReturnsTrail(): void
    {
        $trail = new SearchTrail();
        $this->searchTrailMapper->method('createSearchTrail')->willReturn($trail);

        $result = $this->service->createSearchTrail(
            ['search' => 'test'],
            10,
            100,
            50.5,
            'sync'
        );

        $this->assertSame($trail, $result);
    }

    public function testCreateSearchTrailThrowsOnMapperException(): void
    {
        $this->searchTrailMapper->method('createSearchTrail')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Search trail creation failed: DB error');

        $this->service->createSearchTrail([], 0, 0);
    }

    public function testCreateSearchTrailWithSelfClearingEnabled(): void
    {
        $service = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper,
            365,
            true
        );

        $trail = new SearchTrail();
        $this->searchTrailMapper->method('createSearchTrail')->willReturn($trail);
        // clearLogs should be called because self-clearing is enabled.
        $this->searchTrailMapper->expects($this->once())->method('clearLogs')->willReturn(false);

        $service->createSearchTrail([], 0, 0);
    }

    // ── clearExpiredSearchTrails ──

    public function testClearExpiredSearchTrailsReturnsSuccessWithDeletions(): void
    {
        $this->searchTrailMapper->method('clearLogs')->willReturn(true);

        $result = $this->service->clearExpiredSearchTrails();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['deleted']);
        $this->assertArrayHasKey('cleanup_date', $result);
    }

    public function testClearExpiredSearchTrailsReturnsSuccessNoDeletions(): void
    {
        $this->searchTrailMapper->method('clearLogs')->willReturn(false);

        $result = $this->service->clearExpiredSearchTrails();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['deleted']);
    }

    public function testClearExpiredSearchTrailsHandlesException(): void
    {
        $this->searchTrailMapper->method('clearLogs')
            ->willThrowException(new Exception('DB error'));

        $result = $this->service->clearExpiredSearchTrails();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame('DB error', $result['error']);
    }

    // ── getSearchTrails ──

    public function testGetSearchTrailsReturnsPaginatedResults(): void
    {
        $trail = new SearchTrail();
        $this->searchTrailMapper->method('findAll')->willReturn([$trail]);
        $this->searchTrailMapper->method('count')->willReturn(1);

        $result = $this->service->getSearchTrails();

        $this->assertArrayHasKey('results', $result);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('limit', $result);
    }

    public function testGetSearchTrailsProcessesPaginationFromPage(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails(['page' => 3, 'limit' => 10]);

        $this->assertSame(3, $result['page']);
        $this->assertSame(20, $result['offset']);
        $this->assertSame(10, $result['limit']);
    }

    // ── getSearchTrail ──

    public function testGetSearchTrailReturnsEnrichedTrail(): void
    {
        $trail = new SearchTrail();
        $this->searchTrailMapper->method('find')->willReturn($trail);

        $result = $this->service->getSearchTrail(1);

        $this->assertInstanceOf(SearchTrail::class, $result);
    }

    // ── cleanupSearchTrails ──

    public function testCleanupSearchTrailsReturnsSuccess(): void
    {
        $this->searchTrailMapper->method('clearLogs')->willReturn(true);

        $result = $this->service->cleanupSearchTrails();

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['deleted']);
    }

    public function testCleanupSearchTrailsHandlesException(): void
    {
        $this->searchTrailMapper->method('clearLogs')
            ->willThrowException(new Exception('error'));

        $result = $this->service->cleanupSearchTrails();

        $this->assertFalse($result['success']);
        $this->assertSame('Cleanup operation failed', $result['message']);
    }

    // ── getSearchStatistics ──

    public function testGetSearchStatisticsReturnsEnhancedStats(): void
    {
        $baseStats = [
            'total_searches' => 100,
            'non_empty_searches' => 80,
            'total_results' => 500,
        ];
        $this->searchTrailMapper->method('getSearchStatistics')->willReturn($baseStats);
        $this->searchTrailMapper->method('getUniqueSearchTermsCount')->willReturn(50);
        $this->searchTrailMapper->method('getUniqueUsersCount')->willReturn(10);
        $this->searchTrailMapper->method('getAverageSearchesPerSession')->willReturn(3.5);
        $this->searchTrailMapper->method('getAverageObjectViewsPerSession')->willReturn(2.0);

        $result = $this->service->getSearchStatistics();

        $this->assertSame(80, $result['searches_with_results']);
        $this->assertSame(20, $result['searches_without_results']);
        $this->assertSame(80.0, $result['success_rate']);
        $this->assertSame(50, $result['unique_search_terms']);
        $this->assertSame(10, $result['unique_users']);
    }

    public function testGetSearchStatisticsWithZeroSearches(): void
    {
        $baseStats = [
            'total_searches' => 0,
            'non_empty_searches' => 0,
            'total_results' => 0,
        ];
        $this->searchTrailMapper->method('getSearchStatistics')->willReturn($baseStats);
        $this->searchTrailMapper->method('getUniqueSearchTermsCount')->willReturn(0);
        $this->searchTrailMapper->method('getUniqueUsersCount')->willReturn(0);
        $this->searchTrailMapper->method('getAverageSearchesPerSession')->willReturn(0.0);
        $this->searchTrailMapper->method('getAverageObjectViewsPerSession')->willReturn(0.0);

        $result = $this->service->getSearchStatistics();

        $this->assertSame(0, $result['success_rate']);
        $this->assertSame(['simple' => 0, 'medium' => 0, 'complex' => 0], $result['query_complexity']);
    }

    public function testGetSearchStatisticsWithDateRange(): void
    {
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-31');
        $baseStats = [
            'total_searches' => 100,
            'non_empty_searches' => 50,
            'total_results' => 200,
        ];
        $this->searchTrailMapper->method('getSearchStatistics')->willReturn($baseStats);
        $this->searchTrailMapper->method('getUniqueSearchTermsCount')->willReturn(10);
        $this->searchTrailMapper->method('getUniqueUsersCount')->willReturn(5);
        $this->searchTrailMapper->method('getAverageSearchesPerSession')->willReturn(2.0);
        $this->searchTrailMapper->method('getAverageObjectViewsPerSession')->willReturn(1.0);

        $result = $this->service->getSearchStatistics($from, $to);

        $this->assertArrayHasKey('daily_averages', $result);
        $this->assertArrayHasKey('period', $result);
        $this->assertNotNull($result['period']['days']);
    }

    // ── getPopularSearchTerms ──

    public function testGetPopularSearchTermsReturnsEnhancedTerms(): void
    {
        $this->searchTrailMapper->method('getPopularSearchTerms')->willReturn([
            ['term' => 'test', 'count' => 10, 'avg_results' => 5.0],
            ['term' => 'hello', 'count' => 5, 'avg_results' => 0.0],
        ]);

        $result = $this->service->getPopularSearchTerms(10);

        $this->assertCount(2, $result['terms']);
        $this->assertSame(15, $result['total_searches']);
        $this->assertSame('high', $result['terms'][0]['effectiveness']);
        $this->assertSame('low', $result['terms'][1]['effectiveness']);
    }

    // ── getSearchActivity ──

    public function testGetSearchActivityReturnsInsights(): void
    {
        $this->searchTrailMapper->method('getSearchActivityByTime')->willReturn([
            ['period' => '2024-01-01', 'count' => 10],
            ['period' => '2024-01-02', 'count' => 20],
        ]);

        $result = $this->service->getSearchActivity('day');

        $this->assertArrayHasKey('activity', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertSame('day', $result['interval']);
        $this->assertSame('2024-01-02', $result['insights']['peak_period']);
    }

    public function testGetSearchActivityReturnsNoDataForEmptyActivity(): void
    {
        $this->searchTrailMapper->method('getSearchActivityByTime')->willReturn([]);

        $result = $this->service->getSearchActivity('day');

        $this->assertSame('no_data', $result['insights']['trend']);
    }

    // ── getRegisterSchemaStatistics ──

    public function testGetRegisterSchemaStatisticsReturnsEnhancedStats(): void
    {
        $this->searchTrailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([
            ['register' => 1, 'schema' => 1, 'count' => 50, 'avg_results' => 15.0, 'avg_response_time' => 50.0],
        ]);

        $result = $this->service->getRegisterSchemaStatistics();

        $this->assertCount(1, $result['statistics']);
        $this->assertSame(50, $result['total_searches']);
        $this->assertSame('excellent', $result['statistics'][0]['performance_rating']);
    }

    // ── constructor with custom retention and selfClearing ──

    public function testConstructorWithCustomRetention(): void
    {
        $service = new SearchTrailService(
            $this->searchTrailMapper,
            $this->registerMapper,
            $this->schemaMapper,
            30,
            false
        );

        // No direct way to verify, but constructor should not throw.
        $this->assertInstanceOf(SearchTrailService::class, $service);
    }

    // ── getUserAgentStatistics ──

    public function testGetUserAgentStatisticsReturnsEnhancedStats(): void
    {
        $this->searchTrailMapper->method('getUserAgentStatistics')->willReturn([
            ['user_agent' => 'Mozilla/5.0 Chrome/120.0.0.0', 'count' => 50],
            ['user_agent' => 'Mozilla/5.0 Firefox/121.0', 'count' => 30],
        ]);

        $result = $this->service->getUserAgentStatistics(10);

        $this->assertArrayHasKey('user_agents', $result);
        $this->assertArrayHasKey('browser_distribution', $result);
        $this->assertArrayHasKey('total_user_agents', $result);
        $this->assertArrayHasKey('period', $result);
        $this->assertSame(2, $result['total_user_agents']);
        $this->assertSame('Chrome', $result['user_agents'][0]['browser_info']['browser']);
        $this->assertSame('120.0.0.0', $result['user_agents'][0]['browser_info']['version']);
        $this->assertSame('Firefox', $result['user_agents'][1]['browser_info']['browser']);
        $this->assertSame('121.0', $result['user_agents'][1]['browser_info']['version']);
    }

    public function testGetUserAgentStatisticsWithDateRange(): void
    {
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-01-31');

        $this->searchTrailMapper->method('getUserAgentStatistics')->willReturn([
            ['user_agent' => 'Mozilla/5.0 Safari/605.1.15', 'count' => 20],
        ]);

        $result = $this->service->getUserAgentStatistics(5, $from, $to);

        $this->assertSame('2024-01-01 00:00:00', $result['period']['from']);
        $this->assertSame('2024-01-31 00:00:00', $result['period']['to']);
        $this->assertSame('Safari', $result['user_agents'][0]['browser_info']['browser']);
    }

    public function testGetUserAgentStatisticsWithEdgeBrowser(): void
    {
        $this->searchTrailMapper->method('getUserAgentStatistics')->willReturn([
            ['user_agent' => 'Mozilla/5.0 Edge/18.19041', 'count' => 10],
        ]);

        $result = $this->service->getUserAgentStatistics();

        $this->assertSame('Edge', $result['user_agents'][0]['browser_info']['browser']);
        $this->assertSame('18.19041', $result['user_agents'][0]['browser_info']['version']);
    }

    public function testGetUserAgentStatisticsWithOperaBrowser(): void
    {
        $this->searchTrailMapper->method('getUserAgentStatistics')->willReturn([
            ['user_agent' => 'Opera/9.80', 'count' => 5],
        ]);

        $result = $this->service->getUserAgentStatistics();

        $this->assertSame('Opera', $result['user_agents'][0]['browser_info']['browser']);
    }

    public function testGetUserAgentStatisticsWithUnknownBrowser(): void
    {
        $this->searchTrailMapper->method('getUserAgentStatistics')->willReturn([
            ['user_agent' => 'SomeBot/1.0', 'count' => 3],
        ]);

        $result = $this->service->getUserAgentStatistics();

        $this->assertSame('unknown', $result['user_agents'][0]['browser_info']['browser']);
        $this->assertSame('unknown', $result['user_agents'][0]['browser_info']['version']);
        $this->assertSame('SomeBot/1.0', $result['user_agents'][0]['browser_info']['full_string']);
    }

    public function testGetUserAgentStatisticsEmptyResults(): void
    {
        $this->searchTrailMapper->method('getUserAgentStatistics')->willReturn([]);

        $result = $this->service->getUserAgentStatistics();

        $this->assertSame([], $result['user_agents']);
        $this->assertSame([], $result['browser_distribution']);
        $this->assertSame(0, $result['total_user_agents']);
    }

    public function testGetUserAgentStatisticsBrowserDistribution(): void
    {
        $this->searchTrailMapper->method('getUserAgentStatistics')->willReturn([
            ['user_agent' => 'Mozilla/5.0 Chrome/120.0', 'count' => 60],
            ['user_agent' => 'Mozilla/5.0 Chrome/119.0', 'count' => 40],
            ['user_agent' => 'Mozilla/5.0 Firefox/121.0', 'count' => 100],
        ]);

        $result = $this->service->getUserAgentStatistics();

        // Browser distribution should aggregate Chrome entries.
        $browserNames = array_column($result['browser_distribution'], 'browser');
        $this->assertContains('Chrome', $browserNames);
        $this->assertContains('Firefox', $browserNames);

        // Find Chrome aggregate - should be 100 (60+40).
        $chromeIdx = array_search('Chrome', $browserNames);
        $this->assertSame(100, $result['browser_distribution'][$chromeIdx]['count']);

        // Firefox should be 100.
        $firefoxIdx = array_search('Firefox', $browserNames);
        $this->assertSame(100, $result['browser_distribution'][$firefoxIdx]['count']);

        // Percentages should add up to 100.
        $totalPercentage = array_sum(array_column($result['browser_distribution'], 'percentage'));
        $this->assertSame(100.0, $totalPercentage);
    }

    // ── processConfig additional branches ──

    public function testGetSearchTrailsWithUnderscorePaginationParams(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails([
            '_limit' => 5,
            '_offset' => 10,
        ]);

        $this->assertSame(5, $result['limit']);
        $this->assertSame(10, $result['offset']);
    }

    public function testGetSearchTrailsWithUnderscorePageParam(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails(['_page' => 2]);

        $this->assertSame(2, $result['page']);
        $this->assertSame(20, $result['offset']);
    }

    public function testGetSearchTrailsCalculatesPageFromOffset(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails(['offset' => 40, 'limit' => 20]);

        // page = floor(40/20) + 1 = 3
        $this->assertSame(3.0, $result['page']);
        $this->assertSame(40, $result['offset']);
    }

    public function testGetSearchTrailsWithUnderscoreSearchParam(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails(['_search' => 'hello']);

        $this->assertArrayHasKey('results', $result);
    }

    public function testGetSearchTrailsWithSortParams(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails([
            'sort' => 'resultCount',
            'order' => 'ASC',
        ]);

        $this->assertArrayHasKey('results', $result);
    }

    public function testGetSearchTrailsWithUnderscoreSortParams(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails([
            '_sort' => 'created',
            '_order' => 'DESC',
        ]);

        $this->assertArrayHasKey('results', $result);
    }

    public function testGetSearchTrailsWithDateFilters(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails([
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ]);

        $this->assertArrayHasKey('results', $result);
    }

    public function testGetSearchTrailsWithInvalidDateFilters(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        // Invalid date strings should be silently ignored.
        $result = $this->service->getSearchTrails([
            'from' => 'not-a-date',
            'to' => 'also-not-a-date',
        ]);

        $this->assertArrayHasKey('results', $result);
    }

    public function testGetSearchTrailsWithCustomFilterParams(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails([
            'register' => 1,
            'schema' => 2,
            '_systemParam' => 'ignored',
        ]);

        $this->assertArrayHasKey('results', $result);
    }

    public function testGetSearchTrailsWithLimitLessThanOne(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails(['limit' => -5]);

        // Should be clamped to 1.
        $this->assertSame(1, $result['limit']);
    }

    public function testGetSearchTrailsWithPageLessThanOne(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails(['page' => -1]);

        // Should be clamped to 1.
        $this->assertSame(1, $result['page']);
    }

    // ── calculateTrend additional branches ──

    public function testGetSearchActivityWithSinglePeriod(): void
    {
        $this->searchTrailMapper->method('getSearchActivityByTime')->willReturn([
            ['period' => '2024-01-01', 'count' => 10],
        ]);

        $result = $this->service->getSearchActivity('day');

        // Single data point => stable trend.
        $this->assertSame('stable', $result['insights']['trend']);
        $this->assertSame(1, $result['insights']['total_periods']);
    }

    public function testGetSearchActivityWithDecreasingTrend(): void
    {
        $this->searchTrailMapper->method('getSearchActivityByTime')->willReturn([
            ['period' => '2024-01-01', 'count' => 100],
            ['period' => '2024-01-02', 'count' => 80],
            ['period' => '2024-01-03', 'count' => 60],
            ['period' => '2024-01-04', 'count' => 40],
            ['period' => '2024-01-05', 'count' => 20],
        ]);

        $result = $this->service->getSearchActivity('day');

        $this->assertSame('decreasing', $result['insights']['trend']);
    }

    public function testGetSearchActivityWithStableTrend(): void
    {
        $this->searchTrailMapper->method('getSearchActivityByTime')->willReturn([
            ['period' => '2024-01-01', 'count' => 10],
            ['period' => '2024-01-02', 'count' => 10],
            ['period' => '2024-01-03', 'count' => 10],
        ]);

        $result = $this->service->getSearchActivity('day');

        $this->assertSame('stable', $result['insights']['trend']);
    }

    // ── calculatePerformanceRating additional branches ──

    public function testGetRegisterSchemaStatisticsGoodRating(): void
    {
        $this->searchTrailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([
            ['register' => 1, 'schema' => 1, 'count' => 30, 'avg_results' => 7.0, 'avg_response_time' => 150.0],
        ]);

        $result = $this->service->getRegisterSchemaStatistics();

        $this->assertSame('good', $result['statistics'][0]['performance_rating']);
    }

    public function testGetRegisterSchemaStatisticsAverageRating(): void
    {
        $this->searchTrailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([
            ['register' => 1, 'schema' => 1, 'count' => 20, 'avg_results' => 2.0, 'avg_response_time' => 400.0],
        ]);

        $result = $this->service->getRegisterSchemaStatistics();

        $this->assertSame('average', $result['statistics'][0]['performance_rating']);
    }

    public function testGetRegisterSchemaStatisticsPoorRating(): void
    {
        $this->searchTrailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([
            ['register' => 1, 'schema' => 1, 'count' => 10, 'avg_results' => 0.0, 'avg_response_time' => 1000.0],
        ]);

        $result = $this->service->getRegisterSchemaStatistics();

        $this->assertSame('poor', $result['statistics'][0]['performance_rating']);
    }

    public function testGetRegisterSchemaStatisticsSortsByPercentage(): void
    {
        $this->searchTrailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([
            ['register' => 1, 'schema' => 1, 'count' => 10, 'avg_results' => 15.0, 'avg_response_time' => 50.0],
            ['register' => 2, 'schema' => 2, 'count' => 90, 'avg_results' => 15.0, 'avg_response_time' => 50.0],
        ]);

        $result = $this->service->getRegisterSchemaStatistics();

        // Should be sorted by percentage descending: register 2 first.
        $this->assertSame(90.0, $result['statistics'][0]['percentage']);
        $this->assertSame(10.0, $result['statistics'][1]['percentage']);
    }

    public function testGetRegisterSchemaStatisticsWithDateRange(): void
    {
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-12-31');

        $this->searchTrailMapper->method('getSearchStatisticsByRegisterSchema')->willReturn([]);

        $result = $this->service->getRegisterSchemaStatistics($from, $to);

        $this->assertSame(0, $result['total_searches']);
        $this->assertSame(0, $result['total_combinations']);
        $this->assertSame('2024-01-01 00:00:00', $result['period']['from']);
        $this->assertSame('2024-12-31 00:00:00', $result['period']['to']);
    }

    // ── enrichTrailsWithNames ──

    public function testGetSearchTrailEnrichesWithRegisterAndSchemaNames(): void
    {
        $trail = new SearchTrail();
        $trail->setRegister(1);
        $trail->setSchema(2);

        $register = new Register();
        $register->setTitle('Test Register');
        $this->registerMapper->method('find')->with(1)->willReturn($register);

        $schema = new Schema();
        $schema->setTitle('Test Schema');
        $this->schemaMapper->method('find')->with(2)->willReturn($schema);

        $this->searchTrailMapper->method('find')->willReturn($trail);

        $result = $this->service->getSearchTrail(1);

        $this->assertSame('Test Register', $result->getRegisterName());
        $this->assertSame('Test Schema', $result->getSchemaName());
    }

    public function testGetSearchTrailHandlesRegisterDoesNotExist(): void
    {
        $trail = new SearchTrail();
        $trail->setRegister(999);

        $this->registerMapper->method('find')->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->searchTrailMapper->method('find')->willReturn($trail);

        $result = $this->service->getSearchTrail(1);

        $this->assertSame('Register 999', $result->getRegisterName());
    }

    public function testGetSearchTrailHandlesSchemaDoesNotExist(): void
    {
        $trail = new SearchTrail();
        $trail->setSchema(888);

        $this->schemaMapper->method('find')->with(888)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->searchTrailMapper->method('find')->willReturn($trail);

        $result = $this->service->getSearchTrail(1);

        $this->assertSame('Schema 888', $result->getSchemaName());
    }

    public function testGetSearchTrailHandlesRegisterGenericException(): void
    {
        $trail = new SearchTrail();
        $trail->setRegister(777);

        $this->registerMapper->method('find')->with(777)
            ->willThrowException(new Exception('DB error'));

        $this->searchTrailMapper->method('find')->willReturn($trail);

        $result = $this->service->getSearchTrail(1);

        $this->assertSame('Register 777', $result->getRegisterName());
    }

    public function testGetSearchTrailHandlesSchemaGenericException(): void
    {
        $trail = new SearchTrail();
        $trail->setSchema(666);

        $this->schemaMapper->method('find')->with(666)
            ->willThrowException(new Exception('DB error'));

        $this->searchTrailMapper->method('find')->willReturn($trail);

        $result = $this->service->getSearchTrail(1);

        $this->assertSame('Schema 666', $result->getSchemaName());
    }

    public function testGetSearchTrailsEnrichesMultipleTrails(): void
    {
        $trail1 = new SearchTrail();
        $trail1->setRegister(1);
        $trail1->setSchema(1);

        $trail2 = new SearchTrail();
        $trail2->setRegister(1);
        $trail2->setSchema(2);

        $register = new Register();
        $register->setTitle('Shared Register');
        $this->registerMapper->method('find')->willReturn($register);

        $schema1 = new Schema();
        $schema1->setTitle('Schema One');
        $schema2 = new Schema();
        $schema2->setTitle('Schema Two');
        $this->schemaMapper->method('find')->willReturnCallback(
            function (int $id) use ($schema1, $schema2) {
                return $id === 1 ? $schema1 : $schema2;
            }
        );

        $this->searchTrailMapper->method('findAll')->willReturn([$trail1, $trail2]);
        $this->searchTrailMapper->method('count')->willReturn(2);

        $result = $this->service->getSearchTrails();

        $this->assertCount(2, $result['results']);
    }

    public function testGetSearchTrailWithNullRegisterAndSchema(): void
    {
        $trail = new SearchTrail();
        // register and schema are null by default.

        $this->searchTrailMapper->method('find')->willReturn($trail);

        $result = $this->service->getSearchTrail(1);

        // Should not throw, register/schema names remain null.
        $this->assertInstanceOf(SearchTrail::class, $result);
    }

    // ── cleanupSearchTrails additional branches ──

    public function testCleanupSearchTrailsNoExpiredEntries(): void
    {
        $this->searchTrailMapper->method('clearLogs')->willReturn(false);

        $result = $this->service->cleanupSearchTrails();

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame('No expired entries to delete', $result['message']);
        $this->assertArrayHasKey('cleanup_date', $result);
    }

    public function testCleanupSearchTrailsSuccessMessage(): void
    {
        $this->searchTrailMapper->method('clearLogs')->willReturn(true);

        $result = $this->service->cleanupSearchTrails();

        $this->assertSame('Successfully deleted expired search trail entries', $result['message']);
    }

    // ── getPopularSearchTerms additional branches ──

    public function testGetPopularSearchTermsWithDateRange(): void
    {
        $from = new DateTime('2024-06-01');
        $to = new DateTime('2024-06-30');

        $this->searchTrailMapper->method('getPopularSearchTerms')->willReturn([
            ['term' => 'test', 'count' => 5, 'avg_results' => 3.0],
        ]);

        $result = $this->service->getPopularSearchTerms(10, $from, $to);

        $this->assertSame('2024-06-01 00:00:00', $result['period']['from']);
        $this->assertSame('2024-06-30 00:00:00', $result['period']['to']);
        $this->assertSame(1, $result['total_unique_terms']);
    }

    public function testGetPopularSearchTermsEmpty(): void
    {
        $this->searchTrailMapper->method('getPopularSearchTerms')->willReturn([]);

        $result = $this->service->getPopularSearchTerms();

        $this->assertSame([], $result['terms']);
        $this->assertSame(0, $result['total_searches']);
        $this->assertSame(0, $result['total_unique_terms']);
    }

    // ── getSearchActivity additional branches ──

    public function testGetSearchActivityWithDateRange(): void
    {
        $from = new DateTime('2024-01-01');
        $to = new DateTime('2024-03-31');

        $this->searchTrailMapper->method('getSearchActivityByTime')->willReturn([
            ['period' => '2024-01', 'count' => 50],
            ['period' => '2024-02', 'count' => 30],
            ['period' => '2024-03', 'count' => 70],
        ]);

        $result = $this->service->getSearchActivity('month', $from, $to);

        $this->assertSame('month', $result['interval']);
        $this->assertSame('2024-01-01 00:00:00', $result['period']['from']);
        $this->assertSame('2024-03-31 00:00:00', $result['period']['to']);
        $this->assertSame('2024-03', $result['insights']['peak_period']);
        $this->assertSame('2024-02', $result['insights']['low_period']);
    }

    // ── calculatePages edge case ──

    public function testGetSearchTrailsWithZeroTotalReturnsZeroPages(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(0);

        $result = $this->service->getSearchTrails();

        $this->assertSame(0, $result['pages']);
    }

    public function testGetSearchTrailsPageCalculation(): void
    {
        $this->searchTrailMapper->method('findAll')->willReturn([]);
        $this->searchTrailMapper->method('count')->willReturn(45);

        $result = $this->service->getSearchTrails(['limit' => 20]);

        // ceil(45/20) = 3
        $this->assertSame(3, $result['pages']);
    }
}
