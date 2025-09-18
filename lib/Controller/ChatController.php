<?php
/**
 * OpenRegister Chat Controller
 *
 * This file contains the controller class for handling AI chat interactions in the OpenRegister application.
 * It provides endpoints for chat conversations, context-aware responses, and AI-powered assistance.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\AiService;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling AI chat interactions in OpenRegister.
 *
 * Provides endpoints for chat conversations, context-aware AI responses,
 * and AI-powered assistance for data management tasks.
 */
class ChatController extends Controller
{
    /**
     * ChatController constructor.
     *
     * @param string             $appName         The name of the app
     * @param IRequest           $request         The request object
     * @param ContainerInterface $container       The container for dependency injection
     * @param AiService          $aiService       The AI service for chat operations
     * @param SettingsService    $settingsService The settings service for configuration
     * @param LoggerInterface    $logger          Logger interface for logging operations
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly AiService $aiService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Handle chat message and return AI response
     *
     * Processes a user message and returns an AI-generated response with optional
     * context information and conversation history for enhanced accuracy.
     *
     * @return JSONResponse JSON response containing AI chat response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function chat(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            
            // Validate required parameters
            if (empty($data['message'])) {
                return new JSONResponse([
                    'error' => 'Message is required'
                ], 400);
            }

            $message = $data['message'];
            $context = $data['context'] ?? [];
            $history = $data['history'] ?? [];
            $provider = $data['provider'] ?? null;

            // Check if AI is enabled
            if (!$this->aiService->isAiEnabled()) {
                return new JSONResponse([
                    'error' => 'AI functionality is not enabled. Please configure AI settings first.'
                ], 503);
            }

            // Process chat message
            $response = $this->aiService->chat($message, $context, $history, $provider);

            $this->logger->info('Chat message processed successfully', [
                'message_length' => strlen($message),
                'response_length' => strlen($response['message']),
                'provider' => $response['provider']
            ]);

            return new JSONResponse([
                'success' => true,
                'response' => $response['message'],
                'metadata' => [
                    'provider' => $response['provider'],
                    'model' => $response['model'],
                    'timestamp' => $response['timestamp'],
                    'context_used' => $response['context_used'],
                    'history_length' => $response['history_length']
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Chat processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'error' => 'Failed to process chat message: ' . $e->getMessage()
            ], 500);
        }
    }//end chat()

    /**
     * Get AI chat capabilities and status
     *
     * Returns information about available AI providers, models, and current
     * configuration status for the chat functionality.
     *
     * @return JSONResponse JSON response containing chat capabilities information
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getCapabilities(): JSONResponse
    {
        try {
            $aiSettings = $this->settingsService->getAiSettingsOnly();
            $isEnabled = $this->aiService->isAiEnabled();

            $capabilities = [
                'enabled' => $isEnabled,
                'provider' => $aiSettings['provider'],
                'model' => $aiSettings['model'],
                'embedding_model' => $aiSettings['embeddingModel'],
                'max_tokens' => $aiSettings['maxTokens'],
                'temperature' => $aiSettings['temperature'],
                'auto_enrich_objects' => $aiSettings['autoEnrichObjects'],
                'supported_providers' => [
                    'openai' => [
                        'name' => 'OpenAI',
                        'models' => ['gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo', 'gpt-4o'],
                        'embedding_models' => ['text-embedding-ada-002', 'text-embedding-3-small', 'text-embedding-3-large']
                    ],
                    'ollama' => [
                        'name' => 'Ollama (Local)',
                        'models' => ['llama2', 'llama3', 'mistral', 'codellama'],
                        'embedding_models' => ['nomic-embed-text', 'all-minilm']
                    ]
                ],
                'features' => [
                    'chat' => $isEnabled,
                    'embeddings' => $isEnabled,
                    'object_enrichment' => $isEnabled && $aiSettings['autoEnrichObjects'],
                    'context_aware' => true,
                    'conversation_history' => true
                ]
            ];

            return new JSONResponse($capabilities);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get chat capabilities', [
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'error' => 'Failed to get chat capabilities: ' . $e->getMessage()
            ], 500);
        }
    }//end getCapabilities()

    /**
     * Test AI chat connection
     *
     * Performs a connection test to verify that the AI chat functionality
     * is working correctly with the current configuration.
     *
     * @return JSONResponse JSON response containing connection test results
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function testConnection(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $provider = $data['provider'] ?? null;

            $result = $this->aiService->testConnection($provider);

            return new JSONResponse($result);

        } catch (\Exception $e) {
            $this->logger->error('AI connection test failed', [
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'details' => ['exception' => $e->getMessage()]
            ], 500);
        }
    }//end testConnection()

    /**
     * Get context information for AI chat
     *
     * Retrieves relevant context information that can be used to enhance
     * AI chat responses, including available registers, schemas, and objects.
     *
     * @return JSONResponse JSON response containing context information
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getContext(): JSONResponse
    {
        try {
            $context = [];

            // Try to get available registers
            try {
                if ($this->container->has('OCA\OpenRegister\Db\RegisterMapper')) {
                    $registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
                    $registers = $registerMapper->findAll(limit: 20);
                    $context['registers'] = array_map(function($register) {
                        return [
                            'id' => $register->getId(),
                            'name' => $register->getName(),
                            'description' => $register->getDescription()
                        ];
                    }, $registers);
                }
            } catch (\Exception $e) {
                $this->logger->debug('Could not load registers for context', ['error' => $e->getMessage()]);
            }

            // Try to get available schemas
            try {
                if ($this->container->has('OCA\OpenRegister\Db\SchemaMapper')) {
                    $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
                    $schemas = $schemaMapper->findAll(limit: 20);
                    $context['schemas'] = array_map(function($schema) {
                        return [
                            'id' => $schema->getId(),
                            'name' => $schema->getName(),
                            'description' => $schema->getDescription(),
                            'version' => $schema->getVersion()
                        ];
                    }, $schemas);
                }
            } catch (\Exception $e) {
                $this->logger->debug('Could not load schemas for context', ['error' => $e->getMessage()]);
            }

            // Try to get object statistics
            try {
                $stats = $this->settingsService->getStats();
                $context['statistics'] = [
                    'total_objects' => $stats['totals']['totalObjects'] ?? 0,
                    'total_registers' => $stats['totals']['totalRegisters'] ?? 0,
                    'total_schemas' => $stats['totals']['totalSchemas'] ?? 0
                ];
            } catch (\Exception $e) {
                $this->logger->debug('Could not load statistics for context', ['error' => $e->getMessage()]);
            }

            // Add AI configuration info
            $aiSettings = $this->settingsService->getAiSettingsOnly();
            $context['ai_config'] = [
                'enabled' => $aiSettings['enabled'],
                'provider' => $aiSettings['provider'],
                'auto_enrich_objects' => $aiSettings['autoEnrichObjects']
            ];

            return new JSONResponse([
                'success' => true,
                'context' => $context,
                'generated_at' => date('c')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get chat context', [
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'error' => 'Failed to get context information: ' . $e->getMessage()
            ], 500);
        }
    }//end getContext()

    /**
     * Generate text representation for an object
     *
     * Creates an AI-generated text representation for a given object,
     * useful for search indexing and content analysis.
     *
     * @return JSONResponse JSON response containing generated text representation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function generateText(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            
            if (empty($data['object'])) {
                return new JSONResponse([
                    'error' => 'Object data is required'
                ], 400);
            }

            if (!$this->aiService->isAiEnabled()) {
                return new JSONResponse([
                    'error' => 'AI functionality is not enabled'
                ], 503);
            }

            $objectData = $data['object'];
            $textRepresentation = $this->aiService->generateTextRepresentation($objectData);

            return new JSONResponse([
                'success' => true,
                'text' => $textRepresentation,
                'length' => strlen($textRepresentation),
                'generated_at' => date('c')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate text representation', [
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'error' => 'Failed to generate text representation: ' . $e->getMessage()
            ], 500);
        }
    }//end generateText()

    /**
     * Generate vector embedding for text
     *
     * Creates a vector embedding for the provided text that can be used
     * for semantic search and similarity matching.
     *
     * @return JSONResponse JSON response containing generated vector embedding
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function generateEmbedding(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            
            if (empty($data['text'])) {
                return new JSONResponse([
                    'error' => 'Text is required'
                ], 400);
            }

            if (!$this->aiService->isAiEnabled()) {
                return new JSONResponse([
                    'error' => 'AI functionality is not enabled'
                ], 503);
            }

            $text = $data['text'];
            $provider = $data['provider'] ?? null;
            $embedding = $this->aiService->generateEmbedding($text, $provider);

            return new JSONResponse([
                'success' => true,
                'embedding' => $embedding,
                'dimensions' => count($embedding),
                'text_length' => strlen($text),
                'generated_at' => date('c')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate embedding', [
                'error' => $e->getMessage()
            ]);

            return new JSONResponse([
                'error' => 'Failed to generate embedding: ' . $e->getMessage()
            ], 500);
        }
    }//end generateEmbedding()

}//end class
