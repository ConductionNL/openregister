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
}
