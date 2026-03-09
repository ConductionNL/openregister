<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ConfigurationsController;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigurationsControllerTest extends TestCase
{
    private ConfigurationsController $controller;
    private IRequest&MockObject $request;
    private ConfigurationMapper&MockObject $configurationMapper;
    private ConfigurationService&MockObject $configurationService;
    private UploadService&MockObject $uploadService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->uploadService = $this->createMock(UploadService::class);

        $this->controller = new ConfigurationsController(
            'openregister',
            $this->request,
            $this->configurationMapper,
            $this->configurationService,
            $this->uploadService,
            'admin'
        );
    }

    public function testIndexSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configurationMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $this->assertArrayHasKey('results', $result->getData());
    }

    public function testShowSuccess(): void
    {
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper->method('find')->willReturn($config);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new \Exception('not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'title' => 'Test',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper->method('createFromArray')->willReturn($config);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateSetsDefaultSourceType(): void
    {
        $this->request->method('getParams')->willReturn([
            'title' => 'Test',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return $data['sourceType'] === 'local' && $data['isLocal'] === true;
            }))
            ->willReturn($config);

        $this->controller->create();
    }

    public function testCreateException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configurationMapper->method('createFromArray')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testUpdateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper->method('updateFromArray')->willReturn($config);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateRemovesImmutableFields(): void
    {
        $this->request->method('getParams')->willReturn([
            'id' => 999,
            'organisation' => 'hacked',
            'owner' => 'hacked',
            'created' => '2020-01-01',
            'title' => 'Updated',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->with(1, $this->callback(function ($data) {
                return !isset($data['id'])
                    && !isset($data['organisation'])
                    && !isset($data['owner'])
                    && !isset($data['created'])
                    && $data['title'] === 'Updated';
            }))
            ->willReturn($config);

        $this->controller->update(1);
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper->method('updateFromArray')->willReturn($config);

        $result = $this->controller->patch(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper->method('find')->willReturn($config);

        $result = $this->controller->destroy(1);

        $this->assertEquals(204, $result->getStatus());
    }

    public function testDestroyException(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new \Exception('not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(400, $result->getStatus());
    }

    public function testExportSuccess(): void
    {
        $config = new \OCA\OpenRegister\Db\Configuration();
        $ref = new \ReflectionClass($config);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($config, 1);
        $config->setTitle('TestConfig');
        $this->configurationMapper->method('find')->willReturn($config);
        $this->configurationService->method('exportConfig')->willReturn(['data' => 'test']);

        $result = $this->controller->export(1);

        // Returns DataDownloadResponse on success
        $this->assertNotNull($result);
    }
}
