<?php

/**
 * ElasticsearchIndexManager
 *
 * Manages Elasticsearch indices.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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

    /**
     * Elasticsearch HTTP client for making requests
     *
     * @var ElasticsearchHttpClient
     */
    private readonly ElasticsearchHttpClient $httpClient;

    /**
     * PSR-3 logger instance
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Active Elasticsearch index name
     *
     * @var string
     */
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
     * @param string $indexName The index name to check.
     *
     * @return bool True if index exists
<<<<<<< HEAD
     *
     * @spec exclude thin delegation to ElasticsearchHttpClient — GET index, no error means exists
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
     * @param string $indexName The index name to create.
     * @param array  $mapping   Index mapping configuration (default: empty array).
     *
     * @return bool True on success
<<<<<<< HEAD
     *
     * @spec exclude thin delegation to ElasticsearchHttpClient — PUTs index settings/mappings
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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

            if ($success === true) {
                $this->logger->info(
                    message: '[ElasticsearchIndexManager] Index created',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'index' => $indexName,
                    ]
                );
            }

            return $success;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ElasticsearchIndexManager] Failed to create index',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
     * @param string $indexName The index name to delete.
     *
     * @return bool True on success
<<<<<<< HEAD
     *
     * @spec exclude thin delegation to ElasticsearchHttpClient — DELETEs the index
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function deleteIndex(string $indexName): bool
    {
        try {
            $url      = $this->httpClient->buildBaseUrl().'/'.$indexName;
            $response = $this->httpClient->delete($url);

            $success = isset($response['acknowledged']) && $response['acknowledged'] === true;

            if ($success === true) {
                $this->logger->info(
                    message: '[ElasticsearchIndexManager] Index deleted',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'index' => $indexName,
                    ]
                );
            }

            return $success;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ElasticsearchIndexManager] Failed to delete index',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
     * @param string $indexName The index name to ensure exists.
     *
     * @return bool True on success
<<<<<<< HEAD
     *
     * @spec exclude thin helper — checks indexExists then createIndex
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function ensureIndex(string $indexName): bool
    {
        if ($this->indexExists(indexName: $indexName) === true) {
            $this->logger->debug(
                message: '[ElasticsearchIndexManager] Index already exists',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'index' => $indexName,
                ]
            );
            return true;
        }

        return $this->createIndex(indexName: $indexName);
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
     * @param string $indexName Index name
     *
     * @return array Index statistics
<<<<<<< HEAD
     *
     * @spec exclude thin delegation to ElasticsearchHttpClient — GETs /_stats
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getIndexStats(string $indexName): array
    {
        try {
            $url = $this->httpClient->buildBaseUrl().'/'.$indexName.'/_stats';
            return $this->httpClient->get($url);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ElasticsearchIndexManager] Failed to get index stats',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
     * @param string $indexName Index name
     *
     * @return bool True on success
<<<<<<< HEAD
     *
     * @spec exclude thin delegation to ElasticsearchHttpClient — POSTs /_refresh
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function refreshIndex(string $indexName): bool
    {
        try {
            $url      = $this->httpClient->buildBaseUrl().'/'.$indexName.'/_refresh';
            $response = $this->httpClient->post($url, []);

            return isset($response['error']) === false;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ElasticsearchIndexManager] Failed to refresh index',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'index' => $indexName,
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }
    }//end refreshIndex()
}//end class
