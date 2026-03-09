<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\SolrSettingsController;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class SolrSettingsControllerTest extends TestCase
{
    private SolrSettingsController $controller;
    private IRequest&MockObject $request;
    private SettingsService&MockObject $settingsService;
    private IndexService&MockObject $indexService;
    private ContainerInterface&MockObject $container;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SolrSettingsController(
            'openregister',
            $this->request,
            $this->settingsService,
            $this->indexService,
            $this->container,
            $this->logger
        );
    }

    public function testGetSolrSettingsSuccess(): void
    {
        $data = ['enabled' => true, 'host' => 'localhost'];
        $this->settingsService->method('getSolrSettingsOnly')->willReturn($data);

        $result = $this->controller->getSolrSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    public function testGetSolrSettingsException(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getSolrSettings();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateSolrSettingsSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['enabled' => true]);
        $this->settingsService->method('updateSolrSettingsOnly')
            ->willReturn(['enabled' => true]);

        $result = $this->controller->updateSolrSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateSolrSettingsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateSolrSettingsOnly')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->updateSolrSettings();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetSolrInfoSolrUnavailable(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('isAvailable')->willReturn(false);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrInfo();

        $this->assertEquals(200, $result->getStatus());
        $this->assertFalse($result->getData()['solr']['available']);
    }

    public function testGetSolrInfoSolrAvailable(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'listCollections'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('listCollections')->willReturn([
            ['name' => 'objects', 'documentCount' => 100],
        ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrInfo();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['solr']['available']);
        $this->assertCount(1, $data['solr']['collections']);
    }

    public function testGetSolrInfoException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Container error'));

        $result = $this->controller->getSolrInfo();

        // The controller has nested try-catch: inner catch handles container errors
        // and still returns 200 with available=false.
        $this->assertEquals(200, $result->getStatus());
        $this->assertFalse($result->getData()['solr']['available']);
    }

    public function testGetSolrDashboardStatsSuccess(): void
    {
        $stats = ['total_docs' => 5000];
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('getDashboardStats')->willReturn($stats);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrDashboardStats();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($stats, $result->getData());
    }

    public function testGetSolrDashboardStatsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getSolrDashboardStats();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetSolrFacetConfigurationSuccess(): void
    {
        $data = ['facets' => []];
        $this->settingsService->method('getSolrFacetConfiguration')->willReturn($data);

        $result = $this->controller->getSolrFacetConfiguration();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetSolrFacetConfigurationException(): void
    {
        $this->settingsService->method('getSolrFacetConfiguration')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getSolrFacetConfiguration();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateSolrFacetConfigurationSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['facets' => []]);
        $this->settingsService->method('updateSolrFacetConfiguration')
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateSolrFacetConfiguration();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateSolrFacetConfigurationException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateSolrFacetConfiguration')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->updateSolrFacetConfiguration();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDiscoverSolrFacetsSolrUnavailable(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('isAvailable')->willReturn(false);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->discoverSolrFacets();

        $this->assertEquals(422, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testDiscoverSolrFacetsSuccess(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn(['@self' => ['type' => []]]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->discoverSolrFacets();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testDiscoverSolrFacetsException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->discoverSolrFacets();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testGetSolrFacetConfigWithDiscoverySolrUnavailable(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('isAvailable')->willReturn(false);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $this->assertEquals(422, $result->getStatus());
    }

    public function testUpdateSolrFacetConfigWithDiscoverySuccess(): void
    {
        $this->request->method('getParams')->willReturn(['facets' => []]);
        $this->settingsService->method('updateSolrFacetConfiguration')
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateSolrFacetConfigWithDiscovery();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testUpdateSolrFacetConfigWithDiscoveryException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateSolrFacetConfiguration')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->updateSolrFacetConfigWithDiscovery();

        $this->assertEquals(500, $result->getStatus());
    }
}
