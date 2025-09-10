<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2024 OpenRegister
 *
 * @author OpenRegister Team
 *
 * @license AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\OpenRegister\Setup;

use Psr\Log\LoggerInterface;

/**
 * SOLR Setup and Configuration Manager
 *
 * Handles initial SOLR setup, configSet creation, and core management
 * for the multi-tenant OpenRegister architecture.
 * 
 * This class ensures that SOLR is properly configured with the necessary
 * configSets to support dynamic tenant core creation.
 *
 * @package OCA\OpenRegister\Setup
 * @category Setup
 * @author OpenRegister Team
 * @copyright 2024 OpenRegister
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/OpenRegister/OpenRegister
 */
class SolrSetup
{

    /**
     * @var LoggerInterface PSR-3 compliant logger for operation tracking
     */
    private LoggerInterface $logger;

    /**
     * @var array{host: string, port: int, scheme: string, path: string, username?: string, password?: string} SOLR connection configuration
     */
    private array $solrConfig;

    /**
     * Initialize SOLR setup manager
     *
     * @param array{host: string, port: int, scheme: string, path: string, username?: string, password?: string} $solrConfig SOLR connection configuration
     * @param LoggerInterface $logger PSR-3 compliant logger for operation tracking
     */
    public function __construct(array $solrConfig, LoggerInterface $logger)
    {
        $this->solrConfig = $solrConfig;
        $this->logger = $logger;
    }

    /**
     * Run complete SOLR setup for OpenRegister multi-tenant architecture
     *
     * Performs all necessary setup operations for SolrCloud:
     * 1. Verifies SOLR connectivity
     * 2. Creates base configSet if missing
     * 3. Creates base collection for template
     * 4. Validates setup completion
     *
     * Note: This works with SolrCloud mode with ZooKeeper coordination
     *
     * @return bool True if setup completed successfully, false otherwise
     * @throws \RuntimeException If critical setup operations fail
     */
    public function setupSolr(): bool
    {
        $this->logger->info('Starting SOLR setup for OpenRegister multi-tenant architecture (SolrCloud mode)');

        try {
            // Step 1: Verify SOLR connectivity
            if (!$this->verifySolrConnectivity()) {
                throw new \RuntimeException('Cannot connect to SOLR server');
            }

            // Step 2: Ensure base configSet exists
            if (!$this->ensureBaseConfigSet()) {
                throw new \RuntimeException('Failed to create base configSet');
            }

            // Step 3: Ensure base collection exists (used as template)
            if (!$this->ensureBaseCollectionExists()) {
                throw new \RuntimeException('Failed to create base collection');
            }

            // Step 4: Validate setup
            if (!$this->validateSetup()) {
                throw new \RuntimeException('Setup validation failed');
            }

            $this->logger->info('SOLR setup completed successfully (SolrCloud mode)');
            return true;

        } catch (\Exception $e) {
            $this->logger->error('SOLR setup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Verify that SOLR server is accessible and responding
     *
     * @return bool True if SOLR is accessible and responding properly
     */
    private function verifySolrConnectivity(): bool
    {
        $url = sprintf('%s://%s:%d%s/admin/info/system?wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path']
        );

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => 'GET'
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $this->logger->error('Failed to connect to SOLR', ['url' => $url]);
            return false;
        }

        $data = json_decode($response, true);
        if (!isset($data['lucene'])) {
            $this->logger->error('Invalid SOLR response', ['response' => $response]);
            return false;
        }

        $this->logger->info('SOLR connectivity verified', [
            'version' => $data['lucene']['solr-spec-version'] ?? 'unknown'
        ]);
        
        return true;
    }

    /**
     * Ensure the base configSet exists for creating tenant cores
     *
     * Creates the 'openregister' configSet if it doesn't exist, using
     * the default '_default' configSet as a template.
     *
     * @return bool True if configSet exists or was created successfully
     */
    private function ensureBaseConfigSet(): bool
    {
        // Check if configSet already exists
        if ($this->configSetExists('openregister')) {
            $this->logger->info('Base configSet already exists');
            return true;
        }

        // Create configSet using _default as template
        return $this->createConfigSet('openregister', '_default');
    }

    /**
     * Check if a SOLR configSet exists
     *
     * @param string $configSetName Name of the configSet to check
     * @return bool True if configSet exists, false otherwise
     */
    private function configSetExists(string $configSetName): bool
    {
        $url = sprintf('%s://%s:%d%s/admin/configs?action=LIST&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path']
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        $configSets = $data['configSets'] ?? [];
        
        return in_array($configSetName, $configSets);
    }

    /**
     * Create a new SOLR configSet based on an existing template
     *
     * @param string $newConfigSetName Name for the new configSet
     * @param string $templateConfigSetName Name of the template configSet to copy from
     * @return bool True if configSet was created successfully
     */
    private function createConfigSet(string $newConfigSetName, string $templateConfigSetName): bool
    {
        $url = sprintf('%s://%s:%d%s/admin/configs?action=CREATE&name=%s&baseConfigSet=%s&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path'],
            urlencode($newConfigSetName),
            urlencode($templateConfigSetName)
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logger->error('Failed to create configSet', [
                'configSet' => $newConfigSetName,
                'template' => $templateConfigSetName
            ]);
            return false;
        }

        $data = json_decode($response, true);
        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info('ConfigSet created successfully', [
                'configSet' => $newConfigSetName
            ]);
            return true;
        }

