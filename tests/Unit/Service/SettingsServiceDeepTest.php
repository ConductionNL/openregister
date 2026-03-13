<?php

/**
 * SettingsService Deep Coverage Tests
 *
 * Tests targeting uncovered lines in SettingsService:
 * - formatBytes (all unit ranges)
 * - convertToBytes (g, m, k, plain)
 * - maskToken (short, long tokens)
 * - getSearchBackendConfig (empty, valid JSON, exception)
 * - updateSearchBackendConfig
 * - getDatabaseInfo (empty, invalid JSON, valid)
 * - hasPostgresExtension (not postgres, extension found, not found)
 * - getPostgresExtensions
 * - massValidateObjects parameter validation
 * - createBatchJobs
 * - compareFields (missing, extra, mismatched fields)
 * - getStats with exception
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCA\OpenRegister\Service\Settings\SolrSettingsHandler;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep coverage tests for SettingsService
 */
class SettingsServiceDeepTest extends TestCase
{

    private SettingsService $service;

    private MockObject|IConfig $config;

    private MockObject|LoggerInterface $logger;

    private MockObject|IDBConnection $db;

    private MockObject|IAppContainer $container;

    private MockObject|SearchBackendHandler $searchBackendHandler;

    private MockObject|LlmSettingsHandler $llmSettingsHandler;

    private MockObject|FileSettingsHandler $fileSettingsHandler;

    private MockObject|ObjectRetentionHandler $objectRetentionHandler;

    private MockObject|CacheSettingsHandler $cacheSettingsHandler;

    private MockObject|SolrSettingsHandler $solrSettingsHandler;

    private MockObject|ConfigurationSettingsHandler $configSettingsHandler;

    private MockObject|ValidationOperationsHandler $validationOpsHandler;


    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config    = $this->createMock(IConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->db        = $this->createMock(IDBConnection::class);
        $this->container = $this->createMock(IAppContainer::class);

        $auditTrailMapper    = $this->createMock(AuditTrailMapper::class);
        $cacheFactory        = $this->createMock(ICacheFactory::class);
        $groupManager        = $this->createMock(IGroupManager::class);
        $organisationMapper  = $this->createMock(OrganisationMapper::class);
        $schemaCacheService  = $this->createMock(SchemaCacheHandler::class);
        $facetCacheService   = $this->createMock(FacetCacheHandler::class);
        $searchTrailMapper   = $this->createMock(SearchTrailMapper::class);
        $userManager         = $this->createMock(IUserManager::class);
        $objectCacheService  = $this->createMock(CacheHandler::class);

        $this->searchBackendHandler  = $this->createMock(SearchBackendHandler::class);
        $this->llmSettingsHandler    = $this->createMock(LlmSettingsHandler::class);
        $this->fileSettingsHandler   = $this->createMock(FileSettingsHandler::class);
        $this->objectRetentionHandler = $this->createMock(ObjectRetentionHandler::class);
        $this->cacheSettingsHandler  = $this->createMock(CacheSettingsHandler::class);
        $this->solrSettingsHandler   = $this->createMock(SolrSettingsHandler::class);
        $this->configSettingsHandler = $this->createMock(ConfigurationSettingsHandler::class);
        $this->validationOpsHandler  = $this->createMock(ValidationOperationsHandler::class);

        $this->service = new SettingsService(
            $this->config,
            $auditTrailMapper,
            $cacheFactory,
            $groupManager,
            $this->logger,
            $organisationMapper,
            $schemaCacheService,
            $facetCacheService,
            $searchTrailMapper,
            $userManager,
            $this->db,
            null,
            $objectCacheService,
            $this->container,
            'openregister',
            $this->validationOpsHandler,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configSettingsHandler
        );

    }//end setUp()


    // =========================================================================
    // formatBytes
    // =========================================================================

    /**
     * Test formatBytes with bytes
     *
     * @return void
     */
    public function testFormatBytesBytes(): void
    {
        $this->assertEquals('512 B', $this->service->formatBytes(512));

    }//end testFormatBytesBytes()


