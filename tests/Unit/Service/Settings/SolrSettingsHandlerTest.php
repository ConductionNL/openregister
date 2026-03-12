<?php

declare(strict_types=1);

/**
 * SolrSettingsHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Settings\SolrSettingsHandler;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for SolrSettingsHandler
 *
 * Tests SOLR settings retrieval, update, dashboard stats, facet config,
 * search backend config, and error handling.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)  Comprehensive coverage requires many test methods
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Full coverage of large handler class
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Test class must reference all dependencies
 */
class SolrSettingsHandlerTest extends TestCase
{
    /** @var SolrSettingsHandler */
    private SolrSettingsHandler $handler;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /** @var CacheHandler&MockObject */
    private CacheHandler $cacheHandler;

    /** @var IAppContainer&MockObject */
    private IAppContainer $container;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->container = $this->createMock(IAppContainer::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new SolrSettingsHandler(
            $this->appConfig,
            $this->cacheHandler,
            $this->container,
            $this->logger,
            'openregister'
        );
    }

    // =========================================================================
    // getSolrSettings tests
    // =========================================================================

    /**
     * Test getSolrSettings returns defaults when no config exists
     *
     * @return void
     */
    public function testGetSolrSettingsReturnsDefaultsWhenEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'solr', '')
            ->willReturn('');

        $result = $this->handler->getSolrSettings();

        $this->assertFalse($result['enabled']);
        $this->assertSame('solr', $result['host']);
        $this->assertSame(8983, $result['port']);
        $this->assertSame('/solr', $result['path']);
        $this->assertSame('openregister', $result['core']);
        $this->assertSame('_default', $result['configSet']);
        $this->assertSame('http', $result['scheme']);
        $this->assertSame('', $result['username']);
        $this->assertSame('', $result['password']);
        $this->assertSame(30, $result['timeout']);
        $this->assertTrue($result['autoCommit']);
        $this->assertSame(1000, $result['commitWithin']);
        $this->assertTrue($result['enableLogging']);
        $this->assertSame('zookeeper:2181', $result['zookeeperHosts']);
        $this->assertSame('openregister', $result['collection']);
        $this->assertTrue($result['useCloud']);
    }

    /**
     * Test getSolrSettings returns decoded JSON when config exists
     *
     * @return void
     */
    public function testGetSolrSettingsReturnsStoredConfig(): void
    {
        $storedConfig = [
            'enabled' => true,
            'host'    => 'custom-solr',
            'port'    => 9999,
        ];

        $this->appConfig->method('getValueString')
            ->with('openregister', 'solr', '')
            ->willReturn(json_encode($storedConfig));

        $result = $this->handler->getSolrSettings();

        $this->assertTrue($result['enabled']);
        $this->assertSame('custom-solr', $result['host']);
        $this->assertSame(9999, $result['port']);
    }

    /**
     * Test getSolrSettings throws RuntimeException on failure
     *
     * @return void
     */
    public function testGetSolrSettingsThrowsOnException(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve SOLR settings: DB error');

        $this->handler->getSolrSettings();
    }

    // =========================================================================
    // warmupSolrIndex tests
    // =========================================================================

    /**
     * Test warmupSolrIndex throws RuntimeException (deprecated method)
     *
     * @return void
     */
    public function testWarmupSolrIndexThrowsDeprecatedException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deprecated');

        $this->handler->warmupSolrIndex();
    }

    // =========================================================================
    // getSolrDashboardStats tests
    // =========================================================================

    /**
     * Test getSolrDashboardStats returns stats when CacheHandler is available
     *
     * @return void
     */
    public function testGetSolrDashboardStatsWithAvailableCacheHandler(): void
    {
        $rawStats = [
            'available'      => true,
            'health'         => 'healthy',
            'document_count' => 500,
            'index_size'     => 1024,
            'last_modified'  => '2024-01-01T00:00:00Z',
            'collection'     => 'testcore',
            'service_stats'  => [
                'searches'    => 100,
                'indexes'     => 50,
                'deletes'     => 10,
                'search_time' => 2000,
                'index_time'  => 1000,
                'errors'      => 5,
            ],
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        $this->assertTrue($result['overview']['available']);
        $this->assertSame('healthy', $result['overview']['connection_status']);
        $this->assertSame(500, $result['overview']['total_documents']);
        $this->assertSame('testcore', $result['cores']['active_core']);
        $this->assertSame('active', $result['cores']['core_status']);
        $this->assertSame(100, $result['performance']['total_searches']);
        $this->assertSame(50, $result['performance']['total_indexes']);
        $this->assertSame(10, $result['performance']['total_deletes']);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * Test getSolrDashboardStats returns defaults when CacheHandler throws
     *
     * @return void
     */
    public function testGetSolrDashboardStatsReturnsDefaultsOnException(): void
    {
        $this->cacheHandler->method('getSolrDashboardStats')
            ->willThrowException(new Exception('SOLR unavailable'));

        $result = $this->handler->getSolrDashboardStats();

        $this->assertFalse($result['overview']['available']);
        $this->assertSame('unavailable', $result['overview']['connection_status']);
        $this->assertSame(0, $result['overview']['total_documents']);
        $this->assertSame('SOLR unavailable', $result['error']);
    }

    /**
     * Test getSolrDashboardStats uses container to resolve CacheHandler when not injected
     *
     * @return void
     */
    public function testGetSolrDashboardStatsUsesContainerFallback(): void
    {
        // Create handler without CacheHandler
        $handler = new SolrSettingsHandler(
            $this->appConfig,
            null,
            $this->container,
            $this->logger
        );

        $rawStats = [
            'available'      => true,
            'health'         => 'ok',
            'document_count' => 10,
            'index_size'     => 0,
            'collection'     => 'test',
            'service_stats'  => [],
        ];

        $this->container->method('get')
            ->with(CacheHandler::class)
            ->willReturn($this->cacheHandler);

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $handler->getSolrDashboardStats();

        $this->assertTrue($result['overview']['available']);
    }

    /**
     * Test getSolrDashboardStats when both CacheHandler and container are null
     *
     * @return void
     */
    public function testGetSolrDashboardStatsNoCacheHandlerOrContainer(): void
    {
        $handler = new SolrSettingsHandler(
            $this->appConfig,
            null,
            null,
            $this->logger
        );

        $result = $handler->getSolrDashboardStats();

        $this->assertFalse($result['overview']['available']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test getSolrDashboardStats when container fails to resolve CacheHandler
     *
     * @return void
     */
    public function testGetSolrDashboardStatsContainerResolutionFails(): void
    {
        $handler = new SolrSettingsHandler(
            $this->appConfig,
            null,
            $this->container,
            $this->logger
        );

        $this->container->method('get')
            ->willThrowException(new Exception('Service not found'));

        $result = $handler->getSolrDashboardStats();

        $this->assertFalse($result['overview']['available']);
    }

    // =========================================================================
    // transformSolrStatsToDashboard (tested via getSolrDashboardStats)
    // =========================================================================

    /**
     * Test dashboard transform when SOLR is unavailable in raw stats
     *
     * @return void
     */
    public function testTransformSolrStatsToDashboardWhenUnavailable(): void
    {
        $rawStats = [
            'available' => false,
            'error'     => 'Connection refused',
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        $this->assertFalse($result['overview']['available']);
        $this->assertSame('unavailable', $result['overview']['connection_status']);
        $this->assertContains('Connection refused', $result['health']['warnings']);
        $this->assertSame('Connection refused', $result['error']);
    }

    /**
     * Test dashboard stats calculations with zero operations
     *
     * @return void
     */
    public function testTransformStatsWithZeroOperations(): void
    {
        $rawStats = [
            'available'      => true,
            'health'         => 'ok',
            'document_count' => 0,
            'index_size'     => 0,
            'collection'     => 'test',
            'service_stats'  => [
                'searches'    => 0,
                'indexes'     => 0,
                'deletes'     => 0,
                'search_time' => 0,
                'index_time'  => 0,
                'errors'      => 0,
            ],
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        $this->assertSame(0, $result['performance']['operations_per_sec']);
        $this->assertSame(0, $result['performance']['error_rate']);
        $this->assertSame(0, $result['performance']['avg_search_time_ms']);
        $this->assertSame(0, $result['performance']['avg_index_time_ms']);
    }

    /**
     * Test dashboard stats calculations with nonzero operations
     *
     * @return void
     */
    public function testTransformStatsWithOperations(): void
    {
        $rawStats = [
            'available'      => true,
            'health'         => 'ok',
            'document_count' => 100,
            'index_size'     => 512,
            'collection'     => 'test',
            'service_stats'  => [
                'searches'    => 200,
                'indexes'     => 100,
                'deletes'     => 50,
                'search_time' => 4000,
                'index_time'  => 2000,
                'errors'      => 7,
            ],
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        // avg_search_time = 4000 / 200 = 20
        $this->assertSame(20.0, $result['performance']['avg_search_time_ms']);
        // avg_index_time = 2000 / 100 = 20
        $this->assertSame(20.0, $result['performance']['avg_index_time_ms']);
        // totalOps = 350, totalTime = 6000ms = 6s, opsPerSec = 350/6 = 58.33
        $this->assertSame(58.33, $result['performance']['operations_per_sec']);
        // errorRate = 7/350 * 100 = 2.0
        $this->assertSame(2.0, $result['performance']['error_rate']);
    }

    /**
     * Test dashboard index size formatting
     *
     * @return void
     */
    public function testTransformStatsFormatsIndexSize(): void
    {
        $rawStats = [
            'available'      => true,
            'health'         => 'ok',
            'document_count' => 0,
            'index_size'     => 2048,
            'collection'     => 'test',
            'service_stats'  => [],
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        // 2048 KB * 1024 = 2097152 bytes = 2 MB
        $this->assertSame('2 MB', $result['overview']['index_size']);
    }

    /**
     * Test dashboard with missing service_stats keys
     *
     * @return void
     */
    public function testTransformStatsWithMissingServiceStats(): void
    {
        $rawStats = [
            'available'      => true,
            'health'         => 'ok',
            'document_count' => 5,
            'collection'     => 'test',
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        $this->assertTrue($result['overview']['available']);
        $this->assertSame(0, $result['performance']['total_searches']);
        $this->assertSame(0, $result['performance']['total_indexes']);
    }

    // =========================================================================
    // formatBytesForDashboard (tested via transform)
    // =========================================================================

    /**
     * Test formatBytesForDashboard with zero bytes
     *
     * @return void
     */
    public function testFormatBytesZero(): void
    {
        $rawStats = [
            'available'  => true,
            'health'     => 'ok',
            'index_size' => 0,
            'collection' => 'test',
            'service_stats' => [],
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        $this->assertSame('0 B', $result['overview']['index_size']);
    }

    /**
     * Test formatBytesForDashboard with large value (GB range)
     *
     * @return void
     */
    public function testFormatBytesLargeValue(): void
    {
        // index_size is in KB, so 1048576 KB = 1 GB
        $rawStats = [
            'available'      => true,
            'health'         => 'ok',
            'index_size'     => 1048576,
            'collection'     => 'test',
            'service_stats'  => [],
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        $this->assertSame('1 GB', $result['overview']['index_size']);
    }

    // =========================================================================
    // getSolrSettingsOnly tests
    // =========================================================================

    /**
     * Test getSolrSettingsOnly returns defaults when empty
     *
     * @return void
     */
    public function testGetSolrSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'solr', '')
            ->willReturn('');

        $result = $this->handler->getSolrSettingsOnly();

        $this->assertFalse($result['enabled']);
        $this->assertSame('solr', $result['host']);
        $this->assertSame(8983, $result['port']);
        $this->assertSame('solr', $result['username']);
        $this->assertSame('SolrRocks', $result['password']);
        $this->assertSame('', $result['zookeeperUsername']);
        $this->assertSame('', $result['zookeeperPassword']);
        $this->assertNull($result['objectCollection']);
        $this->assertNull($result['fileCollection']);
    }

    /**
     * Test getSolrSettingsOnly returns stored config with defaults
     *
     * @return void
     */
    public function testGetSolrSettingsOnlyReturnsStoredWithDefaults(): void
    {
        $stored = [
            'enabled' => true,
            'host'    => 'mysolr',
            'port'    => 1234,
        ];

        $this->appConfig->method('getValueString')
            ->with('openregister', 'solr', '')
            ->willReturn(json_encode($stored));

        $result = $this->handler->getSolrSettingsOnly();

        $this->assertTrue($result['enabled']);
        $this->assertSame('mysolr', $result['host']);
        $this->assertSame(1234, $result['port']);
        // Defaults for missing keys
        $this->assertSame('/solr', $result['path']);
        $this->assertSame('openregister', $result['core']);
        $this->assertSame('solr', $result['username']);
    }

    /**
     * Test getSolrSettingsOnly throws on error
     *
     * @return void
     */
    public function testGetSolrSettingsOnlyThrowsOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('fail'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve SOLR settings');

        $this->handler->getSolrSettingsOnly();
    }

    // =========================================================================
    // updateSolrSettingsOnly tests
    // =========================================================================

    /**
     * Test updateSolrSettingsOnly saves and returns config
     *
     * @return void
     */
    public function testUpdateSolrSettingsOnlySavesConfig(): void
    {
        $input = [
            'enabled' => true,
            'host'    => 'newsolr',
            'port'    => '7777',
        ];

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'solr', $this->isType('string'));

        $result = $this->handler->updateSolrSettingsOnly($input);

        $this->assertTrue($result['enabled']);
        $this->assertSame('newsolr', $result['host']);
        $this->assertSame(7777, $result['port']);
        $this->assertSame(30, $result['timeout']);
        $this->assertSame(1000, $result['commitWithin']);
    }

    /**
     * Test updateSolrSettingsOnly applies defaults for missing fields
     *
     * @return void
     */
    public function testUpdateSolrSettingsOnlyAppliesDefaults(): void
    {
        $this->appConfig->method('setValueString');

        $result = $this->handler->updateSolrSettingsOnly([]);

        $this->assertFalse($result['enabled']);
        $this->assertSame('solr', $result['host']);
        $this->assertSame(8983, $result['port']);
        $this->assertSame('solr', $result['username']);
        $this->assertSame('SolrRocks', $result['password']);
        $this->assertNull($result['objectCollection']);
        $this->assertNull($result['fileCollection']);
    }

    /**
     * Test updateSolrSettingsOnly throws on error
     *
     * @return void
     */
    public function testUpdateSolrSettingsOnlyThrowsOnError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB write error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update SOLR settings');

        $this->handler->updateSolrSettingsOnly(['enabled' => true]);
    }

    // =========================================================================
    // getSearchBackendConfig tests
    // =========================================================================

    /**
     * Test getSearchBackendConfig returns defaults when empty
     *
     * @return void
     */
    public function testGetSearchBackendConfigReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'search_backend', '')
            ->willReturn('');

        $result = $this->handler->getSearchBackendConfig();

        $this->assertSame('solr', $result['active']);
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

        $this->appConfig->method('getValueString')
            ->with('openregister', 'search_backend', '')
            ->willReturn(json_encode($stored));

        $result = $this->handler->getSearchBackendConfig();

        $this->assertSame('elasticsearch', $result['active']);
    }

    /**
     * Test getSearchBackendConfig throws on error
     *
     * @return void
     */
    public function testGetSearchBackendConfigThrowsOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('fail'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve search backend configuration');

        $this->handler->getSearchBackendConfig();
    }

    // =========================================================================
    // updateSearchBackendConfig tests
    // =========================================================================

    /**
     * Test updateSearchBackendConfig with valid backend solr
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigSolr(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'search_backend', $this->isType('string'));

        $result = $this->handler->updateSearchBackendConfig('solr');

        $this->assertSame('solr', $result['active']);
        $this->assertContains('solr', $result['available']);
        $this->assertContains('elasticsearch', $result['available']);
        $this->assertArrayHasKey('updated', $result);
    }

    /**
     * Test updateSearchBackendConfig with valid backend elasticsearch
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigElasticsearch(): void
    {
        $this->appConfig->method('setValueString');

        $result = $this->handler->updateSearchBackendConfig('elasticsearch');

        $this->assertSame('elasticsearch', $result['active']);
    }

    /**
     * Test updateSearchBackendConfig with invalid backend
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigInvalidBackend(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid backend 'redis'");

        $this->handler->updateSearchBackendConfig('redis');
    }

    /**
     * Test updateSearchBackendConfig throws on save error
     *
     * @return void
     */
    public function testUpdateSearchBackendConfigThrowsOnSaveError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('DB fail'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update search backend configuration');

        $this->handler->updateSearchBackendConfig('solr');
    }

    // =========================================================================
    // getSolrFacetConfiguration tests
    // =========================================================================

    /**
     * Test getSolrFacetConfiguration returns defaults when empty
     *
     * @return void
     */
    public function testGetSolrFacetConfigurationReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'solr_facet_config', '')
            ->willReturn('');

        $result = $this->handler->getSolrFacetConfiguration();

        $this->assertSame([], $result['facets']);
        $this->assertSame([], $result['global_order']);
        $this->assertTrue($result['default_settings']['show_count']);
        $this->assertFalse($result['default_settings']['show_empty']);
        $this->assertSame(10, $result['default_settings']['max_items']);
    }

    /**
     * Test getSolrFacetConfiguration returns stored config
     *
     * @return void
     */
    public function testGetSolrFacetConfigurationReturnsStored(): void
    {
        $stored = [
            'facets'       => ['status' => ['title' => 'Status']],
            'global_order' => ['status'],
            'default_settings' => ['show_count' => false, 'show_empty' => true, 'max_items' => 5],
        ];

        $this->appConfig->method('getValueString')
            ->with('openregister', 'solr_facet_config', '')
            ->willReturn(json_encode($stored));

        $result = $this->handler->getSolrFacetConfiguration();

        $this->assertArrayHasKey('status', $result['facets']);
        $this->assertSame('Status', $result['facets']['status']['title']);
    }

    /**
     * Test getSolrFacetConfiguration throws on error
     *
     * @return void
     */
    public function testGetSolrFacetConfigurationThrowsOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new Exception('fail'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve SOLR facet configuration');

        $this->handler->getSolrFacetConfiguration();
    }

    // =========================================================================
    // updateSolrFacetConfiguration tests
    // =========================================================================

    /**
     * Test updateSolrFacetConfiguration validates and saves
     *
     * @return void
     */
    public function testUpdateSolrFacetConfigurationSaves(): void
    {
        $input = [
            'facets' => [
                'category' => [
                    'title'       => 'Category',
                    'description' => 'Test facet',
                    'order'       => 1,
                    'enabled'     => true,
                    'show_count'  => false,
                    'max_items'   => 20,
                ],
            ],
            'global_order'     => ['category'],
            'default_settings' => [
                'show_count' => false,
                'show_empty' => true,
                'max_items'  => 15,
            ],
        ];

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'solr_facet_config', $this->isType('string'));

        $result = $this->handler->updateSolrFacetConfiguration($input);

        $this->assertArrayHasKey('category', $result['facets']);
        $this->assertSame('Category', $result['facets']['category']['title']);
        $this->assertSame(1, $result['facets']['category']['order']);
        $this->assertTrue($result['facets']['category']['enabled']);
        $this->assertFalse($result['facets']['category']['show_count']);
        $this->assertSame(20, $result['facets']['category']['max_items']);
        $this->assertSame(['category'], $result['global_order']);
        $this->assertFalse($result['default_settings']['show_count']);
        $this->assertTrue($result['default_settings']['show_empty']);
        $this->assertSame(15, $result['default_settings']['max_items']);
    }

    /**
     * Test updateSolrFacetConfiguration with empty config
     *
     * @return void
     */
    public function testUpdateSolrFacetConfigurationEmptyInput(): void
    {
        $this->appConfig->method('setValueString');

        $result = $this->handler->updateSolrFacetConfiguration([]);

        $this->assertSame([], $result['facets']);
        $this->assertSame([], $result['global_order']);
        $this->assertTrue($result['default_settings']['show_count']);
        $this->assertFalse($result['default_settings']['show_empty']);
        $this->assertSame(10, $result['default_settings']['max_items']);
    }

    /**
     * Test updateSolrFacetConfiguration skips facets with non-string or empty keys
     *
     * @return void
     */
    public function testUpdateSolrFacetConfigurationSkipsInvalidKeys(): void
    {
        $input = [
            'facets' => [
                ''         => ['title' => 'Empty key'],
                'valid'    => ['title' => 'Valid'],
            ],
        ];

        $this->appConfig->method('setValueString');

        $result = $this->handler->updateSolrFacetConfiguration($input);

        $this->assertArrayNotHasKey('', $result['facets']);
        $this->assertArrayHasKey('valid', $result['facets']);
    }

    /**
     * Test updateSolrFacetConfiguration filters non-string global_order entries
     *
     * @return void
     */
    public function testUpdateSolrFacetConfigurationFiltersGlobalOrder(): void
    {
        $input = [
            'global_order' => ['valid', 123, 'also_valid', null],
        ];

        $this->appConfig->method('setValueString');

        $result = $this->handler->updateSolrFacetConfiguration($input);

        $filtered = array_values($result['global_order']);
        $this->assertSame(['valid', 'also_valid'], $filtered);
    }

    /**
     * Test updateSolrFacetConfiguration throws on save error
     *
     * @return void
     */
    public function testUpdateSolrFacetConfigurationThrowsOnSaveError(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new Exception('write fail'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update SOLR facet configuration');

        $this->handler->updateSolrFacetConfiguration([]);
    }

    // =========================================================================
    // Constructor / edge cases
    // =========================================================================

    /**
     * Test constructor with minimal parameters
     *
     * @return void
     */
    public function testConstructorMinimalParams(): void
    {
        $handler = new SolrSettingsHandler($this->appConfig);

        // Should not throw, defaults apply
        $this->appConfig->method('getValueString')->willReturn('');
        $result = $handler->getSolrSettings();

        $this->assertFalse($result['enabled']);
    }

    /**
     * Test constructor with custom app name
     *
     * @return void
     */
    public function testConstructorCustomAppName(): void
    {
        $handler = new SolrSettingsHandler(
            $this->appConfig,
            null,
            null,
            null,
            'customapp'
        );

        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with('customapp', 'solr', '')
            ->willReturn('');

        $handler->getSolrSettings();
    }

    /**
     * Test dashboard with unavailable raw stats has last_modified in operations
     *
     * @return void
     */
    public function testTransformAvailableStatsHasLastCommit(): void
    {
        $rawStats = [
            'available'      => true,
            'health'         => 'ok',
            'last_modified'  => '2024-06-01T12:00:00Z',
            'collection'     => 'test',
            'service_stats'  => [],
        ];

        $this->cacheHandler->method('getSolrDashboardStats')
            ->willReturn($rawStats);

        $result = $this->handler->getSolrDashboardStats();

        $this->assertSame('2024-06-01T12:00:00Z', $result['overview']['last_commit']);
        $this->assertSame('2024-06-01T12:00:00Z', $result['operations']['commit_frequency']['last_commit']);
    }
}
