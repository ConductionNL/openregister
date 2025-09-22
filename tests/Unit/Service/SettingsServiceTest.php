
<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Service\GuzzleSolrService;
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
    private IAppConfig $config;
    private IConfig $systemConfig;
    private IRequest $request;
    private ContainerInterface $container;
    private IAppManager $appManager;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private OrganisationMapper $organisationMapper;
    private AuditTrailMapper $auditTrailMapper;
    private SearchTrailMapper $searchTrailMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private SchemaCacheService $schemaCacheService;
    private SchemaFacetCacheService $schemaFacetCacheService;
    private ICacheFactory $cacheFactory;
    private GuzzleSolrService $guzzleSolrService;

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

        // Configure container to return services
        $this->container->expects($this->any())
            ->method('get')
            ->willReturnMap([
                [GuzzleSolrService::class, $this->guzzleSolrService],
                ['OCP\IDBConnection', $this->createMock(\OCP\IDBConnection::class)]
            ]);

        // Configure GuzzleSolrService mock
        $this->guzzleSolrService->expects($this->any())
            ->method('getTenantId')
            ->willReturn('test-tenant');

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
        // Mock GuzzleSolrService getDashboardStats method
        $this->guzzleSolrService->method('getDashboardStats')
            ->willReturn([
                'available' => true,
                'document_count' => 1000,
                'collection' => 'openregister',
                'health' => 'healthy'
            ]);

        $result = $this->settingsService->getSolrDashboardStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('available', $result);
        $this->assertTrue($result['available']);
    }

    /**
     * Test SOLR management operations (WILL BE MOVED TO GuzzleSolrService)
     */
    public function testManageSolr(): void
    {
        // Mock various operations
        $this->guzzleSolrService->method('clearIndex')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->manageSolr('clearIndex');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }
}