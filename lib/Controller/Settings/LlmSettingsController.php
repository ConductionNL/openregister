<?php

/**
 * OpenRegister LLM Settings Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use Exception;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for LLM (Large Language Model) settings.
 *
 * Handles:
 * - LLM provider configuration (OpenAI, Ollama, Fireworks)
 * - Embedding and chat model settings
 * - Testing LLM connections
 * - Vector database information
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class LlmSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IDBConnection $db Database connection.
	 * @param ContainerInterface $container DI container.
	 * @param SettingsService $settingsService Settings service.
	 * @param VectorizationService $vectorizationService Vectorization service.
	 * @param LoggerInterface $logger Logger.
	 * @param ConnectionReporter $connectionReporter Reports the saved provider to integriq's connection registry.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly VectorizationService $vectorizationService,
		private readonly LoggerInterface $logger,
		private readonly ConnectionReporter $connectionReporter,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get LLM (Large Language Model) settings
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse LLM settings
	 *
	 * @psalm-return JSONResponse<200|500, array, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function getLLMSettings(): JSONResponse {
		try {
			$data = $this->settingsService->getLLMSettingsOnly();
			return new JSONResponse(data: $data);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getLLMSettings()

	/**
	 * Update LLM (Large Language Model) settings
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated LLM settings
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function updateLLMSettings(): JSONResponse {
		try {
			$data = $this->request->getParams();

			// Extract the model IDs from the objects sent by frontend.
			if (($data['fireworksConfig']['embeddingModel'] ?? null) !== null
				&& is_array($data['fireworksConfig']['embeddingModel']) === true
			) {
				$data['fireworksConfig']['embeddingModel'] = $data['fireworksConfig']['embeddingModel']['id'] ?? null;
			}

			if (($data['fireworksConfig']['chatModel'] ?? null) !== null
				&& is_array($data['fireworksConfig']['chatModel']) === true
			) {
				$data['fireworksConfig']['chatModel'] = $data['fireworksConfig']['chatModel']['id'] ?? null;
			}

			if (($data['openaiConfig']['model'] ?? null) !== null
				&& is_array($data['openaiConfig']['model']) === true
			) {
				$data['openaiConfig']['model'] = $data['openaiConfig']['model']['id'] ?? null;
			}

			if (($data['openaiConfig']['chatModel'] ?? null) !== null
				&& is_array($data['openaiConfig']['chatModel']) === true
			) {
				$data['openaiConfig']['chatModel'] = $data['openaiConfig']['chatModel']['id'] ?? null;
			}

			if (($data['ollamaConfig']['model'] ?? null) !== null
				&& is_array($data['ollamaConfig']['model']) === true
			) {
				$data['ollamaConfig']['model'] = $data['ollamaConfig']['model']['id'] ?? null;
			}

			if (($data['ollamaConfig']['chatModel'] ?? null) !== null
				&& is_array($data['ollamaConfig']['chatModel']) === true
			) {
				$data['ollamaConfig']['chatModel'] = $data['ollamaConfig']['chatModel']['id'] ?? null;
			}

			$result = $this->settingsService->updateLLMSettingsOnly($data);
			$this->connectionReporter->reportLlmProviders(
				chatProvider: ($result['chatProvider'] ?? null),
				embeddingProvider: ($result['embeddingProvider'] ?? null)
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'LLM settings updated successfully',
					'data' => $result,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end updateLLMSettings()

	/**
	 * Patch LLM settings (partial update)
	 *
	 * This is an alias for updateLLMSettings but specifically for PATCH requests.
	 * It provides the same functionality but is registered under a different route name
	 * to ensure PATCH verb is properly registered in Nextcloud routing.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Updated LLM settings
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{success: bool, error?: string,
	 *     message?: 'LLM settings updated successfully', data?: array},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function patchLLMSettings(): JSONResponse {
		return $this->updateLLMSettings();
	}//end patchLLMSettings()

	/**
	 * Test LLM embedding functionality
	 *
	 * Tests if the configured embedding provider works correctly
	 * by generating a test embedding vector.
	 * Accepts provider and config from the request to allow testing
	 * before saving the configuration.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with embedding test result
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function testEmbedding(): JSONResponse {
		try {
			// Get parameters from request.
			$provider = (string)$this->request->getParam('provider');
			$config = $this->request->getParam('config', []);
			$defaultTestText = 'This is a test embedding to verify the LLM configuration.';
			$testText = (string)$this->request->getParam('testText', $defaultTestText);

			// Validate input.
			if (empty($provider) === true) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'Missing provider',
						'message' => 'Provider is required for testing',
					],
					statusCode: 400
				);
			}

			if (empty($config) === true || is_array($config) === false) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'Invalid config',
						'message' => 'Config must be provided as an object',
					],
					statusCode: 400
				);
			}

			// Delegate to VectorizationService for testing.
			$vectorService = $this->vectorizationService;
			$result = $vectorService->testEmbedding(provider: $provider, config: $config, testText: $testText);

			// Return appropriate status code.
			$statusCode = 400;
			if ($result['success'] === true) {
				$statusCode = 200;
			}

			return new JSONResponse(data: $result, statusCode: $statusCode);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
					'message' => 'Failed to generate embedding: ' . $e->getMessage(),
				],
				statusCode: 400
			);
		}//end try
	}//end testEmbedding()

	/**
	 * Test LLM chat functionality
	 *
	 * Tests if the configured chat provider works correctly
	 * by sending a simple test message and receiving a response.
	 * Accepts provider and config from the request to allow testing
	 * before saving the configuration.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Test result with chat response
	 *
	 * @psalm-return JSONResponse<200|400, array<array-key, mixed>,
	 *     array<never, never>>|JSONResponse<400,
	 *     array{success: false, error: string, message: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function testChat(): JSONResponse {
		try {
			// Get parameters from request.
			$provider = (string)$this->request->getParam('provider');
			$config = $this->request->getParam('config', []);
			$testMessage = (string)$this->request->getParam('testMessage', 'Hello! Please respond with a brief greeting.');

			// Validate input.
			if (empty($provider) === true) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'Missing provider',
						'message' => 'Provider is required for testing',
					],
					statusCode: 400
				);
			}

			if (empty($config) === true || is_array($config) === false) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'Invalid config',
						'message' => 'Config must be provided as an object',
					],
					statusCode: 400
				);
			}

			// Delegate to ChatService for testing.
			$chatService = $this->container->get('OCA\OpenRegister\Service\ChatService');
			$result = $chatService->testChat(provider: $provider, config: $config, _testMessage: $testMessage);

			// Return appropriate status code.
			$statusCode = 400;
			if ($result['success'] === true) {
				$statusCode = 200;
			}

			return new JSONResponse(data: $result, statusCode: $statusCode);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
					'message' => 'Failed to test chat: ' . $e->getMessage(),
				],
				statusCode: 400
			);
		}//end try
	}//end testChat()

	/**
	 * Get available Ollama models from the configured Ollama instance
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse List of available models
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{success: bool, error?: string,
	 *     models: list<array{description: mixed|string, id: 'unknown'|mixed,
	 *     modified: mixed|null, name: 'unknown'|mixed, size: 0|mixed}>,
	 *     count?: int<0, max>}, array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function getOllamaModels(): JSONResponse {
		try {
			// Get Ollama URL from settings.
			$settings = $this->settingsService->getLLMSettingsOnly();
			$ollamaUrl = $settings['ollamaConfig']['url'] ?? 'http://localhost:11434';

			$fetched = $this->fetchOllamaModels(ollamaUrl: $ollamaUrl);
			if (isset($fetched['error']) === true) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => $fetched['error'],
						'models' => [],
					]
				);
			}

			// Format models for frontend dropdown.
			$models = array_map(
				fn (array $model): array => $this->formatOllamaModel(model: $model),
				$fetched['models']
			);

			// Sort by name.
			usort(
				$models,
				function ($a, $b) {
					return strcmp($a['name'], $b['name']);
				}
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'models' => $models,
					'count' => count($models),
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
					'models' => [],
				],
				statusCode: 500
			);
		}//end try
	}//end getOllamaModels()

	/**
	 * Fetch the raw model list from an Ollama instance's tags endpoint.
	 *
	 * @param string $ollamaUrl The Ollama base URL.
	 *
	 * @return array{error?: string, models?: array} The models, or the error that stopped the request.
	 */
	private function fetchOllamaModels(string $ollamaUrl): array {
		$ch = curl_init(rtrim($ollamaUrl, '/') . '/api/tags');
		curl_setopt_array(
			$ch,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 5,
				CURLOPT_FOLLOWLOCATION => true,
			]
		);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		// No curl_close(): deprecated since PHP 8.0 and a no-op — the
		// CurlHandle object is freed when it goes out of scope.
		if ($curlError !== '') {
			return ['error' => 'Failed to connect to Ollama: ' . $curlError];
		}

		if ($httpCode !== 200) {
			return ['error' => "Ollama API returned HTTP {$httpCode}"];
		}

		$data = json_decode($response, true);
		if (isset($data['models']) === false || is_array($data['models']) === false) {
			return ['error' => 'Unexpected response from Ollama API'];
		}

		return ['models' => $data['models']];
	}//end fetchOllamaModels()

	/**
	 * Format one Ollama model for the frontend dropdown.
	 *
	 * @param array<string, mixed> $model The model as Ollama lists it.
	 *
	 * @return array{id: mixed, name: mixed, description: string, size: mixed, modified: mixed}
	 */
	private function formatOllamaModel(array $model): array {
		$name = $model['name'] ?? 'unknown';
		// Format size if available.
		$size = '';
		if (is_numeric($model['size'] ?? null) === true) {
			$size = $this->settingsService->formatBytes((int)$model['size']);
		}

		$family = $model['details']['family'] ?? '';

		// Build description.
		$description = $family;
		if ($size !== '') {
			// Add size separator if description exists.
			if ($description !== '') {
				$description .= ' • ';
			}

			$description .= $size;
		}

		return [
			'id' => $name,
			'name' => $name,
			'description' => $description,
			'size' => $model['size'] ?? 0,
			'modified' => $model['modified_at'] ?? null,
		];
	}//end formatOllamaModel()

	/**
	 * Check if embedding model has changed and vectors need regeneration
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with mismatch check result
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function checkEmbeddingModelMismatch(): JSONResponse {
		try {
			$result = $this->vectorizationService->checkEmbeddingModelMismatch();

			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'has_vectors' => false,
					'mismatch' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end checkEmbeddingModelMismatch()

	/**
	 * Clear all embeddings from the database
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Result with deleted count
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{success: bool, error?: string, message?: string, deleted?: int},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function clearAllEmbeddings(): JSONResponse {
		try {
			$result = $this->vectorizationService->clearAllEmbeddings();

			if ($result['success'] === true) {
				return new JSONResponse(data: $result);
			}

			return new JSONResponse(data: $result, statusCode: 500);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end clearAllEmbeddings()

	/**
	 * Get statistics of the database vector store
	 *
	 * Admin only: the method carries no NoAdminRequired annotation.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The getVectorStats() shape plus a success flag
	 *
	 * @psalm-return JSONResponse<200|500, array<string, mixed>, array<never, never>>
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md#requirement-the-system-must-expose-vector-statistics-and-surface-embedding-model-rotation-needs
	 */
	public function getVectorStats(): JSONResponse {
		try {
			$stats = $this->vectorizationService->getVectorStats();

			return new JSONResponse(data: array_merge(['success' => true], $stats));
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end getVectorStats()
}//end class
