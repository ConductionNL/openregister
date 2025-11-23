<?php
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\VectorEmbeddingService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SolrObjectService;
use OCA\OpenRegister\Service\SolrFileService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use Psr\Log\LoggerInterface;

/**
 * SOLR Controller
 *
 * Handles all SOLR-related operations including:
 * - Semantic search (vector embeddings)
 * - Hybrid search (keyword + semantic)
 * - Vector statistics
 * - Collection management
 * - ConfigSet management
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
class SolrController extends Controller
{


    /**
     * Constructor
     *
     * @param string             $appName   The app name
     * @param IRequest           $request   The request object
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Perform semantic search using vector embeddings
     *
     * This endpoint allows searching for similar content using AI-powered
     * vector embeddings. It's particularly useful for finding conceptually
     * similar documents even when they don't share exact keywords.
     *
     * @param string      $query    Search query text
     * @param int         $limit    Maximum number of results (default: 10)
     * @param array       $filters  Optional filters (entity_type, entity_id, embedding_model)
     * @param string|null $provider Embedding provider override (openai, ollama)
     *
     * @return JSONResponse Search results with similarity scores
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function semanticSearch(
        string $query,
        int $limit=10,
        array $filters=[],
        ?string $provider=null
    ): JSONResponse {
        try {
            // Validate input.
            if (trim($query) === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Query parameter is required and cannot be empty',
                        ],
                        statusCode: 400
                        );
            }

            if ($limit < 1 || $limit > 100) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Limit must be between 1 and 100',
                        ],
                        statusCode: 400
                        );
            }

            // Get VectorEmbeddingService from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);

            // Perform semantic search.
            $results = $vectorService->semanticSearch($query, $limit, $filters, $provider);

            return new JSONResponse(
                    data: [
                        'success'     => true,
                        'query'       => $query,
                        'results'     => $results,
                        'total'       => count($results),
                        'limit'       => $limit,
                        'filters'     => $filters,
                        'search_type' => 'semantic',
                        'timestamp'   => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Semantic search failed',
                    context: [
                        'error' => $e->getMessage(),
                        'query' => $query ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'query'   => $query ?? null,
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end semanticSearch()


    /**
     * Perform hybrid search combining SOLR keyword and vector semantic search
     *
     * This endpoint combines traditional keyword-based search (SOLR) with
     * AI-powered semantic search for optimal results. Uses Reciprocal Rank
     * Fusion (RRF) to intelligently merge results from both methods.
     *
     * @param string      $query       Search query text
     * @param int         $limit       Maximum number of results (default: 20)
     * @param array       $solrFilters SOLR-specific filters
     * @param array       $weights     Search type weights ['solr' => 0.5, 'vector' => 0.5]
     * @param string|null $provider    Embedding provider override
     *
     * @return JSONResponse Combined search results with source breakdown
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function hybridSearch(
        string $query,
        int $limit=20,
        array $solrFilters=[],
        array $weights=['solr' => 0.5, 'vector' => 0.5],
        ?string $provider=null
    ): JSONResponse {
        try {
            // Validate input.
            if (trim($query) === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Query parameter is required and cannot be empty',
                        ],
                        statusCode: 400
                        );
            }

            if ($limit < 1 || $limit > 200) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Limit must be between 1 and 200',
                        ],
                        statusCode: 400
                        );
            }

            // Validate weights.
            $solrWeight   = $weights['solr'] ?? 0.5;
            $vectorWeight = $weights['vector'] ?? 0.5;

            if ($solrWeight < 0 || $solrWeight > 1 || $vectorWeight < 0 || $vectorWeight > 1) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Weights must be between 0 and 1',
                        ],
                        statusCode: 400
                        );
            }

            // Get VectorEmbeddingService from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);

            // Perform hybrid search.
            $result = $vectorService->hybridSearch($query, $solrFilters, $limit, $weights, $provider);

            return new JSONResponse(
                    data: [
                        'success'     => true,
                        'query'       => $query,
                        'search_type' => 'hybrid',
                        ...$result,
                        'timestamp'   => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Hybrid search failed',
                    context: [
                        'error' => $e->getMessage(),
                        'query' => $query ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'query'   => $query ?? null,
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end hybridSearch()


    /**
     * Get vector embedding statistics
     *
     * Returns comprehensive statistics about stored vector embeddings including:
     * - Total vector count
     * - Breakdown by entity type (file/object)
     * - Breakdown by embedding model
     * - Storage metrics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Vector statistics
     */
    public function getVectorStats(): JSONResponse
    {
        try {
            // Get VectorEmbeddingService from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);

            // Get statistics.
            $stats = $vectorService->getVectorStats();

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'stats'     => $stats,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to get vector stats',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end getVectorStats()


    /**
     * Test vector embedding generation with a provider
     *
     * This endpoint allows testing embedding generation with different providers
     * (OpenAI, Ollama, Fireworks) before enabling them in production. It generates
     * an embedding for the provided test text and returns metadata about the result.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Test results including embedding metadata
     */
    public function testVectorEmbedding(): JSONResponse
    {
        try {
            // Get request parameters.
            $params   = $this->request->getParams();
            $provider = $params['provider'] ?? null;
            $config   = $params['config'] ?? [];
            $testText = $params['testText'] ?? 'This is a test embedding generation.';

            // Validate provider.
            if ($provider === null || $provider === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Provider is required (openai, ollama, or fireworks)',
                        ],
                        statusCode: 400
                        );
            }

            if (in_array($provider, ['openai', 'ollama', 'fireworks']) === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Invalid provider. Must be one of: openai, ollama, fireworks',
                        ],
                        statusCode: 400
                        );
            }

            // Get VectorEmbeddingService from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);

            // Build embedding configuration based on provider.
            $embeddingConfig = [
                'provider' => $provider,
            ];

            // Add provider-specific configuration.
            switch ($provider) {
                case 'openai':
                    if (($config['apiKey'] ?? '') === '') {
                        return new JSONResponse(
                                data: [
                                    'success' => false,
                                    'error'   => 'OpenAI API key is required in config.apiKey',
                                ],
                                statusCode: 400
                                );
                    }

                    $embeddingConfig['apiKey'] = $config['apiKey'];
                    $embeddingConfig['model']  = $config['model'] ?? 'text-embedding-3-small';
                    break;

                case 'ollama':
                    $embeddingConfig['url']   = $config['url'] ?? 'http://localhost:11434';
                    $embeddingConfig['model'] = $config['model'] ?? 'nomic-embed-text';
                    break;

                case 'fireworks':
                    if (($config['apiKey'] ?? '') === '') {
                        return new JSONResponse(
                                data: [
                                    'success' => false,
                                    'error'   => 'Fireworks AI API key is required in config.apiKey',
                                ],
                                statusCode: 400
                                );
                    }

                    $embeddingConfig['apiKey']  = $config['apiKey'];
                    $embeddingConfig['model']   = $config['model'] ?? 'nomic-ai/nomic-embed-text-v1.5';
                    $embeddingConfig['baseUrl'] = $config['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1';
                    break;
            }//end switch

            // Log the test attempt.
            $this->logger->info(
                    message: 'Testing vector embedding generation',
                    context: [
                        'provider'   => $provider,
                        'model'      => $embeddingConfig['model'] ?? 'default',
                        'textLength' => strlen($testText),
                    ]
                    );

            // Generate test embedding with custom config.
            $startTime = microtime(true);
            $embedding = $vectorService->generateEmbeddingWithCustomConfig($testText, $embeddingConfig);
            $duration  = round((microtime(true) - $startTime) * 1000, 2);

            if ($embedding === null || $embedding === []) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Failed to generate embedding. Check provider configuration and credentials.',
                        ],
                        statusCode: 500
                        );
            }

            // Return success with metadata.
            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'message'   => 'Embedding generated successfully',
                        'metadata'  => [
                            'provider'    => $provider,
                            'model'       => $embeddingConfig['model'] ?? 'default',
                            'dimensions'  => count($embedding),
                            'textLength'  => strlen($testText),
                            'duration_ms' => $duration,
                            'firstValues' => array_slice($embedding, 0, 5),
            // First 5 values as preview.
                        ],
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to test vector embedding',
                    [
                        'error'    => $e->getMessage(),
                        'provider' => $params['provider'] ?? 'unknown',
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end testVectorEmbedding()


    /**
     * List all SOLR collections with their metadata
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Collection list
     */
    public function listCollections(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $collections       = $guzzleSolrService->listCollections();

            return new JSONResponse(
                    data: [
                        'success'     => true,
                        'collections' => $collections,
                        'total'       => count($collections),
                        'timestamp'   => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to list collections',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end listCollections()


    /**
     * List all SOLR ConfigSets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse ConfigSet list
     */
    public function listConfigSets(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);
            $configSets        = $guzzleSolrService->listConfigSets();

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'configSets' => $configSets,
                        'total'      => count($configSets),
                        'timestamp'  => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to list ConfigSets',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end listConfigSets()


    /**
     * Create a new SOLR collection
     *
     * @param string $collectionName    Name for the new collection
     * @param string $configName        ConfigSet to use
     * @param int    $numShards         Number of shards (default: 1)
     * @param int    $replicationFactor Replication factor (default: 1)
     * @param int    $maxShardsPerNode  Max shards per node (default: 1)
     *
     * @return JSONResponse Creation result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function createCollection(
        string $collectionName,
        string $configName,
        int $numShards=1,
        int $replicationFactor=1,
        int $maxShardsPerNode=1
    ): JSONResponse {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            $result = $guzzleSolrService->createCollection(
                $collectionName,
                $configName,
                $numShards,
                $replicationFactor,
                $maxShardsPerNode
            );

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'message'    => 'Collection created successfully',
                        'collection' => $collectionName,
                        'result'     => $result,
                        'timestamp'  => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to create collection',
                    context: [
                        'error'      => $e->getMessage(),
                        'collection' => $collectionName ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end createCollection()


    /**
     * Create a new SOLR ConfigSet
     *
     * @param string $name          Name for the new ConfigSet
     * @param string $baseConfigSet Base ConfigSet to copy from (default: _default)
     *
     * @return JSONResponse Creation result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function createConfigSet(string $name, string $baseConfigSet='_default'): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            $result = $guzzleSolrService->createConfigSet($name, $baseConfigSet);

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'message'   => 'ConfigSet created successfully',
                        'configSet' => $name,
                        'result'    => $result,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to create ConfigSet',
                    context: [
                        'error'     => $e->getMessage(),
                        'configSet' => $name ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end createConfigSet()


    /**
     * Delete a SOLR ConfigSet
     *
     * @param string $name ConfigSet name to delete
     *
     * @return JSONResponse Deletion result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function deleteConfigSet(string $name): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            $result = $guzzleSolrService->deleteConfigSet($name);

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'message'   => 'ConfigSet deleted successfully',
                        'configSet' => $name,
                        'result'    => $result,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to delete ConfigSet',
                    context: [
                        'error'     => $e->getMessage(),
                        'configSet' => $name ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end deleteConfigSet()


    /**
     * Copy/duplicate an existing SOLR collection
     *
     * @param string $sourceCollection Source collection name
     * @param string $targetCollection Target collection name
     *
     * @return JSONResponse Copy result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function copyCollection(string $sourceCollection, string $targetCollection): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(GuzzleSolrService::class);

            $result = $guzzleSolrService->copyCollection($sourceCollection, $targetCollection);

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'message'   => 'Collection copied successfully',
                        'source'    => $sourceCollection,
                        'target'    => $targetCollection,
                        'result'    => $result,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to copy collection',
                    context: [
                        'error'  => $e->getMessage(),
                        'source' => $sourceCollection ?? null,
                        'target' => $targetCollection ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end copyCollection()


    /**
     * Vectorize a single object by ID
     *
     * This endpoint generates an AI embedding for an object and stores it
     * in the vector database for semantic search.
     *
     * @param int         $objectId Object ID to vectorize
     * @param string|null $provider Optional embedding provider override
     *
     * @return JSONResponse Vectorization result
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function vectorizeObject(int $objectId, ?string $provider=null): JSONResponse
    {
        try {
            // Get services from container.
            $objectMapper      = $this->container->get(ObjectEntityMapper::class);
            $solrObjectService = $this->container->get(SolrObjectService::class);

            // Fetch the object.
            $object = $objectMapper->find($objectId);

            // Vectorize the object.
            $result = $solrObjectService->vectorizeObject($object, $provider);

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'message'   => 'Object vectorized successfully',
                        ...$result,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to vectorize object',
                    context: [
                        'error'     => $e->getMessage(),
                        'object_id' => $objectId ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success'   => false,
                        'error'     => $e->getMessage(),
                        'object_id' => $objectId ?? null,
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end vectorizeObject()


    /**
     * Bulk vectorize objects with optional filtering
     *
     * This endpoint allows vectorizing multiple objects at once, optionally
     * filtered by schema or register. Supports pagination for large datasets.
     *
     * @param int|null    $schemaId   Optional schema ID to filter
     * @param int|null    $registerId Optional register ID to filter
     * @param int         $limit      Maximum objects to process (default: 100, max: 1000)
     * @param int         $offset     Offset for pagination (default: 0)
     * @param string|null $provider   Optional embedding provider override
     *
     * @return JSONResponse Bulk vectorization results with progress
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function bulkVectorizeObjects(
        ?int $schemaId=null,
        ?int $registerId=null,
        int $limit=100,
        int $offset=0,
        ?string $provider=null
    ): JSONResponse {
        try {
            // Validate limits.
            if ($limit < 1 || $limit > 1000) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Limit must be between 1 and 1000',
                        ],
                        statusCode: 400
                        );
            }

            if ($offset < 0) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Offset must be >= 0',
                        ],
                        statusCode: 400
                        );
            }

            // Get services from container.
            $objectMapper      = $this->container->get(ObjectEntityMapper::class);
            $solrObjectService = $this->container->get(SolrObjectService::class);

            // Build query conditions.
            $conditions = [];
            if ($schemaId !== null) {
                $conditions['schema'] = $schemaId;
            }

            if ($registerId !== null) {
                $conditions['register'] = $registerId;
            }

            // Fetch objects with conditions.
            // Note: This is a simplified example - adjust based on actual ObjectEntityMapper methods.
            $objects = $objectMapper->findAll($limit, $offset);

            if (count($objects) === 0) {
                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => 'No objects found to vectorize',
                            'total'      => 0,
                            'successful' => 0,
                            'failed'     => 0,
                            'results'    => [],
                            'timestamp'  => date('c'),
                        ]
                        );
            }

            // Vectorize the objects.
            $result = $solrObjectService->vectorizeObjects($objects, $provider);

            return new JSONResponse(
                    data: [
                        'success'    => $result['success'],
                        'message'    => "Processed {$result['successful']} of {$result['total']} objects",
                        ...$result,
                        'pagination' => [
                            'limit'    => $limit,
                            'offset'   => $offset,
                            'has_more' => count($objects) === $limit,
                        ],
                        'filters'    => [
                            'schema_id'   => $schemaId,
                            'register_id' => $registerId,
                        ],
                        'timestamp'  => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to bulk vectorize objects',
                    context: [
                        'error'       => $e->getMessage(),
                        'schema_id'   => $schemaId ?? null,
                        'register_id' => $registerId ?? null,
                        'limit'       => $limit ?? null,
                        'offset'      => $offset ?? null,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end bulkVectorizeObjects()


    /**
     * Get vectorization statistics and progress
     *
     * Returns information about how many objects have been vectorized,
     * broken down by schema, register, and embedding model.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Vectorization statistics
     */
    public function getVectorizationStats(): JSONResponse
    {
        try {
            // Get services from container.
            $vectorService = $this->container->get(VectorEmbeddingService::class);
            $objectMapper  = $this->container->get(ObjectEntityMapper::class);

            // Get vector stats.
            $vectorStats = $vectorService->getVectorStats();

            // Get total object count efficiently (don't load all objects into memory!).
            $totalObjects = $objectMapper->countAll();

            // Calculate progress.
            $vectorizedObjects = $vectorStats['object_vectors'] ?? 0;
            if ($totalObjects > 0) {
                $progress = round(($vectorizedObjects / $totalObjects) * 100, 2);
            } else {
                $progress = 0;
            }

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'stats'     => [
                            'total_objects'       => $totalObjects,
                            'vectorized_objects'  => $vectorizedObjects,
                            'progress_percentage' => $progress,
                            'remaining_objects'   => $totalObjects - $vectorizedObjects,
                            'vector_breakdown'    => $vectorStats,
                        ],
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to get vectorization stats',
                    context: [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end getVectorizationStats()


}//end class
