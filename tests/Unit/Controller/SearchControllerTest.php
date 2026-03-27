<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SearchController;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchControllerTest extends TestCase
{
    private SearchController $controller;
    private IRequest&MockObject $request;
    private IndexService&MockObject $indexService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->indexService = $this->createMock(IndexService::class);

        $this->controller = new SearchController(
            'openregister',
            $this->request,
            $this->indexService
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

        $this->indexService->method('searchObjects')->willReturn([
            'objects' => [
                [
                    'uuid' => 'obj-1',
                    'name' => 'Test Object',
                    'url' => '/objects/1',
                ],
            ],
            'total' => 1,
            'facets' => ['type' => ['object' => 1]],
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

        $this->indexService->method('searchObjects')->willReturn([
            'objects' => [],
            'total' => 0,
            'facets' => [],
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

        $this->indexService->method('searchObjects')->willReturn([
            'objects' => [
                ['id' => 'fallback-id'],
            ],
            'total' => 1,
            'facets' => [],
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
