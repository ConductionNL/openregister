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
use OCA\OpenRegister\Controller\Support\LlmSettingsControllerServices;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
use OCA\OpenRegister\Service\Connection\ConnectionReporterInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

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
	 * The request fields the frontend may send as a model object instead of a model id.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private const MODEL_FIELDS = [
		['fireworksConfig', 'embeddingModel'],
		['fireworksConfig', 'chatModel'],
		['openaiConfig', 'model'],
		['openaiConfig', 'chatModel'],
		['ollamaConfig', 'model'],
		['ollamaConfig', 'chatModel'],
	];

	/**
	 * The text embedded when the request names none.
	 *
	 * @var string
	 */
	private const DEFAULT_TEST_TEXT = 'This is a test embedding to verify the LLM configuration.';

	/**
	 * The message sent when the request names none.
	 *
	 * @var string
	 */
	private const DEFAULT_TEST_MESSAGE = 'Hello! Please respond with a brief greeting.';

	/**
	 * DI container.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * Settings service.
	 *
	 * @var AppSettingsInterface
	 */
	private readonly AppSettingsInterface $settingsService;

	/**
	 * Vectorization service.
	 *
	 * @var VectorizationServiceInterface
	 */
	private readonly VectorizationServiceInterface $vectorizationService;

	/**
	 * Reports the saved provider to integriq's connection registry.
	 *
	 * @var ConnectionReporterInterface
	 */
	private readonly ConnectionReporterInterface $connectionReporter;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param LlmSettingsControllerServices $services The llm settings controller services.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		LlmSettingsControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->container = $services->platform->container;
		$this->settingsService = $services->domain->settingsService;
		$this->vectorizationService = $services->domain->vectorizationService;
		$this->connectionReporter = $services->domain->connectionReporter;
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
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function updateLLMSettings(): JSONResponse {
		try {
			$data = $this->withModelIds(data: $this->request->getParams());
			$result = $this->settingsService->updateLLMSettingsOnly($data);
			$this->connectionReporter->reportLlmProviders(
				chatProvider: ($result['chatProvider'] ?? null),
				embeddingProvider: ($result['embeddingProvider'] ?? null)
			);

			return new JSONResponse(
				data: ['success' => true, 'message' => 'LLM settings updated successfully', 'data' => $result]
			);
		} catch (Exception $e) {
			return $this->failure(e: $e);
		}
	}//end updateLLMSettings()

	/**
	 * Replace each model object the frontend sent with its id.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return array<string, mixed> The parameters with model ids only.
	 */
	private function withModelIds(array $data): array {
		foreach (self::MODEL_FIELDS as [$config, $field]) {
			if (is_array($data[$config][$field] ?? null) === true) {
				$data[$config][$field] = $data[$config][$field]['id'] ?? null;
			}
		}

		return $data;
	}//end withModelIds()

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
			$provider = (string)$this->request->getParam('provider');
			$config = $this->request->getParam('config', []);
			$testText = (string)$this->request->getParam('testText', self::DEFAULT_TEST_TEXT);
			$invalid = $this->invalidTestRequest(provider: $provider, config: $config);
			if ($invalid !== null) {
				return $invalid;
			}

			$result = $this->vectorizationService->testEmbedding(provider: $provider, config: $config, testText: $testText);
			return $this->testResult(result: $result);
		} catch (Exception $e) {
			return $this->testFailure(prefix: 'Failed to generate embedding: ', e: $e);
		}
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
			$provider = (string)$this->request->getParam('provider');
			$config = $this->request->getParam('config', []);
			$testMessage = (string)$this->request->getParam('testMessage', self::DEFAULT_TEST_MESSAGE);
			$invalid = $this->invalidTestRequest(provider: $provider, config: $config);
			if ($invalid !== null) {
				return $invalid;
			}

			return $this->testResult(result: $this->runChatTest(provider: $provider, config: $config, message: $testMessage));
		} catch (Exception $e) {
			return $this->testFailure(prefix: 'Failed to test chat: ', e: $e);
		}
	}//end testChat()

	/**
	 * Send the test message through the chat service.
	 *
	 * @param string $provider The provider to test.
	 * @param mixed  $config   The provider configuration.
	 * @param string $message  The test message.
	 *
	 * @return array The chat service's test result
	 */
	private function runChatTest(string $provider, mixed $config, string $message): array {
		$chatService = $this->container->get('OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface');
		return $chatService->testChat(provider: $provider, config: $config, _testMessage: $message);
	}//end runChatTest()

	/**
	 * The 400 answer for a connection test that names no provider or no config, if any.
	 *
	 * @param string $provider The provider to test.
	 * @param mixed $config The provider config as sent.
	 *
	 * @return JSONResponse|null The rejection, or null when the request can be tested.
	 */
	private function invalidTestRequest(string $provider, mixed $config): ?JSONResponse {
		if (empty($provider) === true) {
			return new JSONResponse(
				data: ['success' => false, 'error' => 'Missing provider', 'message' => 'Provider is required for testing'],
				statusCode: 400
			);
		}

		if (empty($config) === true || is_array($config) === false) {
			return new JSONResponse(
				data: ['success' => false, 'error' => 'Invalid config', 'message' => 'Config must be provided as an object'],
				statusCode: 400
			);
		}

		return null;
	}//end invalidTestRequest()

	/**
	 * Answer a connection test result: 200 on success, 400 otherwise.
	 *
	 * @param array<string, mixed> $result The test result.
	 *
	 * @return JSONResponse The result with its status.
	 */
	private function testResult(array $result): JSONResponse {
		$statusCode = 400;
		if ($result['success'] === true) {
			$statusCode = 200;
		}

		return new JSONResponse(data: $result, statusCode: $statusCode);
	}//end testResult()

	/**
	 * The 400 answer for a connection test that threw.
	 *
	 * @param string $prefix What failed, put before the exception message.
	 * @param Exception $e The exception.
	 *
	 * @return JSONResponse The failure.
	 */
	private function testFailure(string $prefix, Exception $e): JSONResponse {
		return new JSONResponse(
			data: ['success' => false, 'error' => $e->getMessage(), 'message' => $prefix . $e->getMessage()],
			statusCode: 400
		);
	}//end testFailure()

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
			$settings = $this->settingsService->getLLMSettingsOnly();
			$ollamaUrl = $settings['ollamaConfig']['url'] ?? 'http://localhost:11434';

			$fetched = $this->fetchOllamaModels(ollamaUrl: $ollamaUrl);
			if (isset($fetched['error']) === true) {
				return $this->ollamaFailure(error: $fetched['error'], statusCode: 200);
			}

			return $this->ollamaModels(models: $fetched['models']);
		} catch (Exception $e) {
			return $this->ollamaFailure(error: $e->getMessage(), statusCode: 500);
		}
	}//end getOllamaModels()

	/**
	 * Answer the Ollama models formatted for the frontend dropdown, sorted by name.
	 *
	 * @param array<int, array<string, mixed>> $models The models as Ollama lists them.
	 *
	 * @return JSONResponse The formatted models and their count.
	 */
	private function ollamaModels(array $models): JSONResponse {
		$models = array_map(
			fn (array $model): array => $this->formatOllamaModel(model: $model),
			$models
		);
		usort(
			$models,
			function ($a, $b) {
				return strcmp($a['name'], $b['name']);
			}
		);

		return new JSONResponse(data: ['success' => true, 'models' => $models, 'count' => count($models)]);
	}//end ollamaModels()

	/**
	 * Answer a failed Ollama model listing.
	 *
	 * @param string $error The error message.
	 * @param int $statusCode The response status.
	 *
	 * @return JSONResponse The failure with an empty model list.
	 */
	private function ollamaFailure(string $error, int $statusCode): JSONResponse {
		return new JSONResponse(
			data: ['success' => false, 'error' => $error, 'models' => []],
			statusCode: $statusCode
		);
	}//end ollamaFailure()

	/**
	 * Fetch the raw model list from an Ollama instance's tags endpoint.
	 *
	 * @param string $ollamaUrl The Ollama base URL.
	 *
	 * @return array{error?: string, models?: array} The models, or the error that stopped the request.
	 */
	private function fetchOllamaModels(string $ollamaUrl): array {
		[$response, $httpCode, $curlError] = $this->requestOllamaTags(ollamaUrl: $ollamaUrl);
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
	 * Call an Ollama instance's tags endpoint.
	 *
	 * No curl_close(): deprecated since PHP 8.0 and a no-op — the CurlHandle
	 * object is freed when it goes out of scope.
	 *
	 * @param string $ollamaUrl The Ollama base URL.
	 *
	 * @return array{0: string|bool, 1: mixed, 2: string} The body, the HTTP code and curl's error text.
	 */
	private function requestOllamaTags(string $ollamaUrl): array {
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
		return [$response, $httpCode, curl_error($ch)];
	}//end requestOllamaTags()

	/**
	 * Format one Ollama model for the frontend dropdown.
	 *
	 * @param array<string, mixed> $model The model as Ollama lists it.
	 *
	 * @return array{id: mixed, name: mixed, description: mixed, size: mixed, modified: mixed}
	 */
	private function formatOllamaModel(array $model): array {
		$name = $model['name'] ?? 'unknown';

		return [
			'id' => $name,
			'name' => $name,
			'description' => $this->ollamaDescription(model: $model),
			'size' => $model['size'] ?? 0,
			'modified' => $model['modified_at'] ?? null,
		];
	}//end formatOllamaModel()

	/**
	 * Describe an Ollama model as its family and formatted size, joined by a bullet.
	 *
	 * @param array<string, mixed> $model The model as Ollama lists it.
	 *
	 * @return mixed The description: the family as listed when the size is unknown.
	 */
	private function ollamaDescription(array $model): mixed {
		$size = $this->ollamaSize(model: $model);
		$family = $model['details']['family'] ?? '';
		if ($size === '') {
			return $family;
		}

		if ($family === '') {
			return $size;
		}

		return $family . ' • ' . $size;
	}//end ollamaDescription()

	/**
	 * Format an Ollama model's size, or an empty string when it lists none.
	 *
	 * @param array<string, mixed> $model The model as Ollama lists it.
	 *
	 * @return string The formatted size.
	 */
	private function ollamaSize(array $model): string {
		if (is_numeric($model['size'] ?? null) === true) {
			return $this->settingsService->formatBytes((int)$model['size']);
		}

		return '';
	}//end ollamaSize()

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
			return $this->failure(e: $e);
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
			return $this->failure(e: $e);
		}
	}//end getVectorStats()

	/**
	 * The 500 answer for an action that threw.
	 *
	 * @param Exception $e The exception.
	 *
	 * @return JSONResponse The failure.
	 */
	private function failure(Exception $e): JSONResponse {
		return new JSONResponse(data: ['success' => false, 'error' => $e->getMessage()], statusCode: 500);
	}//end failure()
}//end class
