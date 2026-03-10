<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SourcesController;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SourcesController
 *
 * @package Unit\Controller
 */
class SourcesControllerTest extends TestCase
{
    private SourcesController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private SourceMapper&MockObject $sourceMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->sourceMapper = $this->createMock(SourceMapper::class);

        $this->controller = new SourcesController(
            'openregister',
            $this->request,
            $this->config,
            $this->sourceMapper
        );
    }

    public function testIndexReturnsSources(): void
    {
        $source = $this->createMock(Source::class);
        $this->request->method('getParams')->willReturn([]);
        $this->sourceMapper->method('findAll')->willReturn([$source]);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
    }

    public function testIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_offset' => '5',
        ]);
        $this->sourceMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexWithPagePagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '3',
        ]);
        $this->sourceMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testShowReturnsSource(): void
    {
        $source = $this->createMock(Source::class);
        $this->sourceMapper->method('find')->willReturn($source);

        $result = $this->controller->show('1');

        $this->assertSame(200, $result->getStatus());
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $this->sourceMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show('999');

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Not Found', $data['error']);
    }

    public function testCreateReturnsCreatedSource(): void
    {
        $source = $this->createMock(Source::class);
        $this->request->method('getParams')->willReturn(['name' => 'test source']);
        $this->sourceMapper->method('createFromArray')->willReturn($source);

        $result = $this->controller->create();

        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateRemovesInternalParams(): void
    {
        $source = $this->createMock(Source::class);
        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            'id' => 5,
            'name' => 'test',
        ]);
        $this->sourceMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route']) && !isset($data['id']) && isset($data['name']);
            }))
            ->willReturn($source);

        $this->controller->create();
    }

    public function testUpdateReturnsUpdatedSource(): void
    {
        $source = $this->createMock(Source::class);
        $this->request->method('getParams')->willReturn(['name' => 'updated']);
        $this->sourceMapper->method('updateFromArray')->willReturn($source);

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateRemovesImmutableFields(): void
    {
        $source = $this->createMock(Source::class);
        $this->request->method('getParams')->willReturn([
            'id' => 1,
            'organisation' => 'org1',
            'owner' => 'user1',
            'created' => '2024-01-01',
            'name' => 'updated',
        ]);
        $this->sourceMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
                $this->callback(function ($data) {
                    return !isset($data['id'])
                        && !isset($data['organisation'])
                        && !isset($data['owner'])
                        && !isset($data['created'])
                        && isset($data['name']);
                })
            )
            ->willReturn($source);

        $this->controller->update(1);
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $source = $this->createMock(Source::class);
        $this->request->method('getParams')->willReturn(['name' => 'patched']);
        $this->sourceMapper->method('updateFromArray')->willReturn($source);

        $result = $this->controller->patch(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyReturnsEmptyOnSuccess(): void
    {
        $source = $this->createMock(Source::class);
        $this->sourceMapper->method('find')->willReturn($source);

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame([], $result->getData());
    }
}
