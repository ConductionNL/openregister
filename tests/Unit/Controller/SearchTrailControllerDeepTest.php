<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SearchTrailController;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SearchTrailControllerDeepTest extends TestCase
{
    private SearchTrailController $controller;
    private IRequest|MockObject $request;
    private SearchTrailService|MockObject $searchTrailService;

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

    public function testShowNotFound(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->show(999);

        $this->assertEquals(404, $response->getStatus());
    }

    public function testShowException(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new \Exception('db error'));

        $response = $this->controller->show(1);

        $this->assertEquals(500, $response->getStatus());
    }

    public function testDestroyNotFound(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->destroy(999);

        $this->assertEquals(404, $response->getStatus());
    }

    public function testDestroyException(): void
    {
        $this->searchTrailService->method('getSearchTrail')
            ->willThrowException(new \Exception('delete error'));

        $response = $this->controller->destroy(1);

        $this->assertEquals(500, $response->getStatus());
    }

    public function testDestroyMultiple(): void
    {
        $response = $this->controller->destroyMultiple();

        $this->assertEquals(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }

    public function testStatisticsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->searchTrailService->method('getSearchStatistics')
            ->willThrowException(new \Exception('stats error'));

        $response = $this->controller->statistics();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testCleanupInvalidDate(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['before', null, 'not-a-date'],
            ]);

        $response = $this->controller->cleanup();

        $this->assertEquals(400, $response->getStatus());
    }

    public function testCleanupException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['before', null, null],
            ]);
        $this->searchTrailService->method('cleanupSearchTrails')
            ->willThrowException(new \Exception('cleanup error'));

        $response = $this->controller->cleanup();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testArrayToCsvEmpty(): void
    {
        $ref = new ReflectionClass(SearchTrailController::class);
        $method = $ref->getMethod('arrayToCsv');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, []);

        $this->assertEquals('', $result);
    }

    public function testArrayToCsvWithData(): void
    {
        $ref = new ReflectionClass(SearchTrailController::class);
        $method = $ref->getMethod('arrayToCsv');
        $method->setAccessible(true);

        $data = [
            ['name' => 'foo', 'value' => 'bar'],
            ['name' => 'baz', 'value' => 'qux'],
        ];
        $result = $method->invoke($this->controller, $data);

        $this->assertStringContainsString('name,value', $result);
        $this->assertStringContainsString('foo,bar', $result);
    }

    public function testPaginateWithOffsetAndPage(): void
    {
        $ref = new ReflectionClass(SearchTrailController::class);
        $method = $ref->getMethod('paginate');
        $method->setAccessible(true);

        $results = range(1, 5);
        $result = $method->invoke($this->controller, $results, 100, 10, 0, 3);

        $this->assertEquals(3, $result['page']);
        $this->assertEquals(10, $result['pages']);
    }

    public function testExtractRequestParametersWithDates(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '5',
            '_page' => '2',
            'from' => '2024-01-01',
            'to' => '2024-12-31',
            '_sort' => 'created',
            '_order' => 'ASC',
        ]);

        $ref = new ReflectionClass(SearchTrailController::class);
        $method = $ref->getMethod('extractRequestParameters');
        $method->setAccessible(true);

        $params = $method->invoke($this->controller);

        $this->assertEquals(5, $params['limit']);
        $this->assertEquals(2, $params['page']);
        $this->assertInstanceOf(\DateTime::class, $params['from']);
        $this->assertInstanceOf(\DateTime::class, $params['to']);
    }
}
