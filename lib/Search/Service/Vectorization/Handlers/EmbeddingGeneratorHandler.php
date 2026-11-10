<?php

/**
 * Embedding Generator Handler
 *
 * Manages creation and caching of embedding generators for different LLM providers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

use Exception;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use Psr\Log\LoggerInterface;

/**
 * EmbeddingGeneratorHandler
 *
 * Responsible for creating and caching embedding generators for different providers.
 * Supports OpenAI, Fireworks AI, and Ollama.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class EmbeddingGeneratorHandler {
	/**
	 * Default embedding dimensions for different models
	 */
	private const EMBEDDING_DIMENSIONS = [
		'text-embedding-ada-002' => 1536,
		'text-embedding-3-small' => 1536,
		'text-embedding-3-large' => 3072,
		'nomic-embed-text' => 768,
		'mxbai-embed-large' => 1024,
		'all-minilm' => 384,
		'ollama-default' => 384,
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
		private readonly LoggerInterface $logger,
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
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getGenerator(array $config): EmbeddingGeneratorInterface {
		$cacheKey = $config['provider'] . '_' . $config['model'];

		if (isset($this->generatorCache[$cacheKey]) === false) {
			$this->generatorCache[$cacheKey] = $this->createGenerator(config: $config);
		}

		return $this->generatorCache[$cacheKey];
	}//end getGenerator()

	/**
	 * Create and log a new embedding generator for a configuration
	 *
	 * @param array $config Embedding configuration with provider, model, api_key, base_url
	 *
	 * @return EmbeddingGeneratorInterface The new generator
	 *
	 * @throws \Exception If the provider is not supported
	 */
	private function createGenerator(array $config): EmbeddingGeneratorInterface {
		$this->logCreating(config: $config);

		$generator = $this->createProviderGenerator(config: $config);

		$this->logCreated(config: $config, generator: $generator);

		return $generator;
	}//end createGenerator()

	/**
	 * Log that a generator is about to be created
	 *
	 * @param array $config Embedding configuration with provider and model
	 *
	 * @return void
	 */
	private function logCreating(array $config): void {
		$this->logger->debug(
			message: '[EmbeddingGeneratorHandler] Creating new embedding generator',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $config['provider'],
				'model' => $config['model'],
			]
		);
	}//end logCreating()

	/**
	 * Log a created generator with its dimensions
	 *
	 * @param array                       $config    Embedding configuration with provider and model
	 * @param EmbeddingGeneratorInterface $generator The created generator
	 *
	 * @return void
	 */
	private function logCreated(array $config, EmbeddingGeneratorInterface $generator): void {
		$this->logger->info(
			message: '[EmbeddingGeneratorHandler] Embedding generator created',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $config['provider'],
				'model' => $config['model'],
				'dimensions' => $generator->getEmbeddingLength(),
			]
		);
	}//end logCreated()

	/**
	 * Pick the generator factory for the configured provider
	 *
	 * @param array $config Embedding configuration with provider and model
	 *
	 * @return EmbeddingGeneratorInterface The new generator
	 *
	 * @throws \Exception If the provider is not supported
	 */
	private function createProviderGenerator(array $config): EmbeddingGeneratorInterface {
		// Create appropriate generator based on provider and model.
		return match ($config['provider']) {
			'openai' => $this->createOpenAIGenerator(model: $config['model'], config: $config),
			'fireworks' => $this->createFireworksGenerator(model: $config['model'], config: $config),
			'ollama' => $this->createOllamaGenerator(model: $config['model'], config: $config),
			default => throw new Exception("Unsupported embedding provider: {$config['provider']}")
		};
	}//end createProviderGenerator()

	/**
	 * Get default dimensions for a model
	 *
	 * @param string $model Model name
	 *
	 * @return int Default dimensions
	 *
	 * @psalm-return 384|768|1024|1536|3072
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getDefaultDimensions(string $model): int {
		return self::EMBEDDING_DIMENSIONS[$model] ?? 1536;
	}//end getDefaultDimensions()

	/**
	 * Create OpenAI embedding generator
	 *
	 * @param string $model Model name
	 * @param array $config Configuration array with api_key and base_url
	 *
	 * @return OpenAIADA002EmbeddingGenerator
	 *                                        |OpenAI3SmallEmbeddingGenerator
	 *                                        |OpenAI3LargeEmbeddingGenerator Generator instance
	 *
	 * @throws \Exception If model is not supported
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function createOpenAIGenerator(
		string $model,
		array $config,
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
	 * Fireworks AI uses an OpenAI-compatible API, so a dedicated client
	 * works with any Fireworks model name without LLPhant's restrictions.
	 *
	 * @param string $model Model name (e.g., 'nomic-ai/nomic-embed-text-v1.5')
	 * @param array $config Configuration array with api_key and base_url
	 *
	 * @return object Generator instance
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function createFireworksGenerator(string $model, array $config): object {
		return new FireworksEmbeddingGenerator(config: $config, logger: $this->logger);
	}//end createFireworksGenerator()

	/**
	 * Create Ollama embedding generator
	 *
	 * @param string $model Model name (e.g., 'nomic-embed-text')
	 * @param array $config Configuration array with base_url
	 *
	 * @return OllamaEmbeddingGenerator Generator instance
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function createOllamaGenerator(string $model, array $config): OllamaEmbeddingGenerator {
		$ollamaConfig = new OllamaConfig();
		$ollamaConfig->url = rtrim($config['base_url'] ?? 'http://localhost:11434', '/') . '/api/';
		$ollamaConfig->model = $model;

		return new OllamaEmbeddingGenerator($ollamaConfig);
	}//end createOllamaGenerator()
}//end class
