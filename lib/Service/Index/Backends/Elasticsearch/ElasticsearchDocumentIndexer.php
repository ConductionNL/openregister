<?php

/**
 * ElasticsearchDocumentIndexer
 *
 * Handles document indexing operations to Elasticsearch.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Index\DocumentBuilder;
use Psr\Log\LoggerInterface;

/**
 * ElasticsearchDocumentIndexer
 *
 * Handles Elasticsearch document indexing operations.
 */
class ElasticsearchDocumentIndexer
{

    /**
     * Elasticsearch HTTP client for making requests
     *
     * @var ElasticsearchHttpClient
     */
    private readonly ElasticsearchHttpClient $httpClient;

    /**
     * Elasticsearch index manager for index operations
     *
     * @var ElasticsearchIndexManager
     */
    private readonly ElasticsearchIndexManager $indexManager;

    /**
     * Document builder for preparing documents
     *
     * @var DocumentBuilder
     */
    private readonly DocumentBuilder $documentBuilder;

    /**
     * PSR-3 logger instance
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ElasticsearchHttpClient   $httpClient      HTTP client
     * @param ElasticsearchIndexManager $indexManager    Index manager
     * @param DocumentBuilder           $documentBuilder Document builder
     * @param LoggerInterface           $logger          Logger
     */
    public function __construct(
        ElasticsearchHttpClient $httpClient,
        ElasticsearchIndexManager $indexManager,
        DocumentBuilder $documentBuilder,
        LoggerInterface $logger
    ) {
        $this->httpClient      = $httpClient;
        $this->indexManager    = $indexManager;
        $this->documentBuilder = $documentBuilder;
        $this->logger          = $logger;

    }//end __construct()

    /**
     * Index a single object.
     *
     * @param ObjectEntity $object  Object to index
     * @param bool         $refresh Whether to refresh immediately
     *
     * @return bool True if successful
     */
    public function indexObject(ObjectEntity $object, bool $refresh=false): bool
    {
        try {
            $index = $this->indexManager->getActiveIndexName();

            // Ensure index exists.
            $this->indexManager->ensureIndex($index);

            // Build document.
            $document = $this->documentBuilder->createDocument($object);

            // Index document.
            $url      = $this->httpClient->buildBaseUrl().'/'.$index.'/_doc/'.$document['id'];
            $response = $this->httpClient->put($url, $document);

            $success = isset($response['result']) && in_array($response['result'], ['created', 'updated']);

            if ($success === true) {
                $this->logger->info(
                        '[ElasticsearchDocumentIndexer] Object indexed',
                        [
                            'object_id' => $object->getId(),
                            'result'    => $response['result'] ?? 'unknown',
                        ]
                        );

                // Refresh index if requested.
                if ($refresh === true) {
                    $this->indexManager->refreshIndex($index);
                }
            }

            return $success;
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchDocumentIndexer] Failed to index object',
                    [
                        'object_id' => $object->getId(),
                        'error'     => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end indexObject()

    /**
     * Index multiple objects in bulk.
     *
     * @param array $objects Objects to index
     * @param bool  $refresh Whether to refresh after bulk
     *
     * @return (bool|int|string)[] Results with success/failure counts
     *
     * @psalm-return array{success: bool, indexed: int<0, max>, failed: int<min, max>, error?: string}
     */
    public function bulkIndexObjects(array $objects, bool $refresh=false): array
    {
        $successCount = 0;
        $failureCount = 0;
        $index        = $this->indexManager->getActiveIndexName();

        // Ensure index exists.
        $this->indexManager->ensureIndex($index);

        // Build bulk request body.
        $bulkBody = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                $failureCount++;
                continue;
            }

            try {
                $document = $this->documentBuilder->createDocument($object);

                // Index action.
                $bulkBody[] = json_encode(
                        [
                            'index' => [
                                '_index' => $index,
                                '_id'    => $document['id'],
                            ],
                        ]
                        );

                // Document data.
                $bulkBody[] = json_encode($document);
            } catch (Exception $e) {
                $this->logger->error(
                        '[ElasticsearchDocumentIndexer] Failed to build document',
                        [
                            'object_id' => $object->getId(),
                            'error'     => $e->getMessage(),
                        ]
                        );
                $failureCount++;
            }//end try
        }//end foreach

        if (empty($bulkBody) === true) {
            return [
                'success' => false,
                'indexed' => 0,
                'failed'  => $failureCount,
                'error'   => 'No documents to index',
            ];
        }

        try {
            $url = $this->httpClient->buildBaseUrl().'/_bulk';

            // Send bulk request with newline-delimited JSON.
            $bulkData = implode("\n", $bulkBody)."\n";
            $response = $this->httpClient->postRaw($url, $bulkData);

            if (isset($response['items']) === true) {
                foreach ($response['items'] as $item) {
                    if (isset($item['index']['status']) === true && $item['index']['status'] < 300) {
                        $successCount++;
                    } else {
                        $failureCount++;
                    }
                }
            }

            $this->logger->info(
                    '[ElasticsearchDocumentIndexer] Bulk indexing completed',
                    [
                        'success' => $successCount,
                        'failed'  => $failureCount,
                    ]
                    );

            // Refresh index if requested.
            if ($refresh === true) {
                $this->indexManager->refreshIndex($index);
            }

            return [
                'success' => true,
                'indexed' => $successCount,
                'failed'  => $failureCount,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchDocumentIndexer] Bulk indexing failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return [
                'success' => false,
                'indexed' => $successCount,
                'failed'  => count($objects) - $successCount,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end bulkIndexObjects()

    /**
     * Delete an object from the index.
     *
     * @param string|int $objectId Object ID to delete
     * @param bool       $refresh  Whether to refresh immediately
     *
     * @return bool True if successful
     */
    public function deleteObject(string|int $objectId, bool $refresh=false): bool
    {
        try {
            $index = $this->indexManager->getActiveIndexName();
            $url   = $this->httpClient->buildBaseUrl().'/'.$index.'/_doc/'.$objectId;

            $response = $this->httpClient->delete($url);

            $success = isset($response['result']) && $response['result'] === 'deleted';

            if ($success === true) {
                $this->logger->info(
                        '[ElasticsearchDocumentIndexer] Object deleted',
                        [
                            'object_id' => $objectId,
                        ]
                        );

                // Refresh index if requested.
                if ($refresh === true) {
                    $this->indexManager->refreshIndex($index);
                }
            }

            return $success;
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchDocumentIndexer] Failed to delete object',
                    [
                        'object_id' => $objectId,
                        'error'     => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end deleteObject()

    /**
     * Clear all documents from index.
     *
     * @return bool True if successful
     */
    public function clearIndex(): bool
    {
        try {
            $index = $this->indexManager->getActiveIndexName();

            // Delete and recreate index.
            $this->indexManager->deleteIndex($index);
            $this->indexManager->createIndex($index);

            $this->logger->info(
                    '[ElasticsearchDocumentIndexer] Index cleared',
                    [
                        'index' => $index,
                    ]
                    );

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchDocumentIndexer] Failed to clear index',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end clearIndex()
}//end class
