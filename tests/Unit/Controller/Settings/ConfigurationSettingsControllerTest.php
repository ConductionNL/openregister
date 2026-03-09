<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\ConfigurationSettingsController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigurationSettingsControllerTest extends TestCase
{
    private ConfigurationSettingsController $controller;
    private IRequest&MockObject $request;
    private SettingsService&MockObject $settingsService;
    private IndexService&MockObject $indexService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ConfigurationSettingsController(
            'openregister',
            $this->request,
            $this->settingsService,
            $this->indexService,
            $this->logger
        );
    }

    /**
     * @dataProvider settingsGetterProvider
     */
    public function testGetSettingsSuccess(string $method, string $serviceMethod): void
    {
        $data = ['setting1' => 'value1'];
        $this->settingsService->method($serviceMethod)->willReturn($data);

        $result = $this->controller->$method();

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * @dataProvider settingsGetterProvider
     */
    public function testGetSettingsException(string $method, string $serviceMethod): void
    {
        $this->settingsService->method($serviceMethod)
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->$method();

        $this->assertEquals(500, $result->getStatus());
    }

    public static function settingsGetterProvider(): array
    {
        return [
            'rbac' => ['getRbacSettings', 'getRbacSettingsOnly'],
            'organisation' => ['getOrganisationSettings', 'getOrganisationSettingsOnly'],
            'multitenancy' => ['getMultitenancySettings', 'getMultitenancySettingsOnly'],
            'retention' => ['getRetentionSettings', 'getRetentionSettingsOnly'],
        ];
    }

    /**
     * @dataProvider settingsUpdaterProvider
     */
    public function testUpdateSettingsSuccess(string $method, string $serviceMethod): void
    {
        $this->request->method('getParams')->willReturn(['key' => 'value']);
        $this->settingsService->method($serviceMethod)->willReturn(['updated' => true]);

        $result = $this->controller->$method();

        $this->assertEquals(200, $result->getStatus());
    }

    /**
     * @dataProvider settingsUpdaterProvider
     */
    public function testUpdateSettingsException(string $method, string $serviceMethod): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method($serviceMethod)
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->$method();

        $this->assertEquals(500, $result->getStatus());
    }

    public static function settingsUpdaterProvider(): array
    {
        return [
            'rbac' => ['updateRbacSettings', 'updateRbacSettingsOnly'],
            'organisation' => ['updateOrganisationSettings', 'updateOrganisationSettingsOnly'],
            'multitenancy' => ['updateMultitenancySettings', 'updateMultitenancySettingsOnly'],
            'retention' => ['updateRetentionSettings', 'updateRetentionSettingsOnly'],
        ];
    }

    public function testGetObjectSettingsSuccess(): void
    {
        $this->settingsService->method('getObjectSettingsOnly')
            ->willReturn(['maxSize' => 1024]);

        $result = $this->controller->getObjectSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testGetObjectSettingsException(): void
    {
        $this->settingsService->method('getObjectSettingsOnly')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getObjectSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testUpdateObjectSettingsSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['key' => 'value']);
        $this->settingsService->method('updateObjectSettingsOnly')
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateObjectSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testUpdateObjectSettingsExtractsProviderId(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider' => ['id' => 'solr', 'name' => 'SOLR'],
        ]);
        $this->settingsService->expects($this->once())
            ->method('updateObjectSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['provider'] === 'solr';
            }))
            ->willReturn(['updated' => true]);

        $this->controller->updateObjectSettings();
    }

    public function testUpdateObjectSettingsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateObjectSettingsOnly')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->updateObjectSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testPatchObjectSettingsDelegatesToUpdate(): void
    {
        $this->request->method('getParams')->willReturn(['key' => 'value']);
        $this->settingsService->method('updateObjectSettingsOnly')
            ->willReturn(['updated' => true]);

        $result = $this->controller->patchObjectSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testGetObjectCollectionFieldsSuccess(): void
    {
        $this->indexService->method('getObjectCollectionFieldStatus')
            ->willReturn(['missing' => [], 'extra' => []]);

        $result = $this->controller->getObjectCollectionFields();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('objects', $result->getData()['collection']);
    }

    public function testGetObjectCollectionFieldsException(): void
    {
        $this->indexService->method('getObjectCollectionFieldStatus')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->getObjectCollectionFields();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testCreateMissingObjectFieldsNoCollection(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willReturn(['objectCollection' => '']);

        $result = $this->controller->createMissingObjectFields();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }
}
