<?php

/**
 * ElasticsearchIndexManager
 *
 * Manages Elasticsearch indices.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Elasticsearch
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends\Elasticsearch;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * ElasticsearchIndexManager
 *
 * Handles Elasticsearch index management operations.
 */
class ElasticsearchIndexManager
{

    private readonly ElasticsearchHttpClient $httpClient;

    private readonly LoggerInterface $logger;

    private string $activeIndex = 'openregister';


    /**
     * Constructor
     *
     * @param ElasticsearchHttpClient $httpClient HTTP client
     * @param LoggerInterface         $logger     Logger
     */
    public function __construct(
        ElasticsearchHttpClient $httpClient,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->logger     = $logger;

    }//end __construct()


    /**
     * Check if index exists.
     *
     * @return bool True if index exists
     */
    public function indexExists(string $indexName): bool
    {
        try {
            $url      = $this->httpClient->buildBaseUrl().'/'.$indexName;
            $response = $this->httpClient->get($url);

            return isset($response['error']) === false;
        } catch (Exception $e) {
            return false;
        }

    }//end indexExists()


    /**
     * Create index with mapping.
     *
     * @return bool True on success
     */
    public function createIndex(string $indexName, array $mapping=[]): bool
    {
        try {
            $url = $this->httpClient->buildBaseUrl().'/'.$indexName;

            $settings = [
                'settings' => [
                    'number_of_shards'   => 1,
                    'number_of_replicas' => 0,
                ],
            ];

            if (empty($mapping) === false) {
                $settings['mappings'] = $mapping;
            }

            $response = $this->httpClient->put($url, $settings);

            $success = isset($response['acknowledged']) && $response['acknowledged'] === true;

            if ($success) {
                $this->logger->info(
                        '[ElasticsearchIndexManager] Index created',
                        [
                            'index' => $indexName,
                        ]
                        );
            }

            return $success;
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchIndexManager] Failed to create index',
                    [
                        'index' => $indexName,
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end createIndex()


    /**
     * Delete index.
     *
     * @return bool True on success
     */
    public function deleteIndex(string $indexName): bool
    {
        try {
            $url      = $this->httpClient->buildBaseUrl().'/'.$indexName;
            $response = $this->httpClient->delete($url);

            $success = isset($response['acknowledged']) && $response['acknowledged'] === true;

            if ($success) {
                $this->logger->info(
                        '[ElasticsearchIndexManager] Index deleted',
                        [
                            'index' => $indexName,
                        ]
                        );
            }

            return $success;
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchIndexManager] Failed to delete index',
                    [
                        'index' => $indexName,
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end deleteIndex()


    /**
     * Ensure index exists, create if not.
     *
     * @return bool True on success
     */
    public function ensureIndex(string $indexName): bool
    {
        if ($this->indexExists($indexName)) {
            $this->logger->debug(
                    '[ElasticsearchIndexManager] Index already exists',
                    [
                        'index' => $indexName,
                    ]
                    );
            return true;
        }

        return $this->createIndex($indexName);

    }//end ensureIndex()


    /**
     * Get active index name.
     *
     * @return string Active index name
     */
    public function getActiveIndexName(): string
    {
        return $this->activeIndex;

    }//end getActiveIndexName()


    /**
     * Get index stats.
     *
     * @return array Index statistics
     */
    public function getIndexStats(string $indexName): array
    {
        try {
            $url = $this->httpClient->buildBaseUrl().'/'.$indexName.'/_stats';
            return $this->httpClient->get($url);
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchIndexManager] Failed to get index stats',
                    [
                        'index' => $indexName,
                        'error' => $e->getMessage(),
                    ]
                    );
            return [];
        }

    }//end getIndexStats()


    /**
     * Refresh index to make documents searchable.
     *
     * @return bool True on success
     */
    public function refreshIndex(string $indexName): bool
    {
        try {
            $url      = $this->httpClient->buildBaseUrl().'/'.$indexName.'/_refresh';
            $response = $this->httpClient->post($url, []);

            return isset($response['error']) === false;
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchIndexManager] Failed to refresh index',
                    [
                        'index' => $indexName,
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }

    }//end refreshIndex()


}//end class
