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

    // ── create() sourceType / isLocal branches ──

    public function testCreateWithGithubSourceTypeSetsIsLocalFalse(): void
    {
        $this->request->method('getParams')->willReturn([
            'title'      => 'GH Config',
            'sourceType' => 'github',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return $data['sourceType'] === 'github' && $data['isLocal'] === false;
            }))
            ->willReturn($config);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateWithManualSourceTypeSetsIsLocalTrue(): void
    {
        $this->request->method('getParams')->willReturn([
            'title'      => 'Manual Config',
            'sourceType' => 'manual',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return $data['sourceType'] === 'manual' && $data['isLocal'] === true;
            }))
            ->willReturn($config);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateWithUnknownSourceTypeDefaultsIsLocalTrue(): void
    {
        // sourceType is something unknown and isLocal is not provided → defaults to true.
        $this->request->method('getParams')->willReturn([
            'title'      => 'Unknown Config',
            'sourceType' => 'exotic',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return $data['isLocal'] === true;
            }))
            ->willReturn($config);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateStripsInternalAndDataParams(): void
    {
        $this->request->method('getParams')->willReturn([
            '_route' => 'ignored',
            'data'   => 'also-ignored',
            'title'  => 'Kept',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return isset($data['title']) && !isset($data['_route']) && !isset($data['data']);
            }))
            ->willReturn($config);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    // ── update() sourceType enforcement branches ──

    public function testUpdateWithUrlSourceTypeSetsIsLocalFalse(): void
    {
        $this->request->method('getParams')->willReturn([
            'title'      => 'URL Config',
            'sourceType' => 'url',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->with(1, $this->callback(function ($data) {
                return $data['isLocal'] === false;
            }))
            ->willReturn($config);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateWithLocalSourceTypeSetsIsLocalTrue(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceType' => 'local',
        ]);
        $config = $this->createMock(\OCA\OpenRegister\Db\Configuration::class);
        $this->configurationMapper
            ->expects($this->once())
            ->method('updateFromArray')
            ->with(1, $this->callback(function ($data) {
                return $data['isLocal'] === true;
            }))
            ->willReturn($config);

        $this->controller->update(1);
    }

    public function testUpdateException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->configurationMapper->method('updateFromArray')
            ->willThrowException(new \Exception('Failed to update'));

        $result = $this->controller->update(1);

        $this->assertEquals(400, $result->getStatus());
        $this->assertStringContainsString('Failed to update configuration', $result->getData()['error']);
    }

    // ── export() ──

    public function testExportException(): void
    {
        $this->configurationMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->controller->export(999);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    // ── import() ──

    public function testImportFromUploadedFile(): void
    {
        $this->request->method('getUploadedFile')->willReturn(['tmp_name' => '/tmp/test.json']);
        $this->request->method('getParam')
            ->willReturnMap([
                ['appId', null, 'myapp'],
                ['owner', null, 'admin'],
                ['version', null, '1.0.0'],
                ['force', null, false],
            ]);
        $this->request->method('getParams')->willReturn([]);

        $jsonData = [
            'info' => ['title' => 'Test', 'description' => 'Desc', 'version' => '1.0.0'],
            'x-openregister' => ['app' => 'myapp'],
        ];
        $this->configurationService->method('getUploadedJson')->willReturn($jsonData);
        $this->configurationService->method('importFromJson')->willReturn(['schemas' => [], 'registers' => []]);

        $result = $this->controller->import();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Import successful', $data['message']);
        $this->assertArrayHasKey('imported', $data);
    }

    public function testImportReturnsJsonResponseFromGetUploadedJson(): void
    {
        // When getUploadedJson returns a JSONResponse (error), it should be returned directly.
        $this->request->method('getUploadedFile')->willReturn([]);
        $this->request->method('getParams')->willReturn([]);

        $errorResponse = new JSONResponse(['error' => 'No file provided'], 400);
        $this->configurationService->method('getUploadedJson')->willReturn($errorResponse);

        $result = $this->controller->import();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testImportException(): void
    {
        $this->request->method('getUploadedFile')->willReturn([]);
        $this->request->method('getParams')->willReturn([]);

        $this->configurationService->method('getUploadedJson')
            ->willThrowException(new \Exception('Parse error'));

        $result = $this->controller->import();

        $this->assertEquals(400, $result->getStatus());
        $this->assertStringContainsString('Failed to import', $result->getData()['error']);
    }
}
