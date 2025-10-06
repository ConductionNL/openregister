<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use PHPUnit\Framework\TestCase;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;

/**
 * Test class for SettingsService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class SettingsServiceTest extends TestCase
{
    private SettingsService $settingsService;
    private $config;
    private $systemConfig;
    private $request;
    private $container;
    private $appManager;
    private $groupManager;
    private $userManager;
    private $organisationMapper;
    private $auditTrailMapper;
    private $searchTrailMapper;
    private $objectEntityMapper;
    private $schemaCacheService;
    private $schemaFacetCacheService;
    private $cacheFactory;
    private $guzzleSolrService;
    private $objectCacheService;
    private $objectService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->config = $this->createMock(IAppConfig::class);
        $this->systemConfig = $this->createMock(IConfig::class);
        $this->request = $this->createMock(IRequest::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheService::class);
        $this->schemaFacetCacheService = $this->createMock(SchemaFacetCacheService::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->guzzleSolrService = $this->createMock(GuzzleSolrService::class);
        $this->objectCacheService = $this->createMock(ObjectCacheService::class);
        $this->objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        // Configure container to return services
        $this->container->expects($this->any())
            ->method('get')
            ->willReturnMap([
                [GuzzleSolrService::class, $this->guzzleSolrService],
                [ObjectCacheService::class, $this->objectCacheService],
                [ObjectService::class, $this->objectService],
                ['OCA\OpenRegister\Db\SchemaMapper', $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class)],
                ['OCP\IDBConnection', $this->createMock(\OCP\IDBConnection::class)]
            ]);

        // Configure GuzzleSolrService mock
        $this->guzzleSolrService->expects($this->any())
            ->method('getTenantId')
            ->willReturn('test-tenant');

        // Configure ObjectCacheService mock
        $this->objectCacheService->expects($this->any())
            ->method('getStats')
            ->willReturn([
                'name_cache_size' => 100,
                'name_hits' => 50,
                'name_misses' => 10
            ]);
            
        $this->objectCacheService->expects($this->any())
            ->method('clearCache')
            ->willReturnCallback(function() { return; });
            
        $this->objectCacheService->expects($this->any())
            ->method('clearNameCache')
            ->willReturnCallback(function() { return; });

        // Configure SchemaCacheService mock
        $this->schemaCacheService->expects($this->any())
            ->method('getCacheStatistics')
            ->willReturn([
                'total_entries' => 50,
                'hits' => 25,
                'misses' => 5
            ]);
            
        $this->schemaCacheService->expects($this->any())
            ->method('clearAllCaches')
            ->willReturnCallback(function() { return; });

        // Configure SchemaFacetCacheService mock
        $this->schemaFacetCacheService->expects($this->any())
            ->method('getCacheStatistics')
            ->willReturn([
                'total_entries' => 30,
                'hits' => 15,
                'misses' => 3
            ]);
            
        $this->schemaFacetCacheService->expects($this->any())
            ->method('clearAllCaches')
            ->willReturnCallback(function() { return; });

        // Configure ICacheFactory mock
        $distributedCache = $this->createMock(\OCP\ICache::class);
        $distributedCache->expects($this->any())
            ->method('clear')
            ->willReturnCallback(function() { return; });
            
        $this->cacheFactory->expects($this->any())
            ->method('createDistributed')
            ->willReturn($distributedCache);

        // Configure GroupManager mock
        $this->groupManager->expects($this->any())
            ->method('search')
            ->willReturn([]);

        // Configure UserManager mock
        $this->userManager->expects($this->any())
            ->method('search')
            ->willReturn([]);

        // Create SettingsService instance
        $this->settingsService = new SettingsService(
            $this->config,
            $this->systemConfig,
            $this->request,
            $this->container,
            $this->appManager,
            $this->groupManager,
            $this->userManager,
            $this->organisationMapper,
            $this->auditTrailMapper,
            $this->searchTrailMapper,
            $this->objectEntityMapper,
            $this->schemaCacheService,
            $this->schemaFacetCacheService,
            $this->cacheFactory
        );
    }

    /**
     * Test isOpenRegisterInstalled method
     */
    public function testIsOpenRegisterInstalled(): void
    {
        // Mock app manager
        $this->appManager->expects($this->once())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);
        
        $this->appManager->expects($this->once())
            ->method('getAppVersion')
            ->with('openregister')
            ->willReturn('1.0.0');

        $result = $this->settingsService->isOpenRegisterInstalled();

        $this->assertTrue($result);
    }

    /**
     * Test isOpenRegisterInstalled method with minimum version
     */
    public function testIsOpenRegisterInstalledWithMinVersion(): void
    {
        $minVersion = '1.0.0';

        // Mock app manager
        $this->appManager->expects($this->any())
            ->method('isInstalled')
            ->willReturn(true);
        $this->appManager->expects($this->any())
            ->method('getAppVersion')
            ->willReturn('2.0.0');

        $result = $this->settingsService->isOpenRegisterInstalled($minVersion);

        $this->assertTrue($result);
    }

    /**
     * Test isOpenRegisterEnabled method
     */
    public function testIsOpenRegisterEnabled(): void
    {
        // Mock app manager
        $this->appManager->expects($this->any())
            ->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $result = $this->settingsService->isOpenRegisterEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test isRbacEnabled method
     */
    public function testIsRbacEnabled(): void
    {
        // Mock config
        $this->config->expects($this->once())
            ->method('getValueString')
            ->with('openregister', 'rbac', '')
            ->willReturn('{"enabled":true}');

        $result = $this->settingsService->isRbacEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test isMultiTenancyEnabled method
     */
    public function testIsMultiTenancyEnabled(): void
    {
        // Mock config
        $this->config->expects($this->once())
            ->method('getValueString')
            ->with('openregister', 'multitenancy', '')
            ->willReturn('{"enabled":true}');

        $result = $this->settingsService->isMultiTenancyEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test getSettings method
     */
    public function testGetSettings(): void
    {
        // Mock config values
        $this->config->expects($this->any())
            ->method('getValueString')
            ->willReturnCallback(function($app, $key, $default) {
                $values = [
                    'rbac' => '{"enabled":true,"anonymousGroup":"public","defaultNewUserGroup":"viewer","defaultObjectOwner":"","adminOverride":true}',
                    'multitenancy' => '{"enabled":false,"defaultUserTenant":"","defaultObjectTenant":""}',
                    'retention' => '{"objectArchiveRetention":31536000000,"objectDeleteRetention":63072000000,"searchTrailRetention":2592000000,"createLogRetention":2592000000,"readLogRetention":86400000,"updateLogRetention":604800000,"deleteLogRetention":2592000000}',
                    'auto_publish_attachments' => 'true',
                    'auto_publish_objects' => 'false',
                    'use_old_style_publishing_view' => 'true'
                ];
                return $values[$key] ?? $default;
            });

        // Mock group manager
        $mockGroup = $this->createMock(\OCP\IGroup::class);
        $mockGroup->expects($this->any())
            ->method('getGID')
            ->willReturn('test-group');
        $mockGroup->expects($this->any())
            ->method('getDisplayName')
            ->willReturn('Test Group');
        
        $this->groupManager->expects($this->any())
            ->method('search')
            ->willReturn([$mockGroup]);

        // Mock organisation mapper
        $mockOrganisation = $this->getMockBuilder(\OCA\OpenRegister\Db\Organisation::class)
            ->addMethods(['getUuid', 'getName'])
            ->getMock();
        $mockOrganisation->expects($this->any())
            ->method('getUuid')
            ->willReturn('test-uuid');
        $mockOrganisation->expects($this->any())
            ->method('getName')
            ->willReturn('Test Organisation');
        
        $this->organisationMapper->expects($this->any())
            ->method('findAllWithUserCount')
            ->willReturn([$mockOrganisation]);

        // Mock user manager
        $mockUser = $this->createMock(\OCP\IUser::class);
        $mockUser->expects($this->any())
            ->method('getUID')
            ->willReturn('test-user');
        $mockUser->expects($this->any())
            ->method('getDisplayName')
            ->willReturn('Test User');
        
        $this->userManager->expects($this->any())
            ->method('search')
            ->willReturn([$mockUser]);

        $result = $this->settingsService->getSettings();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('rbac', $result);
        $this->assertArrayHasKey('multitenancy', $result);
        $this->assertArrayHasKey('retention', $result);
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableTenants', $result);
        $this->assertArrayHasKey('availableUsers', $result);
    }

    /**
     * Test updateSettings method
     */
    public function testUpdateSettings(): void
    {
        $data = [
            'rbac' => [
                'enabled' => true,
                'anonymousGroup' => 'public',
                'defaultNewUserGroup' => 'viewer',
                'defaultObjectOwner' => '',
                'adminOverride' => true
            ],
            'multitenancy' => [
                'enabled' => false,
                'defaultUserTenant' => '',
                'defaultObjectTenant' => ''
            ]
        ];

        // Mock config
        $this->config->expects($this->any())
            ->method('getValueString')
            ->willReturnCallback(function($app, $key, $default) {
                $values = [
                    'rbac' => '{"enabled":true,"anonymousGroup":"public","defaultNewUserGroup":"viewer","defaultObjectOwner":"","adminOverride":true}',
                    'multitenancy' => '{"enabled":false,"defaultUserTenant":"","defaultObjectTenant":""}',
                    'retention' => '{"objectArchiveRetention":31536000000,"objectDeleteRetention":63072000000,"searchTrailRetention":2592000000,"createLogRetention":2592000000,"readLogRetention":86400000,"updateLogRetention":604800000,"deleteLogRetention":2592000000}',
                    'auto_publish_attachments' => 'true',
                    'auto_publish_objects' => 'false',
                    'use_old_style_publishing_view' => 'true'
                ];
                return $values[$key] ?? $default;
            });
        
        $this->config->expects($this->exactly(2))
            ->method('setValueString')
            ->willReturn(true);

        // Mock group manager
        $mockGroup = $this->createMock(\OCP\IGroup::class);
        $mockGroup->expects($this->any())
            ->method('getGID')
            ->willReturn('test-group');
        $mockGroup->expects($this->any())
            ->method('getDisplayName')
            ->willReturn('Test Group');
        
        $this->groupManager->expects($this->any())
            ->method('search')
            ->willReturn([$mockGroup]);

        // Mock organisation mapper
        $mockOrganisation = $this->getMockBuilder(\OCA\OpenRegister\Db\Organisation::class)
            ->addMethods(['getUuid', 'getName'])
            ->getMock();
        $mockOrganisation->expects($this->any())
            ->method('getUuid')
            ->willReturn('test-uuid');
        $mockOrganisation->expects($this->any())
            ->method('getName')
            ->willReturn('Test Organisation');
        
        $this->organisationMapper->expects($this->any())
            ->method('findAllWithUserCount')
            ->willReturn([$mockOrganisation]);

        // Mock user manager
        $mockUser = $this->createMock(\OCP\IUser::class);
        $mockUser->expects($this->any())
            ->method('getUID')
            ->willReturn('test-user');
        $mockUser->expects($this->any())
            ->method('getDisplayName')
            ->willReturn('Test User');
        
        $this->userManager->expects($this->any())
            ->method('search')
            ->willReturn([$mockUser]);

        $result = $this->settingsService->updateSettings($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('rbac', $result);
        $this->assertArrayHasKey('multitenancy', $result);
    }

    /**
     * Test getPublishingOptions method
     */
    public function testGetPublishingOptions(): void
    {
        // Mock config values
        $this->config->expects($this->any())
            ->method('getValueString')
            ->willReturnCallback(function($app, $key, $default) {
                $values = [
                    'publishing' => '{"enabled":true,"auto_approve":false}',
                    'auto_publish_attachments' => 'true',
                    'auto_publish_objects' => 'false',
                    'use_old_style_publishing_view' => 'true'
                ];
                return $values[$key] ?? $default;
            });

        $result = $this->settingsService->getPublishingOptions();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('auto_publish_attachments', $result);
        $this->assertArrayHasKey('auto_publish_objects', $result);
        $this->assertArrayHasKey('use_old_style_publishing_view', $result);
    }

    /**
     * Test updatePublishingOptions method
     */
    public function testUpdatePublishingOptions(): void
    {
        $options = [
            'auto_publish_attachments' => 'true',
            'auto_publish_objects' => 'false'
        ];

        // Mock config
        $this->config->expects($this->exactly(2))
            ->method('setValueString')
            ->willReturn(true);
        
        $this->config->expects($this->exactly(2))
            ->method('getValueString')
            ->willReturn('true');

        $result = $this->settingsService->updatePublishingOptions($options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('auto_publish_attachments', $result);
        $this->assertArrayHasKey('auto_publish_objects', $result);
    }

    /**
     * Test getStats method
     */
    public function testGetStats(): void
    {
        // Mock database connection
        $db = $this->createMock(\OCP\IDBConnection::class);
        $this->container->expects($this->once())
            ->method('get')
            ->with('OCP\IDBConnection')
            ->willReturn($db);

        // Mock database query result
        $mockResult = $this->createMock(\OCP\DB\IResult::class);
        $mockResult->expects($this->any())
            ->method('fetch')
            ->willReturn([
                'total_objects' => 10,
                'total_size' => 1024,
                'without_owner' => 2,
                'without_organisation' => 1,
                'deleted_count' => 0,
                'deleted_size' => 0,
                'expired_count' => 0,
                'expired_size' => 0
            ]);
        $mockResult->expects($this->any())
            ->method('closeCursor')
            ->willReturn(true);
            
        $db->expects($this->any())
            ->method('executeQuery')
            ->willReturn($mockResult);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('sizes', $result);
    }
    
    /**
     * Test SOLR connection testing (WILL BE MOVED TO GuzzleSolrService)
     */
    public function testTestSolrConnection(): void
    {
        // Mock GuzzleSolrService testConnection method
        $this->guzzleSolrService->method('testConnection')
            ->willReturn([
                'success' => true,
                'message' => 'Connection successful',
                'components' => [
                    'solr' => ['success' => true],
                    'zookeeper' => ['success' => true]
                ]
            ]);

        $result = $this->settingsService->testSolrConnection();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test SOLR index warmup (WILL BE MOVED TO GuzzleSolrService)
     */
    public function testWarmupSolrIndex(): void
    {
        // Mock config to return SOLR enabled
        $this->config->expects($this->any())
            ->method('getValueString')
            ->willReturnCallback(function($app, $key, $default) {
                if ($key === 'solr') {
                    return '{"enabled":true,"host":"localhost","port":8983,"core":"openregister","username":"","password":"","ssl":false,"timeout":30}';
                }
                return $default;
            });

        // Mock GuzzleSolrService warmupIndex method
        $this->guzzleSolrService->method('warmupIndex')
            ->willReturn([
                'success' => true,
                'operations' => ['connection_test' => true, 'object_indexing' => true],
                'execution_time_ms' => 1500.0
            ]);

        $result = $this->settingsService->warmupSolrIndex(1000, 0, 'serial', false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting SOLR dashboard stats (WILL BE MOVED TO GuzzleSolrService)
     */
    public function testGetSolrDashboardStats(): void
    {
        // Mock ObjectCacheService getSolrDashboardStats method
        $this->objectCacheService->method('getSolrDashboardStats')
            ->willReturn([
                'available' => true,
                'document_count' => 1000,
                'collection' => 'openregister',
                'health' => 'healthy'
            ]);

        $result = $this->settingsService->getSolrDashboardStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('overview', $result);
    }

    /**
     * Test SOLR management operations (WILL BE MOVED TO GuzzleSolrService)
     */
    public function testManageSolr(): void
    {
        // Mock ObjectCacheService clearSolrIndexForDashboard method
        $this->objectCacheService->method('clearSolrIndexForDashboard')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->manageSolr('clear');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getCacheStats method
     */
    public function testGetCacheStats(): void
    {
        $result = $this->settingsService->getCacheStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('services', $result);
        // Just check that it's an array with expected structure
    }

    /**
     * Test clearCache method
     */
    public function testClearCache(): void
    {
        $result = $this->settingsService->clearCache('all', null, []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('totalCleared', $result);
    }

    /**
     * Test warmupNamesCache method
     */
    public function testWarmupNamesCache(): void
    {
        $result = $this->settingsService->warmupNamesCache();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test getSolrSettings method
     */
    public function testGetSolrSettings(): void
    {
        $expectedSettings = [
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'openregister'
        ];

        $this->config->method('getValueString')
            ->with('openregister', 'solr')
            ->willReturn(json_encode($expectedSettings));

        $result = $this->settingsService->getSolrSettings();

        $this->assertEquals($expectedSettings, $result);
    }

    /**
     * Test rebaseObjectsAndLogs method
     */
    public function testRebaseObjectsAndLogs(): void
    {
        // This test is skipped due to complex mocking requirements
        $this->markTestSkipped('Complex mocking required for rebaseObjectsAndLogs method');
    }

    /**
     * Test rebase method
     */
    public function testRebase(): void
    {
        // This test is skipped due to complex mocking requirements
        $this->markTestSkipped('Complex mocking required for rebase method');
    }

    /**
     * Test getSolrSettingsOnly method
     */
    public function testGetSolrSettingsOnly(): void
    {
        $expectedSettings = [
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'openregister',
            'enabled' => false,
            'path' => '/solr',
            'configSet' => '_default',
            'scheme' => 'http',
            'username' => 'solr',
            'password' => 'SolrRocks',
            'timeout' => 30,
            'autoCommit' => true,
            'commitWithin' => 1000,
            'enableLogging' => true,
            'zookeeperHosts' => 'zookeeper:2181',
            'zookeeperUsername' => '',
            'zookeeperPassword' => '',
            'collection' => 'openregister',
            'useCloud' => true,
            'tenantId' => 'test-tenant'
        ];

        $this->config->method('getValueString')
            ->with('openregister', 'solr')
            ->willReturn(json_encode($expectedSettings));

        $result = $this->settingsService->getSolrSettingsOnly();

        $this->assertEquals($expectedSettings, $result);
    }

    /**
     * Test updateSolrSettingsOnly method
     */
    public function testUpdateSolrSettingsOnly(): void
    {
        $settings = [
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'openregister',
            'enabled' => false,
            'path' => '/solr',
            'configSet' => '_default',
            'scheme' => 'http',
            'username' => 'solr',
            'password' => 'SolrRocks',
            'timeout' => 30,
            'autoCommit' => true,
            'commitWithin' => 1000,
            'enableLogging' => true,
            'zookeeperHosts' => 'zookeeper:2181',
            'zookeeperUsername' => '',
            'zookeeperPassword' => '',
            'collection' => 'openregister',
            'useCloud' => true,
            'tenantId' => 'test-tenant'
        ];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'solr', $this->isType('string'));

        $this->settingsService->updateSolrSettingsOnly($settings);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test getRbacSettingsOnly method
     */
    public function testGetRbacSettingsOnly(): void
    {
        $expectedSettings = [
            'enabled' => true,
            'anonymousGroup' => 'public',
            'defaultNewUserGroup' => 'viewer',
            'defaultObjectOwner' => '',
            'adminOverride' => true
        ];

        $this->config->method('getValueString')
            ->with('openregister', 'rbac')
            ->willReturn(json_encode($expectedSettings));

        $result = $this->settingsService->getRbacSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('rbac', $result);
        $this->assertArrayHasKey('availableGroups', $result);
        $this->assertArrayHasKey('availableUsers', $result);
        $this->assertEquals($expectedSettings, $result['rbac']);
    }

    /**
     * Test updateRbacSettingsOnly method
     */
    public function testUpdateRbacSettingsOnly(): void
    {
        $settings = [
            'enabled' => true,
            'anonymousGroups' => [],
            'defaultRole' => 'user',
            'enforceRbac' => true
        ];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'rbac', $this->isType('string'));

        $this->settingsService->updateRbacSettingsOnly($settings);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test getMultitenancySettings method
     */
    public function testGetMultitenancySettings(): void
    {
        $expectedSettings = [
            'multitenancy' => [
                'enabled' => false,
                'defaultUserTenant' => '',
                'defaultObjectTenant' => ''
            ],
            'availableTenants' => []
        ];

        $this->config->method('getValueString')
            ->with('openregister', 'multitenancy')
            ->willReturn(json_encode($expectedSettings));

        $result = $this->settingsService->getMultitenancySettings();

        $this->assertEquals($expectedSettings, $result);
    }

    /**
     * Test getMultitenancySettingsOnly method
     */
    public function testGetMultitenancySettingsOnly(): void
    {
        $expectedSettings = [
            'multitenancy' => [
                'enabled' => false,
                'defaultUserTenant' => '',
                'defaultObjectTenant' => ''
            ],
            'availableTenants' => []
        ];

        $this->config->method('getValueString')
            ->with('openregister', 'multitenancy')
            ->willReturn(json_encode($expectedSettings));

        $result = $this->settingsService->getMultitenancySettingsOnly();

        $this->assertEquals($expectedSettings, $result);
    }

    /**
     * Test updateMultitenancySettingsOnly method
     */
    public function testUpdateMultitenancySettingsOnly(): void
    {
        $settings = [
            'multitenancy' => [
                'enabled' => false,
                'defaultUserTenant' => '',
                'defaultObjectTenant' => ''
            ],
            'availableTenants' => []
        ];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'multitenancy', $this->isType('string'));

        $this->settingsService->updateMultitenancySettingsOnly($settings);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test getRetentionSettingsOnly method
     */
    public function testGetRetentionSettingsOnly(): void
    {
        $expectedSettings = [
            'objectArchiveRetention' => 31536000000,
            'objectDeleteRetention' => 63072000000,
            'searchTrailRetention' => 2592000000,
            'createLogRetention' => 2592000000,
            'readLogRetention' => 86400000,
            'updateLogRetention' => 604800000,
            'deleteLogRetention' => 2592000000
        ];

        $this->config->method('getValueString')
            ->with('openregister', 'retention')
            ->willReturn(json_encode($expectedSettings));

        $result = $this->settingsService->getRetentionSettingsOnly();

        $this->assertEquals($expectedSettings, $result);
    }

    /**
     * Test updateRetentionSettingsOnly method
     */
    public function testUpdateRetentionSettingsOnly(): void
    {
        $settings = [
            'objectArchiveRetention' => 31536000000,
            'objectDeleteRetention' => 63072000000,
            'searchTrailRetention' => 2592000000,
            'createLogRetention' => 2592000000,
            'readLogRetention' => 86400000,
            'updateLogRetention' => 604800000,
            'deleteLogRetention' => 2592000000
        ];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'retention', json_encode($settings), false, false);

        $this->settingsService->updateRetentionSettingsOnly($settings);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test getVersionInfoOnly method
     */
    public function testGetVersionInfoOnly(): void
    {
        $expectedInfo = [
            'appName' => 'Open Register',
            'appVersion' => '0.2.3'
        ];

        $this->config->method('getValueString')
            ->with('openregister', 'version_info')
            ->willReturn(json_encode($expectedInfo));

        $result = $this->settingsService->getVersionInfoOnly();

        $this->assertEquals($expectedInfo, $result);
    }
}