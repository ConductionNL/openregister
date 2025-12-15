<?php

declare(strict_types=1);

/**
 * ElasticsearchQueryExecutor
 *
 * Manages search queries and execution for Elasticsearch.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Elasticsearch
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Service\Index\Backends\Elasticsearch;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * ElasticsearchQueryExecutor
 *
 * Handles Elasticsearch query execution.
 */
class ElasticsearchQueryExecutor
{
    private readonly ElasticsearchHttpClient $httpClient;
    private readonly ElasticsearchIndexManager $indexManager;
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ElasticsearchHttpClient   $httpClient   HTTP client
     * @param ElasticsearchIndexManager $indexManager Index manager
     * @param LoggerInterface           $logger       Logger
     */
    public function __construct(
        ElasticsearchHttpClient $httpClient,
        ElasticsearchIndexManager $indexManager,
        LoggerInterface $logger
    ) {
        $this->httpClient   = $httpClient;
        $this->indexManager = $indexManager;
        $this->logger       = $logger;
    }

    /**
     * Execute a search query.
     *
     * @param array $query Query parameters
     *
     * @return array Search results
     */
    public function search(array $query): array
    {
        $index = $this->indexManager->getActiveIndexName();

        try {
            // Build Elasticsearch query
            $esQuery = $this->buildElasticsearchQuery($query);

            $url = $this->httpClient->buildBaseUrl() . '/' . $index . '/_search';
            $result = $this->httpClient->post($url, $esQuery);

            $this->logger->debug('[ElasticsearchQueryExecutor] Search executed', [
                'index' => $index,
                'hits' => $result['hits']['total']['value'] ?? 0
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error('[ElasticsearchQueryExecutor] Search failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'hits' => [
                    'total' => ['value' => 0],
                    'hits' => [],
                ],
            ];
        }
    }

    /**
     * Build Elasticsearch query from simple query parameters.
     *
     * @param array $params Query parameters
     *
     * @return array Elasticsearch query DSL
     */
    private function buildElasticsearchQuery(array $params): array
    {
        $query = [
            'query' => [
                'match_all' => new \stdClass() // Empty object
            ],
            'from' => 0,
            'size' => 10,
        ];

        // Handle search text
        if (isset($params['_search']) && $params['_search'] !== '*:*') {
            $query['query'] = [
                'multi_match' => [
                    'query' => $params['_search'],
                    'fields' => ['*'],
                    'type' => 'best_fields'
                ]
            ];
        }

        // Handle pagination
        if (isset($params['_limit'])) {
            $query['size'] = (int) $params['_limit'];
        }

        if (isset($params['_page'])) {
            $page = (int) $params['_page'];
            $query['from'] = ($page - 1) * $query['size'];
        }

        return $query;
    }

    /**
     * Get document count.
     *
     * @return int Number of documents
     */
    public function getDocumentCount(): int
    {
        $index = $this->indexManager->getActiveIndexName();

        try {
            $url = $this->httpClient->buildBaseUrl() . '/' . $index . '/_count';
            $result = $this->httpClient->get($url);

            return $result['count'] ?? 0;
        } catch (Exception $e) {
            $this->logger->error('[ElasticsearchQueryExecutor] Failed to get document count', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}

