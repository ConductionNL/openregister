<?php

/**
 * SolrCollectionManager
 *
 * Manages Solr collections and ConfigSets.
 * Handles creation, deletion, and existence checks for collections.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Solr
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends\Solr;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * SolrCollectionManager
 *
 * Handles Solr collection and ConfigSet operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index\Backends\Solr
 */
class SolrCollectionManager
{

    /**
     * HTTP client.
     *
     * @var SolrHttpClient
     */
    private readonly SolrHttpClient $httpClient;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Solr configuration.
     *
     * @var array
     */
    private array $config;

    /**
     * Constructor
     *
     * @param SolrHttpClient  $httpClient HTTP client
     * @param LoggerInterface $logger     Logger
     *
     * @return void
     */
    public function __construct(
        SolrHttpClient $httpClient,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->logger     = $logger;
        $this->config     = $httpClient->getConfig();
    }//end __construct()

    /**
     * Check if a collection exists.
     *
     * @param string $collectionName Collection name
     *
     * @return bool True if exists
     */
    public function collectionExists(string $collectionName): bool
    {
        try {
            $url  = $this->httpClient->buildSolrBaseUrl().'/admin/collections?action=CLUSTERSTATUS&wt=json';
            $data = $this->httpClient->get($url, ['timeout' => 10]);

            return isset($data['cluster']['collections'][$collectionName]);
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrCollectionManager] Failed to check collection existence',
                [
                    'collection' => $collectionName,
                    'error'      => $e->getMessage(),
                ]
            );
            return false;
        }//end try
    }//end collectionExists()

    /**
     * Get the active collection name.
     *
     * Returns tenant-specific collection if it exists, otherwise null.
     *
     * @return string|null Collection name or null
     */
    public function getActiveCollectionName(): ?string
    {
        $baseCollection   = $this->config['objectCollection'] ?? $this->config['core'] ?? 'openregister';
        $tenantCollection = $this->httpClient->getTenantSpecificCollectionName($baseCollection);

        if ($this->collectionExists($tenantCollection) === true) {
            return $tenantCollection;
        }

        $this->logger->warning(
            '[SolrCollectionManager] Tenant collection does not exist',
            [
                'tenant_collection' => $tenantCollection,
                'base_collection'   => $baseCollection,
            ]
        );

        return null;
    }//end getActiveCollectionName()

    /**
     * Create a Solr collection.
     *
     * @param string $name   Collection name
     * @param array  $config Configuration options
     *
     * @return (mixed|string|true)[] Result with success status
     *
     * @throws Exception If creation fails
     *
     * @psalm-return array{success: true, message: 'Collection created successfully', collection: string, configSet: 'openregister_configset'|mixed}
     */
    public function createCollection(string $name, array $config=[]): array
    {
        $configSetName     = $config['configSetName'] ?? 'openregister_configset';
        $numShards         = $config['numShards'] ?? 1;
        $replicationFactor = $config['replicationFactor'] ?? 1;
        $maxShardsPerNode  = $config['maxShardsPerNode'] ?? 1;

        $this->logger->info(
            '[SolrCollectionManager] Creating collection',
            [
                'name'      => $name,
                'configSet' => $configSetName,
            ]
        );

        $url = $this->httpClient->buildSolrBaseUrl().'/admin/collections?'.http_build_query(
            [
                'action'                => 'CREATE',
                'name'                  => $name,
                'collection.configName' => $configSetName,
                'numShards'             => $numShards,
                'replicationFactor'     => $replicationFactor,
                'maxShardsPerNode'      => $maxShardsPerNode,
                'wt'                    => 'json',
            ]
        );

        $data = $this->httpClient->get($url, ['timeout' => 60]);

        if (($data['responseHeader']['status'] ?? -1) === 0) {
            $this->logger->info('[SolrCollectionManager] Collection created successfully', ['collection' => $name]);

            return [
                'success'    => true,
                'message'    => 'Collection created successfully',
                'collection' => $name,
                'configSet'  => $configSetName,
            ];
        }

        $errorMessage = $data['error']['msg'] ?? 'Unknown Solr error';
        $this->logger->error(
            '[SolrCollectionManager] Collection creation failed',
            [
                'collection' => $name,
                'error'      => $errorMessage,
            ]
        );

        throw new Exception("Solr collection creation failed: {$errorMessage}");
    }//end createCollection()

    /**
     * Delete a Solr collection.
     *
     * @param string|null $collectionName Collection name (null = active collection)
     *
     * @return (bool|string)[] Result with success status
     *
     * @psalm-return array{success: bool, message: string, exception?: string, collection?: string}
     */
    public function deleteCollection(?string $collectionName=null): array
    {
        try {
            $targetCollection = $collectionName ?? $this->getActiveCollectionName();

            if ($targetCollection === null) {
                return [
                    'success' => false,
                    'message' => 'No collection specified and no active collection found',
                ];
            }

            $this->logger->info('[SolrCollectionManager] Deleting collection', ['collection' => $targetCollection]);

            $url  = $this->httpClient->buildSolrBaseUrl().'/admin/collections?'.http_build_query(
                [
                    'action' => 'DELETE',
                    'name'   => $targetCollection,
                    'wt'     => 'json',
                ]
            );
            $data = $this->httpClient->get($url, ['timeout' => 60]);

            if (($data['responseHeader']['status'] ?? -1) === 0) {
                $this->logger->info(
                    '[SolrCollectionManager] Collection deleted successfully',
                    [
                        'collection' => $targetCollection,
                    ]
                );

                return [
                    'success'    => true,
                    'message'    => 'Collection deleted successfully',
                    'collection' => $targetCollection,
                ];
            }

            $errorMessage = $data['error']['msg'] ?? 'Unknown error';
            $this->logger->error(
                '[SolrCollectionManager] Collection deletion failed',
                [
                    'collection' => $targetCollection,
                    'error'      => $errorMessage,
                ]
            );

            return [
                'success' => false,
                'message' => "Failed to delete collection: {$errorMessage}",
            ];
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrCollectionManager] Collection deletion exception',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'success'   => false,
                'message'   => 'Exception during collection deletion',
                'exception' => $e->getMessage(),
            ];
        }//end try
    }//end deleteCollection()

    /**
     * List all Solr collections.
     *
     * @return array List of collections
     */
    public function listCollections(): array
    {
        try {
            $url  = $this->httpClient->buildSolrBaseUrl().'/admin/collections?action=LIST&wt=json';
            $data = $this->httpClient->get($url);

            return $data['collections'] ?? [];
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrCollectionManager] Failed to list collections',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end listCollections()

    /**
     * List all ConfigSets.
     *
     * @return array List of ConfigSets
     */
    public function listConfigSets(): array
    {
        try {
            $url  = $this->httpClient->buildSolrBaseUrl().'/admin/configs?action=LIST&wt=json&omitHeader=true';
            $data = $this->httpClient->get($url);

            return $data['configSets'] ?? [];
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrCollectionManager] Failed to list ConfigSets',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end listConfigSets()

    /**
     * Create a ConfigSet.
     *
     * @param string $name          ConfigSet name
     * @param string $baseConfigSet Base ConfigSet to copy from
     *
     * @return (bool|mixed|string)[] Result with success status
     *
     * @psalm-return array{success: bool,
     *     message: 'ConfigSet created successfully'|
     *     'Exception during ConfigSet creation'|
     *     'Failed to create ConfigSet'|mixed, exception?: string, name?: string}
     */
    public function createConfigSet(string $name, string $baseConfigSet='_default'): array
    {
        try {
            $this->logger->info(
                '[SolrCollectionManager] Creating ConfigSet',
                [
                    'name' => $name,
                    'base' => $baseConfigSet,
                ]
            );

            $url  = $this->httpClient->buildSolrBaseUrl().'/admin/configs?action=CREATE&name='.$name.'&baseConfigSet='.$baseConfigSet.'&wt=json';
            $data = $this->httpClient->get($url);

            if (($data['responseHeader']['status'] ?? -1) === 0) {
                return [
                    'success' => true,
                    'message' => 'ConfigSet created successfully',
                    'name'    => $name,
                ];
            }

            return [
                'success' => false,
                'message' => $data['error']['msg'] ?? 'Failed to create ConfigSet',
            ];
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrCollectionManager] ConfigSet creation failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'success'   => false,
                'message'   => 'Exception during ConfigSet creation',
                'exception' => $e->getMessage(),
            ];
        }//end try
    }//end createConfigSet()

    /**
     * Delete a ConfigSet.
     *
     * @param string $name ConfigSet name
     *
     * @return (bool|mixed|string)[] Result with success status
     *
     * @psalm-return array{success: bool,
     *     message: 'ConfigSet deleted successfully'|
     *     'Exception during ConfigSet deletion'|
     *     'Failed to delete ConfigSet'|mixed, exception?: string, name?: string}
     */
    public function deleteConfigSet(string $name): array
    {
        try {
            $this->logger->info('[SolrCollectionManager] Deleting ConfigSet', ['name' => $name]);

            $url  = $this->httpClient->buildSolrBaseUrl().'/admin/configs?action=DELETE&name='.$name.'&wt=json';
            $data = $this->httpClient->get($url);

            if (($data['responseHeader']['status'] ?? -1) === 0) {
                return [
                    'success' => true,
                    'message' => 'ConfigSet deleted successfully',
                    'name'    => $name,
                ];
            }

            return [
                'success' => false,
                'message' => $data['error']['msg'] ?? 'Failed to delete ConfigSet',
            ];
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrCollectionManager] ConfigSet deletion failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'success'   => false,
                'message'   => 'Exception during ConfigSet deletion',
                'exception' => $e->getMessage(),
            ];
        }//end try
    }//end deleteConfigSet()
}//end class