    /**
     * Test formatBytes with kilobytes
     *
     * @return void
     */
    public function testFormatBytesKilobytes(): void
    {
        $result = $this->service->formatBytes(2048);
        $this->assertStringContainsString('KB', $result);

    }//end testFormatBytesKilobytes()


    /**
     * Test formatBytes with megabytes
     *
     * @return void
     */
    public function testFormatBytesMegabytes(): void
    {
        $result = $this->service->formatBytes(2 * 1024 * 1024);
        $this->assertStringContainsString('MB', $result);

    }//end testFormatBytesMegabytes()


    /**
     * Test formatBytes with gigabytes
     *
     * @return void
     */
    public function testFormatBytesGigabytes(): void
    {
        $result = $this->service->formatBytes(3 * 1024 * 1024 * 1024);
        $this->assertStringContainsString('GB', $result);

    }//end testFormatBytesGigabytes()


    /**
     * Test formatBytes with zero
     *
     * @return void
     */
    public function testFormatBytesZero(): void
    {
        $this->assertEquals('0 B', $this->service->formatBytes(0));

    }//end testFormatBytesZero()


    /**
     * Test formatBytes with custom precision
     *
     * @return void
     */
    public function testFormatBytesCustomPrecision(): void
    {
        $result = $this->service->formatBytes(1536, 1);
        $this->assertStringContainsString('KB', $result);

    }//end testFormatBytesCustomPrecision()


    // =========================================================================
    // convertToBytes
    // =========================================================================

    /**
     * Test convertToBytes with G suffix
     *
     * @return void
     */
    public function testConvertToBytesGigabytes(): void
    {
        $result = $this->service->convertToBytes('2G');
        $this->assertEquals(2 * 1024 * 1024 * 1024, $result);

    }//end testConvertToBytesGigabytes()


    /**
     * Test convertToBytes with M suffix
     *
     * @return void
     */
    public function testConvertToBytesMegabytes(): void
    {
        $result = $this->service->convertToBytes('128M');
        $this->assertEquals(128 * 1024 * 1024, $result);

    }//end testConvertToBytesMegabytes()


    /**
     * Test convertToBytes with K suffix
     *
     * @return void
     */
    public function testConvertToBytesKilobytes(): void
    {
        $result = $this->service->convertToBytes('256K');
        $this->assertEquals(256 * 1024, $result);

    }//end testConvertToBytesKilobytes()


    /**
     * Test convertToBytes with plain number
     *
     * @return void
     */
    public function testConvertToBytesPlainNumber(): void
    {
        $result = $this->service->convertToBytes('1024');
        $this->assertEquals(1024, $result);

    }//end testConvertToBytesPlainNumber()


    // =========================================================================
    // maskToken
    // =========================================================================

    /**
     * Test maskToken with short token
     *
     * @return void
     */
    public function testMaskTokenShort(): void
    {
        $result = $this->service->maskToken('abc');
        $this->assertEquals('***', $result);

    }//end testMaskTokenShort()


    /**
     * Test maskToken with exactly 8 chars
     *
     * @return void
     */
    public function testMaskTokenEightChars(): void
    {
        $result = $this->service->maskToken('12345678');
        $this->assertEquals('********', $result);

    }//end testMaskTokenEightChars()


    /**
     * Test maskToken with long token
     *
     * @return void
     */
    public function testMaskTokenLong(): void
    {
        $result = $this->service->maskToken('sk-1234567890abcdef');
        $this->assertStringStartsWith('sk-1', $result);
        $this->assertStringEndsWith('cdef', $result);
        $this->assertStringContainsString('*', $result);

    }//end testMaskTokenLong()


    // =========================================================================
    // getSearchBackendConfig
    // =========================================================================

    /**
     * Test getSearchBackendConfig with empty config
     *
     * @return void
     */
    public function testGetSearchBackendConfigEmpty(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('');

        $result = $this->service->getSearchBackendConfig();
        $this->assertEquals('solr', $result['active']);
        $this->assertContains('solr', $result['available']);

    }//end testGetSearchBackendConfigEmpty()


