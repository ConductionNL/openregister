<?php

/**
 * OpenRegister LLM Settings Controller
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

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Exception;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
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
class LlmSettingsController extends Controller
{


    /**
     * Constructor.
     *
     * @param string               $appName              The app name.
     * @param IRequest             $request              The request.
     * @param IDBConnection        $db                   Database connection.
     * @param ContainerInterface   $container            DI container.
     * @param SettingsService      $settingsService      Settings service.
     * @param VectorizationService $vectorizationService Vectorization service.
     * @param LoggerInterface      $logger               Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly VectorizationService $vectorizationService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Get LLM (Large Language Model) settings
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse LLM settings
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getLLMSettings(): JSONResponse
    {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated LLM settings
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, message?: 'LLM settings updated successfully', data?: array}, array<never, never>>
     */
    public function updateLLMSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Extract the model IDs from the objects sent by frontend.
            if (($data['fireworksConfig']['embeddingModel'] ?? null) !== null && is_array($data['fireworksConfig']['embeddingModel']) === true) {
                $data['fireworksConfig']['embeddingModel'] = $data['fireworksConfig']['embeddingModel']['id'] ?? null;
            }

            if (($data['fireworksConfig']['chatModel'] ?? null) !== null && is_array($data['fireworksConfig']['chatModel']) === true) {
                $data['fireworksConfig']['chatModel'] = $data['fireworksConfig']['chatModel']['id'] ?? null;
            }

            if (($data['openaiConfig']['model'] ?? null) !== null && is_array($data['openaiConfig']['model']) === true) {
                $data['openaiConfig']['model'] = $data['openaiConfig']['model']['id'] ?? null;
            }

            if (($data['openaiConfig']['chatModel'] ?? null) !== null && is_array($data['openaiConfig']['chatModel']) === true) {
                $data['openaiConfig']['chatModel'] = $data['openaiConfig']['chatModel']['id'] ?? null;
            }

            if (($data['ollamaConfig']['model'] ?? null) !== null && is_array($data['ollamaConfig']['model']) === true) {
                $data['ollamaConfig']['model'] = $data['ollamaConfig']['model']['id'] ?? null;
            }

            if (($data['ollamaConfig']['chatModel'] ?? null) !== null && is_array($data['ollamaConfig']['chatModel']) === true) {
                $data['ollamaConfig']['chatModel'] = $data['ollamaConfig']['chatModel']['id'] ?? null;
            }

            $result = $this->settingsService->updateLLMSettingsOnly($data);
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'LLM settings updated successfully',
                        'data'    => $result,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated LLM settings
     */
    public function patchLLMSettings(): JSONResponse
    {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result with embedding info
     *
     * @psalm-return JSONResponse<200|400, array<array-key, mixed>, array<never, never>>|JSONResponse<400, array{success: false, error: string, message: string}, array<never, never>>
     */
    public function testEmbedding(): JSONResponse
    {
        try {
            // Get parameters from request.
            $provider = (string) $this->request->getParam('provider');
            $config   = $this->request->getParam('config', []);
            $testText = (string) $this->request->getParam('testText', 'This is a test embedding to verify the LLM configuration.');

            // Validate input.
            if (empty($provider) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Missing provider',
                            'message' => 'Provider is required for testing',
                        ],
                        statusCode: 400
                    );
            }

            if (empty($config) === true || is_array($config) === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Invalid config',
                            'message' => 'Config must be provided as an object',
                        ],
                        statusCode: 400
                    );
            }

            // Delegate to VectorizationService for testing.
            $vectorService = $this->vectorizationService;
            $result        = $vectorService->testEmbedding(provider: $provider, config: $config, testText: $testText);

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
                        'error'   => $e->getMessage(),
                        'message' => 'Failed to generate embedding: '.$e->getMessage(),
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Test result with chat response
     *
     * @psalm-return JSONResponse<200|400, array<array-key, mixed>, array<never, never>>|JSONResponse<400, array{success: false, error: string, message: string}, array<never, never>>
     */
    public function testChat(): JSONResponse
    {
        try {
            // Get parameters from request.
            $provider    = (string) $this->request->getParam('provider');
            $config      = $this->request->getParam('config', []);
            $testMessage = (string) $this->request->getParam('testMessage', 'Hello! Please respond with a brief greeting.');

            // Validate input.
            if (empty($provider) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Missing provider',
                            'message' => 'Provider is required for testing',
                        ],
                        statusCode: 400
                    );
            }

            if (empty($config) === true || is_array($config) === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Invalid config',
                            'message' => 'Config must be provided as an object',
                        ],
                        statusCode: 400
                    );
            }

            // Delegate to ChatService for testing.
            $chatService = $this->container->get('OCA\OpenRegister\Service\ChatService');
            $result      = $chatService->testChat(provider: $provider, config: $config, testMessage: $testMessage);

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
                        'error'   => $e->getMessage(),
                        'message' => 'Failed to test chat: '.$e->getMessage(),
                    ],
                    statusCode: 400
                );
        }//end try

    }//end testChat()


    /**
     * Get available Ollama models from the configured Ollama instance
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse List of available models
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, models: list<array{description: mixed|string, id: 'unknown'|mixed, modified: mixed|null, name: 'unknown'|mixed, size: 0|mixed}>, count?: int<0, max>}, array<never, never>>
     */
    public function getOllamaModels(): JSONResponse
    {
        try {
            // Get Ollama URL from settings.
            $settings  = $this->settingsService->getLLMSettingsOnly();
            $ollamaUrl = $settings['ollamaConfig']['url'] ?? 'http://localhost:11434';

            // Call Ollama API to get available models.
            $apiUrl = rtrim($ollamaUrl, '/').'/api/tags';

            $ch = curl_init($apiUrl);
            curl_setopt_array(
                    $ch,
                    [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_FOLLOWLOCATION => true,
                    ]
                    );

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Failed to connect to Ollama: '.$curlError,
                            'models'  => [],
                        ]
                        );
            }

            if ($httpCode !== 200) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => "Ollama API returned HTTP {$httpCode}",
                            'models'  => [],
                        ]
                        );
            }

            $data = json_decode($response, true);
            if (isset($data['models']) === false || is_array($data['models']) === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Unexpected response from Ollama API',
                            'models'  => [],
                        ]
                        );
            }

            // Format models for frontend dropdown.
            $models = array_map(
                    function (array $model): array {
                        $name = $model['name'] ?? 'unknown';
                        // Format size if available.
                        $size = '';
                        if (($model['size'] ?? null) !== null && is_numeric($model['size']) === true) {
                            $size = $this->settingsService->formatBytes((int) $model['size']);
                        }

                        $family = $model['details']['family'] ?? '';

                        // Build description.
                        $description = $family;
                        if ($size !== '') {
                            // Add size separator if description exists.
                            if ($description !== null && $description !== '') {
                                $description .= ' • ';
                            }

                            $description .= $size;
                        }

                        return [
                            'id'          => $name,
                            'name'        => $name,
                            'description' => $description,
                            'size'        => $model['size'] ?? 0,
                            'modified'    => $model['modified_at'] ?? null,
                        ];
                    },
                    $data['models']
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
                        'models'  => $models,
                        'count'   => count($models),
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'models'  => [],
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getOllamaModels()


    /**
     * Check if embedding model has changed and vectors need regeneration
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Mismatch status
     *
     * @psalm-return JSONResponse<200|500, array{has_vectors: bool, mismatch: bool, error?: string, message?: 'No embedding model configured'|'No vectors exist yet'|mixed, current_model?: mixed, existing_models?: list<mixed>, total_vectors?: int, null_model_count?: int, mismatched_models?: list<mixed>}, array<never, never>>
     */
    public function checkEmbeddingModelMismatch(): JSONResponse
    {
        try {
            $result = $this->vectorizationService->checkEmbeddingModelMismatch();

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'has_vectors' => false,
                        'mismatch'    => false,
                        'error'       => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }

    }//end checkEmbeddingModelMismatch()


    /**
     * Clear all embeddings from the database
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Result with deleted count
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, message?: string, deleted?: int}, array<never, never>>
     */
    public function clearAllEmbeddings(): JSONResponse
    {
        try {
            $result = $this->vectorizationService->clearAllEmbeddings();

            if ($result['success'] === true) {
                return new JSONResponse(data: $result);
            } else {
                return new JSONResponse(data: $result, statusCode: 500);
            }
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end clearAllEmbeddings()


    /**
     * Get vector embedding statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Vector statistics
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, trace?: string, stats?: mixed, timestamp?: string}, array<never, never>>
     */
    public function getVectorStats(): JSONResponse
    {
        try {
            // Use VectorizationService.
            $vectorService = $this->vectorizationService;

            // Get statistics.
            $stats = $vectorService->getVectorStats();

            return new JSONResponse(
                    data: [
                        'success'   => true,
                        'stats'     => $stats,
                        'timestamp' => date('c'),
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getVectorStats()


    }//end class
