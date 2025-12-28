<?php

declare(strict_types=1);

/**
 * SettingsService Unit Tests
 *
 * Comprehensive unit tests for SettingsService before SOLR logic refactoring.
 * These tests ensure we maintain functionality during the three-phase refactoring.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for SettingsService
 *
 * Tests all public methods to ensure functionality is preserved during refactoring
 */
class SettingsServiceTest extends TestCase
{
    /** @var SettingsService */
    private SettingsService $settingsService;

    /** @var IConfig|MockObject */
    private $config;

    /** @var IAppConfig|MockObject */
    private $appConfig;

    /** @var IRequest|MockObject */
    private $request;

    /** @var IAppManager|MockObject */
    private $appManager;

    /** @var IGroupManager|MockObject */
    private $groupManager;

    /** @var IUserManager|MockObject */
    private $userManager;

    /** @var ContainerInterface|MockObject */
    private $container;

    /** @var GuzzleSolrService|MockObject */
    private $guzzleSolrService;

    /** @var OrganisationMapper|MockObject */
    private $organisationMapper;

    /** @var AuditTrailMapper|MockObject */
    private $auditTrailMapper;

    /** @var SearchTrailMapper|MockObject */
    private $searchTrailMapper;

    /** @var ObjectEntityMapper|MockObject */
    private $objectEntityMapper;

    /** @var ObjectService|MockObject */
    private $objectService;

    /** @var ObjectCacheService|MockObject */
    private $objectCacheService;

    /** @var SchemaCacheHandler|MockObject */
    private $schemaCacheService;

    /** @var SchemaFacetCacheService|MockObject */
    private $schemaFacetCacheService;

    /** @var ICacheFactory|MockObject */
    private $cacheFactory;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock all dependencies.
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->request = $this->createMock(IRequest::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->guzzleSolrService = $this->createMock(GuzzleSolrService::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectCacheService = $this->createMock(ObjectCacheService::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $this->schemaFacetCacheService = $this->createMock(SchemaFacetCacheService::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);

        // Create SettingsService instance.
        $this->settingsService = new SettingsService(
            $this->config,
            $this->appConfig,
            $this->request,
            $this->appManager,
            $this->groupManager,
            $this->userManager,
            $this->container,
            $this->guzzleSolrService,
            $this->organisationMapper,
            $this->auditTrailMapper,
            $this->searchTrailMapper,
            $this->objectEntityMapper,
            $this->objectService,
            $this->objectCacheService,
            $this->schemaCacheService,
            $this->schemaFacetCacheService,
            $this->cacheFactory
        );
    }

    /**
     * Test OpenRegister installation check
     */
    public function testIsOpenRegisterInstalled(): void
    {
        $this->appManager->method('isInstalled')
            ->with('openregister')
            ->willReturn(true);

        $result = $this->settingsService->isOpenRegisterInstalled();

        $this->assertTrue($result);
    }

    /**
     * Test OpenRegister enabled check
     */
    public function testIsOpenRegisterEnabled(): void
    {
        $this->appManager->method('isEnabledForUser')
            ->with('openregister')
            ->willReturn(true);

        $result = $this->settingsService->isOpenRegisterEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test RBAC enabled check
     */
    public function testIsRbacEnabled(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'rbac', '{}')
            ->willReturn('{"enabled": true}');

        $result = $this->settingsService->isRbacEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test multi-tenancy enabled check
     */
    public function testIsMultiTenancyEnabled(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'multitenancy', '{}')
            ->willReturn('{"enabled": true}');

        $result = $this->settingsService->isMultiTenancyEnabled();

        $this->assertTrue($result);
    }

    /**
     * Test getting general settings
     */
    public function testGetSettings(): void
    {
        // Mock various config calls that getSettings() makes.
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['openregister', 'solr', '{}', '{"enabled": true, "host": "localhost"}'],
                ['openregister', 'rbac', '{}', '{"enabled": false}'],
                ['openregister', 'multitenancy', '{}', '{"enabled": false}'],
                ['openregister', 'retention', '{}', '{"enabled": false}'],
                ['openregister', 'publishing', '{}', '{"enabled": true}']
            ]);

        $result = $this->settingsService->getSettings();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('solr', $result);
        $this->assertArrayHasKey('rbac', $result);
        $this->assertArrayHasKey('multitenancy', $result);
    }