    /**
     * Test getSearchBackendConfig with valid JSON
     *
     * @return void
     */
    public function testGetSearchBackendConfigValidJson(): void
    {
        $this->config->method('getAppValue')
            ->willReturn('{"active":"elasticsearch","available":["elasticsearch"]}');

        $result = $this->service->getSearchBackendConfig();
        $this->assertEquals('elasticsearch', $result['active']);

    }//end testGetSearchBackendConfigValidJson()


    /**
     * Test getSearchBackendConfig with exception
     *
     * @return void
     */
    public function testGetSearchBackendConfigException(): void
    {
        $this->config->method('getAppValue')
            ->willThrowException(new \Exception('Config error'));

        $result = $this->service->getSearchBackendConfig();
        $this->assertEquals('solr', $result['active']);

    }//end testGetSearchBackendConfigException()


    // =========================================================================
    // updateSearchBackendConfig
    // =========================================================================

    /**
     * Test updateSearchBackendConfig with active key
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigWithActiveKey(): void
    {
        $this->searchBackendHandler->expects($this->once())
            ->method('updateSearchBackendConfig')
            ->with('elasticsearch')
            ->willReturn(['active' => 'elasticsearch']);

        $result = $this->service->updateSearchBackendConfig(['active' => 'elasticsearch']);
        $this->assertEquals('elasticsearch', $result['active']);

    }//end testUpdateSearchBackendConfigWithActiveKey()


    /**
     * Test updateSearchBackendConfig with backend key
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigWithBackendKey(): void
    {
        $this->searchBackendHandler->expects($this->once())
            ->method('updateSearchBackendConfig')
            ->with('solr')
            ->willReturn(['active' => 'solr']);

        $result = $this->service->updateSearchBackendConfig(['backend' => 'solr']);
        $this->assertEquals('solr', $result['active']);

    }//end testUpdateSearchBackendConfigWithBackendKey()


    // =========================================================================
    // getDatabaseInfo
    // =========================================================================

    /**
     * Test getDatabaseInfo with empty config
     *
     * @return void
     */
    public function testGetDatabaseInfoEmpty(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('');

        $this->assertNull($this->service->getDatabaseInfo());

    }//end testGetDatabaseInfoEmpty()


    /**
     * Test getDatabaseInfo with invalid JSON
     *
     * @return void
     */
    public function testGetDatabaseInfoInvalidJson(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('not-json');

        $this->assertNull($this->service->getDatabaseInfo());

    }//end testGetDatabaseInfoInvalidJson()


    /**
     * Test getDatabaseInfo with valid data
     *
     * @return void
     */
    public function testGetDatabaseInfoValid(): void
    {
        $data = json_encode([
            'database' => [
                'type'       => 'PostgreSQL',
                'version'    => '14.0',
                'extensions' => [['name' => 'vector']],
            ],
        ]);

        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($data);

        $result = $this->service->getDatabaseInfo();
        $this->assertEquals('PostgreSQL', $result['type']);

    }//end testGetDatabaseInfoValid()


    /**
     * Test getDatabaseInfo with missing database key
     *
     * @return void
     */
    public function testGetDatabaseInfoMissingDatabaseKey(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('{"other":"value"}');

        $this->assertNull($this->service->getDatabaseInfo());

    }//end testGetDatabaseInfoMissingDatabaseKey()


    // =========================================================================
    // hasPostgresExtension
    // =========================================================================

    /**
     * Test hasPostgresExtension when not postgres
     *
     * @return void
     */
    public function testHasPostgresExtensionNotPostgres(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn(json_encode(['database' => ['type' => 'MySQL']]));

        $this->assertFalse($this->service->hasPostgresExtension('vector'));

    }//end testHasPostgresExtensionNotPostgres()


