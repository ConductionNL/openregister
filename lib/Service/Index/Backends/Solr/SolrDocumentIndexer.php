<?php

declare(strict_types=1);

/*
 * SolrDocumentIndexer
 *
 * Handles document indexing operations to Solr.
 * Manages single and bulk indexing, deletions, and commits.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Solr
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index\Backends\Solr;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Index\DocumentBuilder;
use Psr\Log\LoggerInterface;

/**
 * SolrDocumentIndexer
 *
 * Handles Solr document indexing operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index\Backends\Solr
 */
class SolrDocumentIndexer
{

    /**
     * HTTP client.
     *
     * @var SolrHttpClient
     */
    private readonly SolrHttpClient $httpClient;

    /**
     * Collection manager.
     *
     * @var SolrCollectionManager
     */
    private readonly SolrCollectionManager $collectionManager;

    /**
     * Document builder.
     *
     * @var DocumentBuilder
     */
    private readonly DocumentBuilder $documentBuilder;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param SolrHttpClient        $httpClient        HTTP client
     * @param SolrCollectionManager $collectionManager Collection manager
     * @param DocumentBuilder       $documentBuilder   Document builder
     * @param LoggerInterface       $logger            Logger
     *
     * @return void
     */
    public function __construct(
        SolrHttpClient $httpClient,
        SolrCollectionManager $collectionManager,
        DocumentBuilder $documentBuilder,
        LoggerInterface $logger
    ) {
        $this->httpClient        = $httpClient;
        $this->collectionManager = $collectionManager;
        $this->documentBuilder   = $documentBuilder;
        $this->logger            = $logger;

    }//end __construct()


