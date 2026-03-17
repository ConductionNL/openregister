<?php

/**
 * SettingsService Gap Coverage Tests
 *
 * Tests for uncovered methods in SettingsService including
 * utility methods, database info, field comparison, and delegation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

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
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Gap coverage tests for SettingsService
 */
class SettingsServiceGapTest extends TestCase
{
    /** @var SettingsService */
    private SettingsService $service;

    /** @var MockObject|IConfig */
    private $config;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var MockObject|SearchBackendHandler */
    private $searchBackendHandler;

    /** @var MockObject|LlmSettingsHandler */
    private $llmSettingsHandler;

    /** @var MockObject|FileSettingsHandler */
    private $fileSettingsHandler;

    /** @var MockObject|ObjectRetentionHandler */
    private $objectRetentionHandler;

    /** @var MockObject|CacheSettingsHandler */
    private $cacheSettingsHandler;

    /** @var MockObject|SolrSettingsHandler */
    private $solrSettingsHandler;

    /** @var MockObject|ConfigurationSettingsHandler */
    private $configurationSettingsHandler;

    /**
     * Set up test dependencies
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config  = $this->createMock(IConfig::class);
        $this->logger  = $this->createMock(LoggerInterface::class);

        $auditTrailMapper  = $this->createMock(AuditTrailMapper::class);
        $cacheFactory      = $this->createMock(ICacheFactory::class);
        $groupManager      = $this->createMock(IGroupManager::class);
        $organisationMapper = $this->createMock(OrganisationMapper::class);
        $schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $facetCacheService  = $this->createMock(FacetCacheHandler::class);
        $searchTrailMapper  = $this->createMock(SearchTrailMapper::class);
        $userManager        = $this->createMock(IUserManager::class);
        $db                 = $this->createMock(IDBConnection::class);

        $this->searchBackendHandler        = $this->createMock(SearchBackendHandler::class);
        $this->llmSettingsHandler          = $this->createMock(LlmSettingsHandler::class);
        $this->fileSettingsHandler         = $this->createMock(FileSettingsHandler::class);
        $this->objectRetentionHandler      = $this->createMock(ObjectRetentionHandler::class);
        $this->cacheSettingsHandler        = $this->createMock(CacheSettingsHandler::class);
        $this->solrSettingsHandler         = $this->createMock(SolrSettingsHandler::class);
        $this->configurationSettingsHandler = $this->createMock(ConfigurationSettingsHandler::class);

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
            $db,
            null,
            null,
            null,
            'openregister',
            null,
            $this->searchBackendHandler,
            $this->llmSettingsHandler,
            $this->fileSettingsHandler,
            $this->objectRetentionHandler,
            $this->cacheSettingsHandler,
            $this->solrSettingsHandler,
            $this->configurationSettingsHandler
        );
    }

    // =============================================
    // formatBytes tests (public, line 1493)
    // =============================================

    /**
     * Test formatBytes with bytes
     *
     * @return void
     */
    public function testFormatBytesWithBytes(): void
    {
        $result = $this->service->formatBytes(500);
        $this->assertEquals('500 B', $result);
    }

    /**
     * Test formatBytes with kilobytes
     *
     * @return void
     */
    public function testFormatBytesWithKilobytes(): void
    {
        $result = $this->service->formatBytes(2048);
        $this->assertEquals('2 KB', $result);
    }

    /**
     * Test formatBytes with megabytes
     *
     * @return void
     */
    public function testFormatBytesWithMegabytes(): void
    {
        // 1048576 = 1024*1024, but loop condition is > 1024 (strict), so it shows 1024 KB.
        // Use a value > 1024*1024 to trigger MB display.
        $result = $this->service->formatBytes(1048577);
        $this->assertStringContainsString('MB', $result);
    }

    /**
     * Test formatBytes with gigabytes
     *
     * @return void
     */
    public function testFormatBytesWithGigabytes(): void
    {
        // Use value > 1024^3 to trigger GB.
        $result = $this->service->formatBytes(2147483648);
        $this->assertStringContainsString('GB', $result);
    }

    /**
     * Test formatBytes with zero
     *
     * @return void
     */
    public function testFormatBytesWithZero(): void
    {
        $result = $this->service->formatBytes(0);
        $this->assertEquals('0 B', $result);
    }

    /**
     * Test formatBytes with custom precision
     *
     * @return void
     */
    public function testFormatBytesWithCustomPrecision(): void
    {
        $result = $this->service->formatBytes(1536, 1);
        $this->assertEquals('1.5 KB', $result);
    }

