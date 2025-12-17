<?php

/**
 * Embedding Generator Handler
 *
 * Manages creation and caching of embedding generators for different LLM providers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vectorization\Handlers;

use Exception;
use Psr\Log\LoggerInterface;
use LLPhant\OpenAIConfig;
use LLPhant\OllamaConfig;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;

/**
 * EmbeddingGeneratorHandler
 *
 * Responsible for creating and caching embedding generators for different providers.
 * Supports OpenAI, Fireworks AI, and Ollama.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class EmbeddingGeneratorHandler
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
     * @var array<string, EmbeddingGeneratorInterface>
     */
    private array $generatorCache = [];

    /**
     * Constructor
     *
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Get or create embedding generator for a configuration
     *
     * @param array $config Embedding configuration with provider, model, api_key, base_url
     *
     * @return EmbeddingGeneratorInterface LLPhant embedding generator instance
     *
     * @throws \Exception If configuration is invalid or generator cannot be created
     */
    public function getGenerator(array $config): EmbeddingGeneratorInterface
    {
        $cacheKey = $config['provider'].'_'.$config['model'];

        if (isset($this->generatorCache[$cacheKey]) === false) {
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
                default => throw new Exception("Unsupported embedding provider: {$config['provider']}")
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

    }//end getGenerator()

    /**
     * Get default dimensions for a model
     *
     * @param string $model Model name
     *
     * @return int Default dimensions
     *
     * @psalm-return 384|1536|3072
     */
    public function getDefaultDimensions(string $model): int
    {
        return self::EMBEDDING_DIMENSIONS[$model] ?? 1536;

    }//end getDefaultDimensions()

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
            default => throw new Exception("Unsupported OpenAI model: {$model}")
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
             * @var \Psr\Log\LoggerInterface
             */
            private readonly \Psr\Log\LoggerInterface $logger;

            /**
             * Constructor
             *
             * @param string                   $model  Model name
             * @param array<string, mixed>     $config Configuration array
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
             * Embed text using Fireworks AI API
             *
             * @param string $text Text to embed
             *
             * @return array<float> Embedding vector
             *
             * @throws \Exception If API call fails
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
                    throw new Exception("Fireworks API request failed: {$error}");
                }

                if ($httpCode !== 200) {
                    throw new Exception("Fireworks API returned HTTP {$httpCode}: {$response}");
                }

                // Ensure $response is a string.
                if (is_string($response) === false) {
                    throw new Exception("Fireworks API request failed: Invalid response type");
                }

                $data = json_decode($response, true);
                if (isset($data['data'][0]['embedding']) === false) {
                    throw new Exception("Unexpected Fireworks API response format: {$response}");
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
        $ollamaConfig        = new OllamaConfig();
        $ollamaConfig->url   = rtrim($config['base_url'] ?? 'http://localhost:11434', '/').'/api/';
        $ollamaConfig->model = $model;

        return new OllamaEmbeddingGenerator($ollamaConfig);

    }//end createOllamaGenerator()
}//end class
