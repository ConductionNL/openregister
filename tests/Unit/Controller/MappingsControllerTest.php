<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\MappingsController;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MappingsController
 *
 * @package Unit\Controller
 */
class MappingsControllerTest extends TestCase
{
    private MappingsController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private MappingMapper&MockObject $mappingMapper;
    private MappingService&MockObject $mappingService;
    private OrganisationService&MockObject $organisationService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->mappingMapper = $this->createMock(MappingMapper::class);
        $this->mappingService = $this->createMock(MappingService::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new MappingsController(
            'openregister',
            $this->request,
            $this->config,
            $this->mappingMapper,
            $this->mappingService,
            $this->organisationService,
            $this->logger
        );
    }

    public function testIndexReturnsResults(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $mapping->method('jsonSerialize')->willReturn(['id' => 1, 'name' => 'test']);

        $this->request->method('getParams')->willReturn([]);
        $this->mappingMapper->method('findAll')->willReturn([$mapping]);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);
    }

    public function testIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_offset' => '5',
        ]);
        $this->mappingMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(10),
                $this->equalTo(5)
            )
            ->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexWithPagePagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '3',
        ]);
        $this->mappingMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(10),
                $this->equalTo(20) // (3-1)*10
            )
            ->willReturn([]);

        $this->controller->index();
    }

    public function testShowReturnsMapping(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $mapping->method('jsonSerialize')->willReturn(['id' => 1, 'name' => 'test']);

        $this->mappingMapper->method('find')->willReturn($mapping);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(1, $data['id']);
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $this->mappingMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testShowReturns500OnException(): void
    {
        $this->mappingMapper->method('find')
            ->willThrowException(new Exception('Database error'));

        $result = $this->controller->show(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testCreateReturnsCreatedMapping(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $mapping->method('jsonSerialize')->willReturn(['id' => 1, 'name' => 'new']);

        $this->request->method('getParams')->willReturn(['name' => 'new']);
        $this->mappingMapper->method('createFromArray')->willReturn($mapping);

        $result = $this->controller->create();

        $this->assertSame(201, $result->getStatus());
    }

    public function testCreateRemovesIdFromData(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $mapping->method('jsonSerialize')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn(['id' => 5, 'name' => 'test']);
        $this->mappingMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['id']);
            }))
            ->willReturn($mapping);

        $this->controller->create();
    }

    public function testCreateRemovesInternalParams(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $mapping->method('jsonSerialize')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn(['_route' => 'test', 'name' => 'test']);
        $this->mappingMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route']) && isset($data['name']);
            }))
            ->willReturn($mapping);

        $this->controller->create();
    }

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'fail']);
        $this->mappingMapper->method('createFromArray')
            ->willThrowException(new Exception('Create failed'));

        $result = $this->controller->create();

        $this->assertSame(500, $result->getStatus());
    }

    public function testUpdateReturnsUpdatedMapping(): void
    {
        $mapping = $this->createMock(Mapping::class);
        $mapping->method('jsonSerialize')->willReturn(['id' => 1, 'name' => 'updated']);

        $this->request->method('getParams')->willReturn(['name' => 'updated']);
        $this->mappingMapper->method('updateFromArray')->willReturn($mapping);

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateReturns404WhenNotFound(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'updated']);
        $this->mappingMapper->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->update(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testUpdateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'fail']);
        $this->mappingMapper->method('updateFromArray')
            ->willThrowException(new Exception('Update failed'));

        $result = $this->controller->update(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testDestroyReturnsEmptyOnSuccess(): void
    {
        $mapping = $this->createMock(Mapping::class);

        $this->mappingMapper->method('find')->willReturn($mapping);
        $this->mappingMapper->expects($this->once())->method('delete');

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame([], $result->getData());
    }

    public function testDestroyReturns404WhenNotFound(): void
    {
        $this->mappingMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testDestroyReturns500OnException(): void
    {
        $this->mappingMapper->method('find')
            ->willThrowException(new Exception('Delete failed'));

        $result = $this->controller->destroy(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testTestReturnsBadRequestWhenMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->test();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testTestReturnsBadRequestWhenMissingMapping(): void
    {
        $this->request->method('getParams')->willReturn(['inputObject' => ['key' => 'val']]);

        $result = $this->controller->test();

        $this->assertSame(400, $result->getStatus());
    }

    public function testTestReturnsBadRequestWhenMissingInputObject(): void
    {
        $this->request->method('getParams')->willReturn(['mapping' => ['mapping' => []]]);

        $result = $this->controller->test();

        $this->assertSame(400, $result->getStatus());
    }

    public function testTestReturnsResultOnSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'inputObject' => ['key' => 'value'],
            'mapping' => ['mapping' => ['key' => '{{key}}']],
        ]);

        $this->mappingService->method('executeMapping')
            ->willReturn(['result' => 'mapped']);

        $result = $this->controller->test();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('resultObject', $data);
    }

    public function testTestReturns400OnMappingError(): void
    {
        $this->request->method('getParams')->willReturn([
            'inputObject' => ['key' => 'value'],
            'mapping' => ['mapping' => ['key' => '{{key}}']],
        ]);

        $this->mappingService->method('executeMapping')
            ->willThrowException(new Exception('Mapping error'));

        $result = $this->controller->test();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Mapping error', $data['error']);
    }
}
