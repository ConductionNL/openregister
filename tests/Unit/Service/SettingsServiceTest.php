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

    /** @var SearchBackendHandler|MockObject */
    private $searchBackendHandler;

    /** @var LlmSettingsHandler|MockObject */
    private $llmSettingsHandler;

    /** @var FileSettingsHandler|MockObject */
    private $fileSettingsHandler;

    /** @var SolrSettingsHandler|MockObject */
    private $solrSettingsHandler;

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
        $this->searchBackendHandler = $this->createMock(SearchBackendHandler::class);
        $this->llmSettingsHandler = $this->createMock(LlmSettingsHandler::class);
        $this->fileSettingsHandler = $this->createMock(FileSettingsHandler::class);
        $this->solrSettingsHandler = $this->createMock(SolrSettingsHandler::class);

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
            searchBackendHandler: $this->searchBackendHandler,
            llmSettingsHandler: $this->llmSettingsHandler,
            fileSettingsHandler: $this->fileSettingsHandler,
            objRetentionHandler: $this->objectRetentionHandler,
            cacheSettingsHandler: $this->cacheSettingsHandler,
            solrSettingsHandler: $this->solrSettingsHandler,
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

    // ===== PURE LOGIC METHOD TESTS =====.

    /**
     * Test formatBytes with zero bytes
     */
    public function testFormatBytesZero(): void
    {
        $result = $this->settingsService->formatBytes(bytes: 0);
        $this->assertSame('0 B', $result);
    }

    /**
     * Test formatBytes with bytes below 1KB
     */
    public function testFormatBytesSmall(): void
    {
        $result = $this->settingsService->formatBytes(bytes: 512);
        $this->assertSame('512 B', $result);
    }

    /**
     * Test formatBytes with exactly 1KB
     */
    public function testFormatBytesOneKB(): void
    {
        // 1024 bytes is NOT > 1024 so stays as B
        $result = $this->settingsService->formatBytes(bytes: 1024);
        $this->assertSame('1024 B', $result);
    }

    /**
     * Test formatBytes with value above 1KB
     */
    public function testFormatBytesAboveOneKB(): void
    {
        $result = $this->settingsService->formatBytes(bytes: 1025);
        $this->assertSame('1 KB', $result);
    }

    /**
     * Test formatBytes with 1MB
     */
    public function testFormatBytesOneMB(): void
    {
        $result = $this->settingsService->formatBytes(bytes: 1048577);
        $this->assertSame('1 MB', $result);
    }

    /**
     * Test formatBytes with 1GB
     */
    public function testFormatBytesOneGB(): void
    {
        $result = $this->settingsService->formatBytes(bytes: 1073741825);
        $this->assertSame('1 GB', $result);
    }

    /**
     * Test formatBytes with custom precision
     */
    public function testFormatBytesCustomPrecision(): void
    {
        // 1536 bytes = 1.5 KB
        $result = $this->settingsService->formatBytes(bytes: 1536, precision: 1);
        $this->assertSame('1.5 KB', $result);
    }

    /**
     * Test convertToBytes with megabytes
     */
    public function testConvertToBytesMegabytes(): void
    {
        $result = $this->settingsService->convertToBytes(memoryLimit: '128M');
        $this->assertSame(134217728, $result);
    }

    /**
     * Test convertToBytes with gigabytes
     */
    public function testConvertToBytesGigabytes(): void
    {
        $result = $this->settingsService->convertToBytes(memoryLimit: '1G');
        $this->assertSame(1073741824, $result);
    }

    /**
     * Test convertToBytes with kilobytes
     */
    public function testConvertToBytesKilobytes(): void
    {
        $result = $this->settingsService->convertToBytes(memoryLimit: '512K');
        $this->assertSame(524288, $result);
    }

    /**
     * Test convertToBytes with plain number (no suffix)
     */
    public function testConvertToBytesPlainNumber(): void
    {
        $result = $this->settingsService->convertToBytes(memoryLimit: '65536');
        $this->assertSame(65536, $result);
    }

    /**
     * Test convertToBytes with -1 (unlimited)
     */
    public function testConvertToBytesUnlimited(): void
    {
        $result = $this->settingsService->convertToBytes(memoryLimit: '-1');
        $this->assertSame(-1, $result);
    }

    /**
     * Test maskToken with long token
     */
    public function testMaskTokenLong(): void
    {
        $token = 'sk-1234567890abcdef';
        $result = $this->settingsService->maskToken(token: $token);
        // Token is 19 chars: first 4 + min(20, 19-8)=11 stars + last 4
        $this->assertSame('sk-1***********cdef', $result);
        $this->assertStringStartsWith('sk-1', $result);
        $this->assertStringEndsWith('cdef', $result);
    }

    /**
     * Test maskToken with short token (8 chars or fewer)
     */
    public function testMaskTokenShort(): void
    {
        $result = $this->settingsService->maskToken(token: 'short');
        $this->assertSame('*****', $result);
    }

    /**
     * Test maskToken with exactly 8 characters
     */
    public function testMaskTokenExactlyEight(): void
    {
        $result = $this->settingsService->maskToken(token: '12345678');
        $this->assertSame('********', $result);
    }

    /**
     * Test maskToken with 9 characters (boundary case)
     */
    public function testMaskTokenNineChars(): void
    {
        $result = $this->settingsService->maskToken(token: '123456789');
        // first 4 + 1 star (min(20, 9-8)=1) + last 4
        $this->assertSame('1234*6789', $result);
    }

    /**
     * Test maskToken with empty string
     */
    public function testMaskTokenEmpty(): void
    {
        $result = $this->settingsService->maskToken(token: '');
        $this->assertSame('', $result);
    }

    // ===== HANDLER DELEGATION TESTS =====.

    /**
     * Test isMultiTenancyEnabled delegates to configurationSettingsHandler
     */
    public function testIsMultiTenancyEnabled(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('isMultiTenancyEnabled')
            ->willReturn(true);

        $result = $this->settingsService->isMultiTenancyEnabled();
        $this->assertTrue($result);
    }

    /**
     * Test isMultiTenancyEnabled returns false
     */
    public function testIsMultiTenancyEnabledFalse(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('isMultiTenancyEnabled')
            ->willReturn(false);

        $result = $this->settingsService->isMultiTenancyEnabled();
        $this->assertFalse($result);
    }

    /**
     * Test getDefaultOrganisationUuid delegates to configurationSettingsHandler
     */
    public function testGetDefaultOrganisationUuid(): void
    {
        $uuid = 'abc-123-def-456';
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getDefaultOrganisationUuid')
            ->willReturn($uuid);

        $result = $this->settingsService->getDefaultOrganisationUuid();
        $this->assertSame($uuid, $result);
    }

    /**
     * Test getDefaultOrganisationUuid returns null
     */
    public function testGetDefaultOrganisationUuidNull(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getDefaultOrganisationUuid')
            ->willReturn(null);

        $result = $this->settingsService->getDefaultOrganisationUuid();
        $this->assertNull($result);
    }

    /**
     * Test setDefaultOrganisationUuid delegates to configurationSettingsHandler
     */
    public function testSetDefaultOrganisationUuid(): void
    {
        $uuid = 'abc-123-def-456';
        $this->configurationSettingsHandler->expects($this->once())
            ->method('setDefaultOrganisationUuid')
            ->with($uuid);

        $this->settingsService->setDefaultOrganisationUuid(uuid: $uuid);
    }

    /**
     * Test setDefaultOrganisationUuid with null
     */
    public function testSetDefaultOrganisationUuidNull(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('setDefaultOrganisationUuid')
            ->with(null);

        $this->settingsService->setDefaultOrganisationUuid(uuid: null);
    }

    /**
     * Test getTenantId delegates to configurationSettingsHandler
     */
    public function testGetTenantId(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getTenantId')
            ->willReturn('tenant-42');

        $result = $this->settingsService->getTenantId();
        $this->assertSame('tenant-42', $result);
    }

    /**
     * Test getTenantId returns null
     */
    public function testGetTenantIdNull(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getTenantId')
            ->willReturn(null);

        $result = $this->settingsService->getTenantId();
        $this->assertNull($result);
    }

    /**
     * Test getOrganisationId delegates to configurationSettingsHandler
     */
    public function testGetOrganisationId(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getOrganisationId')
            ->willReturn('org-99');

        $result = $this->settingsService->getOrganisationId();
        $this->assertSame('org-99', $result);
    }

    /**
     * Test getOrganisationId returns null
     */
    public function testGetOrganisationIdNull(): void
    {
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getOrganisationId')
            ->willReturn(null);

        $result = $this->settingsService->getOrganisationId();
        $this->assertNull($result);
    }

    /**
     * Test getVersionInfoOnly delegates to configurationSettingsHandler
     */
    public function testGetVersionInfoOnly(): void
    {
        $expected = ['name' => 'openregister', 'version' => '1.2.3'];
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getVersionInfoOnly')
            ->willReturn($expected);

        $result = $this->settingsService->getVersionInfoOnly();
        $this->assertSame($expected, $result);
    }

    /**
     * Test getLLMSettingsOnly delegates to llmSettingsHandler
     */
    public function testGetLLMSettingsOnly(): void
    {
        $expected = ['provider' => 'openai', 'model' => 'gpt-4'];
        $this->llmSettingsHandler->expects($this->once())
            ->method('getLLMSettingsOnly')
            ->willReturn($expected);

        $result = $this->settingsService->getLLMSettingsOnly();
        $this->assertSame($expected, $result);
    }

    /**
     * Test updateLLMSettingsOnly delegates to llmSettingsHandler
     */
    public function testUpdateLLMSettingsOnly(): void
    {
        $data = ['provider' => 'ollama', 'model' => 'llama3'];
        $expected = ['success' => true, 'provider' => 'ollama'];
        $this->llmSettingsHandler->expects($this->once())
            ->method('updateLLMSettingsOnly')
            ->with($data)
            ->willReturn($expected);

        $result = $this->settingsService->updateLLMSettingsOnly(data: $data);
        $this->assertSame($expected, $result);
    }

    /**
     * Test getFileSettingsOnly delegates to fileSettingsHandler
     */
    public function testGetFileSettingsOnly(): void
    {
        $expected = ['max_size' => 10485760, 'allowed_types' => ['pdf', 'docx']];
        $this->fileSettingsHandler->expects($this->once())
            ->method('getFileSettingsOnly')
            ->willReturn($expected);

        $result = $this->settingsService->getFileSettingsOnly();
        $this->assertSame($expected, $result);
    }

    /**
     * Test updateFileSettingsOnly delegates to fileSettingsHandler
     */
    public function testUpdateFileSettingsOnly(): void
    {
        $data = ['max_size' => 20971520];
        $expected = ['success' => true];
        $this->fileSettingsHandler->expects($this->once())
            ->method('updateFileSettingsOnly')
            ->with($data)
            ->willReturn($expected);

        $result = $this->settingsService->updateFileSettingsOnly(data: $data);
        $this->assertSame($expected, $result);
    }

    /**
     * Test getObjectSettingsOnly delegates to objectRetentionHandler
     */
    public function testGetObjectSettingsOnly(): void
    {
        $expected = ['vectorize' => true, 'auto_index' => false];
        $this->objectRetentionHandler->expects($this->once())
            ->method('getObjectSettingsOnly')
            ->willReturn($expected);

        $result = $this->settingsService->getObjectSettingsOnly();
        $this->assertSame($expected, $result);
    }

    /**
     * Test updateObjectSettingsOnly delegates to objectRetentionHandler
     */
    public function testUpdateObjectSettingsOnly(): void
    {
        $data = ['vectorize' => false];
        $expected = ['success' => true];
        $this->objectRetentionHandler->expects($this->once())
            ->method('updateObjectSettingsOnly')
            ->with($data)
            ->willReturn($expected);

        $result = $this->settingsService->updateObjectSettingsOnly(data: $data);
        $this->assertSame($expected, $result);
    }

    /**
     * Test getSolrSettings delegates to solrSettingsHandler
     */
    public function testGetSolrSettings(): void
    {
        $expected = ['host' => 'localhost', 'port' => 8983, 'core' => 'openregister'];
        $this->solrSettingsHandler->expects($this->once())
            ->method('getSolrSettings')
            ->willReturn($expected);

        $result = $this->settingsService->getSolrSettings();
        $this->assertSame($expected, $result);
    }

    /**
     * Test getSolrSettingsOnly delegates to solrSettingsHandler
     */
    public function testGetSolrSettingsOnly(): void
    {
        $expected = ['host' => 'solr', 'port' => 8983];
        $this->solrSettingsHandler->expects($this->once())
            ->method('getSolrSettingsOnly')
            ->willReturn($expected);

        $result = $this->settingsService->getSolrSettingsOnly();
        $this->assertSame($expected, $result);
    }

    /**
     * Test updateSolrSettingsOnly delegates to solrSettingsHandler
     */
    public function testUpdateSolrSettingsOnly(): void
    {
        $data = ['host' => 'new-solr', 'port' => 8984];
        $expected = ['success' => true];
        $this->solrSettingsHandler->expects($this->once())
            ->method('updateSolrSettingsOnly')
            ->with($data)
            ->willReturn($expected);

        $result = $this->settingsService->updateSolrSettingsOnly(data: $data);
        $this->assertSame($expected, $result);
    }

    /**
     * Test getSolrDashboardStats delegates to solrSettingsHandler
     */
    public function testGetSolrDashboardStats(): void
    {
        $expected = ['numDocs' => 1500, 'indexSize' => '25MB'];
        $this->solrSettingsHandler->expects($this->once())
            ->method('getSolrDashboardStats')
            ->willReturn($expected);

        $result = $this->settingsService->getSolrDashboardStats();
        $this->assertSame($expected, $result);
    }

    /**
     * Test getSolrFacetConfiguration delegates to solrSettingsHandler
     */
    public function testGetSolrFacetConfiguration(): void
    {
        $expected = ['facets' => ['category', 'status']];
        $this->solrSettingsHandler->expects($this->once())
            ->method('getSolrFacetConfiguration')
            ->willReturn($expected);

        $result = $this->settingsService->getSolrFacetConfiguration();
        $this->assertSame($expected, $result);
    }

    /**
     * Test updateSolrFacetConfiguration delegates to solrSettingsHandler
     */
    public function testUpdateSolrFacetConfiguration(): void
    {
        $data = ['facets' => ['category', 'type', 'status']];
        $expected = ['success' => true];
        $this->solrSettingsHandler->expects($this->once())
            ->method('updateSolrFacetConfiguration')
            ->with($data)
            ->willReturn($expected);

        $result = $this->settingsService->updateSolrFacetConfiguration(data: $data);
        $this->assertSame($expected, $result);
    }

    /**
     * Test getOrganisationSettingsOnly delegates to configurationSettingsHandler
     */
    public function testGetOrganisationSettingsOnly(): void
    {
        $expected = ['organisation' => ['default_organisation' => 'uuid-1', 'auto_create_default_organisation' => true]];
        $this->configurationSettingsHandler->expects($this->once())
            ->method('getOrganisationSettingsOnly')
            ->willReturn($expected);

        $result = $this->settingsService->getOrganisationSettingsOnly();
        $this->assertSame($expected, $result);
    }

    /**
     * Test updateOrganisationSettingsOnly delegates to configurationSettingsHandler
     */
    public function testUpdateOrganisationSettingsOnly(): void
    {
        $data = ['organisation' => ['default_organisation' => 'uuid-2']];
        $expected = ['organisation' => ['default_organisation' => 'uuid-2', 'auto_create_default_organisation' => true]];
        $this->configurationSettingsHandler->expects($this->once())
            ->method('updateOrganisationSettingsOnly')
            ->with($data)
            ->willReturn($expected);

        $result = $this->settingsService->updateOrganisationSettingsOnly(data: $data);
        $this->assertSame($expected, $result);
    }

    /**
     * Test updatePublishingOptions delegates to configurationSettingsHandler
     */
    public function testUpdatePublishingOptions(): void
    {
        $data = ['auto_publish_objects' => true];
        $expected = ['auto_publish_objects' => true, 'auto_publish_attachments' => false];
        $this->configurationSettingsHandler->expects($this->once())
            ->method('updatePublishingOptions')
            ->with($data)
            ->willReturn($expected);

        $result = $this->settingsService->updatePublishingOptions(data: $data);
        $this->assertSame($expected, $result);
    }

    /**
     * Test validateAllObjects delegates to validationOperationsHandler
     */
    public function testValidateAllObjects(): void
    {
        $expected = ['success' => true, 'validated' => 100];
        $this->validationOperationsHandler->expects($this->once())
            ->method('validateAllObjects')
            ->willReturn($expected);

        $result = $this->settingsService->validateAllObjects();
        $this->assertSame($expected, $result);
    }

    // ===== SEARCH BACKEND CONFIG TESTS =====.

    /**
     * Test getSearchBackendConfig returns stored config
     */
    public function testGetSearchBackendConfigStored(): void
    {
        $storedConfig = json_encode(['active' => 'elasticsearch', 'available' => ['solr', 'elasticsearch']]);
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->with('openregister', 'search_backend', '')
            ->willReturn($storedConfig);

        $result = $this->settingsService->getSearchBackendConfig();
        $this->assertSame('elasticsearch', $result['active']);
    }

    /**
     * Test getSearchBackendConfig returns default when empty
     */
    public function testGetSearchBackendConfigDefault(): void
    {
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->with('openregister', 'search_backend', '')
            ->willReturn('');

        $result = $this->settingsService->getSearchBackendConfig();
        $this->assertSame('solr', $result['active']);
        $this->assertSame(['solr', 'elasticsearch'], $result['available']);
    }

    /**
     * Test getSearchBackendConfig returns default on exception
     */
    public function testGetSearchBackendConfigException(): void
    {
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->willThrowException(new \Exception('Config error'));

        $result = $this->settingsService->getSearchBackendConfig();
        $this->assertSame('solr', $result['active']);
    }

    /**
     * Test updateSearchBackendConfig delegates to searchBackendHandler
     */
    public function testUpdateSearchBackendConfig(): void
    {
        $expected = ['active' => 'elasticsearch', 'available' => ['solr', 'elasticsearch']];
        $this->searchBackendHandler->expects($this->once())
            ->method('updateSearchBackendConfig')
            ->with('elasticsearch')
            ->willReturn($expected);

        $result = $this->settingsService->updateSearchBackendConfig(data: ['backend' => 'elasticsearch']);
        $this->assertSame($expected, $result);
    }

    /**
     * Test updateSearchBackendConfig with 'active' key
     */
    public function testUpdateSearchBackendConfigWithActiveKey(): void
    {
        $expected = ['active' => 'solr', 'available' => ['solr', 'elasticsearch']];
        $this->searchBackendHandler->expects($this->once())
            ->method('updateSearchBackendConfig')
            ->with('solr')
            ->willReturn($expected);

        $result = $this->settingsService->updateSearchBackendConfig(data: ['active' => 'solr']);
        $this->assertSame($expected, $result);
    }

    // ===== DATABASE INFO / POSTGRES EXTENSION TESTS =====.

    /**
     * Test getDatabaseInfo returns cached info
     */
    public function testGetDatabaseInfo(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
                'version' => '15.0',
                'extensions' => [['name' => 'vector'], ['name' => 'pg_trgm']],
            ],
        ]);
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $result = $this->settingsService->getDatabaseInfo();
        $this->assertSame('PostgreSQL', $result['type']);
    }

    /**
     * Test getDatabaseInfo returns null when empty
     */
    public function testGetDatabaseInfoEmpty(): void
    {
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('');

        $result = $this->settingsService->getDatabaseInfo();
        $this->assertNull($result);
    }

    /**
     * Test getDatabaseInfo returns null for invalid JSON
     */
    public function testGetDatabaseInfoInvalidJson(): void
    {
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('not-json');

        $result = $this->settingsService->getDatabaseInfo();
        $this->assertNull($result);
    }

    /**
     * Test getDatabaseInfo returns null when database key missing
     */
    public function testGetDatabaseInfoMissingKey(): void
    {
        $this->config->expects($this->once())
            ->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn(json_encode(['something' => 'else']));

        $result = $this->settingsService->getDatabaseInfo();
        $this->assertNull($result);
    }

    /**
     * Test hasPostgresExtension returns true when extension exists
     */
    public function testHasPostgresExtensionTrue(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
                'extensions' => [['name' => 'vector'], ['name' => 'pg_trgm']],
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $this->assertTrue($this->settingsService->hasPostgresExtension(extensionName: 'vector'));
    }

    /**
     * Test hasPostgresExtension returns false when extension not found
     */
    public function testHasPostgresExtensionFalse(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
                'extensions' => [['name' => 'pg_trgm']],
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $this->assertFalse($this->settingsService->hasPostgresExtension(extensionName: 'vector'));
    }

    /**
     * Test hasPostgresExtension returns false for non-PostgreSQL database
     */
    public function testHasPostgresExtensionNotPostgres(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'MySQL',
                'extensions' => [],
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $this->assertFalse($this->settingsService->hasPostgresExtension(extensionName: 'vector'));
    }

    /**
     * Test hasPostgresExtension returns false when no cached data
     */
    public function testHasPostgresExtensionNoCachedData(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('');

        $this->assertFalse($this->settingsService->hasPostgresExtension(extensionName: 'vector'));
    }

    /**
     * Test getPostgresExtensions returns extensions list
     */
    public function testGetPostgresExtensions(): void
    {
        $extensions = [['name' => 'vector'], ['name' => 'pg_trgm']];
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
                'extensions' => $extensions,
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $result = $this->settingsService->getPostgresExtensions();
        $this->assertSame($extensions, $result);
    }

    /**
     * Test getPostgresExtensions returns empty for non-PostgreSQL
     */
    public function testGetPostgresExtensionsNotPostgres(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'MySQL',
                'extensions' => [['name' => 'something']],
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $result = $this->settingsService->getPostgresExtensions();
        $this->assertSame([], $result);
    }

    /**
     * Test getPostgresExtensions returns empty when no cached data
     */
    public function testGetPostgresExtensionsNoCachedData(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('');

        $result = $this->settingsService->getPostgresExtensions();
        $this->assertSame([], $result);
    }

    // ===== COMPARE FIELDS TESTS =====.

    /**
     * Test compareFields with no differences
     */
    public function testCompareFieldsNoDifferences(): void
    {
        $fields = [
            'title' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false],
            'status' => ['type' => 'string', 'multiValued' => false, 'docValues' => true],
        ];

        $result = $this->settingsService->compareFields(actualFields: $fields, expectedFields: $fields);

        $this->assertSame(0, $result['summary']['total_differences']);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['extra']);
        $this->assertEmpty($result['mismatched']);
    }

    /**
     * Test compareFields with missing fields
     */
    public function testCompareFieldsMissingFields(): void
    {
        $actual = [
            'title' => ['type' => 'text_general'],
        ];
        $expected = [
            'title' => ['type' => 'text_general'],
            'description' => ['type' => 'text_general'],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        $this->assertSame(1, $result['summary']['missing_count']);
        $this->assertSame('description', $result['missing'][0]['field']);
    }

    /**
     * Test compareFields with extra fields
     */
    public function testCompareFieldsExtraFields(): void
    {
        $actual = [
            'title' => ['type' => 'text_general'],
            'extra_field' => ['type' => 'string'],
        ];
        $expected = [
            'title' => ['type' => 'text_general'],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        $this->assertSame(1, $result['summary']['extra_count']);
        $this->assertSame('extra_field', $result['extra'][0]['field']);
    }

    /**
     * Test compareFields skips system fields starting with underscore
     */
    public function testCompareFieldsSkipsSystemFields(): void
    {
        $actual = [
            '_version_' => ['type' => 'plong'],
            'title' => ['type' => 'text_general'],
        ];
        $expected = [
            'title' => ['type' => 'text_general'],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        // _version_ should be skipped, not counted as extra
        $this->assertSame(0, $result['summary']['extra_count']);
    }

    /**
     * Test compareFields with type mismatch
     */
    public function testCompareFieldsTypeMismatch(): void
    {
        $actual = [
            'status' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false],
        ];
        $expected = [
            'status' => ['type' => 'string', 'multiValued' => false, 'docValues' => false],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        $this->assertSame(1, $result['summary']['mismatched_count']);
        $this->assertContains('type', $result['mismatched'][0]['differences']);
    }

    /**
     * Test compareFields with multiValued mismatch
     */
    public function testCompareFieldsMultiValuedMismatch(): void
    {
        $actual = [
            'tags' => ['type' => 'string', 'multiValued' => false, 'docValues' => false],
        ];
        $expected = [
            'tags' => ['type' => 'string', 'multiValued' => true, 'docValues' => false],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        $this->assertSame(1, $result['summary']['mismatched_count']);
        $this->assertContains('multiValued', $result['mismatched'][0]['differences']);
    }

    /**
     * Test compareFields with multiple differences at once
     */
    public function testCompareFieldsMultipleDifferences(): void
    {
        $actual = [
            'title' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false],
            'orphan_field' => ['type' => 'string'],
        ];
        $expected = [
            'title' => ['type' => 'string', 'multiValued' => false, 'docValues' => false],
            'missing_field' => ['type' => 'text_general'],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        $this->assertSame(1, $result['summary']['missing_count']);
        $this->assertSame(1, $result['summary']['extra_count']);
        $this->assertSame(1, $result['summary']['mismatched_count']);
        $this->assertSame(3, $result['summary']['total_differences']);
    }

    // ===== REBASE TESTS =====.

    /**
     * Test rebase with 'all' components triggers TypeError when clearCache receives null
     *
     * SettingsService::clearCache(?string) passes null to CacheSettingsHandler::clearCache(string).
     * This is a known type mismatch in production code; we verify the behavior here.
     */
    public function testRebaseAllComponents(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('all')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->rebase(options: ['components' => ['all']]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('solr', $result['rebased']);
        $this->assertArrayHasKey('cache', $result['rebased']);
    }

    /**
     * Test rebase with only solr component
     */
    public function testRebaseSolrOnly(): void
    {
        $result = $this->settingsService->rebase(options: ['components' => ['solr']]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('solr', $result['rebased']);
        $this->assertArrayNotHasKey('cache', $result['rebased']);
    }

    /**
     * Test rebase with explicit cache type via clearCache delegation
     */
    public function testRebaseSolrComponent(): void
    {
        $result = $this->settingsService->rebase(options: ['components' => ['solr']]);

        $this->assertTrue($result['success']);
        $this->assertSame('Solr configuration rebased', $result['rebased']['solr']['message']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test rebase with default (no options) triggers all components
     */
    public function testRebaseDefaultOptionsTriggersAllComponents(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('all')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->rebase();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('solr', $result['rebased']);
        $this->assertArrayHasKey('cache', $result['rebased']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test rebase with cache component only
     */
    public function testRebaseCacheOnly(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('all')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->rebase(options: ['components' => ['cache']]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('cache', $result['rebased']);
        $this->assertArrayNotHasKey('solr', $result['rebased']);
    }

    // ===== STATS TESTS =====.

    /**
     * Test getStats returns complete structure when DB fails
     */
    public function testGetStatsWithDbFailure(): void
    {
        $this->db->method('getQueryBuilder')
            ->willThrowException(new \Exception('DB connection error'));

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn(['success' => true]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn(['numDocs' => 0]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('system', $result);
    }

    /**
     * Test getStats includes system info
     */
    public function testGetStatsIncludesSystemInfo(): void
    {
        $this->db->method('getQueryBuilder')
            ->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class));
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('No database'));
        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertArrayHasKey('system', $result);
        $this->assertArrayHasKey('php_version', $result['system']);
        $this->assertArrayHasKey('memory_limit', $result['system']);
        $this->assertArrayHasKey('max_execution_time', $result['system']);
    }

    /**
     * Test getStats handles Solr exception gracefully
     */
    public function testGetStatsHandlesSolrException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class));
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('No database'));
        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willThrowException(new \Exception('Solr down'));

        $result = $this->settingsService->getStats();

        $this->assertArrayHasKey('solr', $result);
        $this->assertSame('Solr down', $result['solr']['error']);
    }

    /**
     * Test getStats handles cache stats exception gracefully
     */
    public function testGetStatsHandlesCacheStatsException(): void
    {
        $this->db->method('getQueryBuilder')
            ->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class));
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('No database'));
        $this->cacheSettingsHandler->method('getCacheStats')
            ->willThrowException(new \Exception('Cache error'));
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertArrayHasKey('cache', $result);
        $this->assertSame('Cache error', $result['cache']['error']);
    }

    /**
     * Test getStats includes timestamp
     */
    public function testGetStatsIncludesTimestamp(): void
    {
        $this->db->method('getQueryBuilder')
            ->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class));
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('No database'));
        $this->cacheSettingsHandler->method('getCacheStats')->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('date', $result);
    }

    // ===== MASS VALIDATE PARAMETER VALIDATION =====.

    /**
     * Test massValidateObjects with invalid mode throws exception
     */
    public function testMassValidateObjectsInvalidMode(): void
    {
        $container = $this->createMock(IAppContainer::class);

        $service = new SettingsService(
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
            container: $container,
            validOpsHandler: $this->validationOperationsHandler,
            searchBackendHandler: $this->searchBackendHandler,
            llmSettingsHandler: $this->llmSettingsHandler,
            fileSettingsHandler: $this->fileSettingsHandler,
            objRetentionHandler: $this->objectRetentionHandler,
            cacheSettingsHandler: $this->cacheSettingsHandler,
            solrSettingsHandler: $this->solrSettingsHandler,
            cfgSettingsHandler: $this->configurationSettingsHandler
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mode parameter');

        $service->massValidateObjects(mode: 'invalid_mode');
    }

    /**
     * Test massValidateObjects with invalid batch size (too small)
     */
    public function testMassValidateObjectsInvalidBatchSizeTooSmall(): void
    {
        $container = $this->createMock(IAppContainer::class);

        $service = new SettingsService(
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
            container: $container,
            validOpsHandler: $this->validationOperationsHandler,
            searchBackendHandler: $this->searchBackendHandler,
            llmSettingsHandler: $this->llmSettingsHandler,
            fileSettingsHandler: $this->fileSettingsHandler,
            objRetentionHandler: $this->objectRetentionHandler,
            cacheSettingsHandler: $this->cacheSettingsHandler,
            solrSettingsHandler: $this->solrSettingsHandler,
            cfgSettingsHandler: $this->configurationSettingsHandler
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid batch size');

        $service->massValidateObjects(batchSize: 0);
    }

    /**
     * Test massValidateObjects with invalid batch size (too large)
     */
    public function testMassValidateObjectsInvalidBatchSizeTooLarge(): void
    {
        $container = $this->createMock(IAppContainer::class);

        $service = new SettingsService(
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
            container: $container,
            validOpsHandler: $this->validationOperationsHandler,
            searchBackendHandler: $this->searchBackendHandler,
            llmSettingsHandler: $this->llmSettingsHandler,
            fileSettingsHandler: $this->fileSettingsHandler,
            objRetentionHandler: $this->objectRetentionHandler,
            cacheSettingsHandler: $this->cacheSettingsHandler,
            solrSettingsHandler: $this->solrSettingsHandler,
            cfgSettingsHandler: $this->configurationSettingsHandler
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid batch size');

        $service->massValidateObjects(batchSize: 5001);
    }

    // ===== PURE LOGIC METHOD TESTS (additional coverage) =====.

    /**
     * Test formatBytes with TB level
     */
    public function testFormatBytesTB(): void
    {
        // 2 TB = 2 * 1024^4 = 2199023255552
        $result = $this->settingsService->formatBytes(bytes: 2199023255552);
        $this->assertSame('2 TB', $result);
    }

    /**
     * Test convertToBytes with lowercase suffix
     */
    public function testConvertToBytesLowercase(): void
    {
        $result = $this->settingsService->convertToBytes(memoryLimit: '512k');
        $this->assertSame(524288, $result);
    }

    /**
     * Test convertToBytes with leading/trailing spaces
     */
    public function testConvertToBytesWithSpaces(): void
    {
        $result = $this->settingsService->convertToBytes(memoryLimit: ' 128M ');
        $this->assertSame(134217728, $result);
    }

    /**
     * Test maskToken with very long token
     */
    public function testMaskTokenVeryLong(): void
    {
        $token = str_repeat('a', 100);
        $result = $this->settingsService->maskToken(token: $token);

        // first 4 + min(20, 100-8)=20 stars + last 4
        $this->assertSame('aaaa' . str_repeat('*', 20) . 'aaaa', $result);
        $this->assertSame(28, strlen($result));
    }

    // ===== COMPARE FIELDS (additional coverage) =====.

    /**
     * Test compareFields with docValues mismatch
     */
    public function testCompareFieldsDocValuesMismatch(): void
    {
        $actual = [
            'status' => ['type' => 'string', 'multiValued' => false, 'docValues' => true],
        ];
        $expected = [
            'status' => ['type' => 'string', 'multiValued' => false, 'docValues' => false],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        $this->assertSame(1, $result['summary']['mismatched_count']);
        $this->assertContains('docValues', $result['mismatched'][0]['differences']);
    }

    /**
     * Test compareFields with empty arrays
     */
    public function testCompareFieldsEmpty(): void
    {
        $result = $this->settingsService->compareFields(actualFields: [], expectedFields: []);

        $this->assertSame(0, $result['summary']['total_differences']);
    }

    /**
     * Test compareFields with all three difference types simultaneously
     */
    public function testCompareFieldsAllDifferenceTypes(): void
    {
        $actual = [
            'field_a' => ['type' => 'string', 'multiValued' => true, 'docValues' => true],
            'field_extra' => ['type' => 'string'],
        ];
        $expected = [
            'field_a' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false],
            'field_missing' => ['type' => 'string'],
        ];

        $result = $this->settingsService->compareFields(actualFields: $actual, expectedFields: $expected);

        $this->assertSame(1, $result['summary']['missing_count']);
        $this->assertSame(1, $result['summary']['extra_count']);
        $this->assertSame(1, $result['summary']['mismatched_count']);
        $this->assertSame(3, $result['summary']['total_differences']);

        // Check mismatch has all three differences.
        $differences = $result['mismatched'][0]['differences'];
        $this->assertContains('type', $differences);
        $this->assertContains('multiValued', $differences);
        $this->assertContains('docValues', $differences);
    }

    // ===== DATABASE INFO (additional coverage) =====.

    /**
     * Test getDatabaseInfo with valid data but no extensions key
     */
    public function testGetDatabaseInfoWithNoExtensions(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
                'version' => '15.0',
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $result = $this->settingsService->getDatabaseInfo();
        $this->assertSame('PostgreSQL', $result['type']);
        $this->assertArrayNotHasKey('extensions', $result);
    }

    /**
     * Test hasPostgresExtension returns false when no extensions key
     */
    public function testHasPostgresExtensionNoExtensionsKey(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $this->assertFalse($this->settingsService->hasPostgresExtension(extensionName: 'vector'));
    }

    // ===== HANDLER DELEGATION (additional methods) =====.

    /**
     * Test clearCache with null cacheType
     */
    public function testClearCacheNull(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('all')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->clearCache(null);
        $this->assertTrue($result['success']);
    }

    /**
     * Test updateSearchBackendConfig defaults to 'solr' when no key present
     */
    public function testUpdateSearchBackendConfigDefaultsSolr(): void
    {
        $this->searchBackendHandler->expects($this->once())
            ->method('updateSearchBackendConfig')
            ->with('solr')
            ->willReturn(['active' => 'solr']);

        $result = $this->settingsService->updateSearchBackendConfig(data: []);
        $this->assertSame('solr', $result['active']);
    }

    /**
     * Test getPostgresExtensions with extensions but no name keys
     */
    public function testGetPostgresExtensionsWithMalformedData(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
                'extensions' => [['version' => '1.0']],
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $result = $this->settingsService->getPostgresExtensions();
        $this->assertCount(1, $result);
    }
}
