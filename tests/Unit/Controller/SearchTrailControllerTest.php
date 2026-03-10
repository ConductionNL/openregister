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
    }

    public function testIndexReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->searchTrailService->method('getSearchTrails')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->index();

        $this->assertSame(500, $result->getStatus());
    }

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
    }

    public function testShowReturns500OnException(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->show(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testStatisticsReturnsData(): void
    {
        $stats = ['total_searches' => 100, 'unique_terms' => 50];

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('getSearchStatistics')
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
    }

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

    public function testActivityReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturn('day');
        $this->searchTrailService->method('getSearchActivity')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->activity();

        $this->assertSame(500, $result->getStatus());
    }

    public function testCleanupReturnsResult(): void
    {
        $cleanupResult = ['success' => true, 'deleted' => 10];

        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('cleanupSearchTrails')
            ->willReturn($cleanupResult);

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
    }

    public function testCleanupReturns500OnException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->searchTrailService->method('cleanupSearchTrails')
            ->willThrowException(new Exception('Cleanup error'));

        $result = $this->controller->cleanup();

        $this->assertSame(500, $result->getStatus());
    }

    public function testDestroyReturns404WhenNotFound(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertSame(404, $result->getStatus());
    }

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
    }

    public function testDestroyMultipleReturnsSuccess(): void
    {
        $result = $this->controller->destroyMultiple();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }
}