    /**
     * Index a single object.
     *
     * @param ObjectEntity $object Object to index
     * @param bool         $commit Whether to commit immediately
     *
     * @return bool True if successful
     */
    public function indexObject(ObjectEntity $object, bool $commit=false): bool
    {
        try {
            $collection = $this->collectionManager->getActiveCollectionName();

            if ($collection === null) {
                $this->logger->warning('[SolrDocumentIndexer] No active collection for indexing');
                return false;
            }

            // Use DocumentBuilder to create the Solr document.
            $document = $this->documentBuilder->createDocument($object);

            // Index the document.
            $url = $this->httpClient->getEndpointUrl($collection).'/update?commit='.($commit ? 'true' : 'false');

            $this->httpClient->post($url, [$document]);

            $this->logger->debug(
                    '[SolrDocumentIndexer] Object indexed',
                    [
                        'objectId' => $object->getId(),
                        'commit'   => $commit,
                    ]
                    );

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Failed to index object',
                    [
                        'objectId' => $object->getId(),
                        'error'    => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end indexObject()


    /**
     * Index multiple objects in bulk.
     *
     * @param array $objects Array of ObjectEntity objects
     * @param bool  $commit  Whether to commit immediately
     *
     * @return array Result with statistics
     */
    public function bulkIndexObjects(array $objects, bool $commit=true): array
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            return [
                'success' => false,
                'indexed' => 0,
                'failed'  => count($objects),
                'error'   => 'No active collection',
            ];
        }

        $documents    = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($objects as $object) {
            try {
                $documents[] = $this->documentBuilder->createDocument($object);
                $successCount++;
            } catch (Exception $e) {
                $failureCount++;
                $this->logger->warning(
                        '[SolrDocumentIndexer] Failed to create document for object',
                        [
                            'objectId' => $object->getId(),
                            'error'    => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        if (empty($documents) === false) {
            try {
                $url = $this->httpClient->getEndpointUrl($collection).'/update?commit='.($commit ? 'true' : 'false');
                $this->httpClient->post($url, $documents);

                $this->logger->info(
                        '[SolrDocumentIndexer] Bulk index completed',
                        [
                            'indexed' => $successCount,
                            'failed'  => $failureCount,
                        ]
                        );
            } catch (Exception $e) {
                $this->logger->error(
                        '[SolrDocumentIndexer] Bulk index failed',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
                return [
                    'success' => false,
                    'indexed' => 0,
                    'failed'  => count($objects),
                    'error'   => $e->getMessage(),
                ];
            }//end try
        }//end if

        return [
            'success' => true,
            'indexed' => $successCount,
            'failed'  => $failureCount,
        ];

    }//end bulkIndexObjects()


    /**
     * Index raw documents (not ObjectEntity).
     *
     * Used by FileHandler for indexing file chunks.
     *
     * @param array $documents Array of documents to index
     * @param bool  $commit    Whether to commit immediately
     *
     * @return bool True if successful
     */
    public function indexDocuments(array $documents, bool $commit=false): bool
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning('[SolrDocumentIndexer] No active collection for bulk index');
            return false;
        }

        try {
            $url = $this->httpClient->getEndpointUrl($collection).'/update?commit='.($commit ? 'true' : 'false');
            $this->httpClient->post($url, $documents);

            $this->logger->info(
                    '[SolrDocumentIndexer] Documents indexed',
                    [
                        'count'  => count($documents),
                        'commit' => $commit,
                    ]
                    );

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Failed to index documents',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end indexDocuments()


    /**
     * Delete an object from the index.
     *
     * @param string|int $objectId Object ID to delete
     * @param bool       $commit   Whether to commit immediately
     *
     * @return bool True if successful
     */
    public function deleteObject(string|int $objectId, bool $commit=false): bool
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning('[SolrDocumentIndexer] No active collection for deletion');
            return false;
        }

        try {
            $url = $this->httpClient->getEndpointUrl($collection).'/update?commit='.($commit ? 'true' : 'false');

            $deleteCommand = [
                'delete' => [
                    'query' => 'id:'.$objectId,
                ],
            ];

            $this->httpClient->post($url, $deleteCommand);

            $this->logger->debug(
                    '[SolrDocumentIndexer] Object deleted',
                    [
                        'objectId' => $objectId,
                        'commit'   => $commit,
                    ]
                    );

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Failed to delete object',
                    [
                        'objectId' => $objectId,
                        'error'    => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end deleteObject()


    /**
     * Delete documents by query.
     *
     * @param string $query         Solr query
     * @param bool   $commit        Whether to commit immediately
     * @param bool   $returnDetails Whether to return detailed results
     *
     * @return array|bool Results or boolean
     */
    public function deleteByQuery(string $query, bool $commit=false, bool $returnDetails=false): array|bool
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            return $returnDetails ? ['success' => false, 'error' => 'No active collection'] : false;
        }

        try {
            $url = $this->httpClient->getEndpointUrl($collection).'/update?commit='.($commit ? 'true' : 'false');

            $deleteCommand = [
                'delete' => [
                    'query' => $query,
                ],
            ];

            $result = $this->httpClient->post($url, $deleteCommand);

            $this->logger->info(
                    '[SolrDocumentIndexer] Deleted by query',
                    [
                        'query'  => $query,
                        'commit' => $commit,
                    ]
                    );

            if ($returnDetails) {
                return [
                    'success' => true,
                    'query'   => $query,
                    'result'  => $result,
                ];
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Delete by query failed',
                    [
                        'query' => $query,
                        'error' => $e->getMessage(),
                    ]
                    );

            if ($returnDetails) {
                return [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }

            return false;
        }//end try

    }//end deleteByQuery()


    /**
     * Commit changes to Solr.
     *
     * @return bool True if successful
     */
    public function commit(): bool
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning('[SolrDocumentIndexer] No active collection for commit');
            return false;
        }

        try {
            $url = $this->httpClient->getEndpointUrl($collection).'/update?commit=true';
            $this->httpClient->post($url, []);

            $this->logger->debug('[SolrDocumentIndexer] Commit successful');

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Commit failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end commit()


    /**
     * Clear all documents from the index.
     *
     * @param string|null $collectionName Collection to clear (null = active collection)
     *
     * @return array Result with statistics
     */
    public function clearIndex(?string $collectionName=null): array
    {
        $collection = $collectionName ?? $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            return [
                'success' => false,
                'message' => 'No collection specified',
            ];
        }

        try {
            $this->logger->info('[SolrDocumentIndexer] Clearing index', ['collection' => $collection]);

            $url = $this->httpClient->getEndpointUrl($collection).'/update?commit=true';

            $deleteCommand = [
                'delete' => [
                    'query' => '*:*',
                ],
            ];

            $this->httpClient->post($url, $deleteCommand);

            return [
                'success'    => true,
                'message'    => 'Index cleared successfully',
                'collection' => $collection,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Failed to clear index',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return [
                'success' => false,
                'message' => 'Failed to clear index: '.$e->getMessage(),
            ];
        }//end try

    }//end clearIndex()


    /**
     * Optimize the Solr index.
     *
     * @return bool True if successful
     */
    public function optimize(): bool
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning('[SolrDocumentIndexer] No active collection for optimization');
            return false;
        }

        try {
            $this->logger->info('[SolrDocumentIndexer] Optimizing index', ['collection' => $collection]);

            $url = $this->httpClient->getEndpointUrl($collection).'/update?optimize=true';
            $this->httpClient->post($url, []);

            $this->logger->info('[SolrDocumentIndexer] Optimization completed');

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Optimization failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return false;
        }//end try

    }//end optimize()


    /**
     * Get document count in the index.
     *
     * @return int Document count
     */
    public function getDocumentCount(): int
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            return 0;
        }

        try {
            $url  = $this->httpClient->getEndpointUrl($collection).'/select?q=*:*&rows=0&wt=json';
            $data = $this->httpClient->get($url);

            return $data['response']['numFound'] ?? 0;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrDocumentIndexer] Failed to get document count',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return 0;
        }//end try

    }//end getDocumentCount()


}//end class
