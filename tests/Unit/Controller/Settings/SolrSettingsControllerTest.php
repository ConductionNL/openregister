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
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Failed', $data['error']);
    }

    public function testGetSolrInfoCollectionListingFailure(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'listCollections'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('listCollections')
            ->willThrowException(new \Exception('Collection listing failed'));
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrInfo();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['solr']['available']);
        $this->assertEmpty($data['solr']['collections']);
        $this->assertNull($data['solr']['error']);
    }

    public function testGetSolrInfoCollectionWithDefaults(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'listCollections'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('listCollections')->willReturn([
            ['name' => 'test_collection'],
        ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrInfo();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $collections = $data['solr']['collections'];
        $this->assertCount(1, $collections);
        $this->assertEquals('test_collection', $collections[0]['id']);
        $this->assertEquals('test_collection', $collections[0]['name']);
        $this->assertEquals(0, $collections[0]['documentCount']);
        $this->assertEquals(0, $collections[0]['shards']);
        $this->assertEquals('unknown', $collections[0]['health']);
    }

    public function testGetSolrInfoMultipleCollections(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'listCollections'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('listCollections')->willReturn([
            ['name' => 'col1', 'documentCount' => 50, 'shards' => 2, 'health' => 'green'],
            ['name' => 'col2', 'documentCount' => 200, 'shards' => 1, 'health' => 'yellow'],
        ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrInfo();

        $data = $result->getData();
        $this->assertCount(2, $data['solr']['collections']);
        $this->assertEquals(50, $data['solr']['collections'][0]['documentCount']);
        $this->assertEquals('yellow', $data['solr']['collections'][1]['health']);
    }

    public function testGetSolrFacetConfigWithDiscoverySuccess(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn([
                '@self' => [
                    'type' => [
                        'category' => 'metadata',
                        'displayName' => 'Type',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['select'],
                    ],
                ],
                'object_fields' => [
                    'title' => [
                        'category' => 'object',
                        'displayName' => 'Title',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['checkbox'],
                    ],
                ],
            ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn(['facets' => [], 'default_settings' => ['show_count' => true, 'show_empty' => false, 'max_items' => 10]]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('facets', $data);
        $this->assertArrayHasKey('@self', $data['facets']);
        $this->assertArrayHasKey('object_fields', $data['facets']);

        // Check @self facet config defaults
        $selfFacet = $data['facets']['@self']['type'];
        $this->assertTrue($selfFacet['config']['enabled']);
        $this->assertEquals('Type', $selfFacet['config']['title']);
        $this->assertEquals(0, $selfFacet['config']['order']);
        $this->assertEquals(10, $selfFacet['config']['maxItems']);
        $this->assertEquals('terms', $selfFacet['config']['facetType']);
        $this->assertEquals('select', $selfFacet['config']['displayType']);
        $this->assertTrue($selfFacet['config']['showCount']);

        // Check object_fields facet config defaults
        $objFacet = $data['facets']['object_fields']['title'];
        $this->assertFalse($objFacet['config']['enabled']);
        $this->assertEquals('Title', $objFacet['config']['title']);
        $this->assertEquals(100, $objFacet['config']['order']);
        $this->assertEquals('checkbox', $objFacet['config']['displayType']);
    }

    public function testGetSolrFacetConfigWithDiscoveryMergesExistingConfig(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn([
                '@self' => [
                    'status' => [
                        'category' => 'metadata',
                        'displayName' => 'Status',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['select'],
                    ],
                ],
                'object_fields' => [
                    'category' => [
                        'category' => 'object',
                        'displayName' => 'Category',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['select'],
                    ],
                ],
            ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        // Existing configuration that should be merged
        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn([
                'facets' => [
                    'self_status' => [
                        'enabled' => false,
                        'title' => 'Custom Status Title',
                        'description' => 'Custom description',
                        'order' => 5,
                        'facetType' => 'range',
                        'displayType' => 'checkbox',
                        'showCount' => false,
                        'maxItems' => 20,
                    ],
                    'category' => [
                        'enabled' => true,
                        'title' => 'Custom Category',
                        'description' => 'Category desc',
                        'order' => 2,
                        'facetType' => 'multi',
                        'displayType' => 'tags',
                        'showCount' => true,
                        'maxItems' => 5,
                    ],
                ],
            ]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();

        // Check that existing config is used for @self
        $selfFacet = $data['facets']['@self']['status'];
        $this->assertFalse($selfFacet['config']['enabled']);
        $this->assertEquals('Custom Status Title', $selfFacet['config']['title']);
        $this->assertEquals('Custom description', $selfFacet['config']['description']);
        $this->assertEquals(5, $selfFacet['config']['order']);
        $this->assertEquals('range', $selfFacet['config']['facetType']);
        $this->assertEquals('checkbox', $selfFacet['config']['displayType']);
        $this->assertFalse($selfFacet['config']['showCount']);
        $this->assertEquals(20, $selfFacet['config']['maxItems']);

        // Check that existing config is used for object_fields
        $objFacet = $data['facets']['object_fields']['category'];
        $this->assertTrue($objFacet['config']['enabled']);
        $this->assertEquals('Custom Category', $objFacet['config']['title']);
        $this->assertEquals('multi', $objFacet['config']['facetType']);
        $this->assertEquals('tags', $objFacet['config']['displayType']);
        $this->assertEquals(5, $objFacet['config']['maxItems']);
    }

    public function testGetSolrFacetConfigWithDiscoveryNoDefaultSettings(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn(['@self' => [], 'object_fields' => []]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        // No default_settings key in returned config
        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn(['facets' => []]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        // Should fall back to defaults
        $this->assertEquals(['show_count' => true, 'show_empty' => false, 'max_items' => 10], $data['global_settings']);
    }

    public function testGetSolrFacetConfigWithDiscoveryException(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Service error'));

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Service error', $data['error']);
    }

    public function testGetSolrFacetConfigWithDiscoveryEmptyDiscoveredFacets(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn([]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn(['facets' => []]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEmpty($data['facets']['@self']);
        $this->assertEmpty($data['facets']['object_fields']);
    }

    public function testGetSolrFacetConfigWithDiscoveryMultipleSelfFacets(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn([
                '@self' => [
                    'type' => [
                        'displayName' => 'Type',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['select'],
                    ],
                    'status' => [
                        'displayName' => 'Status',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['checkbox'],
                    ],
                    'register' => [
                        'displayName' => 'Register',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['radio'],
                    ],
                ],
                'object_fields' => [],
            ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn(['facets' => []]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $data = $result->getData();
        // Verify ordering increments
        $this->assertEquals(0, $data['facets']['@self']['type']['config']['order']);
        $this->assertEquals(1, $data['facets']['@self']['status']['config']['order']);
        $this->assertEquals(2, $data['facets']['@self']['register']['config']['order']);
    }

    public function testGetSolrFacetConfigWithDiscoveryMultipleObjectFields(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn([
                '@self' => [],
                'object_fields' => [
                    'name' => [
                        'displayName' => 'Name',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['select'],
                    ],
                    'date' => [
                        'displayName' => 'Date',
                        'suggestedFacetType' => 'range',
                        'suggestedDisplayTypes' => ['datepicker'],
                    ],
                ],
            ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn(['facets' => []]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $data = $result->getData();
        // Object fields order starts at 100
        $this->assertEquals(100, $data['facets']['object_fields']['name']['config']['order']);
        $this->assertEquals(101, $data['facets']['object_fields']['date']['config']['order']);
        $this->assertEquals('range', $data['facets']['object_fields']['date']['config']['facetType']);
        $this->assertEquals('datepicker', $data['facets']['object_fields']['date']['config']['displayType']);
    }

    public function testGetSolrFacetConfigWithDiscoveryExistingConfigWithUnderscoreKeys(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn([
                '@self' => [
                    'type' => [
                        'displayName' => 'Type',
                        'suggestedFacetType' => 'terms',
                        'suggestedDisplayTypes' => ['select'],
                    ],
                ],
                'object_fields' => [],
            ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        // Use underscore-style keys (facet_type instead of facetType)
        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn([
                'facets' => [
                    'self_type' => [
                        'enabled' => true,
                        'title' => 'Custom',
                        'facet_type' => 'multi_select',
                        'display_type' => 'tags',
                        'show_count' => false,
                        'max_items' => 25,
                    ],
                ],
            ]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $data = $result->getData();
        $selfFacet = $data['facets']['@self']['type'];
        $this->assertEquals('multi_select', $selfFacet['config']['facetType']);
        $this->assertEquals('tags', $selfFacet['config']['displayType']);
        $this->assertFalse($selfFacet['config']['showCount']);
        $this->assertEquals(25, $selfFacet['config']['maxItems']);
    }

    public function testGetSolrFacetConfigWithDiscoveryFacetWithNoSuggestions(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn([
                '@self' => [
                    'minimal' => [],
                ],
                'object_fields' => [
                    'bare_field' => [],
                ],
            ]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn(['facets' => []]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $data = $result->getData();
        // Should use defaults when no suggestions present
        $selfFacet = $data['facets']['@self']['minimal'];
        $this->assertEquals('minimal', $selfFacet['config']['title']);
        $this->assertEquals('terms', $selfFacet['config']['facetType']);
        $this->assertEquals('select', $selfFacet['config']['displayType']);

        $objFacet = $data['facets']['object_fields']['bare_field'];
        $this->assertEquals('bare_field', $objFacet['config']['title']);
        $this->assertEquals('terms', $objFacet['config']['facetType']);
        $this->assertEquals('select', $objFacet['config']['displayType']);
    }

    public function testGetSolrSettingsReturnsExactData(): void
    {
        $data = [
            'enabled' => true,
            'host' => 'solr.example.com',
            'port' => 8983,
            'core' => 'openregister',
        ];
        $this->settingsService->method('getSolrSettingsOnly')->willReturn($data);

        $result = $this->controller->getSolrSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    public function testGetSolrSettingsExceptionContainsMessage(): void
    {
        $this->settingsService->method('getSolrSettingsOnly')
            ->willThrowException(new \Exception('Connection refused'));

        $result = $this->controller->getSolrSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals('Connection refused', $result->getData()['error']);
    }

    public function testUpdateSolrSettingsPassesParamsToService(): void
    {
        $params = ['host' => 'new-host', 'port' => 9999];
        $this->request->method('getParams')->willReturn($params);
        $this->settingsService->expects($this->once())
            ->method('updateSolrSettingsOnly')
            ->with($params)
            ->willReturn(['saved' => true]);

        $result = $this->controller->updateSolrSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(['saved' => true], $result->getData());
    }

    public function testUpdateSolrSettingsExceptionContainsMessage(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateSolrSettingsOnly')
            ->willThrowException(new \Exception('Invalid host'));

        $result = $this->controller->updateSolrSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals('Invalid host', $result->getData()['error']);
    }

    public function testGetSolrFacetConfigurationReturnsData(): void
    {
        $facetData = ['facets' => ['type' => ['enabled' => true]], 'default_settings' => ['max_items' => 10]];
        $this->settingsService->method('getSolrFacetConfiguration')->willReturn($facetData);

        $result = $this->controller->getSolrFacetConfiguration();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($facetData, $result->getData());
    }

    public function testUpdateSolrFacetConfigurationPassesParams(): void
    {
        $params = ['facets' => ['status' => ['enabled' => false]]];
        $this->request->method('getParams')->willReturn($params);
        $this->settingsService->expects($this->once())
            ->method('updateSolrFacetConfiguration')
            ->with($params)
            ->willReturn(['updated' => true]);

        $result = $this->controller->updateSolrFacetConfiguration();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDiscoverSolrFacetsExceptionMessage(): void
    {
        $this->container->method('get')
            ->willThrowException(new \Exception('Solr connection timeout'));

        $result = $this->controller->discoverSolrFacets();

        $this->assertEquals(422, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Solr connection timeout', $data['message']);
        $this->assertEmpty($data['facets']);
    }

    public function testUpdateSolrFacetConfigWithDiscoveryPassesParams(): void
    {
        $params = ['facets' => ['type' => ['enabled' => true]], 'global' => ['max_items' => 20]];
        $this->request->method('getParams')->willReturn($params);
        $this->settingsService->expects($this->once())
            ->method('updateSolrFacetConfiguration')
            ->with($params)
            ->willReturn(['saved' => true]);

        $result = $this->controller->updateSolrFacetConfigWithDiscovery();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['saved' => true], $data['config']);
    }

    public function testGetSolrInfoStructure(): void
    {
        $mockIndexService = $this->createMock(IndexService::class);
        $mockIndexService->method('isAvailable')->willReturn(false);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrInfo();

        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('solr', $data);
        $this->assertArrayHasKey('available', $data['solr']);
        $this->assertArrayHasKey('version', $data['solr']);
        $this->assertArrayHasKey('vectorSupport', $data['solr']);
        $this->assertArrayHasKey('collections', $data['solr']);
        $this->assertArrayHasKey('error', $data['solr']);
        $this->assertEquals('Unknown', $data['solr']['version']);
        $this->assertFalse($data['solr']['vectorSupport']);
    }

    public function testGetSolrInfoAvailableStructure(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'listCollections'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('listCollections')->willReturn([]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $result = $this->controller->getSolrInfo();

        $data = $result->getData();
        $this->assertTrue($data['solr']['available']);
        $this->assertEquals('9.x (detection pending)', $data['solr']['version']);
        $this->assertFalse($data['solr']['vectorSupport']);
        $this->assertEmpty($data['solr']['collections']);
        $this->assertNull($data['solr']['error']);
    }

    public function testGetSolrFacetConfigWithDiscoveryGlobalSettingsFromExisting(): void
    {
        $mockIndexService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['isAvailable', 'getRawSolrFieldsForFacetConfiguration'])
            ->getMock();
        $mockIndexService->method('isAvailable')->willReturn(true);
        $mockIndexService->method('getRawSolrFieldsForFacetConfiguration')
            ->willReturn(['@self' => [], 'object_fields' => []]);
        $this->container->method('get')
            ->with(IndexService::class)
            ->willReturn($mockIndexService);

        $customSettings = ['show_count' => false, 'show_empty' => true, 'max_items' => 50];
        $this->settingsService->method('getSolrFacetConfiguration')
            ->willReturn(['facets' => [], 'default_settings' => $customSettings]);

        $result = $this->controller->getSolrFacetConfigWithDiscovery();

        $data = $result->getData();
        $this->assertEquals($customSettings, $data['global_settings']);
    }
}
