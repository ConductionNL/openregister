<?php

declare(strict_types=1);

/**
 * OpenRegister GuzzleSolrService Test
 *
 * This file contains tests for the GuzzleSolrService in the OpenRegister application.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for GuzzleSolrService
 *
 * This class tests the lightweight SOLR integration using HTTP calls.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class GuzzleSolrServiceTest extends TestCase
{

    /** @var GuzzleSolrService */
    private GuzzleSolrService $guzzleSolrService;

    /** @var MockObject|SettingsService */
    private $settingsService;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var MockObject|IClientService */
    private $clientService;

    /** @var MockObject|IConfig */
    private $config;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|OrganisationService */
    private $organisationService;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);

        // Mock config to return SOLR disabled by default
        $this->config->method('getSystemValue')->willReturnMap([
            ['solr.enabled', false, false],
            ['solr.host', 'localhost', 'localhost'],
            ['solr.port', 8983, 8983],
            ['solr.path', '/solr', '/solr'],
            ['solr.core', 'openregister', 'openregister'],
            ['instanceid', 'default', 'test-instance-id'],
            ['overwrite.cli.url', '', '']
        ]);

        $this->guzzleSolrService = new GuzzleSolrService(
            $this->settingsService,
            $this->logger,
            $this->clientService,
            $this->config,
            $this->schemaMapper,
            $this->registerMapper,
            $this->organisationService
        );
    }

    /**
     * Test constructor
     *
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(GuzzleSolrService::class, $this->guzzleSolrService);
    }

    /**
     * Test isAvailable method when SOLR is disabled
     *
     * @return void
     */
    public function testIsAvailableWhenDisabled(): void
    {
        // Mock settings service to return SOLR disabled configuration
        $this->settingsService->method('getSolrSettings')->willReturn([
            'enabled' => false
        ]);

        $result = $this->guzzleSolrService->isAvailable();
        $this->assertFalse($result);
    }

    /**
     * Test getStats method
     *
     * @return void
     */
    public function testGetStats(): void
    {
        $stats = $this->guzzleSolrService->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('searches', $stats);
        $this->assertArrayHasKey('indexes', $stats);
        $this->assertArrayHasKey('deletes', $stats);
        $this->assertArrayHasKey('search_time', $stats);
        $this->assertArrayHasKey('index_time', $stats);
        $this->assertArrayHasKey('errors', $stats);
    }

    /**
     * Test getTenantId method
     *
     * @return void
     */
    public function testGetTenantId(): void
    {
        $tenantId = $this->guzzleSolrService->getTenantId();

        $this->assertIsString($tenantId);
        $this->assertNotEmpty($tenantId);
    }

    /**
     * Test clearIndex method when SOLR is disabled
     *
     * @return void
     */
    public function testClearIndexWhenDisabled(): void
    {
        $result = $this->guzzleSolrService->clearIndex();

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test getEndpointUrl method
     */
    public function testGetEndpointUrl(): void
    {
        $result = $this->guzzleSolrService->getEndpointUrl();

        $this->assertIsString($result);
        $this->assertStringContainsString('N/A', $result);
    }

    /**
     * Test getEndpointUrl method with collection
     */
    public function testGetEndpointUrlWithCollection(): void
    {
        $collection = 'test-collection';
        $result = $this->guzzleSolrService->getEndpointUrl($collection);

        $this->assertIsString($result);
        $this->assertStringContainsString($collection, $result);
    }

    /**
     * Test getHttpClient method
     */
    public function testGetHttpClient(): void
    {
        $result = $this->guzzleSolrService->getHttpClient();

        $this->assertInstanceOf(\GuzzleHttp\Client::class, $result);
    }

    /**
     * Test getSolrConfig method
     */
    public function testGetSolrConfig(): void
    {
        $result = $this->guzzleSolrService->getSolrConfig();

        $this->assertIsArray($result);
        // Just check that it's an array, the actual keys depend on the configuration
    }

    /**
     * Test getDashboardStats method when SOLR is disabled
     */
    public function testGetDashboardStatsWhenDisabled(): void
    {
        $result = $this->guzzleSolrService->getDashboardStats();

        $this->assertIsArray($result);
        $this->assertFalse($result['available']);
    }

    /**
     * Test getStats method when SOLR is disabled
     */
    public function testGetStatsWhenDisabled(): void
    {
        $result = $this->guzzleSolrService->getStats();

        $this->assertIsArray($result);
        $this->assertFalse($result['available']);
    }

    /**
     * Test testConnectionForDashboard method when SOLR is disabled
     */
    public function testTestConnectionForDashboardWhenDisabled(): void
    {
        $result = $this->guzzleSolrService->testConnectionForDashboard();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('connection', $result);
        $this->assertArrayHasKey('availability', $result);
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test inspectIndex method when SOLR is disabled
     */
    public function testInspectIndexWhenDisabled(): void
    {
        $result = $this->guzzleSolrService->inspectIndex();

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    /**
     * Test optimize method when SOLR is disabled
     */
    public function testOptimizeWhenDisabled(): void
    {
        $result = $this->guzzleSolrService->optimize();

        $this->assertFalse($result);
    }

    /**
     * Test clearCache method
     */
    public function testClearCache(): void
    {
        // This method should not throw exceptions
        $this->guzzleSolrService->clearCache();
        
        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test bulkIndexFromDatabase method when SOLR is not available
     */
    public function testBulkIndexFromDatabaseWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->bulkIndexFromDatabase(100, 0);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test bulkIndexFromDatabaseParallel method when SOLR is not available
     */
    public function testBulkIndexFromDatabaseParallelWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->bulkIndexFromDatabaseParallel(100, 0, 2);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test bulkIndexFromDatabaseHyperFast method when SOLR is not available
     */
    public function testBulkIndexFromDatabaseHyperFastWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->bulkIndexFromDatabaseHyperFast(1000, 1000);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test testSchemaAwareMapping method
     */
    public function testTestSchemaAwareMapping(): void
    {
        $objectEntityMapper = $this->createMock(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
        $result = $this->guzzleSolrService->testSchemaAwareMapping($objectEntityMapper, $this->schemaMapper);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Test warmupIndex method when SOLR is not available
     */
    public function testWarmupIndexWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->warmupIndex([], 0, 'serial', false);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test bulkIndexFromDatabaseOptimized method when SOLR is not available
     */
    public function testBulkIndexFromDatabaseOptimizedWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->bulkIndexFromDatabaseOptimized(100, 0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('indexed', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    /**
     * Test fixMismatchedFields method when SOLR is not available
     */
    public function testFixMismatchedFieldsWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->fixMismatchedFields([], true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test createMissingFields method when SOLR is not available
     */
    public function testCreateMissingFieldsWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->createMissingFields([], true);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test getFieldsConfiguration method when SOLR is not available
     */
    public function testGetFieldsConfigurationWhenNotAvailable(): void
    {
        // Mock isAvailable to return false by making the service unavailable
        $result = $this->guzzleSolrService->getFieldsConfiguration();

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test testConnectivityOnly method when SOLR is not available
     */
    public function testTestConnectivityOnlyWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->testConnectivityOnly();

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test testFullOperationalReadiness method when SOLR is not available
     */
    public function testTestFullOperationalReadinessWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->testFullOperationalReadiness();

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test collectionExists method when SOLR is not available
     */
    public function testCollectionExistsWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->collectionExists('test-collection');

        $this->assertFalse($result);
    }

    /**
     * Test ensureTenantCollection method when SOLR is not available
     */
    public function testEnsureTenantCollectionWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->ensureTenantCollection();

        $this->assertFalse($result);
    }

    /**
     * Test getActiveCollectionName method when SOLR is not available
     */
    public function testGetActiveCollectionNameWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->getActiveCollectionName();

        $this->assertNull($result);
    }

    /**
     * Test createCollection method when SOLR is not available
     */
    public function testCreateCollectionWhenNotAvailable(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SOLR collection creation failed');
        
        $this->guzzleSolrService->createCollection('test-collection', 'openregister');
    }

    /**
     * Test deleteCollection method when SOLR is not available
     */
    public function testDeleteCollectionWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->deleteCollection('test-collection');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test indexObject method when SOLR is not available
     */
    public function testIndexObjectWhenNotAvailable(): void
    {
        $objectEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $result = $this->guzzleSolrService->indexObject($objectEntity);

        $this->assertFalse($result);
    }

    /**
     * Test deleteObject method when SOLR is not available
     */
    public function testDeleteObjectWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->deleteObject('test-uuid');

        $this->assertFalse($result);
    }

    /**
     * Test getDocumentCount method when SOLR is not available
     */
    public function testGetDocumentCountWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->getDocumentCount();

        $this->assertEquals(0, $result);
    }

    /**
     * Test searchObjectsPaginated method when SOLR is not available
     */
    public function testSearchObjectsPaginatedWhenNotAvailable(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SOLR configuration validation failed');
        
        $this->guzzleSolrService->searchObjectsPaginated(['query' => '*:*', 'start' => 0, 'rows' => 10]);
    }

    /**
     * Test bulkIndexObjects method when SOLR is not available
     */
    public function testBulkIndexObjectsWhenNotAvailable(): void
    {
        $objects = [];
        $result = $this->guzzleSolrService->bulkIndexObjects($objects);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('processed', $result);
    }

    /**
     * Test bulkIndex method when SOLR is not available
     */
    public function testBulkIndexWhenNotAvailable(): void
    {
        $data = [];
        $result = $this->guzzleSolrService->bulkIndex($data);

        $this->assertFalse($result);
    }

    /**
     * Test commit method when SOLR is not available
     */
    public function testCommitWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->commit();

        $this->assertFalse($result);
    }

    /**
     * Test deleteByQuery method when SOLR is not available
     */
    public function testDeleteByQueryWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->deleteByQuery('*:*');

        $this->assertFalse($result);
    }

    /**
     * Test searchObjects method when SOLR is not available
     */
    public function testSearchObjectsWhenNotAvailable(): void
    {
        $result = $this->guzzleSolrService->searchObjects(['query' => '*:*']);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

}