        $this->logger->error('ConfigSet creation failed', [
            'configSet' => $newConfigSetName,
            'response' => $data
        ]);
        return false;
    }

    /**
     * Ensure the base collection exists as a template for tenant collections
     *
     * The base 'openregister' collection serves as both a working collection and
     * a template for creating tenant-specific collections in SolrCloud.
     *
     * @return bool True if base collection exists or was created successfully
     */
    private function ensureBaseCollectionExists(): bool
    {
        // Check if base collection already exists
        if ($this->collectionExists('openregister')) {
            $this->logger->info('Base collection already exists');
            return true;
        }

        // Create base collection using the openregister configSet
        return $this->createCollection('openregister', 'openregister');
    }

    /**
     * Check if a SOLR collection exists (SolrCloud)
     *
     * @param string $collectionName Name of the collection to check
     * @return bool True if collection exists, false otherwise
     */
    private function collectionExists(string $collectionName): bool
    {
        $url = sprintf('%s://%s:%d%s/admin/collections?action=CLUSTERSTATUS&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path']
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
        // Collection exists if it's in the cluster status
        return isset($data['cluster']['collections'][$collectionName]);
    }

    /**
     * Create a new SOLR collection using a configSet (SolrCloud)
     *
     * @param string $collectionName Name for the new collection
     * @param string $configSetName Name of the configSet to use
     * @return bool True if collection was created successfully
     */
    private function createCollection(string $collectionName, string $configSetName): bool
    {
        $url = sprintf('%s://%s:%d%s/admin/collections?action=CREATE&name=%s&collection.configName=%s&numShards=1&replicationFactor=1&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path'],
            urlencode($collectionName),
            urlencode($configSetName)
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logger->error('Failed to create collection', [
                'collection' => $collectionName,
                'configSet' => $configSetName
            ]);
            return false;
        }

        $data = json_decode($response, true);
        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info('Collection created successfully', [
                'collection' => $collectionName,
                'configSet' => $configSetName
            ]);
            return true;
        }

        $this->logger->error('Collection creation failed', [
            'collection' => $collectionName,
            'response' => $data
        ]);
        return false;
    }

    /**
     * Check if a SOLR core exists
     *
     * @param string $coreName Name of the core to check
     * @return bool True if core exists, false otherwise
     */
    private function coreExists(string $coreName): bool
    {
        $url = sprintf('%s://%s:%d%s/admin/cores?action=STATUS&core=%s&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path'],
            urlencode($coreName)
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
        // Core exists if it's in the status response
        return isset($data['status'][$coreName]);
    }

    /**
     * Create a new SOLR core using a configSet
     *
     * @param string $coreName Name for the new core
     * @param string $configSetName Name of the configSet to use
     * @return bool True if core was created successfully
     */
    private function createCore(string $coreName, string $configSetName): bool
    {
        $url = sprintf('%s://%s:%d%s/admin/cores?action=CREATE&name=%s&configSet=%s&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path'],
            urlencode($coreName),
            urlencode($configSetName)
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->logger->error('Failed to create core', [
                'core' => $coreName,
                'configSet' => $configSetName
            ]);
            return false;
        }

        $data = json_decode($response, true);
        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info('Core created successfully', [
                'core' => $coreName,
                'configSet' => $configSetName
            ]);
            return true;
        }

        $this->logger->error('Core creation failed', [
            'core' => $coreName,
            'response' => $data
        ]);
        return false;
    }

    /**
     * Validate that SOLR setup is complete and functional (SolrCloud)
     *
     * Performs final validation checks:
     * 1. Base configSet exists
     * 2. Base collection exists
     * 3. Base collection is accessible via query
     *
     * @return bool True if all validation checks pass
     */
    private function validateSetup(): bool
    {
        // Check configSet exists
        if (!$this->configSetExists('openregister')) {
            $this->logger->error('Validation failed: configSet missing');
            return false;
        }

        // Check base collection exists
        if (!$this->collectionExists('openregister')) {
            $this->logger->error('Validation failed: base collection missing');
            return false;
        }

        // Test collection query functionality
        if (!$this->testCollectionQuery('openregister')) {
            $this->logger->error('Validation failed: collection query test failed');
            return false;
        }

        $this->logger->info('SOLR setup validation passed (SolrCloud mode)');
        return true;
    }

    /**
     * Test that a collection responds to queries correctly (SolrCloud)
     *
     * @param string $collectionName Name of the collection to test
     * @return bool True if collection responds to queries properly
     */
    private function testCollectionQuery(string $collectionName): bool
    {
        $url = sprintf('%s://%s:%d%s/%s/select?q=*:*&rows=0&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path'],
            urlencode($collectionName)
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
        // Valid response should have a response header with status 0
        return ($data['responseHeader']['status'] ?? -1) === 0;
    }

    /**
     * Test that a core responds to queries correctly
     *
     * @param string $coreName Name of the core to test
     * @return bool True if core responds to queries properly
     */
    private function testCoreQuery(string $coreName): bool
    {
        $url = sprintf('%s://%s:%d%s/%s/select?q=*:*&rows=0&wt=json',
            $this->solrConfig['scheme'],
            $this->solrConfig['host'],
            $this->solrConfig['port'],
            $this->solrConfig['path'],
            urlencode($coreName)
        );

        $response = @file_get_contents($url);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        
        // Valid response should have a response header with status 0
        return ($data['responseHeader']['status'] ?? -1) === 0;
    }

}
