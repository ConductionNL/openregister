<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SearchController;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchControllerTest extends TestCase
{
    private SearchController $controller;
    private IRequest&MockObject $request;
    private ObjectService&MockObject $objectService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $this->controller = new SearchController(
            'openregister',
            $this->request,
            $this->objectService
        );
    }

    public function testSearchSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test'],
                ['offset', 0, 0],
                ['limit', 25, 25],
                ['_search', [], []],
            ]);

        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                [
                    'uuid' => 'obj-1',
                    'name' => 'Test Object',
                ],
            ],
            'total' => 1,
            '@self' => [],
        ]);

        $result = $this->controller->search();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['results']);
        $this->assertEquals(1, $data['total']);
        $this->assertEquals('obj-1', $data['results'][0]['id']);
        $this->assertEquals('openregister', $data['results'][0]['source']);
    }

    public function testSearchEmptyQuery(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', ''],
                ['offset', 0, 0],
                ['limit', 25, 25],
                ['_search', [], []],
            ]);

        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $result = $this->controller->search();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(0, $result->getData()['total']);
    }

    public function testSearchFormatsResultsCorrectly(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['query', '', 'test'],
                ['offset', 0, 0],
                ['limit', 25, 25],
                ['_search', [], []],
            ]);

        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['id' => 'fallback-id'],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->search();

        $data = $result->getData();
        $item = $data['results'][0];
        $this->assertEquals('fallback-id', $item['id']);
        $this->assertEquals('Unknown', $item['name']);
        $this->assertEquals('object', $item['type']);
        $this->assertEquals('openregister', $item['source']);
    }
}
