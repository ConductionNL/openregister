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

        // Create SettingsService instance matching the actual constructor.
        // Non-nullable handler properties require mock instances (not null).
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
            searchBackendHandler: $this->createMock(SearchBackendHandler::class),
            llmSettingsHandler: $this->createMock(LlmSettingsHandler::class),
            fileSettingsHandler: $this->createMock(FileSettingsHandler::class),
            cacheSettingsHandler: $this->createMock(CacheSettingsHandler::class),
            solrSettingsHandler: $this->createMock(SolrSettingsHandler::class)
        );
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
     * Test getting statistics
     */
    public function testGetStats(): void
    {
        $this->auditTrailMapper->method('countAll')
            ->willReturn(50);

        $this->searchTrailMapper->method('countAll')
            ->willReturn(25);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
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
    public function testGetMultitenancySettingsOnly(): void
    {
        $this->config->method('getValueString')
            ->with('openregister', 'multitenancy', '')
            ->willReturn('{"enabled": false, "isolation": "strict"}');

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
