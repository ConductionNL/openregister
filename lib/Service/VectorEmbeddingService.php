<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use LLPhant\OpenAIConfig;
use LLPhant\OllamaConfig;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;

/**
 * Vector Embedding Service
 *
 * Handles vector embeddings generation, storage, and semantic search using LLPhant.
 * This service is INDEPENDENT of SOLR and handles all LLM embedding operations.
 *
 * RESPONSIBILITIES:
 * - Generate embeddings for text using multiple LLM providers (OpenAI, Fireworks, Ollama)
 * - Store embeddings in the database (oc_openregister_vectors table)
 * - Perform semantic similarity searches using cosine similarity
 * - Test embedding configurations without saving settings
 * - Manage embedding generators for different providers and models
 *
 * PROVIDER SUPPORT:
 * - OpenAI: text-embedding-ada-002, text-embedding-3-small, text-embedding-3-large
 * - Fireworks AI: Custom OpenAI-compatible API with various models
 * - Ollama: Local models with custom configurations
 *
 * ARCHITECTURE:
 * - Uses LLPhant library for embedding generation
 * - Stores embeddings in database for semantic search
 * - Independent of SOLR/keyword search infrastructure
 * - Can be used standalone for vector operations
 *
 * INTEGRATION POINTS:
 * - ChatService: Uses embeddings for RAG (Retrieval Augmented Generation)
 * - SolrObjectService/SolrFileService: Can work together for hybrid search
 * - SettingsController: Delegates testing to this service
 *
 * NOTE: This service does NOT depend on SOLR. For hybrid search that combines
 * keyword and semantic results, use ChatService or implement in calling code.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
class VectorEmbeddingService
{
    /**
     * Default embedding dimensions for different models
     */
    private const EMBEDDING_DIMENSIONS = [
        'text-embedding-ada-002' => 1536,
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'ollama-default'         => 384,
    ];

    /**
     * Cache for embedding generators (to avoid recreating them)
     *
     * @var array<string, mixed>
     */
    private array $generatorCache = [];


    /**
     * Constructor
     *
     * @param IDBConnection     $db              Database connection
     * @param SettingsService   $settingsService Settings management service
     * @param GuzzleSolrService $solrService     Solr service for vector storage
     * @param LoggerInterface   $logger          PSR-3 logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly SettingsService $settingsService,
        private readonly GuzzleSolrService $solrService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Get the configured vector search backend
     *
     * Returns the backend configured in LLM settings: 'php', 'database', or 'solr'
     * Defaults to 'php' if not configured.
     *
     * @return string Vector search backend ('php', 'database', or 'solr')
     */
    private function getVectorSearchBackend(): string
    {
        try {
            $llmSettings = $this->settingsService->getLLMSettingsOnly();
            return $llmSettings['vectorConfig']['backend'] ?? 'php';
        } catch (\Exception $e) {
            $this->logger->warning(
                    message: '[VectorEmbeddingService] Failed to get vector search backend, defaulting to PHP',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );
            return 'php';
        }

    }//end getVectorSearchBackend()


    /**
     * Get the appropriate Solr collection based on entity type
     *
     * Files go to fileCollection, objects go to objectCollection
     *
     * @param string $entityType Entity type ('file' or 'object')
     *
     * @return string|null Solr collection name or null if not configured
     */
    private function getSolrCollectionForEntityType(string $entityType): ?string
    {
        try {
            $settings = $this->settingsService->getSettings();

            // Normalize entity type.
            $entityType = strtolower($entityType);

            // Determine which collection to use based on entity type.
            if ($entityType === 'file' || $entityType === 'files') {
                $collection = $settings['solr']['fileCollection'] ?? null;
            } else {
                // Default to object collection for objects and any other type.
                $collection = $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
            }

            if ($collection === null || $collection === '') {
                $this->logger->warning(
                        message: '[VectorEmbeddingService] No Solr collection configured for entity type',
                        context: [
                            'entity_type' => $entityType,
                        ]
                        );
            }

            return $collection;
        } catch (\Exception $e) {
            $this->logger->warning(
                    message: '[VectorEmbeddingService] Failed to get Solr collection for entity type',
                    context: [
                        'entity_type' => $entityType,
                        'error'       => $e->getMessage(),
                    ]
                    );
            return null;
        }//end try

    }//end getSolrCollectionForEntityType()


    /**
     * Get the configured Solr vector field name
     *
     * @return string Solr vector field name (default: '_embedding_')
     */
    private function getSolrVectorField(): string
    {
        try {
            $settings = $this->settingsService->getSettings();
            return $settings['llm']['vectorConfig']['solrField'] ?? '_embedding_';
        } catch (\Exception $e) {
            $this->logger->warning(
                    message: '[VectorEmbeddingService] Failed to get Solr vector field, using default',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );
            return '_embedding_';
        }

    }//end getSolrVectorField()


    /**
     * Generate embedding for a single text
     *
     * @param string      $text     Text to embed
     * @param string|null $provider Embedding provider (null = use default from settings)
     *
     * @return (float[]|int|string)[] Embedding data
     *
     * @throws \Exception If embedding generation fails
     *
     * @psalm-return array{embedding: array<float>, model: string, dimensions: int<0, max>}
     */
    public function generateEmbedding(string $text, ?string $provider=null): array
    {
        $config = $this->getEmbeddingConfig($provider);

        $this->logger->debug(
                message: 'Generating embedding',
                context: [
                    'text_length' => strlen($text),
                    'provider'    => $config['provider'],
                    'model'       => $config['model'],
                ]
                );

        try {
            $generator = $this->getEmbeddingGenerator($config);

            // Generate embedding using LLPhant.
            $embedding = $generator->embedText($text);

            $dimensions = count($embedding);

            $this->logger->debug(
                    message: 'Embedding generated successfully',
                    context: [
                        'dimensions' => $dimensions,
                        'model'      => $config['model'],
                    ]
                    );

            return [
                'embedding'  => $embedding,
                'model'      => $config['model'],
                'dimensions' => $dimensions,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to generate embedding',
                    context: [
                        'error'       => $e->getMessage(),
                        'provider'    => $config['provider'],
                        'text_length' => strlen($text),
                    ]
                    );
            throw new \Exception('Embedding generation failed: '.$e->getMessage());
        }//end try

    }//end generateEmbedding()


    /**
     * Generate embedding with custom configuration (for testing)
     *
     * This method allows testing embedding generation with custom config
     * without saving it to settings. Useful for validating API keys and
     * provider configuration before saving.
     *
     * @param string $text   Text to embed
     * @param array  $config Custom configuration array with:
     *                       - provider: 'openai', 'ollama', or 'fireworks'
     *                       - model: Model name
     *                       - apiKey: API key (for OpenAI/Fireworks)
     *                       - url: URL (for Ollama)
     *                       - baseUrl: Base URL (for Fireworks)
     *
     * @return float[] Embedding vector (array of floats)
     *
     * @throws \Exception If embedding generation fails
     *
     * @psalm-return array<float>
     */
    public function generateEmbeddingWithCustomConfig(string $text, array $config): array
    {
        $this->logger->debug(
                message: 'Generating embedding with custom config',
                context: [
                    'text_length' => strlen($text),
                    'provider'    => $config['provider'] ?? 'unknown',
                    'model'       => $config['model'] ?? 'unknown',
                ]
                );

        try {
            // Normalize config format (frontend uses camelCase, backend uses snake_case).
            $normalizedConfig = [
                'provider' => $config['provider'],
                'model'    => $config['model'] ?? null,
                'api_key'  => $config['apiKey'] ?? null,
                'base_url' => $config['baseUrl'] ?? $config['url'] ?? null,
            ];

            // Validate required fields.
            if (empty($normalizedConfig['provider']) === true) {
                throw new \Exception('Provider is required');
            }

            if (empty($normalizedConfig['model']) === true) {
                throw new \Exception('Model is required');
            }

            // Validate API keys for providers that need them.
            if (in_array($normalizedConfig['provider'], ['openai', 'fireworks'], true) === true && empty($normalizedConfig['api_key']) === true) {
                throw new \Exception("API key is required for {$normalizedConfig['provider']}");
            }

            // Create embedding generator.
            $generator = $this->getEmbeddingGenerator($normalizedConfig);

            // Generate embedding.
            $embedding = $generator->embedText($text);

            $this->logger->debug(
                    message: 'Embedding generated successfully with custom config',
                    context: [
                        'dimensions' => count($embedding),
                        'model'      => $normalizedConfig['model'],
                    ]
                    );

            return $embedding;
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to generate embedding with custom config',
                    context: [
                        'error'       => $e->getMessage(),
                        'provider'    => $config['provider'] ?? 'unknown',
                        'text_length' => strlen($text),
                    ]
                    );
            throw new \Exception('Embedding generation failed: '.$e->getMessage());
        }//end try

    }//end generateEmbeddingWithCustomConfig()


    /**
     * Test embedding generation with custom configuration
     *
     * Tests if the provided embedding configuration works correctly by generating
     * a test embedding. Does not save any configuration or store the embedding.
     *
     * @param string $provider Provider name ('openai', 'fireworks', 'ollama')
     * @param array  $config   Provider-specific configuration
     * @param string $testText Optional test text to embed
     *
     * @return ((float[]|int|mixed|string)[]|bool|string)[]
     *
     * @psalm-return array{success: bool, error?: string, message: string, data?: array{provider: string, model: 'unknown'|mixed, vectorLength: int<0, max>, sampleValues: array<float>, testText: string}}
     */
    public function testEmbedding(string $provider, array $config, string $testText='Test.'): array
    {
        $this->logger->info(
                message: '[VectorEmbeddingService] Testing embedding generation',
                context: [
                    'provider'       => $provider,
                    'model'          => $config['model'] ?? 'unknown',
                    'testTextLength' => strlen($testText),
                ]
                );

        try {
            // Generate embedding using custom config.
            $embedding = $this->generateEmbeddingWithCustomConfig(
                    $testText,
                    [
                        'provider' => $provider,
                        'model'    => $config['model'] ?? null,
                        'apiKey'   => $config['apiKey'] ?? null,
                        'baseUrl'  => $config['baseUrl'] ?? $config['url'] ?? null,
                    ]
                    );

            $this->logger->info(
                    message: '[VectorEmbeddingService] Embedding test successful',
                    context: [
                        'provider'   => $provider,
                        'model'      => $config['model'] ?? 'unknown',
                        'dimensions' => count($embedding),
                    ]
                    );

            return [
                'success' => true,
                'message' => 'Embedding test successful',
                'data'    => [
                    'provider'     => $provider,
                    'model'        => $config['model'] ?? 'unknown',
                    'vectorLength' => count($embedding),
                    'sampleValues' => array_slice($embedding, 0, 5),
                    'testText'     => $testText,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[VectorEmbeddingService] Embedding test failed',
                    context: [
                        'provider' => $provider,
                        'error'    => $e->getMessage(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => 'Failed to generate embedding: '.$e->getMessage(),
            ];
        }//end try

    }//end testEmbedding()


    /**
     * Generate embeddings for multiple texts in batch
     *
     * More efficient than calling generateEmbedding() multiple times.
     *
     * @param array<string> $texts    Array of texts to embed
     * @param string|null   $provider Embedding provider
     *
     * @return array<int, array{embedding: array<float>, model: string, dimensions: int}> Array of embeddings
     *
     * @throws \Exception If batch embedding generation fails
     */
    public function generateBatchEmbeddings(array $texts, ?string $provider=null): array
    {
        $config = $this->getEmbeddingConfig($provider);

        $this->logger->info(
                message: 'Generating batch embeddings',
                context: [
                    'count'    => count($texts),
                    'provider' => $config['provider'],
                    'model'    => $config['model'],
                ]
                );

        try {
            $generator = $this->getEmbeddingGenerator($config);

            // Generate embeddings individually for each text.
                // Note: For true batch processing, use LLPhant's Document system.
            $results = [];
            foreach ($texts as $index => $text) {
                try {
                    $embedding = $generator->embedText($text);
                    $results[] = [
                        'embedding'  => $embedding,
                        'model'      => $config['model'],
                        'dimensions' => count($embedding),
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning(
                            message: 'Failed to generate embedding for text',
                            context: [
                                'index' => $index,
                                'error' => $e->getMessage(),
                            ]
                            );
                    // Skip this text but continue with others.
                    $results[] = [
                        'embedding'  => null,
                        'model'      => $config['model'],
                        'dimensions' => 0,
                        'error'      => $e->getMessage(),
                    ];
                }//end try
            }//end foreach

            $successful = count(array_filter($results, fn($r) => $r['embedding'] !== null));

            $this->logger->info(
                    message: 'Batch embedding generation completed',
                    context: [
                        'total'      => count($texts),
                        'successful' => $successful,
                        'failed'     => count($texts) - $successful,
                    ]
                    );

            return $results;
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to generate batch embeddings',
                    context: [
                        'error' => $e->getMessage(),
                        'count' => count($texts),
                    ]
                    );
            throw new \Exception('Batch embedding generation failed: '.$e->getMessage());
        }//end try

    }//end generateBatchEmbeddings()


    /**
     * Store vector embedding in Solr
     *
     * Stores a vector embedding in the configured Solr collection using dense vector fields.
     * The vector is stored as a Solr document with the embedding in a dense vector field.
     *
     * @param string      $entityType  Entity type ('object' or 'file')
     * @param string      $entityId    Entity UUID
     * @param array       $embedding   Vector embedding (array of floats)
     * @param string      $model       Model used to generate embedding
     * @param int         $dimensions  Number of dimensions
     * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks Total number of chunks
     * @param string|null $chunkText   The text that was embedded
     * @param array       $metadata    Additional metadata as associative array
     *
     * @return string The Solr document ID
     *
     * @throws \Exception If storage fails or Solr is not configured
     */
    private function storeVectorInSolr(
        string $entityType,
        string $entityId,
        array $embedding,
        string $model,
        int $dimensions,
        int $chunkIndex=0,
        int $_totalChunks=1,
        ?string $_chunkText=null,
        array $_metadata=[]
    ): string {
        $this->logger->debug(
                message: '[VectorEmbeddingService] Storing vector in Solr',
                context: [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'chunk_index' => $chunkIndex,
                    'dimensions'  => $dimensions,
                ]
                );

        try {
            // Get appropriate Solr collection based on entity type.
            $collection  = $this->getSolrCollectionForEntityType($entityType);
            $vectorField = $this->getSolrVectorField();

            if ($collection === null || $collection === '') {
                throw new \Exception("Solr collection not configured for entity type: {$entityType}");
            }

            // Check if Solr is available.
            if ($this->solrService->isAvailable() === false) {
                throw new \Exception('Solr service is not available');
            }

            // Determine document ID based on entity type.
            // Files: {fileId}_chunk_{chunkIndex} (matches existing file indexing)
            // Objects: use the object's UUID or ID directly.
            $entityTypeLower = strtolower($entityType);
            if ($entityTypeLower === 'file' || $entityTypeLower === 'files') {
                $documentId = "{$entityId}_chunk_{$chunkIndex}";
            } else {
                // For objects, use the entity ID directly (should be UUID).
                $documentId = $entityId;
            }

            // Prepare atomic update document to add embedding fields to existing document.
                // Using Solr atomic updates: https://solr.apache.org/guide/solr/latest/indexing-guide/partial-document-updates.html.
            $updateDocument = [
                'id'                => $documentId,
                $vectorField        => ['set' => $embedding],
            // Set the embedding vector.
                '_embedding_model_' => ['set' => $model],
            // Set the model name.
                '_embedding_dim_'   => ['set' => $dimensions],
            // Set the dimensions.
                'self_updated'      => ['set' => gmdate('Y-m-d\TH:i:s\Z')],
            // Update timestamp.
            ];

            $this->logger->debug(
                    message: '[VectorEmbeddingService] Preparing atomic update',
                    context: [
                        'document_id'    => $documentId,
                        'collection'     => $collection,
                        'vector_field'   => $vectorField,
                        'embedding_size' => count($embedding),
                    ]
                    );

            // Perform atomic update in Solr.
            $solrUrl  = $this->solrService->buildSolrBaseUrl()."/{$collection}/update?commit=true";
            $response = $this->solrService->getHttpClient()->post(
                    $solrUrl,
                    [
                        'json'    => [$updateDocument],
                        'headers' => ['Content-Type' => 'application/json'],
                    ]
                    );

            $responseData = json_decode((string) $response->getBody(), true);

            if (!isset($responseData['responseHeader']['status']) === false || $responseData['responseHeader']['status'] !== 0) {
                throw new \Exception('Solr atomic update failed: '.json_encode($responseData));
            }

            $this->logger->info(
                    message: '[VectorEmbeddingService] Vector added to existing Solr document',
                    context: [
                        'document_id' => $documentId,
                        'collection'  => $collection,
                        'entity_type' => $entityType,
                        'entity_id'   => $entityId,
                        'operation'   => 'atomic_update',
                    ]
                    );

            return $documentId;
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[VectorEmbeddingService] Failed to store vector in Solr',
                    context: [
                        'error'       => $e->getMessage(),
                        'entity_type' => $entityType,
                        'entity_id'   => $entityId,
                        'chunk_index' => $chunkIndex,
                    ]
                    );
            throw new \Exception('Solr vector storage failed: '.$e->getMessage());
        }//end try

    }//end storeVectorInSolr()


    /**
     * Search vectors in Solr using dense vector KNN
     *
     * Performs K-Nearest Neighbors search using Solr's dense vector capabilities.
     * Uses the {!knn f=FIELD topK=N} query syntax for efficient vector similarity search.
     *
     * @param array $queryEmbedding Query vector embedding
     * @param int   $limit          Maximum number of results
     * @param array $filters        Additional filters (entity_type, etc.)
     *
     * @return (array|float|int|mixed|null|string)[][]
     *
     * @throws \Exception If search fails or Solr is not configured
     *
     * @psalm-return list<array{chunk_index: 0|mixed, chunk_text: mixed|null, dimensions: 0|mixed, entity_id: string, entity_type: string, metadata: array, model: ''|mixed, similarity: float(0)|mixed, total_chunks: 1|mixed, vector_id: mixed}>
     */
    private function searchVectorsInSolr(
        array $queryEmbedding,
        int $limit=10,
        array $filters=[]
    ): array {
        $this->logger->debug(
                message: '[VectorEmbeddingService] Searching vectors in Solr',
                context: [
                    'limit'   => $limit,
                    'filters' => $filters,
                ]
                );

        try {
            // Check if Solr is available.
            if ($this->solrService->isAvailable() === false) {
                throw new \Exception('Solr service is not available');
            }

            $vectorField = $this->getSolrVectorField();
            $allResults  = [];

            // Determine which collections to search based on entity_type filter.
            $collectionsToSearch = [];
            if (($filters['entity_type'] ?? null) !== null) {
                if (is_array($filters['entity_type']) === true) {
                    $entityTypes = $filters['entity_type'];
                } else {
                    $entityTypes = [$filters['entity_type']];
                }

                foreach ($entityTypes as $entityType) {
                    $collection = $this->getSolrCollectionForEntityType($entityType);
                    if ($collection !== null && $collection !== '') {
                        $collectionsToSearch[] = [
                            'type'       => $entityType,
                            'collection' => $collection,
                        ];
                    }
                }
            } else {
                // Search both object and file collections.
                $settings         = $this->settingsService->getSettings();
                $objectCollection = $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
                $fileCollection   = $settings['solr']['fileCollection'] ?? null;

                if ($objectCollection !== null && $objectCollection !== '') {
                    $collectionsToSearch[] = ['type' => 'object', 'collection' => $objectCollection];
                }

                if ($fileCollection !== null && $fileCollection !== '') {
                    $collectionsToSearch[] = ['type' => 'file', 'collection' => $fileCollection];
                }
            }//end if

            if ($collectionsToSearch === []) {
                throw new \Exception('No Solr collections configured for vector search');
            }

            // Build Solr KNN query.
            // Format: {!knn f=_embedding_ topK=10}[0.1, 0.2, 0.3, ...].
            $vectorString = '['.implode(', ', $queryEmbedding).']';
            $knnQuery     = "{!knn f={$vectorField} topK={$limit}}{$vectorString}";

            // Search each collection.
            foreach ($collectionsToSearch as $collectionInfo) {
                $collection = $collectionInfo['collection'];
                $entityType = $collectionInfo['type'];

                $this->logger->debug(
                        message: '[VectorEmbeddingService] Searching collection',
                        context: [
                            'collection'  => $collection,
                            'entity_type' => $entityType,
                        ]
                        );

                // Query Solr - return all fields (*).
                $queryParams = [
                    'q'    => $knnQuery,
                    'rows' => $limit,
                    'fl'   => '*,score',
                // Return all fields plus the similarity score.
                    'wt'   => 'json',
                ];

                $solrUrl = $this->solrService->buildSolrBaseUrl()."/{$collection}/select";

                try {
                    $response = $this->solrService->getHttpClient()->get(
                            $solrUrl,
                            [
                                'query' => $queryParams,
                            ]
                            );

                    $responseData = json_decode((string) $response->getBody(), true);

                    if (!isset($responseData['response']['docs'])) {
                        $this->logger->warning(
                                message: '[VectorEmbeddingService] Invalid Solr response format',
                                context: [
                                    'collection' => $collection,
                                ]
                                );
                        continue;
                    }

                    // Transform Solr documents - keep the full document.
                    foreach ($responseData['response']['docs'] as $doc) {
                        $allResults[] = [
                            'vector_id'    => $doc['id'],
                            'entity_type'  => $entityType,
                            'entity_id'    => $this->extractEntityId($doc, $entityType),
                            'similarity'   => $doc['score'] ?? 0.0,
                            'chunk_index'  => $doc['chunk_index'] ?? $doc['chunk_index_i'] ?? 0,
                            'total_chunks' => $doc['chunk_total'] ?? $doc['total_chunks_i'] ?? 1,
                            'chunk_text'   => $doc['chunk_text'] ?? $doc['chunk_text_txt'] ?? null,
                            'metadata'     => $doc,
                        // Include full Solr document as metadata.
                            'model'        => $doc['_embedding_model_'] ?? $doc['embedding_model_s'] ?? '',
                            'dimensions'   => $doc['_embedding_dim_'] ?? $doc['embedding_dimensions_i'] ?? 0,
                        ];
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                            message: '[VectorEmbeddingService] Failed to search collection',
                            context: [
                                'collection' => $collection,
                                'error'      => $e->getMessage(),
                            ]
                            );
                    // Continue to next collection.
                }//end try
            }//end foreach

            // Sort all results by similarity (descending) and limit.
            usort($allResults, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
            $allResults = array_slice($allResults, 0, $limit);

            $this->logger->info(
                    message: '[VectorEmbeddingService] Solr vector search completed',
                    context: [
                        'results_count'        => count($allResults),
                        'collections_searched' => count($collectionsToSearch),
                    ]
                    );

            return $allResults;
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[VectorEmbeddingService] Solr vector search failed',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );
            throw new \Exception('Solr vector search failed: '.$e->getMessage());
        }//end try

    }//end searchVectorsInSolr()


    /**
     * Extract entity ID from Solr document based on entity type
     *
     * @param array  $doc        Solr document
     * @param string $entityType Entity type ('file' or 'object')
     *
     * @return string Entity ID
     */
    private function extractEntityId(array $doc, string $entityType): string
    {
        // For files, extract file_id.
        if ($entityType === 'file' || $entityType === 'files') {
            return (string) ($doc['file_id'] ?? $doc['file_id_l'] ?? '');
        }

        // For objects, use self_uuid or self_object_id.
        return $doc['self_uuid'] ?? $doc['self_object_id'] ?? $doc['id'] ?? '';

    }//end extractEntityId()


    /**
     * Store vector embedding in database
     *
     * @param string      $entityType  Entity type ('object' or 'file')
     * @param string      $entityId    Entity UUID
     * @param array       $embedding   Vector embedding (array of floats)
     * @param string      $model       Model used to generate embedding
     * @param int         $dimensions  Number of dimensions
     * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks Total number of chunks
     * @param string|null $chunkText   The text that was embedded
     * @param array       $metadata    Additional metadata as associative array
     *
     * @return int The ID of the inserted vector
     *
     * @throws \Exception If storage fails
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function storeVector(
        string $entityType,
        string $entityId,
        array $embedding,
        string $model,
        int $dimensions,
        int $chunkIndex=0,
        int $totalChunks=1,
        ?string $chunkText=null,
        array $metadata=[]
    ): int {
        // Route to appropriate backend based on configuration.
        $backend = $this->getVectorSearchBackend();

        $this->logger->debug(
                message: '[VectorEmbeddingService] Routing vector storage',
                context: [
                    'backend'     => $backend,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'chunk_index' => $chunkIndex,
                    'dimensions'  => $dimensions,
                ]
                );

        try {
            // Route to selected backend.
            if ($backend === 'solr') {
                // Store in Solr and return a pseudo-ID (we hash the document ID to an integer for compatibility).
                $documentId = $this->storeVectorInSolr(
                    $entityType,
                    $entityId,
                    $embedding,
                    $model,
                    $dimensions,
                    $chunkIndex,
                    $totalChunks,
                    $chunkText,
                    $metadata
                );
                // Return a hash of the document ID as integer for API compatibility.
                return crc32($documentId);
            }

            // Default: Store in database (PHP backend or future database backend).
            return $this->storeVectorInDatabase(
                $entityType,
                $entityId,
                $embedding,
                $model,
                $dimensions,
                $chunkIndex,
                $totalChunks,
                $chunkText,
                $metadata
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[VectorEmbeddingService] Failed to store vector',
                    context: [
                        'backend'     => $backend,
                        'error'       => $e->getMessage(),
                        'entity_type' => $entityType,
                        'entity_id'   => $entityId,
                    ]
                    );
            throw new \Exception('Vector storage failed: '.$e->getMessage());
        }//end try

    }//end storeVector()


    /**
     * Store vector embedding in database (original implementation)
     *
     * @param string      $entityType  Entity type ('object' or 'file')
     * @param string      $entityId    Entity UUID
     * @param array       $embedding   Vector embedding (array of floats)
     * @param string      $model       Model used to generate embedding
     * @param int         $dimensions  Number of dimensions
     * @param int         $chunkIndex  Chunk index (0 for objects, N for file chunks)
     * @param int         $totalChunks Total number of chunks
     * @param string|null $chunkText   The text that was embedded
     * @param array       $metadata    Additional metadata as associative array
     *
     * @return int The ID of the inserted vector
     *
     * @throws \Exception If storage fails
     */
    private function storeVectorInDatabase(
        string $entityType,
        string $entityId,
        array $embedding,
        string $model,
        int $dimensions,
        int $chunkIndex=0,
        int $totalChunks=1,
        ?string $chunkText=null,
        array $metadata=[]
    ): int {
        $this->logger->debug(
            message: '[VectorEmbeddingService] Storing vector in database',
            context: [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'chunk_index' => $chunkIndex,
                'dimensions'  => $dimensions,
            ]
        );

        try {
            // Serialize embedding to binary format.
            $embeddingBlob = serialize($embedding);

            // Serialize metadata to JSON.
            if (empty($metadata) === false) {
                $metadataJson = json_encode($metadata);
            } else {
                $metadataJson = null;
            }

            // Sanitize chunk_text to prevent encoding errors.
            // Remove invalid UTF-8 sequences and control characters.
            if ($chunkText !== null) {
                $sanitizedChunkText = $this->sanitizeText($chunkText);
            } else {
                $sanitizedChunkText = null;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('openregister_vectors')
                ->values(values: [
                            'entity_type'          => $qb->createNamedParameter($entityType),
                            'entity_id'            => $qb->createNamedParameter($entityId),
                            'chunk_index'          => $qb->createNamedParameter($chunkIndex, \PDO::PARAM_INT),
                            'total_chunks'         => $qb->createNamedParameter($totalChunks, \PDO::PARAM_INT),
                            'chunk_text'           => $qb->createNamedParameter($sanitizedChunkText),
                            'embedding'            => $qb->createNamedParameter($embeddingBlob, \PDO::PARAM_LOB),
                            'embedding_model'      => $qb->createNamedParameter($model),
                            'embedding_dimensions' => $qb->createNamedParameter($dimensions, \PDO::PARAM_INT),
                            'metadata'             => $qb->createNamedParameter($metadataJson),
                            'created_at'           => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                            'updated_at'           => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                        ]
                        )
                ->executeStatement();

            $vectorId = $qb->getLastInsertId();

            $this->logger->info(
                message: 'Vector stored successfully',
                context: [
                    'vector_id'   => $vectorId,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ]
                    );

            return $vectorId;
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to store vector',
                    context: [
                        'error'       => $e->getMessage(),
                        'entity_type' => $entityType,
                        'entity_id'   => $entityId,
                    ]
                    );
            throw new \Exception('Vector storage failed: '.$e->getMessage());
        }//end try

    }//end storeVectorInDatabase()


    /**
     * Perform semantic similarity search
     *
     * Find the most similar vectors to a query using cosine similarity.
     *
     * @param string      $query    Query text to search for
     * @param int         $limit    Maximum number of results
     * @param array       $filters  Additional filters (entity_type, etc.)
     * @param string|null $provider Embedding provider
     *
     * @return array<int,array<string,mixed>> Search results
     *
     * @throws \Exception If search fails
     */
    public function semanticSearch(
        string $query,
        int $limit=10,
        array $filters=[],
        ?string $provider=null
    ): array {
        $startTime = microtime(true);
        $backend   = $this->getVectorSearchBackend();

        $this->logger->info(
                message: '[VectorEmbeddingService] Performing semantic search',
                context: [
                    'backend'      => $backend,
                    'query_length' => strlen($query),
                    'limit'        => $limit,
                    'filters'      => $filters,
                ]
                );

        try {
            // Step 1: Generate embedding for query.
            $this->logger->debug(message: 'Step 1: Generating query embedding');
            $queryEmbeddingData = $this->generateEmbedding($query, $provider);
            $queryEmbedding     = $queryEmbeddingData['embedding'];

            // Step 2: Route to appropriate backend for vector search.
            if ($backend === 'solr') {
                // Use Solr KNN search.
                $this->logger->debug(
                message:'Step 2: Searching vectors in Solr using KNN'
                        );
                $results = $this->searchVectorsInSolr(queryEmbedding: $queryEmbedding, limit: $limit, filters: $filters);
            } else {
                // Use PHP/database similarity calculation.
                $this->logger->debug(
                message:'Step 2: Fetching vectors from database'
                        );
                $vectors = $this->fetchVectors($filters);

                if ($vectors === []) {
                    $this->logger->warning(
                        message: 'No vectors found in database',
                        context: ['filters' => $filters]
                    );
                    return [];
                }

                // Step 3: Calculate cosine similarity for each vector.
                $this->logger->debug(
                    message: 'Step 3: Calculating similarities',
                    context: ['vector_count' => count($vectors)]
                );
                $results = [];

                foreach ($vectors as $vector) {
                    try {
                        $storedEmbedding = unserialize($vector['embedding']);

                        if (is_array($storedEmbedding) === false) {
                            $this->logger->warning(
                                message: 'Invalid embedding format',
                                context: ['vector_id' => $vector['id']]
                            );
                            continue;
                        }

                        $similarity = $this->cosineSimilarity($queryEmbedding, $storedEmbedding);

                        // Parse metadata.
                        $metadata = [];
                        if (empty($vector['metadata']) === false) {
                            $metadata = json_decode($vector['metadata'], true) ?? [];
                        }

                        $results[] = [
                            'vector_id'    => $vector['id'],
                            'entity_type'  => $vector['entity_type'],
                            'entity_id'    => $vector['entity_id'],
                            'similarity'   => $similarity,
                            'chunk_index'  => $vector['chunk_index'],
                            'total_chunks' => $vector['total_chunks'],
                            'chunk_text'   => $vector['chunk_text'],
                            'metadata'     => $metadata,
                            'model'        => $vector['embedding_model'],
                            'dimensions'   => $vector['embedding_dimensions'],
                        ];
                    } catch (\Exception $e) {
                        $this->logger->warning(
                        message: 'Failed to process vector',
                        context: [
                            'vector_id' => $vector['id'],
                            'error'     => $e->getMessage(),
                        ]
                                );
                    }//end try
                }//end foreach

                // Step 4: Sort by similarity descending.
                usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

                // Step 5: Return top N results.
                $results = array_slice($results, 0, $limit);
            }//end if

            // Log final results.
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                message: '[VectorEmbeddingService] Semantic search completed',
                context: [
                    'backend'        => $backend,
                    'results_count'  => count($results),
                    'top_similarity' => $results[0]['similarity'] ?? 0,
                    'search_time_ms' => $searchTime,
                ]
                    );

            return $results;
        } catch (\Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                    message: 'Semantic search failed',
                    context: [
                        'error'          => $e->getMessage(),
                        'search_time_ms' => $searchTime,
                    ]
                    );
            throw new \Exception('Semantic search failed: '.$e->getMessage());
        }//end try

    }//end semanticSearch()


    /**
     * Fetch vectors from database with optional filters
     *
     * @param array $filters Filters (entity_type, entity_id, etc.)
     *
     * @return array<int, array> Vector records from database
     *
     * @throws \Exception If query fails
     */
    private function fetchVectors(array $filters=[]): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('openregister_vectors');

            // Apply filters.
            if (($filters['entity_type'] ?? null) !== null) {
                // Support both string and array for entity_type.
                if (is_array($filters['entity_type']) === true) {
                    $qb->andWhere(
                        $qb->expr()->in(
                            'entity_type',
                            $qb->createNamedParameter(
                                $filters['entity_type'],
                                \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                            )
                        )
                    );
                } else {
                    $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($filters['entity_type'])));
                }
            }

            if (($filters['entity_id'] ?? null) !== null) {
                // Support both string and array for entity_id.
                if (is_array($filters['entity_id']) === true) {
                    $qb->andWhere(
                        $qb->expr()->in(
                            'entity_id',
                            $qb->createNamedParameter(
                                $filters['entity_id'],
                                \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
                            )
                        )
                    );
                } else {
                    $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($filters['entity_id'])));
                }
            }

            if (($filters['embedding_model'] ?? null) !== null) {
                $qb->andWhere($qb->expr()->eq('embedding_model', $qb->createNamedParameter($filters['embedding_model'])));
            }

            // PERFORMANCE OPTIMIZATION: Limit vectors fetched to reduce PHP similarity calculations.
            // TODO: Replace with proper database-level vector search (PostgreSQL + pgvector).
            // For now, limit to most recent vectors to improve performance.
            // This is a temporary fix until we migrate to a database with native vector operations.
            $maxVectors = $filters['max_vectors'] ?? 500;
            // Default: Compare against max 500 vectors (reduced from 10000).
            $qb->setMaxResults($maxVectors);
            $qb->orderBy('created_at', 'DESC');
            // Get most recent vectors first.
            $this->logger->debug(
                message: '[VectorEmbeddingService] Applied vector fetch limit for performance',
                context: [
                    'max_vectors' => $maxVectors,
                    'note'        => 'Temporary optimization until PostgreSQL + pgvector migration',
                ]
                    );

            $result  = $qb->executeQuery();
            $vectors = $result->fetchAll();

            $this->logger->debug(
                message: 'Fetched vectors from database',
                context: [
                    'count'   => count($vectors),
                    'filters' => $filters,
                ]
                    );

            return $vectors;
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to fetch vectors',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );
            throw new \Exception('Failed to fetch vectors: '.$e->getMessage());
        }//end try

    }//end fetchVectors()


    /**
     * Perform hybrid search combining keyword (SOLR) and semantic (vectors)
     *
     * Uses Reciprocal Rank Fusion (RRF) to combine results from both search methods.
     *
     * @param string      $query       Query text
     * @param array       $solrFilters SOLR-specific filters
     * @param int         $limit       Maximum results
     * @param array       $weights     Weights for each search type ['solr' => 0.5, 'vector' => 0.5]
     * @param string|null $provider    Embedding provider
     *
     * @return (((array|bool|float|int|mixed|null)[]|float|int)[]|float|int)[]
     *
     * @throws \Exception If hybrid search fails
     *
     * @psalm-return array{results: list<array{chunk_index: 0|mixed, chunk_text: mixed|null, combined_score: 0|float, entity_id: mixed, entity_type: mixed, in_solr: bool, in_vector: bool, metadata: array<never, never>|mixed, solr_rank: float|int|null, solr_score: mixed|null, vector_rank: float|int|null, vector_similarity: mixed|null}>, total: int<0, max>, search_time_ms: float, source_breakdown: array{vector_only: int<0, max>, solr_only: int<0, max>, both: int<0, max>}, weights: array{solr: float, vector: float}}
     */
    public function hybridSearch(
        string $query,
        array $solrFilters=[],
        int $limit=20,
        array $weights=['solr' => 0.5, 'vector' => 0.5],
        ?string $provider=null
    ): array {
        $startTime = microtime(true);

        $this->logger->info(
                message: 'Performing hybrid search',
                context: [
                    'query'        => $query,
                    'limit'        => $limit,
                    'weights'      => $weights,
                    'solr_filters' => $solrFilters,
                ]
                );

        try {
            // Validate weights.
            $solrWeight   = $weights['solr'] ?? 0.5;
            $vectorWeight = $weights['vector'] ?? 0.5;

            // Normalize weights.
            $totalWeight = $solrWeight + $vectorWeight;
            if ($totalWeight > 0) {
                $solrWeight   = $solrWeight / $totalWeight;
                $vectorWeight = $vectorWeight / $totalWeight;
            }

            // Step 1: Perform vector semantic search (get 2x limit for better coverage).
            $this->logger->debug(
                message:'Step 1: Performing semantic search'
                    );
            $vectorResults = [];
            if ($vectorWeight > 0) {
                try {
                    $vectorResults = $this->semanticSearch(
                        $query,
                        $limit * 2,
                        $solrFilters['vector_filters'] ?? [],
                        $provider
                    );
                } catch (\Exception $e) {
                    $this->logger->warning(
                            message: 'Vector search failed, continuing with SOLR only',
                            context: [
                                'error' => $e->getMessage(),
                            ]
                            );
                }
            }

            // Step 2: TODO - Perform SOLR keyword search (get 2x limit).
            // This will be implemented when we integrate with SOLR services.
            $this->logger->debug(
                message:'Step 2: SOLR keyword search (not yet integrated)'
                    );
            $solrResults = [];

            // For now, just use vector results.
            // In Phase 6.2, we'll integrate actual SOLR search.
            // Step 3: Combine results using Reciprocal Rank Fusion (RRF).
            $this->logger->debug(
                message:'Step 3: Merging results with RRF'
                    );
            $combined = $this->reciprocalRankFusion(
                $vectorResults,
                $solrResults,
                $vectorWeight,
                $solrWeight
            );

            // Step 4: Return top N results.
            $finalResults = array_slice($combined, 0, $limit);

            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            // Calculate source breakdown.
            $vectorOnly = 0;
            $solrOnly   = 0;
            $both       = 0;

            foreach ($finalResults as $result) {
                if ($result['in_vector'] === true && $result['in_solr'] === true) {
                    $both++;
                } elseif ($result['in_vector'] === true) {
                    $vectorOnly++;
                } elseif ($result['in_solr'] === true) {
                    $solrOnly++;
                }
            }

            $this->logger->info(
                    message: 'Hybrid search completed',
                    context: [
                        'results_count'    => count($finalResults),
                        'search_time_ms'   => $searchTime,
                        'source_breakdown' => [
                            'vector_only' => $vectorOnly,
                            'solr_only'   => $solrOnly,
                            'both'        => $both,
                        ],
                    ]
                    );

            return [
                'results'          => $finalResults,
                'total'            => count($finalResults),
                'search_time_ms'   => $searchTime,
                'source_breakdown' => [
                    'vector_only' => $vectorOnly,
                    'solr_only'   => $solrOnly,
                    'both'        => $both,
                ],
                'weights'          => [
                    'solr'   => $solrWeight,
                    'vector' => $vectorWeight,
                ],
            ];
        } catch (\Exception $e) {
            $searchTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                    message: 'Hybrid search failed',
                    context: [
                        'error'          => $e->getMessage(),
                        'search_time_ms' => $searchTime,
                    ]
                    );
            throw new \Exception('Hybrid search failed: '.$e->getMessage());
        }//end try

    }//end hybridSearch()


    /**
     * Combine search results using Reciprocal Rank Fusion (RRF)
     *
     * RRF formula: score(d) = Σ 1 / (k + rank(d))
     * where k is a constant (typically 60) and rank is the position in the result list
     *
     * @param array $vectorResults Results from vector search
     * @param array $solrResults   Results from SOLR search
     * @param float $vectorWeight  Weight for vector results (0-1)
     * @param float $solrWeight    Weight for SOLR results (0-1)
     *
     * @return (array|bool|float|int|mixed|null)[][]
     *
     * @psalm-return list<array{chunk_index: 0|mixed, chunk_text: mixed|null, combined_score: 0|float, entity_id: mixed, entity_type: mixed, in_solr: bool, in_vector: bool, metadata: array<never, never>|mixed, solr_rank: int|null, solr_score: mixed|null, vector_rank: int|null, vector_similarity: mixed|null}>
     */
    private function reciprocalRankFusion(
        array $vectorResults,
        array $solrResults,
        float $vectorWeight=0.5,
        float $solrWeight=0.5
    ): array {
        $k = 60;
        // RRF constant.
        $combinedScores = [];

        // Process vector results.
        foreach ($vectorResults as $rank => $result) {
            $key = $result['entity_type'].'_'.$result['entity_id'];

            if (!isset($combinedScores[$key])) {
                $combinedScores[$key] = [
                    'entity_type'       => $result['entity_type'],
                    'entity_id'         => $result['entity_id'],
                    'chunk_index'       => $result['chunk_index'],
                    'chunk_text'        => $result['chunk_text'],
                    'metadata'          => $result['metadata'],
                    'vector_similarity' => $result['similarity'],
                    'solr_score'        => null,
                    'combined_score'    => 0,
                    'in_vector'         => false,
                    'in_solr'           => false,
                    'vector_rank'       => null,
                    'solr_rank'         => null,
                ];
            }

            $rrfScore = $vectorWeight / ($k + (int) $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_vector']       = true;
            $combinedScores[$key]['vector_rank']     = (int) $rank + 1;
        }//end foreach

        // Process SOLR results.
        foreach ($solrResults as $rank => $result) {
            $key = $result['entity_type'].'_'.$result['entity_id'];

            if (!isset($combinedScores[$key])) {
                $combinedScores[$key] = [
                    'entity_type'       => $result['entity_type'],
                    'entity_id'         => $result['entity_id'],
                    'chunk_index'       => $result['chunk_index'] ?? 0,
                    'chunk_text'        => $result['chunk_text'] ?? null,
                    'metadata'          => $result['metadata'] ?? [],
                    'vector_similarity' => null,
                    'solr_score'        => $result['score'],
                    'combined_score'    => 0,
                    'in_vector'         => false,
                    'in_solr'           => false,
                    'vector_rank'       => null,
                    'solr_rank'         => null,
                ];
            }

            $rrfScore = $solrWeight / ($k + (int) $rank + 1);
            $combinedScores[$key]['combined_score'] += $rrfScore;
            $combinedScores[$key]['in_solr']         = true;
            $combinedScores[$key]['solr_rank']       = (int) $rank + 1;
            $combinedScores[$key]['solr_score']      = $result['score'];
        }//end foreach

        // Convert to array and sort by combined score.
        $results = array_values($combinedScores);
        usort($results, fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);

        return $results;

    }//end reciprocalRankFusion()


    /**
     * Get vector statistics
     *
     * @return ((int|mixed)[]|int|string)[] Statistics about stored vectors
     *
     * @psalm-return array{total_vectors: int, by_type: array<int>, by_model: array<int|mixed>, object_vectors?: int, file_vectors?: int, source?: 'solr'|'solr_error'|'solr_unavailable'}
     */
    public function getVectorStats(): array
    {
        try {
            // Check if we should use Solr for stats.
            $backend = $this->getVectorSearchBackend();

            if ($backend === 'solr') {
                return $this->getVectorStatsFromSolr();
            }

            // Default: get stats from database.
            $qb = $this->db->getQueryBuilder();

            // Total vectors.
            $qb->select($qb->func()->count('id', 'total'))
                ->from('openregister_vectors');
            $total = (int) $qb->executeQuery()->fetchOne();

            // By entity type.
            $qb = $this->db->getQueryBuilder();
            $qb->select('entity_type', $qb->func()->count('id', 'count'))
                ->from('openregister_vectors')
                ->groupBy('entity_type');
            $result = $qb->executeQuery();
            $byType = [];
            while (($row = $result->fetch()) !== false) {
                $byType[$row['entity_type']] = (int) $row['count'];
            }

            $result->closeCursor();

            // By model.
            $qb = $this->db->getQueryBuilder();
            $qb->select('embedding_model', $qb->func()->count('id', 'count'))
                ->from('openregister_vectors')
                ->groupBy('embedding_model');
            $result  = $qb->executeQuery();
            $byModel = [];
            while (($row = $result->fetch()) !== false) {
                $byModel[$row['embedding_model']] = (int) $row['count'];
            }

            $result->closeCursor();

            return [
                'total_vectors'  => $total,
                'by_type'        => $byType,
                'by_model'       => $byModel,
                'object_vectors' => $byType['object'] ?? 0,
                'file_vectors'   => $byType['file'] ?? 0,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to get vector stats',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );
            return [
                'total_vectors' => 0,
                'by_type'       => [],
                'by_model'      => [],
            ];
        }//end try

    }//end getVectorStats()


    /**
     * Get vector statistics from Solr collections
     *
     * Counts documents with embeddings in file and object collections
     *
     * @return (array|int|string)[] Vector statistics from Solr
     *
     * @psalm-return array{
     *     total_vectors: int,
     *     by_type: array{object?: int, file?: int},
     *     by_model: array,
     *     object_vectors: int,
     *     file_vectors: int,
     *     source: 'solr'|'solr_error'|'solr_unavailable'
     * }
     */
    private function getVectorStatsFromSolr(): array
    {
        try {
            if ($this->solrService->isAvailable() === false) {
                $this->logger->warning(
                    message:'[VectorEmbeddingService] Solr not available for stats'
                        );
                return [
                    'total_vectors'  => 0,
                    'by_type'        => [],
                    'by_model'       => [],
                    'object_vectors' => 0,
                    'file_vectors'   => 0,
                    'source'         => 'solr_unavailable',
                ];
            }

            $settings         = $this->settingsService->getSettings();
            $vectorField      = $this->getSolrVectorField();
            $objectCollection = $settings['solr']['objectCollection'] ?? $settings['solr']['collection'] ?? null;
            $fileCollection   = $settings['solr']['fileCollection'] ?? null;

            $objectCount = 0;
            $fileCount   = 0;
            $byModel     = [];

            // Count objects with embeddings.
            if ($objectCollection !== null && $objectCollection !== '') {
                try {
                    $objectStats = $this->countVectorsInCollection($objectCollection, $vectorField);
                    $objectCount = $objectStats['count'];
                    $byModel     = array_merge($byModel, $objectStats['by_model']);
                } catch (\Exception $e) {
                    $this->logger->warning(
                            message: '[VectorEmbeddingService] Failed to get object vector stats from Solr',
                            context: [
                                'error' => $e->getMessage(),
                            ]
                            );
                }
            }

            // Count files with embeddings.
            if ($fileCollection !== null && $fileCollection !== '') {
                try {
                    $fileStats = $this->countVectorsInCollection($fileCollection, $vectorField);
                    $fileCount = $fileStats['count'];
                    // Merge model counts.
                    foreach ($fileStats['by_model'] as $model => $count) {
                        $byModel[$model] = ($byModel[$model] ?? 0) + $count;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                            message: '[VectorEmbeddingService] Failed to get file vector stats from Solr',
                            context: [
                                'error' => $e->getMessage(),
                            ]
                            );
                }
            }

            $total = $objectCount + $fileCount;

            $this->logger->debug(
                    message: '[VectorEmbeddingService] Vector stats from Solr',
                    context: [
                        'total'   => $total,
                        'objects' => $objectCount,
                        'files'   => $fileCount,
                    ]
                    );

            return [
                'total_vectors'  => $total,
                'by_type'        => [
                    'object' => $objectCount,
                    'file'   => $fileCount,
                ],
                'by_model'       => $byModel,
                'object_vectors' => $objectCount,
                'file_vectors'   => $fileCount,
                'source'         => 'solr',
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[VectorEmbeddingService] Failed to get vector stats from Solr',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );
            return [
                'total_vectors'  => 0,
                'by_type'        => [],
                'by_model'       => [],
                'object_vectors' => 0,
                'file_vectors'   => 0,
                'source'         => 'solr_error',
            ];
        }//end try

    }//end getVectorStatsFromSolr()


    /**
     * Count vectors in a specific Solr collection
     *
     * @param string $collection  Collection name
     * @param string $vectorField Vector field name
     *
     * @return array{count: int, by_model: array} Count and breakdown by model
     */
    private function countVectorsInCollection(string $collection, string $vectorField): array
    {
        // Get Solr configuration for authentication.
        $settings   = $this->settingsService->getSettings();
        $solrConfig = $settings['solr'] ?? [];

        // Build request options.
        $options = [
            'query' => [
                'q'           => "{$vectorField}:*",
        // Documents with vector field.
                'rows'        => 0,
        // Don't return documents, just count.
                'wt'          => 'json',
                'facet'       => 'true',
                'facet.field' => '_embedding_model_',
        // Count by model.
            ],
        ];

        // Add HTTP authentication if configured.
        if (empty($solrConfig['username']) === false && empty($solrConfig['password']) === false) {
            $options['auth'] = [$solrConfig['username'], $solrConfig['password']];
        }

        // Query Solr for documents with the embedding field present.
        $solrUrl  = $this->solrService->buildSolrBaseUrl()."/{$collection}/select";
        $response = $this->solrService->getHttpClient()->get($solrUrl, $options);

        $data  = json_decode((string) $response->getBody(), true);
        $count = $data['response']['numFound'] ?? 0;

        // Extract model counts from facets.
        $byModel = [];
        if (($data['facet_counts']['facet_fields']['_embedding_model_'] ?? null) !== null) {
            $facets = $data['facet_counts']['facet_fields']['_embedding_model_'];
            // Facets are returned as [value1, count1, value2, count2, ...].
            for ($i = 0; $i < count($facets); $i += 2) {
                if (($facets[$i] ?? null) !== null && (($facets[$i + 1] ?? null) !== null) === true) {
                    $modelName  = $facets[$i];
                    $modelCount = $facets[$i + 1];
                    if ($modelName !== null && $modelName !== '' && $modelCount > 0) {
                        $byModel[$modelName] = $modelCount;
                    }
                }
            }
        }

        return [
            'count'    => $count,
            'by_model' => $byModel,
        ];

    }//end countVectorsInCollection()


    /**
     * Get embedding configuration from settings
     *
     * @param string|null $provider Override provider (null = use default from settings)
     *
     * @return array{provider: string, model: string, dimensions: int, api_key: string|null, base_url: string|null} Configuration
     */
    private function getEmbeddingConfig(?string $provider=null): array
    {
        // Load from LLM settings.
        $llmSettings = $this->settingsService->getLLMSettingsOnly();

        // Determine provider: use provided, or fall back to configured embedding provider.
        $configuredProvider = $provider ?? ($llmSettings['embeddingProvider'] ?? 'openai');

        // Get provider-specific configuration.
        $providerConfig = match ($configuredProvider) {
            'fireworks' => $llmSettings['fireworksConfig'] ?? [],
            'ollama' => $llmSettings['ollamaConfig'] ?? [],
            'openai' => $llmSettings['openaiConfig'] ?? [],
            default => []
        };

        // Extract model and credentials based on provider.
        $model = match ($configuredProvider) {
            'fireworks' => $providerConfig['embeddingModel'] ?? 'thenlper/gte-base',
            'ollama' => $providerConfig['model'] ?? 'nomic-embed-text',
            'openai' => $providerConfig['model'] ?? 'text-embedding-ada-002',
            default => 'text-embedding-ada-002'
        };

        $apiKey  = $providerConfig['apiKey'] ?? null;
        $baseUrl = $providerConfig['baseUrl'] ?? $providerConfig['url'] ?? null;

        return [
            'provider'   => $configuredProvider,
            'model'      => $model,
            'dimensions' => self::EMBEDDING_DIMENSIONS[$model] ?? 1536,
            'api_key'    => $apiKey,
            'base_url'   => $baseUrl,
        ];

    }//end getEmbeddingConfig()


    /**
     * Get or create embedding generator for a configuration
     *
     * @param array $config Embedding configuration
     *
     * @return EmbeddingGeneratorInterface LLPhant embedding generator instance
     *
     * @throws \Exception If configuration is invalid or generator cannot be created
     */
    private function getEmbeddingGenerator(array $config): EmbeddingGeneratorInterface
    {
        $cacheKey = $config['provider'].'_'.$config['model'];

        if (!isset($this->generatorCache[$cacheKey])) {
            $this->logger->debug(
                    message: 'Creating new embedding generator',
                    context: [
                        'provider' => $config['provider'],
                        'model'    => $config['model'],
                    ]
                    );

            // Create appropriate generator based on provider and model.
            $generator = match ($config['provider']) {
                'openai' => $this->createOpenAIGenerator($config['model'], $config),
                'fireworks' => $this->createFireworksGenerator($config['model'], $config),
                'ollama' => $this->createOllamaGenerator($config['model'], $config),
                default => throw new \Exception("Unsupported embedding provider: {$config['provider']}")
            };

            $this->generatorCache[$cacheKey] = $generator;

            $this->logger->info(
                    message: 'Embedding generator created',
                    context: [
                        'provider'   => $config['provider'],
                        'model'      => $config['model'],
                        'dimensions' => $generator->getEmbeddingLength(),
                    ]
                    );
        }//end if

        return $this->generatorCache[$cacheKey];

    }//end getEmbeddingGenerator()


    /**
     * Create OpenAI embedding generator
     *
     * @param string $model  Model name
     * @param array  $config Configuration array with api_key and base_url
     *
     * @return OpenAI3LargeEmbeddingGenerator|OpenAI3SmallEmbeddingGenerator|OpenAIADA002EmbeddingGenerator Generator instance
     *
     * @throws \Exception If model is not supported
     */
    private function createOpenAIGenerator(
        string $model,
        array $config
    ): OpenAIADA002EmbeddingGenerator|OpenAI3SmallEmbeddingGenerator|OpenAI3LargeEmbeddingGenerator {
        $llphantConfig = new OpenAIConfig();

        if (empty($config['api_key']) === false) {
            $llphantConfig->apiKey = $config['api_key'];
        }

        if (empty($config['base_url']) === false) {
            $llphantConfig->url = $config['base_url'];
        }

        return match ($model) {
            'text-embedding-ada-002' => new OpenAIADA002EmbeddingGenerator($llphantConfig),
            'text-embedding-3-small' => new OpenAI3SmallEmbeddingGenerator($llphantConfig),
            'text-embedding-3-large' => new OpenAI3LargeEmbeddingGenerator($llphantConfig),
            default => throw new \Exception("Unsupported OpenAI model: {$model}")
        };

    }//end createOpenAIGenerator()


    /**
     * Create Fireworks AI embedding generator
     *
     * Fireworks AI uses OpenAI-compatible API, so we create a custom wrapper
     * that works with any Fireworks model.
     *
     * @param string $model  Model name (e.g., 'nomic-ai/nomic-embed-text-v1.5')
     * @param array  $config Configuration array with api_key and base_url
     *
     * @return object Generator instance
     *
     * @throws \Exception If model is not supported
     */
    private function createFireworksGenerator(string $model, array $config): object
    {
        // Create a custom anonymous class that implements the EmbeddingGeneratorInterface.
        // This allows us to use any Fireworks model name without LLPhant's restrictions.
        return new class($model, $config, $this->logger) implements EmbeddingGeneratorInterface {

            /**
             * Model name
             *
             * @var string
             */
            private string $model;

            /**
             * Configuration array
             *
             * @var array
             */
            private array $config;

            /**
             * Logger instance
             *
             * Used for logging embedding operations and errors.
             *
             * @var \Psr\Log\LoggerInterface Logger instance
             */
            private readonly \Psr\Log\LoggerInterface $logger;

            /**
             * Constructor
             *
             * Initializes embedding generator with model, configuration, and logger.
             *
             * @param string                   $model  Model name
             * @param array<string, mixed>      $config Configuration array
             * @param \Psr\Log\LoggerInterface $logger Logger instance
             *
             * @return void
             */
            public function __construct(string $model, array $config, \Psr\Log\LoggerInterface $logger)
            {
                $this->model  = $model;
                $this->config = $config;
                $this->logger = $logger;
            }//end __construct()


            /**
             * Embed text
             *
             * @param string $text Text to embed
             *
             * @return array<float> Embedding vector
             */
            public function embedText(string $text): array
            {
                $url = rtrim($this->config['base_url'] ?? 'https://api.fireworks.ai/inference/v1', '/').'/embeddings';

                $this->logger->debug(
                        message: 'Calling Fireworks AI API',
                        context: [
                            'url'   => $url,
                            'model' => $this->model,
                        ]
                        );

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt(
                        $ch,
                        CURLOPT_HTTPHEADER,
                        [
                            'Authorization: Bearer '.$this->config['api_key'],
                            'Content-Type: application/json',
                        ]
                        );
                curl_setopt(
                        $ch,
                        CURLOPT_POSTFIELDS,
                        json_encode(
                        [
                            'model' => $this->model,
                            'input' => $text,
                        ]
                        )
                        );

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error    = curl_error($ch);
                curl_close($ch);

                if ($error !== null && $error !== '') {
                    throw new \Exception("Fireworks API request failed: {$error}");
                }

                if ($httpCode !== 200) {
                    throw new \Exception("Fireworks API returned HTTP {$httpCode}: {$response}");
                }

                // Ensure $response is a string (curl_exec can return bool on failure).
                if (is_string($response) === false) {
                    throw new \Exception("Fireworks API request failed: Invalid response type");
                }

                $data = json_decode($response, true);
                if (!isset($data['data'][0]['embedding'])) {
                    throw new \Exception("Unexpected Fireworks API response format: {$response}");
                }

                return $data['data'][0]['embedding'];
            }//end embedText()


            /**
             * Get embedding length
             *
             * @return int Embedding length
             *
             * @psalm-return 768|1024
             */
            public function getEmbeddingLength(): int
            {
                // Return expected dimensions based on model.
                return match ($this->model) {
                    'nomic-ai/nomic-embed-text-v1.5' => 768,
                    'thenlper/gte-base' => 768,
                    'thenlper/gte-large' => 1024,
                    'WhereIsAI/UAE-Large-V1' => 1024,
                    default => 768
                };
            }//end getEmbeddingLength()


            /**
             * Embed a document
             *
             * @param \LLPhant\Embeddings\Document $document Document to embed
             *
             * @return \LLPhant\Embeddings\Document Embedded document
             */
            public function embedDocument(\LLPhant\Embeddings\Document $document): \LLPhant\Embeddings\Document
            {
                // Embed the document content and store it back in the document.
                $document->embedding = $this->embedText($document->content);
                return $document;
            }//end embedDocument()


            /**
             * Embed multiple documents
             *
             * @param array<int,\LLPhant\Embeddings\Document> $documents Documents to embed
             *
             * @return array<int,\LLPhant\Embeddings\Document> Embedded documents
             */
            public function embedDocuments(array $documents): array
            {
                // Embed multiple documents.
                foreach ($documents as $document) {
                    $document->embedding = $this->embedText($document->content);
                }

                return $documents;
            }//end embedDocuments()


        };

    }//end createFireworksGenerator()


    /**
     * Create Ollama embedding generator
     *
     * @param string $model  Model name (e.g., 'nomic-embed-text')
     * @param array  $config Configuration array with base_url
     *
     * @return OllamaEmbeddingGenerator Generator instance
     */
    private function createOllamaGenerator(string $model, array $config): OllamaEmbeddingGenerator
    {
        // Create native Ollama configuration.
        $ollamaConfig        = new OllamaConfig();
        $ollamaConfig->url   = rtrim($config['base_url'] ?? 'http://localhost:11434', '/').'/api/';
        $ollamaConfig->model = $model;

        // Create and return Ollama embedding generator with native config.
        return new OllamaEmbeddingGenerator($ollamaConfig);

    }//end createOllamaGenerator()


    /**
     * Calculate cosine similarity between two vectors
     *
     * @param array<float> $vector1 First vector
     * @param array<float> $vector2 Second vector
     *
     * @return float Similarity score (0-1, where 1 is identical)
     */
    private function cosineSimilarity(array $vector1, array $vector2): float
    {
        if (count($vector1) !== count($vector2)) {
            throw new \InvalidArgumentException('Vectors must have same dimensions');
        }

        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] ** 2;
            $magnitude2 += $vector2[$i] ** 2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 === 0.0 || $magnitude2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);

    }//end cosineSimilarity()


    /**
     * Sanitize text to prevent UTF-8 encoding errors
     *
     * Removes invalid UTF-8 sequences and problematic control characters
     * that can cause database storage issues.
     *
     * @param string $text Text to sanitize
     *
     * @return string Sanitized text safe for UTF-8 storage
     */
    private function sanitizeText(string $text): string
    {
        // Step 1: Remove invalid UTF-8 sequences.
        // This handles cases like \xC2 that aren't valid UTF-8.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Step 2: Remove NULL bytes and other problematic control characters.
        // but keep newlines, tabs, and carriage returns.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Step 3: Replace any remaining invalid UTF-8 with replacement character.
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

        // Step 4: Normalize whitespace (optional but helpful).
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);

    }//end sanitizeText()


    /**
     * Check if embedding model has changed since vectors were created
     *
     * Compares the configured embedding model with models used in existing vectors.
     * If they don't match, vectors need to be regenerated.
     *
     * @return (array|bool|int|mixed|string)[]
     *
     * @psalm-return array{has_vectors: bool, mismatch: bool, error?: string, message?: string, current_model?: mixed, existing_models?: list{0?: mixed,...}, total_vectors?: int, null_model_count?: int, mismatched_models?: list<mixed>}
     */
    public function checkEmbeddingModelMismatch(): array
    {
        try {
            // Get current configured model.
            $settings        = $this->settingsService->getLLMSettingsOnly();
            $currentProvider = $settings['embeddingProvider'] ?? null;
            $currentModel    = null;

            if ($currentProvider === 'openai') {
                $currentModel = $settings['openaiConfig']['model'] ?? null;
            } elseif ($currentProvider === 'ollama') {
                $currentModel = $settings['ollamaConfig']['model'] ?? null;
            } elseif ($currentProvider === 'fireworks') {
                $currentModel = $settings['fireworksConfig']['embeddingModel'] ?? null;
            }

            if ($currentModel === null || $currentModel === '') {
                return [
                    'has_vectors' => false,
                    'mismatch'    => false,
                    'message'     => 'No embedding model configured',
                ];
            }

            // Check if any vectors exist.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))
                ->from('openregister_vectors');

            $result       = $qb->executeQuery();
            $totalVectors = (int) $result->fetchOne();
            $result->closeCursor();

            if ($totalVectors === 0) {
                return [
                    'has_vectors'   => false,
                    'mismatch'      => false,
                    'current_model' => $currentModel,
                    'message'       => 'No vectors exist yet',
                ];
            }

            // Get distinct embedding models used in existing vectors.
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('embedding_model')
                ->from('openregister_vectors')
                ->where($qb->expr()->isNotNull('embedding_model'));

            $result         = $qb->executeQuery();
            $existingModels = [];
            while (($row = $result->fetch()) !== false) {
                $existingModels[] = $row['embedding_model'];
            }

            $result->closeCursor();

            // Count vectors with NULL model (created before tracking).
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'null_count'))
                ->from('openregister_vectors')
                ->where($qb->expr()->isNull('embedding_model'));

            $result         = $qb->executeQuery();
            $nullModelCount = (int) $result->fetchOne();
            $result->closeCursor();

            // Check for mismatch.
            $hasMismatch     = false;
            $mismatchDetails = [];

            foreach ($existingModels as $existingModel) {
                if ($existingModel !== $currentModel) {
                    $hasMismatch       = true;
                    $mismatchDetails[] = $existingModel;
                }
            }

            return [
                'has_vectors'       => true,
                'mismatch'          => $hasMismatch || $nullModelCount > 0,
                'current_model'     => $currentModel,
                'existing_models'   => $existingModels,
                'total_vectors'     => $totalVectors,
                'null_model_count'  => $nullModelCount,
                'mismatched_models' => $mismatchDetails,

                'message'           => $this->formatModelMismatchMessage($hasMismatch, $nullModelCount),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to check embedding model mismatch',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return [
                'has_vectors' => false,
                'mismatch'    => false,
                'error'       => $e->getMessage(),
            ];
        }//end try

    }//end checkEmbeddingModelMismatch()


    /**
     * Format model mismatch message
     *
     * @param bool $hasMismatch    Whether there is a mismatch
     * @param int  $nullModelCount Number of vectors with null model
     *
     * @return string Formatted message
     */
    private function formatModelMismatchMessage(bool $hasMismatch, int $nullModelCount): string
    {
        if ($hasMismatch === true) {
            return 'Multiple embedding models detected. Consider re-embedding all vectors with a single model.';
        }

        if ($nullModelCount > 0) {
            return sprintf('%d vectors have no model information.', $nullModelCount);
        }

        return 'All vectors use the same embedding model.';

    }//end formatModelMismatchMessage()


    /**
     * Clear all embeddings from the database
     *
     * Deletes all vectors. This should be done when changing embedding models.
     *
     * @return (bool|int|string)[]
     *
     * @psalm-return array{success: bool, error?: string, message: string, deleted?: int}
     */
    public function clearAllEmbeddings(): array
    {
        try {
            // Count vectors before deletion.
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'total'))
                ->from('openregister_vectors');

            $result       = $qb->executeQuery();
            $totalVectors = (int) $result->fetchOne();
            $result->closeCursor();

            if ($totalVectors === 0) {
                return [
                    'success' => true,
                    'deleted' => 0,
                    'message' => 'No vectors to delete',
                ];
            }

            // Delete all vectors.
            $qb = $this->db->getQueryBuilder();
            $qb->delete('openregister_vectors');
            $deletedCount = $qb->executeStatement();

            $this->logger->info(
                    message: 'All embeddings cleared',
                    context: [
                        'deleted_count' => $deletedCount,
                    ]
                    );

            return [
                'success' => true,
                'deleted' => $deletedCount,
                'message' => "Deleted {$deletedCount} vectors successfully",
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: 'Failed to clear embeddings',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => 'Failed to clear embeddings: '.$e->getMessage(),
            ];
        }//end try

    }//end clearAllEmbeddings()


}//end class