    // =============================================
    // convertToBytes tests (public, line 1516)
    // =============================================

    /**
     * Test convertToBytes with megabytes
     *
     * @return void
     */
    public function testConvertToBytesWithMegabytes(): void
    {
        $result = $this->service->convertToBytes('128M');
        $this->assertEquals(128 * 1024 * 1024, $result);
    }

    /**
     * Test convertToBytes with gigabytes
     *
     * @return void
     */
    public function testConvertToBytesWithGigabytes(): void
    {
        $result = $this->service->convertToBytes('1G');
        $this->assertEquals(1 * 1024 * 1024 * 1024, $result);
    }

    /**
     * Test convertToBytes with kilobytes
     *
     * @return void
     */
    public function testConvertToBytesWithKilobytes(): void
    {
        $result = $this->service->convertToBytes('512K');
        $this->assertEquals(512 * 1024, $result);
    }

    /**
     * Test convertToBytes with plain number
     *
     * @return void
     */
    public function testConvertToBytesWithPlainNumber(): void
    {
        $result = $this->service->convertToBytes('1024');
        $this->assertEquals(1024, $result);
    }

    // =============================================
    // maskToken tests (public, line 1545)
    // =============================================

    /**
     * Test maskToken with long token
     *
     * @return void
     */
    public function testMaskTokenWithLongToken(): void
    {
        $result = $this->service->maskToken('sk-1234567890abcdef');
        $this->assertStringStartsWith('sk-1', $result);
        $this->assertStringEndsWith('cdef', $result);
        $this->assertStringContainsString('*', $result);
    }

    /**
     * Test maskToken with short token
     *
     * @return void
     */
    public function testMaskTokenWithShortToken(): void
    {
        $result = $this->service->maskToken('abc');
        $this->assertEquals('***', $result);
    }

    /**
     * Test maskToken with exactly 8 chars
     *
     * @return void
     */
    public function testMaskTokenWith8Chars(): void
    {
        $result = $this->service->maskToken('12345678');
        $this->assertEquals('********', $result);
    }

    /**
     * Test maskToken with 9 chars shows first 4 and last 4
     *
     * @return void
     */
    public function testMaskTokenWith9Chars(): void
    {
        $result = $this->service->maskToken('123456789');
        $this->assertStringStartsWith('1234', $result);
        $this->assertStringEndsWith('6789', $result);
    }

    // =============================================
    // compareFields tests (public, line 1626)
    // =============================================

    /**
     * Test compareFields finds missing fields
     *
     * @return void
     */
    public function testCompareFieldsFindsMissing(): void
    {
        $actual   = [];
        $expected = [
            'title' => ['type' => 'text_general'],
            'body'  => ['type' => 'text_general'],
        ];

        $result = $this->service->compareFields($actual, $expected);

        $this->assertCount(2, $result['missing']);
        $this->assertEquals(2, $result['summary']['missing_count']);
    }

    /**
     * Test compareFields finds extra fields
     *
     * @return void
     */
    public function testCompareFieldsFindsExtra(): void
    {
        $actual = [
            'title'       => ['type' => 'text_general'],
            'extra_field' => ['type' => 'string'],
        ];
        $expected = [
            'title' => ['type' => 'text_general'],
        ];

        $result = $this->service->compareFields($actual, $expected);

        $this->assertCount(1, $result['extra']);
        $this->assertEquals('extra_field', $result['extra'][0]['field']);
    }

    /**
     * Test compareFields finds mismatched types
     *
     * @return void
     */
    public function testCompareFieldsFindsMismatched(): void
    {
        $actual = [
            'title' => ['type' => 'string'],
        ];
        $expected = [
            'title' => ['type' => 'text_general'],
        ];

        $result = $this->service->compareFields($actual, $expected);

        $this->assertCount(1, $result['mismatched']);
        $this->assertContains('type', $result['mismatched'][0]['differences']);
    }