    /**
     * Test hasPostgresExtension when extension exists
     *
     * @return void
     */
    public function testHasPostgresExtensionFound(): void
    {
        $data = json_encode([
            'database' => [
                'type'       => 'PostgreSQL',
                'extensions' => [
                    ['name' => 'pg_trgm'],
                    ['name' => 'vector'],
                ],
            ],
        ]);

        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($data);

        $this->assertTrue($this->service->hasPostgresExtension('vector'));

    }//end testHasPostgresExtensionFound()


    /**
     * Test hasPostgresExtension when extension not found
     *
     * @return void
     */
    public function testHasPostgresExtensionNotFound(): void
    {
        $data = json_encode([
            'database' => [
                'type'       => 'PostgreSQL',
                'extensions' => [['name' => 'pg_trgm']],
            ],
        ]);

        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($data);

        $this->assertFalse($this->service->hasPostgresExtension('vector'));

    }//end testHasPostgresExtensionNotFound()


    /**
     * Test hasPostgresExtension when no dbInfo
     *
     * @return void
     */
    public function testHasPostgresExtensionNoDbInfo(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn('');

        $this->assertFalse($this->service->hasPostgresExtension('vector'));

    }//end testHasPostgresExtensionNoDbInfo()


    // =========================================================================
    // getPostgresExtensions
    // =========================================================================

    /**
     * Test getPostgresExtensions not postgres
     *
     * @return void
     */
    public function testGetPostgresExtensionsNotPostgres(): void
    {
        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn(json_encode(['database' => ['type' => 'MySQL']]));

        $this->assertEquals([], $this->service->getPostgresExtensions());

    }//end testGetPostgresExtensionsNotPostgres()


    /**
     * Test getPostgresExtensions returns extensions
     *
     * @return void
     */
    public function testGetPostgresExtensionsReturns(): void
    {
        $exts = [['name' => 'pg_trgm'], ['name' => 'vector']];
        $data = json_encode([
            'database' => [
                'type'       => 'PostgreSQL',
                'extensions' => $exts,
            ],
        ]);

        $this->config->method('getAppValue')
            ->with('openregister', 'databaseInfo', '')
            ->willReturn($data);

        $result = $this->service->getPostgresExtensions();
        $this->assertCount(2, $result);

    }//end testGetPostgresExtensionsReturns()


    // =========================================================================
    // massValidateObjects — parameter validation
    // =========================================================================

    /**
     * Test massValidateObjects with invalid mode
     *
     * @return void
     */
    public function testMassValidateObjectsInvalidMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mode parameter');

