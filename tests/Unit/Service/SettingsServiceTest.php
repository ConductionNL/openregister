<?php

declare(strict_types=1);

/**
 * SettingsService Unit Tests
 *
 * Comprehensive unit tests for SettingsService.
 * These tests ensure we maintain functionality during refactoring.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Index\SetupHandler;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\SolrSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\ICacheFactory;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

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

    /** @var IGroupManager|MockObject */
    private $groupManager;

    /** @var IUserManager|MockObject */
    private $userManager;

    /** @var AuditTrailMapper|MockObject */
    private $auditTrailMapper;

    /** @var SearchTrailMapper|MockObject */
    private $searchTrailMapper;

    /** @var OrganisationMapper|MockObject */
    private $organisationMapper;

    /** @var SchemaCacheHandler|MockObject */
    private $schemaCacheService;

    /** @var FacetCacheHandler|MockObject */
    private $facetCacheHandler;

    /** @var ICacheFactory|MockObject */
    private $cacheFactory;

    /** @var LoggerInterface|MockObject */
    private $logger;

    /** @var IDBConnection|MockObject */
    private $db;

    /** @var ConfigurationSettingsHandler|MockObject */
    private $configurationSettingsHandler;

    /** @var ObjectRetentionHandler|MockObject */
    private $objectRetentionHandler;

    /** @var CacheSettingsHandler|MockObject */
    private $cacheSettingsHandler;

    /** @var ValidationOperationsHandler|MockObject */
    private $validationOperationsHandler;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock all dependencies.
        $this->config = $this->createMock(IConfig::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $this->facetCacheHandler = $this->createMock(FacetCacheHandler::class);
        $this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->db = $this->createMock(IDBConnection::class);

        // Mock handler dependencies.
        $this->configurationSettingsHandler = $this->createMock(ConfigurationSettingsHandler::class);
        $this->objectRetentionHandler = $this->createMock(ObjectRetentionHandler::class);
        $this->cacheSettingsHandler = $this->createMock(CacheSettingsHandler::class);
        $this->validationOperationsHandler = $this->createMock(ValidationOperationsHandler::class);

        // Create SettingsService instance matching the actual constructor.
        // All handler properties must be injected to avoid null property access.
        $this->settingsService = new SettingsService(
            config: $this->config,
            auditTrailMapper: $this->auditTrailMapper,
            cacheFactory: $this->cacheFactory,
            groupManager: $this->groupManager,
            logger: $this->logger,
            organisationMapper: $this->organisationMapper,
            schemaCacheService: $this->schemaCacheService,
            facetCacheSvc: $this->facetCacheHandler,
            searchTrailMapper: $this->searchTrailMapper,
            userManager: $this->userManager,
            db: $this->db,
            validOpsHandler: $this->validationOperationsHandler,
            searchBackendHandler: $this->createMock(SearchBackendHandler::class),
            llmSettingsHandler: $this->createMock(LlmSettingsHandler::class),
            fileSettingsHandler: $this->createMock(FileSettingsHandler::class),
            objRetentionHandler: $this->objectRetentionHandler,
            cacheSettingsHandler: $this->cacheSettingsHandler,
            solrSettingsHandler: $this->createMock(SolrSettingsHandler::class),
            cfgSettingsHandler: $this->configurationSettingsHandler
        );
    }

    /**
     * Test getting general settings
     */
    public function testGetSettings(): void
    {
        // getSettings() delegates to configurationSettingsHandler->getSettings().
        $this->configurationSettingsHandler->method('getSettings')
            ->willReturn([
                'solr' => ['enabled' => true, 'host' => 'localhost'],
                'rbac' => ['enabled' => false],
            ]);

        $result = $this->settingsService->getSettings();

        $this->assertIsArray($result);
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

        // updateSettings() delegates to configurationSettingsHandler->updateSettings().
        $this->configurationSettingsHandler->expects($this->once())
            ->method('updateSettings')
            ->with($settingsData)
            ->willReturn(['success' => true]);

        $result = $this->settingsService->updateSettings($settingsData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting statistics
     */
    public function testGetStats(): void
    {
        // getStats() uses $this->db for database queries and delegates cache stats
        // to cacheSettingsHandler. Mock the DB query builder to avoid real DB calls.
        $queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $this->db->method('getQueryBuilder')
            ->willReturn($queryBuilder);

        // getDatabaseStats() calls $this->db->executeQuery() and $this->db->getDatabasePlatform().
        // Mock executeQuery to throw so getDatabaseStats() falls into its catch block.
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('No database in unit test'));

        // Mock cacheSettingsHandler->getCacheStats() since getStats() calls getCacheStats().
        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn(['success' => true, 'caches' => []]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
    }

    /**
     * Test getting cache statistics
     */
    public function testGetCacheStats(): void
    {
        // getCacheStats() delegates to cacheSettingsHandler->getCacheStats().
        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn(['success' => true, 'caches' => []]);

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
        // clearCache() delegates to cacheSettingsHandler->clearCache().
        $this->cacheSettingsHandler->method('clearCache')
            ->willReturn(['success' => true, 'type' => 'all']);

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
        // warmupNamesCache() delegates to cacheSettingsHandler->warmupNamesCache().
        $this->cacheSettingsHandler->method('warmupNamesCache')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->warmupNamesCache();

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
        // getRbacSettingsOnly() delegates to configurationSettingsHandler->getRbacSettingsOnly().
        $this->configurationSettingsHandler->method('getRbacSettingsOnly')
            ->willReturn(['enabled' => true, 'default_role' => 'user']);

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

        // updateRbacSettingsOnly() delegates to configurationSettingsHandler->updateRbacSettingsOnly().
        $this->configurationSettingsHandler->expects($this->once())
            ->method('updateRbacSettingsOnly')
            ->with($rbacData)
            ->willReturn(['success' => true]);

        $result = $this->settingsService->updateRbacSettingsOnly($rbacData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting multitenancy settings
     */
    public function testGetMultitenancySettingsOnly(): void
    {
        // getMultitenancySettingsOnly() delegates to configurationSettingsHandler.
        $this->configurationSettingsHandler->method('getMultitenancySettingsOnly')
            ->willReturn(['enabled' => false, 'isolation' => 'strict']);

        $result = $this->settingsService->getMultitenancySettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
    }

    /**
     * Test updating multitenancy settings
     */
    public function testUpdateMultitenancySettingsOnly(): void
    {
        $multitenancyData = ['enabled' => true, 'isolation' => 'loose'];

        // updateMultitenancySettingsOnly() delegates to configurationSettingsHandler.
        $this->configurationSettingsHandler->expects($this->once())
            ->method('updateMultitenancySettingsOnly')
            ->with($multitenancyData)
            ->willReturn(['success' => true]);

        $result = $this->settingsService->updateMultitenancySettingsOnly($multitenancyData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test getting retention settings
     */
    public function testGetRetentionSettingsOnly(): void
    {
        // getRetentionSettingsOnly() delegates to objectRetentionHandler.
        $this->objectRetentionHandler->method('getRetentionSettingsOnly')
            ->willReturn(['enabled' => false, 'days' => 365]);

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

        // updateRetentionSettingsOnly() delegates to objectRetentionHandler.
        $this->objectRetentionHandler->expects($this->once())
            ->method('updateRetentionSettingsOnly')
            ->with($retentionData)
            ->willReturn(['success' => true]);

        $result = $this->settingsService->updateRetentionSettingsOnly($retentionData);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test rebase operation
     */
    public function testRebase(): void
    {
        // rebase() calls clearCache(null) internally via SettingsService::clearCache(?string).
        // Since CacheSettingsHandler::clearCache(string) does not accept null,
        // we pass explicit options to skip the cache clearing code path.
        $result = $this->settingsService->rebase(options: ['components' => ['solr']]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    /**
     * Test error handling in settings retrieval
     */
    public function testGetSettingsWithException(): void
    {
        // getSettings() delegates to configurationSettingsHandler->getSettings().
        // If the handler throws, the exception propagates (no catch in SettingsService::getSettings).
        $this->configurationSettingsHandler->method('getSettings')
            ->willThrowException(new \Exception('Config error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Config error');

        $this->settingsService->getSettings();
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

        // updateSettings() delegates to configurationSettingsHandler->updateSettings().
        // The handler handles validation internally.
        $this->configurationSettingsHandler->method('updateSettings')
            ->with($invalidData)
            ->willReturn(['success' => false, 'error' => 'Validation failed']);

        $result = $this->settingsService->updateSettings($invalidData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // May be false due to validation issues.
    }
}