    /**
     * Test compareFields with matching fields
     *
     * @return void
     */
    public function testCompareFieldsAllMatch(): void
    {
        $fields = [
            'title' => ['type' => 'text_general', 'multiValued' => false, 'docValues' => false],
        ];

        $result = $this->service->compareFields($fields, $fields);

        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['extra']);
        $this->assertEmpty($result['mismatched']);
        $this->assertEquals(0, $result['summary']['total_differences']);
    }

    /**
     * Test compareFields skips system fields starting with _
     *
     * @return void
     */
    public function testCompareFieldsSkipsSystemFields(): void
    {
        $actual = [
            '_version_' => ['type' => 'plong'],
            'title'     => ['type' => 'text_general'],
        ];
        $expected = [
            'title' => ['type' => 'text_general'],
        ];

        $result = $this->service->compareFields($actual, $expected);

        // _version_ should be skipped.
        $this->assertEmpty($result['extra']);
    }

    /**
     * Test compareFields detects multiValued mismatch
     *
     * @return void
     */
    public function testCompareFieldsDetectsMultiValuedMismatch(): void
    {
        $actual = [
            'tags' => ['type' => 'string', 'multiValued' => true, 'docValues' => false],
        ];
        $expected = [
            'tags' => ['type' => 'string', 'multiValued' => false, 'docValues' => false],
        ];

        $result = $this->service->compareFields($actual, $expected);

        $this->assertCount(1, $result['mismatched']);
        $this->assertContains('multiValued', $result['mismatched'][0]['differences']);
    }

    // =============================================
    // getDatabaseInfo tests (public, line 858)
    // =============================================

    /**
     * Test getDatabaseInfo returns null when empty
     *
     * @return void
     */
    public function testGetDatabaseInfoReturnsNullWhenEmpty(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn('');

        $result = $this->service->getDatabaseInfo();
        $this->assertNull($result);
    }

    /**
     * Test getDatabaseInfo returns null when invalid JSON
     *
     * @return void
     */
    public function testGetDatabaseInfoReturnsNullWhenInvalidJson(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn('not-json');

        $result = $this->service->getDatabaseInfo();
        $this->assertNull($result);
    }

    /**
     * Test getDatabaseInfo returns null when no database key
     *
     * @return void
     */
    public function testGetDatabaseInfoReturnsNullWhenNoDatabaseKey(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn('{"other": "data"}');

        $result = $this->service->getDatabaseInfo();
        $this->assertNull($result);
    }

    /**
     * Test getDatabaseInfo returns database data
     *
     * @return void
     */
    public function testGetDatabaseInfoReturnsDatabaseData(): void
    {
        $dbData = ['type' => 'PostgreSQL', 'version' => '15.2'];
        $this->config
            ->method('getAppValue')
            ->willReturn(json_encode(['database' => $dbData]));

        $result = $this->service->getDatabaseInfo();
        $this->assertEquals('PostgreSQL', $result['type']);
        $this->assertEquals('15.2', $result['version']);
    }

    // =============================================
    // hasPostgresExtension tests (public, line 880)
    // =============================================

    /**
     * Test hasPostgresExtension returns false when no db info
     *
     * @return void
     */
    public function testHasPostgresExtensionReturnsFalseWhenNoDbInfo(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn('');

        $result = $this->service->hasPostgresExtension('vector');
        $this->assertFalse($result);
    }

    /**
     * Test hasPostgresExtension returns false for non-postgres
     *
     * @return void
     */
    public function testHasPostgresExtensionReturnsFalseForNonPostgres(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn(json_encode(['database' => ['type' => 'MySQL']]));

        $result = $this->service->hasPostgresExtension('vector');
        $this->assertFalse($result);
    }

    /**
     * Test hasPostgresExtension returns true when extension exists
     *
     * @return void
     */
    public function testHasPostgresExtensionReturnsTrueWhenFound(): void
    {
        $dbData = [
            'type'       => 'PostgreSQL',
            'extensions' => [
                ['name' => 'pg_trgm'],
                ['name' => 'vector'],
            ],
        ];
        $this->config
            ->method('getAppValue')
            ->willReturn(json_encode(['database' => $dbData]));

        $this->assertTrue($this->service->hasPostgresExtension('vector'));
        $this->assertTrue($this->service->hasPostgresExtension('pg_trgm'));
        $this->assertFalse($this->service->hasPostgresExtension('nonexistent'));
    }

    // =============================================
    // getPostgresExtensions tests (public, line 902)
    // =============================================

    /**
     * Test getPostgresExtensions returns empty for non-postgres
     *
     * @return void
     */
    public function testGetPostgresExtensionsReturnsEmptyForNonPostgres(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn(json_encode(['database' => ['type' => 'MySQL']]));

        $result = $this->service->getPostgresExtensions();
        $this->assertEmpty($result);
    }

    /**
     * Test getPostgresExtensions returns extensions list
     *
     * @return void
     */
    public function testGetPostgresExtensionsReturnsList(): void
    {
        $extensions = [['name' => 'pg_trgm'], ['name' => 'vector']];
        $dbData     = ['type' => 'PostgreSQL', 'extensions' => $extensions];

        $this->config
            ->method('getAppValue')
            ->willReturn(json_encode(['database' => $dbData]));

        $result = $this->service->getPostgresExtensions();
        $this->assertCount(2, $result);
    }

    // =============================================
    // getSearchBackendConfig tests (public, line 410)
    // =============================================

    /**
     * Test getSearchBackendConfig returns defaults when empty
     *
     * @return void
     */
    public function testGetSearchBackendConfigReturnsDefaultsWhenEmpty(): void
    {
        $this->config
            ->method('getAppValue')
            ->willReturn('');

        $result = $this->service->getSearchBackendConfig();
        $this->assertEquals('solr', $result['active']);
        $this->assertContains('solr', $result['available']);
        $this->assertContains('elasticsearch', $result['available']);
    }

    /**
     * Test getSearchBackendConfig returns stored config
     *
     * @return void
     */
    public function testGetSearchBackendConfigReturnsStoredConfig(): void
    {
        $stored = ['active' => 'elasticsearch', 'available' => ['solr', 'elasticsearch']];
        $this->config
            ->method('getAppValue')
            ->willReturn(json_encode($stored));

        $result = $this->service->getSearchBackendConfig();
        $this->assertEquals('elasticsearch', $result['active']);
    }

    /**
     * Test getSearchBackendConfig handles exception
     *
     * @return void
     */
    public function testGetSearchBackendConfigHandlesException(): void
    {
        $this->config
            ->method('getAppValue')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->service->getSearchBackendConfig();
        $this->assertEquals('solr', $result['active']);
    }

    // =============================================
    // Delegation method tests
    // =============================================

    /**
     * Test getLLMSettingsOnly delegates to handler
     *
     * @return void
     */
    public function testGetLLMSettingsOnlyDelegates(): void
    {
        $expected = ['provider' => 'openai', 'model' => 'gpt-4'];
        $this->llmSettingsHandler
            ->expects($this->once())
            ->method('getLLMSettingsOnly')
            ->willReturn($expected);

        $result = $this->service->getLLMSettingsOnly();
        $this->assertEquals($expected, $result);
    }

    /**
     * Test getFileSettingsOnly delegates to handler
     *
     * @return void
     */
    public function testGetFileSettingsOnlyDelegates(): void
    {
        $expected = ['maxFileSize' => 10485760];
        $this->fileSettingsHandler
            ->expects($this->once())
            ->method('getFileSettingsOnly')
            ->willReturn($expected);

        $result = $this->service->getFileSettingsOnly();
        $this->assertEquals($expected, $result);
    }

    /**
     * Test getObjectSettingsOnly delegates to handler
     *
     * @return void
     */
    public function testGetObjectSettingsOnlyDelegates(): void
    {
        $expected = ['vectorize' => true];
        $this->objectRetentionHandler
            ->expects($this->once())
            ->method('getObjectSettingsOnly')
            ->willReturn($expected);

        $result = $this->service->getObjectSettingsOnly();
        $this->assertEquals($expected, $result);
    }

    /**
     * Test getRetentionSettingsOnly delegates to handler
     *
     * @return void
     */
    public function testGetRetentionSettingsOnlyDelegates(): void
    {
        $expected = ['retention_days' => 90];
        $this->objectRetentionHandler
            ->expects($this->once())
            ->method('getRetentionSettingsOnly')
            ->willReturn($expected);

        $result = $this->service->getRetentionSettingsOnly();
        $this->assertEquals($expected, $result);
    }

    /**
     * Test isMultiTenancyEnabled delegates to handler
     *
     * @return void
     */
    public function testIsMultiTenancyEnabledDelegates(): void
    {
        $this->configurationSettingsHandler
            ->expects($this->once())
            ->method('isMultiTenancyEnabled')
            ->willReturn(true);

        $this->assertTrue($this->service->isMultiTenancyEnabled());
    }

    /**
     * Test getDefaultOrganisationUuid delegates to handler
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidDelegates(): void
    {
        $this->configurationSettingsHandler
            ->expects($this->once())
            ->method('getDefaultOrganisationUuid')
            ->willReturn('test-uuid');

        $this->assertEquals('test-uuid', $this->service->getDefaultOrganisationUuid());
    }
}
