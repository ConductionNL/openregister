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

        $this->assertFalse($result);
    }

}