    /**
     * Test updating settings
     */
    public function testUpdateSettings(): void
    {
        $settingsData = [
            'solr' => ['enabled' => true, 'host' => 'solr-server'],
            'rbac' => ['enabled' => true],
            'multitenancy' => ['enabled' => false]
        ];

        $this->config->expects($this->atLeastOnce())
            ->method('setAppValue')
            ->with('openregister', $this->anything(), $this->anything());

        $result = $this->settingsService->updateSettings($settingsData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting publishing options
     */
    public function testGetPublishingOptions(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'publishing', '{}')
            ->willReturn('{"enabled": true, "auto_publish": false}');

        $result = $this->settingsService->getPublishingOptions();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
    }

    /**
     * Test updating publishing options
     */
    public function testUpdatePublishingOptions(): void
    {
        $options = ['enabled' => true, 'auto_publish' => true];

        $this->config->expects($this->once())
            ->method('setAppValue')
            ->with('openregister', 'publishing', json_encode($options));

        $result = $this->settingsService->updatePublishingOptions($options);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting statistics
     */
    public function testGetStats(): void
    {
        // Mock the various mappers for statistics.
        $this->objectEntityMapper->method('countAll')
            ->willReturn(100);
        
        $this->auditTrailMapper->method('countAll')
            ->willReturn(50);
        
        $this->searchTrailMapper->method('countAll')
            ->willReturn(25);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertEquals(100, $result['objects']);
    }

    /**
     * Test getting cache statistics
     */
    public function testGetCacheStats(): void
    {
        $result = $this->settingsService->getCacheStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test clearing cache
     */
    public function testClearCache(): void
    {
        $result = $this->settingsService->clearCache('all');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test warming up names cache
     */
    public function testWarmupNamesCache(): void
    {
        $result = $this->settingsService->warmupNamesCache();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    // ===== SOLR-RELATED TESTS (These methods will be moved to GuzzleSolrService) =====.

    /**
     * Test getting SOLR settings
     */
    public function testGetSolrSettings(): void
    {
        $this->config->method('getValueString')
            ->with('openregister', 'solr', '')
            ->willReturn('{"enabled": true, "host": "localhost", "port": 8983}');

        $result = $this->settingsService->getSolrSettings();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('host', $result);
        $this->assertArrayHasKey('port', $result);
    }

    /**
     * Test SOLR connection testing (WILL BE MOVED TO GuzzleSolrService)
     */
    public function testTestSolrConnection(): void
    {
        // Mock GuzzleSolrService testConnection method.
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
        // Mock GuzzleSolrService warmupIndex method.
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
        // Mock GuzzleSolrService getDashboardStats method.
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
        // Mock various operations.
        $this->guzzleSolrService->method('clearIndex')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->manageSolr('clearIndex');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test SOLR connection for dashboard (WILL BE MOVED TO GuzzleSolrService)
     */
    public function testTestSolrConnectionForDashboard(): void
    {
        $this->guzzleSolrService->method('testConnection')
            ->willReturn([
                'success' => true,
                'message' => 'All tests passed',
                'components' => [
                    'solr' => ['success' => true],
                    'collection' => ['success' => true]
                ]
            ]);

        $result = $this->settingsService->testSolrConnectionForDashboard();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting SOLR settings only
     */
    public function testGetSolrSettingsOnly(): void
    {
        $this->config->method('getValueString')
            ->with('openregister', 'solr', '')
            ->willReturn('{"host": "solr-server", "port": 8983, "enabled": true}');

        $result = $this->settingsService->getSolrSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('host', $result);
        $this->assertArrayHasKey('port', $result);
        $this->assertArrayHasKey('enabled', $result);
    }

    /**
     * Test updating SOLR settings only
     */
    public function testUpdateSolrSettingsOnly(): void
    {
        $solrData = [
            'host' => 'new-solr-server',
            'port' => 9983,
            'enabled' => true
        ];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'solr', json_encode($solrData));

        $result = $this->settingsService->updateSolrSettingsOnly($solrData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    // ===== NON-SOLR SETTINGS TESTS =====.

    /**
     * Test getting RBAC settings only
     */
    public function testGetRbacSettingsOnly(): void
    {
        $this->config->method('getValueString')
            ->with('openregister', 'rbac', '')
            ->willReturn('{"enabled": true, "default_role": "user"}');

        $result = $this->settingsService->getRbacSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('default_role', $result);
    }

    /**
     * Test updating RBAC settings only
     */
    public function testUpdateRbacSettingsOnly(): void
    {
        $rbacData = ['enabled' => true, 'default_role' => 'admin'];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'rbac', json_encode($rbacData));

        $result = $this->settingsService->updateRbacSettingsOnly($rbacData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting multitenancy settings
     */
    public function testGetMultitenancySettings(): void
    {
        $this->config->method('getValueString')
            ->with('openregister', 'multitenancy', '')
            ->willReturn('{"enabled": false, "isolation": "strict"}');

        $result = $this->settingsService->getMultitenancySettings();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
    }

    /**
     * Test updating multitenancy settings
     */
    public function testUpdateMultitenancySettingsOnly(): void
    {
        $multitenancyData = ['enabled' => true, 'isolation' => 'loose'];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'multitenancy', json_encode($multitenancyData));

        $result = $this->settingsService->updateMultitenancySettingsOnly($multitenancyData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting retention settings
     */
    public function testGetRetentionSettingsOnly(): void
    {
        $this->config->method('getValueString')
            ->with('openregister', 'retention', '')
            ->willReturn('{"enabled": false, "days": 365}');

        $result = $this->settingsService->getRetentionSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('days', $result);
    }

    /**
     * Test updating retention settings
     */
    public function testUpdateRetentionSettingsOnly(): void
    {
        $retentionData = ['enabled' => true, 'days' => 730];

        $this->config->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'retention', json_encode($retentionData));

        $result = $this->settingsService->updateRetentionSettingsOnly($retentionData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting version info
     */
    public function testGetVersionInfoOnly(): void
    {
        $this->appManager->method('getAppVersion')
            ->with('openregister')
            ->willReturn('1.0.0');

        $result = $this->settingsService->getVersionInfoOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('version', $result);
        $this->assertEquals('1.0.0', $result['version']);
    }

    /**
     * Test rebase operation
     */
    public function testRebase(): void
    {
        $result = $this->settingsService->rebase();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test rebase objects and logs operation
     */
    public function testRebaseObjectsAndLogs(): void
    {
        $result = $this->settingsService->rebaseObjectsAndLogs();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test error handling in settings retrieval
     */
    public function testGetSettingsWithException(): void
    {
        $this->config->method('getAppValue')
            ->willThrowException(new \Exception('Config error'));

        $result = $this->settingsService->getSettings();

        $this->assertIsArray($result);
        // Should return default/fallback settings even if config fails.
    }

    /**
     * Test error handling in SOLR settings retrieval
     */
    public function testGetSolrSettingsWithException(): void
    {
        $this->config->method('getValueString')
            ->willThrowException(new \Exception('SOLR config error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve SOLR settings');

        $this->settingsService->getSolrSettings();
    }

    /**
     * Test settings validation
     */
    public function testUpdateSettingsValidation(): void
    {
        $invalidData = [
            'solr' => 'invalid_json_structure',
            'rbac' => ['enabled' => 'not_boolean']
        ];

        // Should handle invalid data gracefully.
        $result = $this->settingsService->updateSettings($invalidData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // May be false due to validation issues.
    }
}