        $this->service->massValidateObjects(0, 1000, 'invalid_mode');

    }//end testMassValidateObjectsInvalidMode()


    /**
     * Test massValidateObjects with batch size too small
     *
     * @return void
     */
    public function testMassValidateObjectsBatchSizeTooSmall(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid batch size');

        $this->service->massValidateObjects(0, 0, 'serial');

    }//end testMassValidateObjectsBatchSizeTooSmall()


    /**
     * Test massValidateObjects with batch size too large
     *
     * @return void
     */
    public function testMassValidateObjectsBatchSizeTooLarge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid batch size');

        $this->service->massValidateObjects(0, 10000, 'serial');

    }//end testMassValidateObjectsBatchSizeTooLarge()


    // =========================================================================
    // createBatchJobs (private)
    // =========================================================================

    /**
     * Test createBatchJobs creates correct batches
     *
     * @return void
     */
    public function testCreateBatchJobs(): void
    {
        $method = new \ReflectionMethod(SettingsService::class, 'createBatchJobs');

        $result = $method->invoke($this->service, 250, 100);

        $this->assertCount(3, $result);

        $this->assertEquals(1, $result[0]['batchNumber']);
        $this->assertEquals(0, $result[0]['offset']);
        $this->assertEquals(100, $result[0]['limit']);

        $this->assertEquals(2, $result[1]['batchNumber']);
        $this->assertEquals(100, $result[1]['offset']);
        $this->assertEquals(100, $result[1]['limit']);

        $this->assertEquals(3, $result[2]['batchNumber']);
        $this->assertEquals(200, $result[2]['offset']);
        $this->assertEquals(50, $result[2]['limit']);

    }//end testCreateBatchJobs()


    /**
     * Test createBatchJobs with zero objects
     *
     * @return void
     */
    public function testCreateBatchJobsZero(): void
    {
        $method = new \ReflectionMethod(SettingsService::class, 'createBatchJobs');

        $result = $method->invoke($this->service, 0, 100);
        $this->assertCount(0, $result);

    }//end testCreateBatchJobsZero()


    /**
     * Test createBatchJobs with exact batch size
     *
     * @return void
     */
    public function testCreateBatchJobsExact(): void
    {
        $method = new \ReflectionMethod(SettingsService::class, 'createBatchJobs');

        $result = $method->invoke($this->service, 100, 100);
        $this->assertCount(1, $result);
        $this->assertEquals(100, $result[0]['limit']);

    }//end testCreateBatchJobsExact()


    // =========================================================================
    // compareFields
    // =========================================================================

    /**
     * Test compareFields with no differences
     *
     * @return void
     */
    public function testCompareFieldsNoDifferences(): void
    {
        $expected = [
            'title' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false],
        ];
        $actual   = [
            'title' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false],
        ];

        $result = $this->service->compareFields($actual, $expected);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['extra']);
        $this->assertEmpty($result['mismatched']);
        $this->assertEquals(0, $result['summary']['total_differences']);

    }//end testCompareFieldsNoDifferences()


    /**
     * Test compareFields with missing fields
     *
     * @return void
     */
    public function testCompareFieldsMissingFields(): void
    {
        $expected = [
            'title'       => ['type' => 'text_general'],
            'description' => ['type' => 'text_general'],
        ];
        $actual   = [
            'title' => ['type' => 'text_general'],
        ];

        $result = $this->service->compareFields($actual, $expected);
        $this->assertCount(1, $result['missing']);
        $this->assertEquals('description', $result['missing'][0]['field']);

    }//end testCompareFieldsMissingFields()


    /**
     * Test compareFields with extra fields
     *
     * @return void
     */
    public function testCompareFieldsExtraFields(): void
    {
        $expected = ['title' => ['type' => 'text_general']];
        $actual   = [
            'title'   => ['type' => 'text_general'],
            'custom'  => ['type' => 'string'],
        ];

        $result = $this->service->compareFields($actual, $expected);
        $this->assertCount(1, $result['extra']);
        $this->assertEquals('custom', $result['extra'][0]['field']);

    }//end testCompareFieldsExtraFields()


    /**
     * Test compareFields with mismatched type
     *
     * @return void
     */
    public function testCompareFieldsMismatchedType(): void
    {
        $expected = ['title' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false]];
        $actual   = ['title' => ['type' => 'string', 'multiValued' => false, 'docValues' => false]];

        $result = $this->service->compareFields($actual, $expected);
        $this->assertCount(1, $result['mismatched']);
        $this->assertContains('type', $result['mismatched'][0]['differences']);

    }//end testCompareFieldsMismatchedType()


    /**
     * Test compareFields with mismatched multiValued
     *
     * @return void
     */
    public function testCompareFieldsMismatchedMultiValued(): void
    {
        $expected = ['tags' => ['type' => 'string', 'multiValued' => true, 'docValues' => false]];
        $actual   = ['tags' => ['type' => 'string', 'multiValued' => false, 'docValues' => false]];

        $result = $this->service->compareFields($actual, $expected);
        $this->assertCount(1, $result['mismatched']);
        $this->assertContains('multiValued', $result['mismatched'][0]['differences']);

    }//end testCompareFieldsMismatchedMultiValued()


    /**
     * Test compareFields skips system fields starting with underscore
     *
     * @return void
     */
    public function testCompareFieldsSkipsSystemFields(): void
    {
        $expected = [];
        $actual   = ['_version_' => ['type' => 'long']];

        $result = $this->service->compareFields($actual, $expected);
        $this->assertEmpty($result['extra']);

    }//end testCompareFieldsSkipsSystemFields()


    // =========================================================================
    // Handler delegation methods
    // =========================================================================

    /**
     * Test getLLMSettingsOnly delegates
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyDelegates(): void
    {
        $this->llmSettingsHandler->expects($this->once())
            ->method('getLLMSettingsOnly')
            ->willReturn(['provider' => 'openai']);

        $result = $this->service->getLLMSettingsOnly();
        $this->assertEquals('openai', $result['provider']);

    }//end testGetLLMSettingsOnlyDelegates()


    /**
     * Test getFileSettingsOnly delegates
     *
     * @return void
     */
    public function testGetFileSettingsOnlyDelegates(): void
    {
        $this->fileSettingsHandler->expects($this->once())
            ->method('getFileSettingsOnly')
            ->willReturn(['maxSize' => 10]);

        $result = $this->service->getFileSettingsOnly();
        $this->assertEquals(10, $result['maxSize']);

    }//end testGetFileSettingsOnlyDelegates()


    /**
     * Test getObjectSettingsOnly delegates
     *
     * @return void
     */
    public function testGetObjectSettingsOnlyDelegates(): void
    {
        $this->objectRetentionHandler->expects($this->once())
            ->method('getObjectSettingsOnly')
            ->willReturn(['vectorize' => true]);

        $result = $this->service->getObjectSettingsOnly();
        $this->assertTrue($result['vectorize']);

    }//end testGetObjectSettingsOnlyDelegates()


    /**
     * Test getRetentionSettingsOnly delegates
     *
     * @return void
     */
    public function testGetRetentionSettingsOnlyDelegates(): void
    {
        $this->objectRetentionHandler->expects($this->once())
            ->method('getRetentionSettingsOnly')
            ->willReturn(['days' => 30]);

        $result = $this->service->getRetentionSettingsOnly();
        $this->assertEquals(30, $result['days']);

    }//end testGetRetentionSettingsOnlyDelegates()


    /**
     * Test getCacheStats delegates
     *
     * @return void
     */
    public function testGetCacheStatsDelegates(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('getCacheStats')
            ->willReturn(['hits' => 100]);

        $result = $this->service->getCacheStats();
        $this->assertEquals(100, $result['hits']);

    }//end testGetCacheStatsDelegates()


    /**
     * Test clearCache delegates
     *
     * @return void
     */
    public function testClearCacheDelegates(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('all')
            ->willReturn(['cleared' => true]);

        $result = $this->service->clearCache();
        $this->assertTrue($result['cleared']);

    }//end testClearCacheDelegates()


    /**
     * Test clearCache with specific type delegates
     *
     * @return void
     */
    public function testClearCacheWithTypeDelegates(): void
    {
        $this->cacheSettingsHandler->expects($this->once())
            ->method('clearCache')
            ->with('schema')
            ->willReturn(['cleared' => true]);

        $result = $this->service->clearCache('schema');
        $this->assertTrue($result['cleared']);

    }//end testClearCacheWithTypeDelegates()


    /**
     * Test isMultiTenancyEnabled delegates
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledDelegates(): void
    {
        $this->configSettingsHandler->expects($this->once())
            ->method('isMultiTenancyEnabled')
            ->willReturn(true);

        $this->assertTrue($this->service->isMultiTenancyEnabled());

    }//end testIsMultiTenancyEnabledDelegates()


    /**
     * Test getTenantId delegates
     *
     * @return void
     */
    public function testGetTenantIdDelegates(): void
    {
        $this->configSettingsHandler->expects($this->once())
            ->method('getTenantId')
            ->willReturn('tenant-123');

        $this->assertEquals('tenant-123', $this->service->getTenantId());

    }//end testGetTenantIdDelegates()


    /**
     * Test getOrganisationId delegates
     *
     * @return void
     */
    public function testGetOrganisationIdDelegates(): void
    {
        $this->configSettingsHandler->expects($this->once())
            ->method('getOrganisationId')
            ->willReturn('org-abc');

        $this->assertEquals('org-abc', $this->service->getOrganisationId());

    }//end testGetOrganisationIdDelegates()


}//end class
