<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ApplicationsController;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Service\ApplicationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApplicationsControllerTest extends TestCase
{
    private ApplicationsController $controller;
    private IRequest&MockObject $request;
    private ApplicationService&MockObject $applicationService;
    private ApplicationMapper&MockObject $applicationMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->applicationService = $this->createMock(ApplicationService::class);
        $this->applicationMapper = $this->createMock(ApplicationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ApplicationsController(
            'openregister',
            $this->request,
            $this->applicationService,
            $this->applicationMapper,
            $this->logger
        );
    }

    public function testPage(): void
    {
        $result = $this->controller->page();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testIndexSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->applicationMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
    }

    public function testIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_offset' => '5',
        ]);
        $this->applicationMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testIndexWithPage(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '2',
        ]);
        $this->applicationMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testIndexException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->applicationMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to retrieve applications', $data['error']);
    }

    public function testShowSuccess(): void
    {
        $app = new Application();
        $this->applicationService->method('find')->willReturn($app);

        $result = $this->controller->show(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->applicationService->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Application not found', $data['error']);
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Test App']);
        $app = new Application();
        $this->applicationService->method('create')->willReturn($app);

        $result = $this->controller->create();

        $this->assertEquals(Http::STATUS_CREATED, $result->getStatus());
    }

    public function testCreateException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->applicationService->method('create')
            ->willThrowException(new \Exception('Validation error'));

        $result = $this->controller->create();

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Failed to create application', $data['error']);
    }

    public function testUpdateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $app = new Application();
        $this->applicationService->method('update')->willReturn($app);

        $result = $this->controller->update(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->applicationService->method('update')
            ->willThrowException(new \Exception('Update failed'));

        $result = $this->controller->update(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $app = new Application();
        $this->applicationService->method('update')->willReturn($app);

        $result = $this->controller->patch(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Application deleted successfully', $data['message']);
    }

    public function testDestroyException(): void
    {
        $this->applicationService->method('delete')
            ->willThrowException(new \Exception('Delete failed'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to delete application', $data['error']);
    }
}
