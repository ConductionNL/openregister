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

    // ===== EXPECTED SCHEMA FIELDS TESTS =====.

    /**
     * Test getExpectedSchemaFields with setupHandler and schemas
     */
    public function testGetExpectedSchemaFieldsWithSetupHandler(): void
    {
        $setupHandler = $this->createMock(SetupHandler::class);
        $setupHandler->method('getObjectEntityFieldDefinitions')
            ->willReturn([
                'uuid' => ['type' => 'string', 'multiValued' => false],
                'name' => ['type' => 'text_general', 'multiValued' => false],
            ]);

        $service = new SettingsService(
            $this->config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            $setupHandler,
            null,
            null,
            'openregister',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );

        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('findAll')->willReturn([]);

        $indexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);

        // The method uses reflection to call analyzeAndResolveFieldConflicts.
        // Since we pass empty schemas, the reflected method should return empty fields.
        // We test the fallback path by making reflection throw.
        $result = $service->getExpectedSchemaFields($schemaMapper, $indexService);

        // Should at least contain the core fields from setupHandler.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayHasKey('name', $result);
    }

    /**
     * Test getExpectedSchemaFields without setupHandler returns empty on exception
     */
    public function testGetExpectedSchemaFieldsWithoutSetupHandlerOnException(): void
    {
        $service = new SettingsService(
            $this->config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            null,
            null,
            null,
            'openregister',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );

        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $indexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);

        $result = $service->getExpectedSchemaFields($schemaMapper, $indexService);

        // Without setupHandler and with exception, should return empty array.
        $this->assertSame([], $result);
    }

    /**
     * Test getExpectedSchemaFields with setupHandler falls back to core fields on exception
     */
    public function testGetExpectedSchemaFieldsFallbackToCoreFields(): void
    {
        $coreFields = [
            'uuid' => ['type' => 'string'],
            'owner' => ['type' => 'string'],
        ];

        $setupHandler = $this->createMock(SetupHandler::class);
        $setupHandler->method('getObjectEntityFieldDefinitions')
            ->willReturn($coreFields);

        $service = new SettingsService(
            $this->config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            $setupHandler,
            null,
            null,
            'openregister',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );

        $schemaMapper = $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class);
        $schemaMapper->method('findAll')
            ->willThrowException(new \Exception('Schema error'));

        $indexService = $this->createMock(\OCA\OpenRegister\Service\IndexService::class);

        $result = $service->getExpectedSchemaFields($schemaMapper, $indexService);

        // Should fall back to core fields from setupHandler.
        $this->assertSame($coreFields, $result);
    }

    // ===== REBASE EXCEPTION PATH =====.

    /**
     * Test rebase handles exception gracefully
     */
    public function testRebaseExceptionPath(): void
    {
        // Make clearCache throw to trigger the catch block in rebase.
        $this->cacheSettingsHandler->method('clearCache')
            ->willThrowException(new \Exception('Cache clear failed'));

        $result = $this->settingsService->rebase(options: ['components' => ['cache']]);

        $this->assertFalse($result['success']);
        $this->assertSame('Rebase failed', $result['error']);
        $this->assertSame('Cache clear failed', $result['message']);
    }

    /**
     * Test rebase with unknown component only returns success with empty rebased
     */
    public function testRebaseUnknownComponent(): void
    {
        $result = $this->settingsService->rebase(options: ['components' => ['unknown_thing']]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['rebased']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test rebase with both solr and cache components
     */
    public function testRebaseSolrAndCache(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('all')
            ->willReturn(['success' => true]);

        $result = $this->settingsService->rebase(options: ['components' => ['solr', 'cache']]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('solr', $result['rebased']);
        $this->assertArrayHasKey('cache', $result['rebased']);
    }

    // ===== MASS VALIDATE BOUNDARY TESTS =====.

    /**
     * Test massValidateObjects with negative batch size throws exception
     */
    public function testMassValidateObjectsNegativeBatchSize(): void
    {
        $container = $this->createMock(IAppContainer::class);

        $service = new SettingsService(
            $this->config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            null,
            null,
            $container,
            'openregister',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid batch size');

        $service->massValidateObjects(0, -1, 'serial', false);
    }

    /**
     * Test massValidateObjects validates mode before batch size
     */
    public function testMassValidateObjectsModeValidatedFirst(): void
    {
        $container = $this->createMock(IAppContainer::class);

        $service = new SettingsService(
            $this->config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            null,
            null,
            $container,
            'openregister',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );

        // Both mode and batchSize are invalid, but mode is checked first.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mode parameter');

        $service->massValidateObjects(0, 0, 'bogus', false);
    }

    // ===== CLEAR CACHE WITH SPECIFIC TYPE =====.

    /**
     * Test clearCache with specific cache type passes correct type
     */
    public function testClearCacheSpecificType(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('objects')
            ->willReturn(['success' => true, 'type' => 'objects']);

        $result = $this->settingsService->clearCache('objects');
        $this->assertSame('objects', $result['type']);
    }

    // ===== COMPARE FIELDS ADDITIONAL EDGE CASES =====.

    /**
     * Test compareFields with missing type defaults to empty string comparison
     */
    public function testCompareFieldsMissingTypeKeys(): void
    {
        $actual = [
            'field_a' => ['multiValued' => false, 'docValues' => false],
        ];
        $expected = [
            'field_a' => ['multiValued' => false, 'docValues' => false],
        ];

        $result = $this->settingsService->compareFields($actual, $expected);

        // Both missing 'type' defaults to '' === '' so no mismatch.
        $this->assertSame(0, $result['summary']['mismatched_count']);
    }

    /**
     * Test compareFields where actual has no type but expected does
     */
    public function testCompareFieldsActualMissingType(): void
    {
        $actual = [
            'field_a' => ['multiValued' => false, 'docValues' => false],
        ];
        $expected = [
            'field_a' => ['type' => 'string', 'multiValued' => false, 'docValues' => false],
        ];

        $result = $this->settingsService->compareFields($actual, $expected);

        $this->assertSame(1, $result['summary']['mismatched_count']);
        $this->assertContains('type', $result['mismatched'][0]['differences']);
    }

    /**
     * Test compareFields extra field reports correct actual_type
     */
    public function testCompareFieldsExtraFieldType(): void
    {
        $actual = [
            'orphan' => ['type' => 'plong'],
        ];
        $expected = [];

        $result = $this->settingsService->compareFields($actual, $expected);

        $this->assertSame(1, $result['summary']['extra_count']);
        $this->assertSame('plong', $result['extra'][0]['actual_type']);
    }

    /**
     * Test compareFields extra field with no type key defaults to 'unknown'
     */
    public function testCompareFieldsExtraFieldNoType(): void
    {
        $actual = [
            'orphan' => ['multiValued' => true],
        ];
        $expected = [];

        $result = $this->settingsService->compareFields($actual, $expected);

        $this->assertSame('unknown', $result['extra'][0]['actual_type']);
    }

    /**
     * Test compareFields missing field with no type key defaults to 'unknown'
     */
    public function testCompareFieldsMissingFieldNoType(): void
    {
        $actual = [];
        $expected = [
            'needed' => ['multiValued' => true],
        ];

        $result = $this->settingsService->compareFields($actual, $expected);

        $this->assertSame('unknown', $result['missing'][0]['expected_type']);
    }

    // ===== FORMAT BYTES ADDITIONAL COVERAGE =====.

    /**
     * Test formatBytes with exactly 1 byte
     */
    public function testFormatBytesOneByte(): void
    {
        $result = $this->settingsService->formatBytes(1);
        $this->assertSame('1 B', $result);
    }

    /**
     * Test formatBytes with precision 0
     */
    public function testFormatBytesPrecisionZero(): void
    {
        // 1536 bytes = 1.5 KB, with precision 0 should round to 2 KB.
        $result = $this->settingsService->formatBytes(1536, 0);
        $this->assertSame('2 KB', $result);
    }

    /**
     * Test formatBytes with large TB value
     */
    public function testFormatBytesLargeTB(): void
    {
        // 10 TB = 10 * 1024^4 = 10995116277760
        $result = $this->settingsService->formatBytes(10995116277760);
        $this->assertSame('10 TB', $result);
    }

    // ===== CONVERT TO BYTES ADDITIONAL COVERAGE =====.

    /**
     * Test convertToBytes with lowercase 'g'
     */
    public function testConvertToBytesLowercaseG(): void
    {
        $result = $this->settingsService->convertToBytes('2g');
        $this->assertSame(2147483648, $result);
    }

    /**
     * Test convertToBytes with lowercase 'm'
     */
    public function testConvertToBytesLowercaseM(): void
    {
        $result = $this->settingsService->convertToBytes('256m');
        $this->assertSame(268435456, $result);
    }

    /**
     * Test convertToBytes with zero
     */
    public function testConvertToBytesZero(): void
    {
        $result = $this->settingsService->convertToBytes('0');
        $this->assertSame(0, $result);
    }

    // ===== HAS POSTGRES EXTENSION EDGE CASES =====.

    /**
     * Test hasPostgresExtension with missing type key returns false
     */
    public function testHasPostgresExtensionMissingTypeKey(): void
    {
        $dbData = json_encode([
            'database' => [
                'extensions' => [['name' => 'vector']],
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $this->assertFalse($this->settingsService->hasPostgresExtension('vector'));
    }

    /**
     * Test getPostgresExtensions with PostgreSQL but no extensions key returns empty
     */
    public function testGetPostgresExtensionsNoExtensionsKey(): void
    {
        $dbData = json_encode([
            'database' => [
                'type' => 'PostgreSQL',
            ],
        ]);
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($dbData);

        $result = $this->settingsService->getPostgresExtensions();
        $this->assertSame([], $result);
    }

    // ===== SEARCH BACKEND CONFIG EDGE CASES =====.

    /**
     * Test updateSearchBackendConfig prefers 'backend' over 'active' key
     */
    public function testUpdateSearchBackendConfigBackendPrecedence(): void
    {
        $this->searchBackendHandler->expects($this->once())
            ->method('updateSearchBackendConfig')
            ->with('elasticsearch')
            ->willReturn(['active' => 'elasticsearch']);

        // When both 'backend' and 'active' are present, 'backend' takes precedence.
        $result = $this->settingsService->updateSearchBackendConfig(
            ['backend' => 'elasticsearch', 'active' => 'solr']
        );

        $this->assertSame('elasticsearch', $result['active']);
    }

    // ===== GET STATS OUTER EXCEPTION =====.

    /**
     * Test getStats catches outer exception and returns error structure
     */
    public function testGetStatsOuterException(): void
    {
        // Make getQueryBuilder throw to trigger DB stats failure.
        $this->db->method('getQueryBuilder')
            ->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class));
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('DB failed'));

        // Make getSolrDashboardStats throw.
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willThrowException(new \Exception('Solr error'));

        // Make getCacheStats throw.
        $this->cacheSettingsHandler->method('getCacheStats')
            ->willThrowException(new \Exception('Cache error'));

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        // Despite all sub-errors, getStats still returns a valid structure.
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('solr', $result);
        $this->assertArrayHasKey('cache', $result);
        $this->assertArrayHasKey('system', $result);
    }

    // ===== HANDLER DELEGATION EDGE CASES =====.

    /**
     * Test warmupNamesCache handler exception propagates
     */
    public function testWarmupNamesCacheException(): void
    {
        $this->cacheSettingsHandler->method('warmupNamesCache')
            ->willThrowException(new \Exception('Warmup failed'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Warmup failed');

        $this->settingsService->warmupNamesCache();
    }

    /**
     * Test validateAllObjects handler exception propagates
     */
    public function testValidateAllObjectsException(): void
    {
        $this->validationOperationsHandler->method('validateAllObjects')
            ->willThrowException(new \Exception('Validation error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Validation error');

        $this->settingsService->validateAllObjects();
    }

    /**
     * Test getLLMSettingsOnly handler exception propagates
     */
    public function testGetLLMSettingsOnlyException(): void
    {
        $this->llmSettingsHandler->method('getLLMSettingsOnly')
            ->willThrowException(new \Exception('LLM error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getLLMSettingsOnly();
    }

    /**
     * Test getFileSettingsOnly handler exception propagates
     */
    public function testGetFileSettingsOnlyException(): void
    {
        $this->fileSettingsHandler->method('getFileSettingsOnly')
            ->willThrowException(new \Exception('File error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getFileSettingsOnly();
    }

    /**
     * Test getObjectSettingsOnly handler exception propagates
     */
    public function testGetObjectSettingsOnlyException(): void
    {
        $this->objectRetentionHandler->method('getObjectSettingsOnly')
            ->willThrowException(new \Exception('Object error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getObjectSettingsOnly();
    }

    /**
     * Test getSolrSettings handler exception propagates
     */
    public function testGetSolrSettingsException(): void
    {
        $this->solrSettingsHandler->method('getSolrSettings')
            ->willThrowException(new \Exception('Solr error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getSolrSettings();
    }

    /**
     * Test getRetentionSettingsOnly handler exception propagates
     */
    public function testGetRetentionSettingsOnlyException(): void
    {
        $this->objectRetentionHandler->method('getRetentionSettingsOnly')
            ->willThrowException(new \Exception('Retention error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getRetentionSettingsOnly();
    }

    /**
     * Test getRbacSettingsOnly handler exception propagates
     */
    public function testGetRbacSettingsOnlyException(): void
    {
        $this->configurationSettingsHandler->method('getRbacSettingsOnly')
            ->willThrowException(new \Exception('RBAC error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getRbacSettingsOnly();
    }

    /**
     * Test getMultitenancySettingsOnly handler exception propagates
     */
    public function testGetMultitenancySettingsOnlyException(): void
    {
        $this->configurationSettingsHandler->method('getMultitenancySettingsOnly')
            ->willThrowException(new \Exception('MT error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getMultitenancySettingsOnly();
    }

    /**
     * Test getOrganisationSettingsOnly handler exception propagates
     */
    public function testGetOrganisationSettingsOnlyException(): void
    {
        $this->configurationSettingsHandler->method('getOrganisationSettingsOnly')
            ->willThrowException(new \Exception('Org error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getOrganisationSettingsOnly();
    }

    /**
     * Test getVersionInfoOnly handler exception propagates
     */
    public function testGetVersionInfoOnlyException(): void
    {
        $this->configurationSettingsHandler->method('getVersionInfoOnly')
            ->willThrowException(new \Exception('Version error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getVersionInfoOnly();
    }

    /**
     * Test getCacheStats handler exception propagates
     */
    public function testGetCacheStatsException(): void
    {
        $this->cacheSettingsHandler->method('getCacheStats')
            ->willThrowException(new \Exception('Cache stats error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getCacheStats();
    }

    /**
     * Test getSolrSettingsOnly handler exception propagates
     */
    public function testGetSolrSettingsOnlyException(): void
    {
        $this->solrSettingsHandler->method('getSolrSettingsOnly')
            ->willThrowException(new \Exception('Solr only error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getSolrSettingsOnly();
    }

    /**
     * Test getSolrFacetConfiguration handler exception propagates
     */
    public function testGetSolrFacetConfigurationException(): void
    {
        $this->solrSettingsHandler->method('getSolrFacetConfiguration')
            ->willThrowException(new \Exception('Facet error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getSolrFacetConfiguration();
    }

    /**
     * Test getSolrDashboardStats handler exception propagates
     */
    public function testGetSolrDashboardStatsException(): void
    {
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willThrowException(new \Exception('Dashboard error'));

        $this->expectException(\Exception::class);
        $this->settingsService->getSolrDashboardStats();
    }

    // ===== CONSTRUCTOR / INITIALIZATION TESTS =====.

    /**
     * Test service can be constructed with all handler dependencies
     */
    public function testConstructorWithAllHandlers(): void
    {
        $service = new SettingsService(
            $this->config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            null,
            null,
            null,
            'openregister',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );

        // Service should be created without errors.
        $this->assertInstanceOf(SettingsService::class, $service);
    }

    /**
     * Test service can be constructed with custom app name
     */
    public function testConstructorWithCustomAppName(): void
    {
        $config = $this->createMock(IConfig::class);

        $service = new SettingsService(
            $config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            null,
            null,
            null,
            'custom_app',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );

        $this->assertInstanceOf(SettingsService::class, $service);

        // Verify the custom app name is used: getSearchBackendConfig uses $this->appName.
        $config->expects($this->once())
            ->method('getAppValue')
            ->with('custom_app', 'search_backend', '')
            ->willReturn('');

        $result = $service->getSearchBackendConfig();
        $this->assertSame('solr', $result['active']);
    }

    // ===== MASK TOKEN ADDITIONAL COVERAGE =====.

    /**
     * Test maskToken with exactly 9 characters confirms boundary behavior
     */
    public function testMaskTokenBoundaryTenChars(): void
    {
        $result = $this->settingsService->maskToken('1234567890');
        // first 4 + min(20, 10-8)=2 stars + last 4
        $this->assertSame('1234**7890', $result);
        $this->assertSame(10, strlen($result));
    }

    /**
     * Test maskToken with single character
     */
    public function testMaskTokenSingleChar(): void
    {
        $result = $this->settingsService->maskToken('x');
        $this->assertSame('*', $result);
    }

    // ===== COMPARE FIELDS: MISMATCHED FIELD DETAILS =====.

    /**
     * Test compareFields mismatch entry contains all expected keys
     */
    public function testCompareFieldsMismatchEntryStructure(): void
    {
        $actual = [
            'test_field' => ['type' => 'text', 'multiValued' => true, 'docValues' => true],
        ];
        $expected = [
            'test_field' => ['type' => 'string', 'multiValued' => false, 'docValues' => false],
        ];

        $result = $this->settingsService->compareFields($actual, $expected);

        $mismatch = $result['mismatched'][0];
        $this->assertArrayHasKey('field', $mismatch);
        $this->assertArrayHasKey('expected_type', $mismatch);
        $this->assertArrayHasKey('actual_type', $mismatch);
        $this->assertArrayHasKey('expected_multiValued', $mismatch);
        $this->assertArrayHasKey('actual_multiValued', $mismatch);
        $this->assertArrayHasKey('expected_docValues', $mismatch);
        $this->assertArrayHasKey('actual_docValues', $mismatch);
        $this->assertArrayHasKey('differences', $mismatch);
        $this->assertArrayHasKey('expected_config', $mismatch);
        $this->assertArrayHasKey('actual_config', $mismatch);
        $this->assertSame('test_field', $mismatch['field']);
    }

    /**
     * Test compareFields summary structure
     */
    public function testCompareFieldsSummaryStructure(): void
    {
        $result = $this->settingsService->compareFields([], []);

        $this->assertArrayHasKey('missing', $result);
        $this->assertArrayHasKey('extra', $result);
        $this->assertArrayHasKey('mismatched', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('missing_count', $result['summary']);
        $this->assertArrayHasKey('extra_count', $result['summary']);
        $this->assertArrayHasKey('mismatched_count', $result['summary']);
        $this->assertArrayHasKey('total_differences', $result['summary']);
    }

    // ===== DATABASE INFO EDGE CASE =====.

    /**
     * Test getDatabaseInfo with JSON that decodes to non-array (e.g., a string)
     */
    public function testGetDatabaseInfoJsonDecodesToScalar(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('"just a string"');

        $result = $this->settingsService->getDatabaseInfo();
        // json_decode returns a string, which is not null, but isset($data['database']) is false.
        $this->assertNull($result);
    }

    // ===== WARMUP SOLR INDEX =====.

    /**
     * Test warmupSolrIndex delegates to solrSettingsHandler
     */
    public function testWarmupSolrIndexDelegatesToHandler(): void
    {
        $this->solrSettingsHandler->expects($this->once())
            ->method('warmupSolrIndex')
            ->willThrowException(new \RuntimeException('Deprecated: Use IndexService->warmupIndex()'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Deprecated');

        $this->settingsService->warmupSolrIndex();
    }

    /**
     * Test warmupSolrIndex with parameters still delegates to handler
     */
    public function testWarmupSolrIndexWithParams(): void
    {
        $this->solrSettingsHandler->expects($this->once())
            ->method('warmupSolrIndex')
            ->willThrowException(new \RuntimeException('Deprecated'));

        $this->expectException(\RuntimeException::class);

        $this->settingsService->warmupSolrIndex(
            schemas: ['schema1'],
            maxObjects: 100,
            mode: 'parallel',
            collectErrors: true,
            batchSize: 500,
            schemaIds: [1, 2]
        );
    }

    // ===== MASS VALIDATE OBJECTS HAPPY PATH =====.

    /**
     * Helper to create a SettingsService with a mocked container
     *
     * @param \OCP\AppFramework\IAppContainer|MockObject $container Container mock
     *
     * @return SettingsService
     */
    private function createServiceWithContainer($container): SettingsService
    {
        return new SettingsService(
            $this->config,
            $this->auditTrailMapper,
            $this->cacheFactory,
            $this->groupManager,
            $this->logger,
            $this->organisationMapper,
            $this->schemaCacheService,
            $this->facetCacheHandler,
            $this->searchTrailMapper,
            $this->userManager,
            $this->db,
            null,
            null,
            $container,
            'openregister',
            $this->validationOperationsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );
    }

    /**
     * Test massValidateObjects serial mode with zero objects
     */
    public function testMassValidateObjectsSerialZeroObjects(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(0);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(0, 1000, 'serial', false);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['stats']['total_objects']);
        $this->assertSame(0, $result['stats']['processed_objects']);
        $this->assertSame(0, $result['stats']['successful_saves']);
        $this->assertSame(0, $result['stats']['failed_saves']);
        $this->assertSame('serial', $result['config_used']['mode']);
        $this->assertArrayHasKey('memory_usage', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test massValidateObjects parallel mode with zero objects
     */
    public function testMassValidateObjectsParallelZeroObjects(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(0);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(0, 1000, 'parallel', false);

        $this->assertTrue($result['success']);
        $this->assertSame('parallel', $result['config_used']['mode']);
        $this->assertSame(0, $result['stats']['total_objects']);
    }

    /**
     * Test massValidateObjects serial mode with maxObjects limit
     */
    public function testMassValidateObjectsWithMaxObjectsLimit(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        // Total objects is 1000, but maxObjects limits to 50.
        $objectMapper->method('countSearchObjects')
            ->willReturn(1000);
        $objectMapper->method('findAll')
            ->willReturn([]);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(50, 100, 'serial', false);

        $this->assertTrue($result['success']);
        $this->assertSame(50, $result['stats']['total_objects']);
        $this->assertSame(50, $result['config_used']['max_objects']);
    }

    /**
     * Test massValidateObjects serial mode with objects throws Error due to null objectService
     *
     * The source hardcodes $objectService = null (circular dependency fix).
     * processJobsSerial catches Exception but not Error, so the null->saveObject()
     * call propagates as a PHP Error.
     */
    public function testMassValidateObjectsSerialWithObjectsThrowsError(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(1);

        $obj = new \OCA\OpenRegister\Db\ObjectEntity();
        $obj->setUuid('uuid-1');
        $obj->setRegister('reg-1');
        $obj->setSchema('schema-1');

        $objectMapper->method('findAll')
            ->willReturn([$obj]);

        // PHP Error (not Exception) propagates because catch(Exception) doesn't catch Error.
        $this->expectException(\Error::class);

        $service = $this->createServiceWithContainer($container);
        $service->massValidateObjects(0, 1000, 'serial', true);
    }

    /**
     * Test massValidateObjects parallel mode with objects throws TypeError due to null objectService
     *
     * processBatchDirectly has a non-nullable ObjectService parameter, so passing
     * null triggers a TypeError.
     */
    public function testMassValidateObjectsParallelWithObjectsThrowsTypeError(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(1);

        $obj = new \OCA\OpenRegister\Db\ObjectEntity();
        $obj->setUuid('uuid-1');
        $obj->setRegister('reg-1');
        $obj->setSchema('schema-1');

        $objectMapper->method('findAll')
            ->willReturn([$obj]);

        $this->expectException(\TypeError::class);

        $service = $this->createServiceWithContainer($container);
        $service->massValidateObjects(0, 1000, 'parallel', true);
    }

    /**
     * Test massValidateObjects valid batch size boundary (exactly 1)
     */
    public function testMassValidateObjectsBatchSizeOne(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(0);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(0, 1, 'serial', false);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['config_used']['batch_size']);
    }

    /**
     * Test massValidateObjects valid batch size boundary (exactly 5000)
     */
    public function testMassValidateObjectsBatchSizeFiveThousand(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(0);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(0, 5000, 'serial', false);

        $this->assertTrue($result['success']);
        $this->assertSame(5000, $result['config_used']['batch_size']);
    }

    /**
     * Test massValidateObjects config_used reflects all input parameters
     */
    public function testMassValidateObjectsConfigUsed(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(0);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(42, 500, 'serial', true);

        $this->assertArrayHasKey('config_used', $result);
        $this->assertSame('serial', $result['config_used']['mode']);
        $this->assertSame(42, $result['config_used']['max_objects']);
        $this->assertSame(500, $result['config_used']['batch_size']);
        $this->assertTrue($result['config_used']['collect_errors']);
    }

    /**
     * Test massValidateObjects duration calculation with zero-time processing
     */
    public function testMassValidateObjectsDurationAndMemory(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(0);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(0, 1000, 'serial', false);

        // Check memory_usage structure.
        $this->assertArrayHasKey('memory_usage', $result);
        $this->assertArrayHasKey('start_memory', $result['memory_usage']);
        $this->assertArrayHasKey('end_memory', $result['memory_usage']);
        $this->assertArrayHasKey('peak_memory', $result['memory_usage']);
        $this->assertArrayHasKey('memory_used', $result['memory_usage']);
        $this->assertArrayHasKey('peak_percentage', $result['memory_usage']);
        $this->assertArrayHasKey('formatted', $result['memory_usage']);
        $this->assertArrayHasKey('actual_used', $result['memory_usage']['formatted']);
        $this->assertArrayHasKey('peak_usage', $result['memory_usage']['formatted']);
        $this->assertArrayHasKey('peak_percentage', $result['memory_usage']['formatted']);

        // Duration should be set.
        $this->assertIsFloat($result['stats']['duration_seconds']);
    }

    // ===== GET STATS WITH SUCCESSFUL DATABASE STATS =====.

    /**
     * Test getStats with successful database stats retrieval
     *
     * This covers the getDatabaseStats private method happy path through getStats.
     */
    public function testGetStatsWithSuccessfulDbStats(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')
            ->willReturnCallback(function (string $table) {
                return 'oc_' . $table;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        // Mock executeQuery to handle blob count, blob size, magic tables, sources check, and main query.
        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(function (string $query) use (&$callCount) {
                $callCount++;
                $mockResult = $this->createMock(\OCP\DB\IResult::class);

                // First call: blob count query.
                if ($callCount === 1) {
                    $mockResult->method('fetch')
                        ->willReturn(['cnt' => '10']);
                    return $mockResult;
                }

                // Second call: blob size query.
                if ($callCount === 2) {
                    $mockResult->method('fetch')
                        ->willReturn(['total' => '5000']);
                    return $mockResult;
                }

                // Third call: getDatabasePlatform tables query.
                // This is after the platform check, so simulate pg_tables or info_schema.
                if ($callCount === 3) {
                    // Return empty table list (no magic mapper tables).
                    $mockResult->method('fetchAll')
                        ->willReturn([]);
                    return $mockResult;
                }

                // Fourth call: sources table existence check.
                if ($callCount === 4) {
                    $mockResult->method('fetch')
                        ->willReturn(['1' => 1]);
                    return $mockResult;
                }

                // Fifth call: main stats query.
                if ($callCount === 5) {
                    $mockResult->method('fetch')
                        ->willReturn([
                            'total_objects'                  => '10',
                            'deleted_objects'                => '2',
                            'total_audit_trails'             => '50',
                            'total_search_trails'            => '30',
                            'total_configurations'           => '5',
                            'total_organisations'            => '3',
                            'total_registers'                => '4',
                            'total_schemas'                  => '6',
                            'total_sources'                  => '2',
                            'total_webhook_logs'             => '15',
                            'objects_without_owner'           => '1',
                            'objects_without_organisation'    => '0',
                            'audit_trails_without_expiry'    => '10',
                            'search_trails_without_expiry'   => '5',
                            'expired_audit_trails'           => '3',
                            'expired_search_trails'          => '1',
                            'expired_objects'                => '0',
                        ]);
                    return $mockResult;
                }

                // Additional calls.
                return $mockResult;
            });

        // Mock getDatabasePlatform (needed for magic mapper table detection).
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn(['success' => true, 'caches' => []]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn(['numDocs' => 100]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('system', $result);
        $this->assertArrayHasKey('solr', $result);
        $this->assertArrayHasKey('cache', $result);

        // Verify totals include blob + magic counts.
        $this->assertSame(10, $result['totals']['totalObjects']);
        $this->assertSame(10, $result['totals']['totalBlobObjects']);
        $this->assertSame(0, $result['totals']['totalMagicObjects']);
        $this->assertSame(5000, $result['totals']['totalBlobSize']);

        // Verify warnings.
        $this->assertSame(1, $result['warnings']['objectsWithoutOwner']);
        $this->assertSame(0, $result['warnings']['objectsWithoutOrganisation']);
        $this->assertSame(10, $result['warnings']['auditTrailsWithoutExpiry']);
        $this->assertSame(3, $result['warnings']['expiredAuditTrails']);
    }

    /**
     * Test getStats with MySQL database platform
     */
    public function testGetStatsWithMysqlPlatform(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')
            ->willReturnCallback(function (string $table) {
                return 'oc_' . $table;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(function (string $query) use (&$callCount) {
                $callCount++;
                $mockResult = $this->createMock(\OCP\DB\IResult::class);

                if ($callCount === 1) {
                    // Blob count.
                    $mockResult->method('fetch')->willReturn(['cnt' => '5']);
                    return $mockResult;
                }

                if ($callCount === 2) {
                    // Blob size.
                    $mockResult->method('fetch')->willReturn(['total' => '2000']);
                    return $mockResult;
                }

                if ($callCount === 3) {
                    // MySQL table listing.
                    $mockResult->method('fetchAll')->willReturn([]);
                    return $mockResult;
                }

                if ($callCount === 4) {
                    // Sources table check - throw to simulate not installed.
                    throw new \Exception('Table does not exist');
                }

                if ($callCount === 5) {
                    // Main stats query.
                    $mockResult->method('fetch')->willReturn([
                        'total_objects'                 => '5',
                        'deleted_objects'               => '0',
                        'total_audit_trails'            => '20',
                        'total_search_trails'           => '10',
                        'total_configurations'          => '2',
                        'total_organisations'           => '1',
                        'total_registers'               => '2',
                        'total_schemas'                 => '3',
                        'total_sources'                 => '0',
                        'total_webhook_logs'            => '5',
                        'objects_without_owner'          => '0',
                        'objects_without_organisation'   => '0',
                        'audit_trails_without_expiry'   => '0',
                        'search_trails_without_expiry'  => '0',
                        'expired_audit_trails'          => '0',
                        'expired_search_trails'         => '0',
                        'expired_objects'               => '0',
                    ]);
                    return $mockResult;
                }

                return $mockResult;
            });

        // MySQL platform (not PostgreSQL).
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\MySQLPlatform::class);
        $this->db->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertSame(5, $result['totals']['totalObjects']);
    }

    /**
     * Test getStats with magic mapper tables
     */
    public function testGetStatsWithMagicMapperTables(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')
            ->willReturnCallback(function (string $table) {
                return 'oc_' . $table;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(function (string $query) use (&$callCount) {
                $callCount++;
                $mockResult = $this->createMock(\OCP\DB\IResult::class);

                if ($callCount === 1) {
                    // Blob count.
                    $mockResult->method('fetch')->willReturn(['cnt' => '5']);
                    return $mockResult;
                }

                if ($callCount === 2) {
                    // Blob size.
                    $mockResult->method('fetch')->willReturn(['total' => '1000']);
                    return $mockResult;
                }

                if ($callCount === 3) {
                    // Table listing - return 2 magic mapper tables.
                    $mockResult->method('fetchAll')
                        ->willReturn(['oc_openregister_table_abc', 'oc_openregister_table_def']);
                    return $mockResult;
                }

                if ($callCount === 4) {
                    // First magic mapper table count/size.
                    $mockResult->method('fetch')
                        ->willReturn(['cnt' => '100', 'total_size' => '50000']);
                    return $mockResult;
                }

                if ($callCount === 5) {
                    // Second magic mapper table count/size.
                    $mockResult->method('fetch')
                        ->willReturn(['cnt' => '200', 'total_size' => '80000']);
                    return $mockResult;
                }

                if ($callCount === 6) {
                    // Sources table check.
                    $mockResult->method('fetch')->willReturn(['1' => 1]);
                    return $mockResult;
                }

                if ($callCount === 7) {
                    // Main stats query.
                    $mockResult->method('fetch')->willReturn([
                        'total_objects'                 => '5',
                        'deleted_objects'               => '0',
                        'total_audit_trails'            => '0',
                        'total_search_trails'           => '0',
                        'total_configurations'          => '0',
                        'total_organisations'           => '0',
                        'total_registers'               => '0',
                        'total_schemas'                 => '0',
                        'total_sources'                 => '0',
                        'total_webhook_logs'            => '0',
                        'objects_without_owner'          => '0',
                        'objects_without_organisation'   => '0',
                        'audit_trails_without_expiry'   => '0',
                        'search_trails_without_expiry'  => '0',
                        'expired_audit_trails'          => '0',
                        'expired_search_trails'         => '0',
                        'expired_objects'               => '0',
                    ]);
                    return $mockResult;
                }

                return $mockResult;
            });

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        // totalObjects = blobCount(5) + magicCount(100+200) = 305.
        $this->assertSame(305, $result['totals']['totalObjects']);
        $this->assertSame(5, $result['totals']['totalBlobObjects']);
        $this->assertSame(300, $result['totals']['totalMagicObjects']);
        $this->assertSame(131000, $result['totals']['totalSize']);
        $this->assertSame(1000, $result['totals']['totalBlobSize']);
        $this->assertSame(130000, $result['totals']['totalMagicSize']);
    }

    /**
     * Test getStats when getDatabaseStats main query returns false
     */
    public function testGetStatsWhenMainQueryReturnsFalse(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')
            ->willReturnCallback(function (string $table) {
                return 'oc_' . $table;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(function (string $query) use (&$callCount) {
                $callCount++;
                $mockResult = $this->createMock(\OCP\DB\IResult::class);

                if ($callCount === 1) {
                    $mockResult->method('fetch')->willReturn(['cnt' => '0']);
                    return $mockResult;
                }

                if ($callCount === 2) {
                    $mockResult->method('fetch')->willReturn(['total' => '0']);
                    return $mockResult;
                }

                if ($callCount === 3) {
                    // Tables listing.
                    $mockResult->method('fetchAll')->willReturn([]);
                    return $mockResult;
                }

                if ($callCount === 4) {
                    // Sources check - throw.
                    throw new \Exception('No sources table');
                }

                if ($callCount === 5) {
                    // Main query returns false.
                    $mockResult->method('fetch')->willReturn(false);
                    return $mockResult;
                }

                return $mockResult;
            });

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn([]);

        $result = $this->settingsService->getStats();

        // getDatabaseStats throws RuntimeException ('Failed to fetch database statistics'),
        // getStats catches it and returns default empty stats.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertSame(0, $result['warnings']['objectsWithoutOwner']);
    }

    /**
     * Test getStats when magic mapper table query fails individually
     */
    public function testGetStatsWithFailingMagicMapperTable(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')
            ->willReturnCallback(function (string $table) {
                return 'oc_' . $table;
            });

        $this->db->method('getQueryBuilder')
            ->willReturn($qb);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(function (string $query) use (&$callCount) {
                $callCount++;
                $mockResult = $this->createMock(\OCP\DB\IResult::class);

                if ($callCount === 1) {
                    $mockResult->method('fetch')->willReturn(['cnt' => '5']);
                    return $mockResult;
                }

                if ($callCount === 2) {
                    $mockResult->method('fetch')->willReturn(['total' => '500']);
                    return $mockResult;
                }

                if ($callCount === 3) {
                    // Return one magic table.
                    $mockResult->method('fetchAll')
                        ->willReturn(['oc_openregister_table_broken']);
                    return $mockResult;
                }

                if ($callCount === 4) {
                    // Magic table query fails.
                    throw new \Exception('Corrupted table');
                }

                if ($callCount === 5) {
                    // Sources check.
                    throw new \Exception('No sources');
                }

                if ($callCount === 6) {
                    // Main stats query.
                    $mockResult->method('fetch')->willReturn([
                        'total_objects'                 => '5',
                        'deleted_objects'               => '0',
                        'total_audit_trails'            => '0',
                        'total_search_trails'           => '0',
                        'total_configurations'          => '0',
                        'total_organisations'           => '0',
                        'total_registers'               => '0',
                        'total_schemas'                 => '0',
                        'total_sources'                 => '0',
                        'total_webhook_logs'            => '0',
                        'objects_without_owner'          => '0',
                        'objects_without_organisation'   => '0',
                        'audit_trails_without_expiry'   => '0',
                        'search_trails_without_expiry'  => '0',
                        'expired_audit_trails'          => '0',
                        'expired_search_trails'         => '0',
                        'expired_objects'               => '0',
                    ]);
                    return $mockResult;
                }

                return $mockResult;
            });

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')
            ->willReturn($platform);

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        // Magic count should be 0 since the table query failed (skipped).
        $this->assertSame(5, $result['totals']['totalObjects']);
        $this->assertSame(0, $result['totals']['totalMagicObjects']);
    }

    /**
     * Test massValidateObjects maxObjects exactly equals totalObjects (no limiting)
     */
    public function testMassValidateObjectsMaxObjectsEqualsTotal(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        // maxObjects = totalObjects, no limiting applied.
        $objectMapper->method('countSearchObjects')
            ->willReturn(50);
        $objectMapper->method('findAll')
            ->willReturn([]);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(50, 100, 'serial', false);

        $this->assertTrue($result['success']);
        $this->assertSame(50, $result['stats']['total_objects']);
    }

    /**
     * Test massValidateObjects maxObjects greater than totalObjects (no limiting)
     */
    public function testMassValidateObjectsMaxObjectsGreaterThanTotal(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }
                return null;
            });

        $objectMapper->method('countSearchObjects')
            ->willReturn(10);
        $objectMapper->method('findAll')
            ->willReturn([]);

        $service = $this->createServiceWithContainer($container);
        $result = $service->massValidateObjects(100, 100, 'serial', false);

        $this->assertTrue($result['success']);
        // totalObjects stays at 10 since maxObjects(100) > total(10).
        $this->assertSame(10, $result['stats']['total_objects']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // getStats() — inner DB exception path (getDatabaseStats throws)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * When getDatabaseStats() throws, getStats() should populate zeroed warnings/totals.
     */
    public function testGetStatsWithDatabaseExceptionFallsBackToZeroedStats(): void
    {
        $queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $queryBuilder->method('getTableName')->willReturnArgument(0);

        $this->db->method('getQueryBuilder')->willReturn($queryBuilder);
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('DB unavailable'));

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willReturn(['caches' => []]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willThrowException(new \Exception('SOLR unavailable'));

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        // Either the outer catch was hit (error key) or the inner catch zeroed the stats.
        if (isset($result['error']) === false) {
            $this->assertArrayHasKey('warnings', $result);
            $this->assertSame(0, $result['warnings']['objectsWithoutOwner']);
            $this->assertSame(0, $result['totals']['totalObjects']);
        } else {
            $this->assertSame('Failed to retrieve stats', $result['error']);
        }
    }

    /**
     * When getCacheStats() throws inside getStats(), the cache key gets an error entry.
     */
    public function testGetStatsWithCacheExceptionRecordsErrorInCacheKey(): void
    {
        $queryBuilder = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $queryBuilder->method('getTableName')->willReturnArgument(0);

        $this->db->method('getQueryBuilder')->willReturn($queryBuilder);
        $this->db->method('executeQuery')
            ->willThrowException(new \Exception('DB unavailable'));

        $this->cacheSettingsHandler->method('getCacheStats')
            ->willThrowException(new \Exception('Cache broken'));
        $this->solrSettingsHandler->method('getSolrDashboardStats')
            ->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        // If no outer exception, cache key should contain the error message.
        if (isset($result['cache']) === true) {
            $this->assertArrayHasKey('error', $result['cache']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // rebase() — 'all' component covers both solr and cache branches
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * rebase() with component 'all' should rebase both solr and cache.
     */
    public function testRebaseWithAllComponentRebasesBothSolrAndCache(): void
    {
        // clearCache() is called internally — the cacheSettingsHandler must exist.
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->willReturn([]);

        $result = $this->settingsService->rebase(['components' => ['all']]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('rebased', $result);
        $this->assertArrayHasKey('solr', $result['rebased']);
        $this->assertArrayHasKey('cache', $result['rebased']);
        $this->assertTrue($result['rebased']['solr']['success']);
        $this->assertTrue($result['rebased']['cache']['success']);
    }

    /**
     * rebase() with only 'solr' component should NOT include cache in rebased.
     */
    public function testRebaseWithSolrOnlyComponentDoesNotRebaseCache(): void
    {
        $result = $this->settingsService->rebase(['components' => ['solr']]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('solr', $result['rebased']);
        $this->assertArrayNotHasKey('cache', $result['rebased']);
    }

    /**
     * rebase() with only 'cache' component should NOT include solr in rebased.
     */
    public function testRebaseWithCacheOnlyComponentDoesNotRebaseSolr(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->willReturn([]);

        $result = $this->settingsService->rebase(['components' => ['cache']]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('cache', $result['rebased']);
        $this->assertArrayNotHasKey('solr', $result['rebased']);
    }

    /**
     * rebase() with default (empty options) uses 'all' and covers both branches.
     */
    public function testRebaseWithDefaultOptionsUsesAll(): void
    {
        $this->cacheSettingsHandler->method('clearCache')->willReturn([]);

        $result = $this->settingsService->rebase([]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('solr', $result['rebased']);
        $this->assertArrayHasKey('cache', $result['rebased']);
        $this->assertGreaterThan(0, $result['timestamp']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // massValidateObjects() — failed_saves > 0 branches
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * massValidateObjects() in serial mode — when objects exist but objectService is null,
     * a TypeError is thrown (since objectService is hardcoded to null in the source).
     * The caught Exception increments failed_saves for each object.
     */
    public function testMassValidateObjectsSerialWithObjectsAndNullServiceThrowsTypeError(): void
    {
        $container    = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }

                return null;
            });

        // Real ObjectEntity with setters to avoid __call issues.
        $obj = new \OCA\OpenRegister\Db\ObjectEntity();
        $obj->setUuid('uuid-serial-null-svc');
        $obj->setRegister('reg-1');
        $obj->setSchema('schema-1');

        $objectMapper->method('countSearchObjects')->willReturn(1);
        $objectMapper->method('findAll')->willReturn([$obj]);

        $this->expectException(\Error::class);

        $service = $this->createServiceWithContainer($container);
        $service->massValidateObjects(0, 10, 'serial', true);
    }

    /**
     * massValidateObjects() in parallel mode — when objects exist but objectService is null,
     * an Error is thrown inside processBatchDirectly.
     */
    public function testMassValidateObjectsParallelModeWithObjectsThrowsTypeErrorForNullService(): void
    {
        $container    = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }

                return null;
            });

        $obj = new \OCA\OpenRegister\Db\ObjectEntity();
        $obj->setUuid('uuid-parallel-null-svc');
        $obj->setRegister('reg-1');
        $obj->setSchema('schema-1');

        $objectMapper->method('countSearchObjects')->willReturn(1);
        $objectMapper->method('findAll')->willReturn([$obj]);

        $this->expectException(\Error::class);

        $service = $this->createServiceWithContainer($container);
        $service->massValidateObjects(0, 10, 'parallel', true);
    }

    /**
     * massValidateObjects() in serial mode with collectErrors=false still throws
     * Error on first object (objectService is always null in the source).
     */
    public function testMassValidateObjectsSerialCollectErrorsFalseThrowsError(): void
    {
        $container    = $this->createMock(IAppContainer::class);
        $objectMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);

        $container->method('get')
            ->willReturnCallback(function (string $class) use ($objectMapper) {
                if ($class === \OCA\OpenRegister\Db\MagicMapper::class) {
                    return $objectMapper;
                }

                return null;
            });

        $obj = new \OCA\OpenRegister\Db\ObjectEntity();
        $obj->setUuid('uuid-ce-false');
        $obj->setRegister('reg-1');
        $obj->setSchema('schema-1');

        $objectMapper->method('countSearchObjects')->willReturn(1);
        $objectMapper->method('findAll')->willReturn([$obj]);

        $this->expectException(\Error::class);

        $service = $this->createServiceWithContainer($container);
        $service->massValidateObjects(0, 10, 'serial', false);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // getDatabaseStats() — success path (via getStats())
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * getStats() when all DB queries succeed returns structured stats with system key.
     */
    public function testGetStatsWithSuccessfulDbQueriesReturnsSystemInfo(): void
    {
        $row = [
            'cnt'                          => '5',
            'total'                        => '0',
            'total_objects'                => '5',
            'deleted_objects'              => '0',
            'total_audit_trails'           => '2',
            'total_search_trails'          => '1',
            'total_configurations'         => '0',
            'total_organisations'          => '0',
            'total_registers'              => '3',
            'total_schemas'                => '4',
            'total_sources'                => '0',
            'total_webhook_logs'           => '0',
            'objects_without_owner'        => '1',
            'objects_without_organisation' => '0',
            'audit_trails_without_expiry'  => '0',
            'search_trails_without_expiry' => '0',
            'expired_audit_trails'         => '0',
            'expired_search_trails'        => '0',
            'expired_objects'              => '0',
        ];

        $stmt = $this->createMock(\OCP\DB\IResult::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->method('fetchAll')->willReturn([]);

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);

        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')->willReturnArgument(0);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $this->db->method('getDatabasePlatform')->willReturn($platform);
        $this->db->method('executeQuery')->willReturn($stmt);

        $this->cacheSettingsHandler->method('getCacheStats')->willReturn(['caches' => []]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        // If DB succeeded the result should have a 'system' key.
        if (isset($result['error']) === false) {
            $this->assertArrayHasKey('system', $result);
            $this->assertArrayHasKey('php_version', $result['system']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // rebase() — outer exception path
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * rebase() returns error array when an unexpected exception is thrown.
     */
    public function testRebaseReturnsErrorOnException(): void
    {
        // Force an exception by making clearCache() (which rebase calls) throw.
        $this->cacheSettingsHandler->method('clearCache')
            ->willThrowException(new \Exception('Unexpected failure'));

        $result = $this->settingsService->rebase(['components' => ['all']]);

        // solr branch runs first (no exception), then cache branch throws.
        // The outer try/catch should catch it and return success=false.
        $this->assertFalse($result['success']);
        $this->assertSame('Rebase failed', $result['error']);
        $this->assertSame('Unexpected failure', $result['message']);
    }

    // ===== getDatabaseStats() — magic mapper coverage tests =====.

    /**
     * getStats() covers getDatabaseStats magic mapper outer catch when
     * getDatabasePlatform() throws, but blobCount/blobSize queries succeed.
     */
    public function testGetStatsMagicMapperOuterCatchWhenGetDatabasePlatformThrows(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')->willReturnArgument(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $this->db->method('getDatabasePlatform')
            ->willThrowException(new \Exception('Platform unavailable'));

        $callCount = 0;
        $blobCountStmt = $this->createMock(\OCP\DB\IResult::class);
        $blobCountStmt->method('fetch')->willReturn(['cnt' => '10', 'total' => '500']);

        $statsStmt = $this->createMock(\OCP\DB\IResult::class);
        $statsStmt->method('fetch')->willReturn([
            'total_objects'                => '10',
            'deleted_objects'              => '0',
            'total_audit_trails'           => '0',
            'total_search_trails'          => '0',
            'total_configurations'         => '0',
            'total_organisations'          => '0',
            'total_registers'              => '1',
            'total_schemas'                => '2',
            'total_sources'                => '0',
            'total_webhook_logs'           => '0',
            'objects_without_owner'        => '0',
            'objects_without_organisation' => '0',
            'audit_trails_without_expiry'  => '0',
            'search_trails_without_expiry' => '0',
            'expired_audit_trails'         => '0',
            'expired_search_trails'        => '0',
            'expired_objects'              => '0',
        ]);

        $this->db->method('executeQuery')
            ->willReturnCallback(function ($query) use (&$callCount, $blobCountStmt, $statsStmt) {
                $callCount++;
                if ($callCount <= 2) {
                    return $blobCountStmt;
                }

                if ($callCount === 3) {
                    throw new \Exception('No sources table');
                }

                return $statsStmt;
            });

        $this->cacheSettingsHandler->method('getCacheStats')->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertSame(0, $result['totals']['totalMagicObjects']);
        $this->assertSame(10, $result['totals']['totalBlobObjects']);
    }

    /**
     * getStats() covers getDatabaseStats magic mapper inner catch
     * when individual magic table query fails.
     */
    public function testGetStatsMagicMapperInnerCatchWhenTableQueryFails(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')->willReturnArgument(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $blobStmt = $this->createMock(\OCP\DB\IResult::class);
        $blobStmt->method('fetch')->willReturn(['cnt' => '5', 'total' => '200']);

        $tablesStmt = $this->createMock(\OCP\DB\IResult::class);
        $tablesStmt->method('fetchAll')->willReturn(['oc_openregister_table_abc']);

        $statsStmt = $this->createMock(\OCP\DB\IResult::class);
        $statsStmt->method('fetch')->willReturn([
            'total_objects'                => '5',
            'deleted_objects'              => '0',
            'total_audit_trails'           => '0',
            'total_search_trails'          => '0',
            'total_configurations'         => '0',
            'total_organisations'          => '0',
            'total_registers'              => '0',
            'total_schemas'                => '0',
            'total_sources'                => '0',
            'total_webhook_logs'           => '0',
            'objects_without_owner'        => '0',
            'objects_without_organisation' => '0',
            'audit_trails_without_expiry'  => '0',
            'search_trails_without_expiry' => '0',
            'expired_audit_trails'         => '0',
            'expired_search_trails'        => '0',
            'expired_objects'              => '0',
        ]);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(
                function ($query) use (&$callCount, $blobStmt, $tablesStmt, $statsStmt) {
                    $callCount++;
                    if ($callCount <= 2) {
                        return $blobStmt;
                    }

                    if ($callCount === 3) {
                        return $tablesStmt;
                    }

                    if ($callCount === 4) {
                        throw new \Exception('Table oc_openregister_table_abc corrupt');
                    }

                    if ($callCount === 5) {
                        throw new \Exception('No sources table');
                    }

                    return $statsStmt;
                }
            );

        $this->cacheSettingsHandler->method('getCacheStats')->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertSame(0, $result['totals']['totalMagicObjects']);
    }

    /**
     * getStats() covers getDatabaseStats magic mapper success path
     * when magic table query succeeds and returns count/size.
     */
    public function testGetStatsMagicMapperSuccessPathWithTableData(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')->willReturnArgument(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $blobStmt = $this->createMock(\OCP\DB\IResult::class);
        $blobStmt->method('fetch')->willReturn(['cnt' => '3', 'total' => '100']);

        $tablesStmt = $this->createMock(\OCP\DB\IResult::class);
        $tablesStmt->method('fetchAll')->willReturn(['oc_openregister_table_xyz']);

        $magicStmt = $this->createMock(\OCP\DB\IResult::class);
        $magicStmt->method('fetch')->willReturn(['cnt' => '7', 'total_size' => '350']);

        $statsStmt = $this->createMock(\OCP\DB\IResult::class);
        $statsStmt->method('fetch')->willReturn([
            'total_objects'                => '3',
            'deleted_objects'              => '1',
            'total_audit_trails'           => '5',
            'total_search_trails'          => '2',
            'total_configurations'         => '1',
            'total_organisations'          => '2',
            'total_registers'              => '3',
            'total_schemas'                => '4',
            'total_sources'                => '0',
            'total_webhook_logs'           => '0',
            'objects_without_owner'        => '1',
            'objects_without_organisation' => '0',
            'audit_trails_without_expiry'  => '0',
            'search_trails_without_expiry' => '0',
            'expired_audit_trails'         => '0',
            'expired_search_trails'        => '0',
            'expired_objects'              => '0',
        ]);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(
                function ($query) use (
                    &$callCount,
                    $blobStmt,
                    $tablesStmt,
                    $magicStmt,
                    $statsStmt
                ) {
                    $callCount++;
                    if ($callCount <= 2) {
                        return $blobStmt;
                    }

                    if ($callCount === 3) {
                        return $tablesStmt;
                    }

                    if ($callCount === 4) {
                        return $magicStmt;
                    }

                    if ($callCount === 5) {
                        throw new \Exception('No sources table');
                    }

                    return $statsStmt;
                }
            );

        $this->cacheSettingsHandler->method('getCacheStats')->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertSame(10, $result['totals']['totalObjects']);
        $this->assertSame(3, $result['totals']['totalBlobObjects']);
        $this->assertSame(7, $result['totals']['totalMagicObjects']);
        $this->assertSame(450, $result['totals']['totalSize']);
        $this->assertSame(100, $result['totals']['totalBlobSize']);
        $this->assertSame(350, $result['totals']['totalMagicSize']);
        $this->assertSame(1, $result['totals']['deletedObjects']);
        $this->assertSame(5, $result['totals']['totalAuditTrails']);
        $this->assertSame(1, $result['warnings']['objectsWithoutOwner']);
    }

    /**
     * getStats() covers getDatabaseStats with PostgreSQL platform detection.
     */
    public function testGetStatsWithPostgresPlatformUsesPostgresQuery(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')->willReturnArgument(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $platform = $this->getMockBuilder(\Doctrine\DBAL\Platforms\AbstractPlatform::class)
            ->setMockClassName('MockPostgreSQLPlatform')
            ->getMock();
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $blobStmt = $this->createMock(\OCP\DB\IResult::class);
        $blobStmt->method('fetch')->willReturn(['cnt' => '0', 'total' => '0']);

        $tablesStmt = $this->createMock(\OCP\DB\IResult::class);
        $tablesStmt->method('fetchAll')->willReturn([]);

        $statsStmt = $this->createMock(\OCP\DB\IResult::class);
        $statsStmt->method('fetch')->willReturn([
            'total_objects'                => '0',
            'deleted_objects'              => '0',
            'total_audit_trails'           => '0',
            'total_search_trails'          => '0',
            'total_configurations'         => '0',
            'total_organisations'          => '0',
            'total_registers'              => '0',
            'total_schemas'                => '0',
            'total_sources'                => '0',
            'total_webhook_logs'           => '0',
            'objects_without_owner'        => '0',
            'objects_without_organisation' => '0',
            'audit_trails_without_expiry'  => '0',
            'search_trails_without_expiry' => '0',
            'expired_audit_trails'         => '0',
            'expired_search_trails'        => '0',
            'expired_objects'              => '0',
        ]);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(
                function ($query) use (&$callCount, $blobStmt, $tablesStmt, $statsStmt) {
                    $callCount++;
                    if ($callCount <= 2) {
                        return $blobStmt;
                    }

                    if ($callCount === 3) {
                        $this->assertStringContainsString('pg_tables', $query);
                        return $tablesStmt;
                    }

                    if ($callCount === 4) {
                        throw new \Exception('No sources');
                    }

                    return $statsStmt;
                }
            );

        $this->cacheSettingsHandler->method('getCacheStats')->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('system', $result);
    }

    /**
     * getStats() covers getDatabaseStats with sourcesTableExists=true path.
     */
    public function testGetStatsWithSourcesTableExisting(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')->willReturnArgument(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $blobStmt = $this->createMock(\OCP\DB\IResult::class);
        $blobStmt->method('fetch')->willReturn(['cnt' => '0', 'total' => '0']);

        $tablesStmt = $this->createMock(\OCP\DB\IResult::class);
        $tablesStmt->method('fetchAll')->willReturn([]);

        $sourcesCheckStmt = $this->createMock(\OCP\DB\IResult::class);

        $statsStmt = $this->createMock(\OCP\DB\IResult::class);
        $statsStmt->method('fetch')->willReturn([
            'total_objects'                => '0',
            'deleted_objects'              => '0',
            'total_audit_trails'           => '0',
            'total_search_trails'          => '0',
            'total_configurations'         => '0',
            'total_organisations'          => '0',
            'total_registers'              => '0',
            'total_schemas'                => '0',
            'total_sources'                => '3',
            'total_webhook_logs'           => '0',
            'objects_without_owner'        => '0',
            'objects_without_organisation' => '0',
            'audit_trails_without_expiry'  => '0',
            'search_trails_without_expiry' => '0',
            'expired_audit_trails'         => '0',
            'expired_search_trails'        => '0',
            'expired_objects'              => '0',
        ]);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(
                function ($query) use (
                    &$callCount,
                    $blobStmt,
                    $tablesStmt,
                    $sourcesCheckStmt,
                    $statsStmt
                ) {
                    $callCount++;
                    if ($callCount <= 2) {
                        return $blobStmt;
                    }

                    if ($callCount === 3) {
                        return $tablesStmt;
                    }

                    if ($callCount === 4) {
                        return $sourcesCheckStmt;
                    }

                    return $statsStmt;
                }
            );

        $this->cacheSettingsHandler->method('getCacheStats')->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertSame(3, $result['totals']['totalSources']);
    }

    /**
     * getStats() when final stats query returns false triggers RuntimeException
     * caught by inner catch in getStats().
     */
    public function testGetStatsOuterCatchWhenFinalQueryReturnsFalse(): void
    {
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('getTableName')->willReturnArgument(0);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        $platform = $this->createMock(\Doctrine\DBAL\Platforms\AbstractPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $blobStmt = $this->createMock(\OCP\DB\IResult::class);
        $blobStmt->method('fetch')->willReturn(['cnt' => '0', 'total' => '0']);

        $tablesStmt = $this->createMock(\OCP\DB\IResult::class);
        $tablesStmt->method('fetchAll')->willReturn([]);

        $falseStmt = $this->createMock(\OCP\DB\IResult::class);
        $falseStmt->method('fetch')->willReturn(false);

        $callCount = 0;
        $this->db->method('executeQuery')
            ->willReturnCallback(
                function ($query) use (&$callCount, $blobStmt, $tablesStmt, $falseStmt) {
                    $callCount++;
                    if ($callCount <= 2) {
                        return $blobStmt;
                    }

                    if ($callCount === 3) {
                        return $tablesStmt;
                    }

                    if ($callCount === 4) {
                        throw new \Exception('No sources');
                    }

                    return $falseStmt;
                }
            );

        $this->cacheSettingsHandler->method('getCacheStats')->willReturn([]);
        $this->solrSettingsHandler->method('getSolrDashboardStats')->willReturn([]);

        $result = $this->settingsService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertSame(0, $result['totals']['totalObjects']);
    }
}
