<?php

declare(strict_types=1);

/**
 * ConfigurationHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Index
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Index;

use OCA\OpenRegister\Service\Index\ConfigurationHandler;
use OCA\OpenRegister\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ConfigurationHandler
 *
 * Tests configuration loading, URL building, and status checking.
 */
class ConfigurationHandlerTest extends TestCase
{
    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /**
     * Create a ConfigurationHandler with the given Solr settings.
     *
     * @param array $solrSettings Settings to return from SettingsService
     *
     * @return ConfigurationHandler
     */
    private function createHandler(array $solrSettings): ConfigurationHandler
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->settingsService->method('getSolrSettings')->willReturn($solrSettings);
        $this->logger = $this->createMock(LoggerInterface::class);

        return new ConfigurationHandler($this->settingsService, $this->logger);
    }

    // =========================================================================
    // isSolrConfigured
    // =========================================================================

    public function testIsSolrConfiguredReturnsFalseWhenDisabled(): void
    {
        $handler = $this->createHandler(['enabled' => false]);

        $this->assertFalse($handler->isSolrConfigured());
    }

    public function testIsSolrConfiguredReturnsFalseWhenMissingHost(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => '',
            'core'    => 'openregister',
        ]);

        $this->assertFalse($handler->isSolrConfigured());
    }

    public function testIsSolrConfiguredReturnsFalseWhenMissingCore(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'solr.example.com',
            'core'    => '',
        ]);

        $this->assertFalse($handler->isSolrConfigured());
    }

    public function testIsSolrConfiguredReturnsTrueWhenValid(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'solr.example.com',
            'core'    => 'openregister',
        ]);

        $this->assertTrue($handler->isSolrConfigured());
    }

    // =========================================================================
    // buildSolrBaseUrl
    // =========================================================================

    public function testBuildSolrBaseUrlWithPort(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'solr.example.com',
            'port'    => 8983,
            'core'    => 'openregister',
            'scheme'  => 'http',
        ]);

        $this->assertSame('http://solr.example.com:8983', $handler->buildSolrBaseUrl());
    }

    public function testBuildSolrBaseUrlWithoutPort(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'solr.example.com',
            'core'    => 'openregister',
            'scheme'  => 'https',
        ]);

        $this->assertSame('https://solr.example.com', $handler->buildSolrBaseUrl());
    }

    public function testBuildSolrBaseUrlWithPath(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'proxy.example.com',
            'core'    => 'openregister',
            'scheme'  => 'https',
            'path'    => 'solr-proxy',
        ]);

        $this->assertSame('https://proxy.example.com/solr-proxy', $handler->buildSolrBaseUrl());
    }

    public function testBuildSolrBaseUrlPortZeroIgnored(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'localhost',
            'port'    => '0',
            'core'    => 'test',
            'scheme'  => 'http',
        ]);

        $this->assertSame('http://localhost', $handler->buildSolrBaseUrl());
    }

    public function testBuildSolrBaseUrlEmptyPortStringIgnored(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'localhost',
            'port'    => '',
            'core'    => 'test',
            'scheme'  => 'http',
        ]);

        $this->assertSame('http://localhost', $handler->buildSolrBaseUrl());
    }

    // =========================================================================
    // getEndpointUrl
    // =========================================================================

    public function testGetEndpointUrlDefaultCollection(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'localhost',
            'port'    => 8983,
            'core'    => 'mycore',
            'scheme'  => 'http',
        ]);

        $this->assertSame('http://localhost:8983/solr/mycore', $handler->getEndpointUrl());
    }

    public function testGetEndpointUrlCustomCollection(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'localhost',
            'port'    => 8983,
            'core'    => 'mycore',
            'scheme'  => 'http',
        ]);

        $this->assertSame('http://localhost:8983/solr/custom', $handler->getEndpointUrl('custom'));
    }

    // =========================================================================
    // getTenantSpecificCollectionName
    // =========================================================================

    public function testGetTenantSpecificCollectionNameReturnsBaseNameAsIs(): void
    {
        $handler = $this->createHandler(['enabled' => false]);

        $this->assertSame('my-collection', $handler->getTenantSpecificCollectionName('my-collection'));
    }

    // =========================================================================
    // getConfigStatus / getPortStatus / getCoreStatus
    // =========================================================================

    public function testGetConfigStatusConfigured(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'host'    => 'localhost',
            'core'    => 'test',
        ]);

        $this->assertSame('✓ Configured', $handler->getConfigStatus('host'));
    }

    public function testGetConfigStatusNotConfigured(): void
    {
        $handler = $this->createHandler(['enabled' => true]);

        $this->assertSame('✗ Not configured', $handler->getConfigStatus('host'));
    }

    public function testGetPortStatusDefault(): void
    {
        $handler = $this->createHandler(['enabled' => true]);

        $this->assertSame('✓ Using default port', $handler->getPortStatus());
    }

    public function testGetPortStatusCustom(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'port'    => 8983,
        ]);

        $this->assertSame('✓ Port 8983', $handler->getPortStatus());
    }

    public function testGetCoreStatusDefault(): void
    {
        $handler = $this->createHandler(['enabled' => true]);

        $this->assertSame('✓ Core: openregister', $handler->getCoreStatus());
    }

    public function testGetCoreStatusCustom(): void
    {
        $handler = $this->createHandler([
            'enabled' => true,
            'core'    => 'mycore',
        ]);

        $this->assertSame('✓ Core: mycore', $handler->getCoreStatus());
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function testInitializationHandlesSettingsException(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSolrSettings')
            ->willThrowException(new \Exception('Settings unavailable'));

        $logger = $this->createMock(LoggerInterface::class);

        $handler = new ConfigurationHandler($settingsService, $logger);

        $this->assertFalse($handler->isSolrConfigured());
    }
}
