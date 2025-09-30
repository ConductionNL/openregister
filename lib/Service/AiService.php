<?php
/**
 * OpenRegister AI Service
 *
 * This file contains the service class for handling AI operations in the OpenRegister application.
 * It provides functionality for text generation, embeddings, and AI-powered content enrichment
 * using the LLPhant framework.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Embeddings\OpenAIEmbeddings;
use LLPhant\OpenAIConfig;
use LLPhant\Chat\OllamaChat;
use LLPhant\Embeddings\OllamaEmbeddings;
use LLPhant\OllamaConfig;
use LLPhant\Chat\Message;
use LLPhant\Chat\SystemMessage;
use LLPhant\Chat\UserMessage;

/**
 * Service for handling AI-related operations.
 *
 * Provides functionality for text generation, embeddings, chat interactions,
 * and automatic content enrichment using various AI providers through LLPhant.
 */
class AiService
{
    /**
     * The name of the application
     *
     * @var string $appName The name of the app
     */
    private string $appName;

    /**
     * AiService constructor.
     *
     * @param IAppConfig        $config  App configuration interface
     * @param IConfig           $systemConfig System configuration interface
     * @param LoggerInterface   $logger  Logger interface for logging operations
     * @param SettingsService   $settingsService Settings service for configuration
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IConfig $systemConfig,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService
    ) {
        $this->appName = 'openregister';
    }//end __construct()

    /**
     * Check if AI functionality is enabled and properly configured
     *
     * @return bool True if AI is enabled and configured, false otherwise
     */
    public function isAiEnabled(): bool
    {
        try {
            $aiSettings = $this->settingsService->getAiSettingsOnly();
            return $aiSettings['enabled'] && !empty($aiSettings['apiKey']) || $aiSettings['provider'] === 'ollama';
        } catch (\Exception $e) {
            $this->logger->error('Failed to check AI configuration', ['error' => $e->getMessage()]);
            return false;
        }
    }//end isAiEnabled()

    /**
     * Get AI configuration for the specified provider
     *
     * @param string|null $provider AI provider (openai, ollama, etc.)
     * @return array AI configuration
     * @throws \RuntimeException If AI configuration is invalid
     */
    private function getAiConfig(?string $provider = null): array
    {
        $aiSettings = $this->settingsService->getAiSettingsOnly();
        
        if (!$aiSettings['enabled']) {
            throw new \RuntimeException('AI functionality is disabled');
        }

        $provider = $provider ?? $aiSettings['provider'];
        
        switch ($provider) {
            case 'openai':
                if (empty($aiSettings['apiKey'])) {
                    throw new \RuntimeException('OpenAI API key is required');
                }
                return [
                    'provider' => 'openai',
                    'apiKey' => $aiSettings['apiKey'],
                    'model' => $aiSettings['model'],
                    'embeddingModel' => $aiSettings['embeddingModel'],
                    'maxTokens' => $aiSettings['maxTokens'],
                    'temperature' => $aiSettings['temperature'],
                    'timeout' => $aiSettings['timeout'],
                ];
                
            case 'ollama':
                return [
                    'provider' => 'ollama',
                    'host' => $aiSettings['host'] ?: 'localhost',
                    'port' => $aiSettings['port'] ?: 11434,
                    'model' => $aiSettings['model'],
                    'embeddingModel' => $aiSettings['embeddingModel'],
                    'maxTokens' => $aiSettings['maxTokens'],
                    'temperature' => $aiSettings['temperature'],
                    'timeout' => $aiSettings['timeout'],
                ];
                
            default:
                throw new \RuntimeException('Unsupported AI provider: ' . $provider);
        }
    }//end getAiConfig()

    /**
     * Create chat client for the configured AI provider
     *
     * @param string|null $provider AI provider to use
     * @return OpenAIChat|OllamaChat Chat client instance
     * @throws \RuntimeException If chat client creation fails
     */
    private function createChatClient(?string $provider = null)
    {
        $config = $this->getAiConfig($provider);
        
        switch ($config['provider']) {
            case 'openai':
                $openAIConfig = new OpenAIConfig();
                $openAIConfig->apiKey = $config['apiKey'];
                $openAIConfig->model = $config['model'];
                return new OpenAIChat($openAIConfig);
                
            case 'ollama':
                $ollamaConfig = new OllamaConfig();
                $ollamaConfig->url = 'http://' . $config['host'] . ':' . $config['port'];
                $ollamaConfig->model = $config['model'];
                return new OllamaChat($ollamaConfig);
                
            default:
                throw new \RuntimeException('Unsupported provider for chat: ' . $config['provider']);
        }
    }//end createChatClient()

    /**
     * Create embeddings client for the configured AI provider
     *
     * @param string|null $provider AI provider to use
     * @return OpenAIEmbeddings|OllamaEmbeddings Embeddings client instance
     * @throws \RuntimeException If embeddings client creation fails
     */
    private function createEmbeddingsClient(?string $provider = null)
    {
        $config = $this->getAiConfig($provider);
        
        switch ($config['provider']) {
            case 'openai':
                $openAIConfig = new OpenAIConfig();
                $openAIConfig->apiKey = $config['apiKey'];
                $openAIConfig->model = $config['embeddingModel'];
                return new OpenAIEmbeddings($openAIConfig);
                
            case 'ollama':
                $ollamaConfig = new OllamaConfig();
                $ollamaConfig->url = 'http://' . $config['host'] . ':' . $config['port'];
                $ollamaConfig->model = $config['embeddingModel'];
                return new OllamaEmbeddings($ollamaConfig);
                
            default:
                throw new \RuntimeException('Unsupported provider for embeddings: ' . $config['provider']);
        }
    }//end createEmbeddingsClient()

    /**
     * Generate text representation for an object
     *
     * Creates a searchable text representation from object data, focusing on
     * name, summary, and description fields for optimal search functionality.
     *
     * @param array $objectData Object data array
     * @return string Generated text representation
     */
    public function generateTextRepresentation(array $objectData): string
    {
        $textParts = [];
        
        // Extract key fields for text representation
        if (!empty($objectData['name'])) {
            $textParts[] = 'Name: ' . $objectData['name'];
        }
        
        if (!empty($objectData['summary'])) {
            $textParts[] = 'Summary: ' . $objectData['summary'];
        }
        
        if (!empty($objectData['description'])) {
            $textParts[] = 'Description: ' . $objectData['description'];
        }
        
        // Add other relevant fields
        if (!empty($objectData['tags']) && is_array($objectData['tags'])) {
            $textParts[] = 'Tags: ' . implode(', ', $objectData['tags']);
        }
        
        if (!empty($objectData['category'])) {
            $textParts[] = 'Category: ' . $objectData['category'];
        }
        
        // Combine all parts
        $textRepresentation = implode('. ', $textParts);
        
        // Ensure we have meaningful content
        if (empty(trim($textRepresentation))) {
            $textRepresentation = 'Object ID: ' . ($objectData['id'] ?? 'unknown');
        }
        
        $this->logger->debug('Generated text representation for object', [
            'object_id' => $objectData['id'] ?? 'unknown',
            'text_length' => strlen($textRepresentation)
        ]);
        
        return $textRepresentation;
    }//end generateTextRepresentation()

    /**
     * Generate vector embedding for text content
     *
     * Creates a vector embedding that can be used for semantic search,
     * similarity matching, and AI-powered content recommendations.
     *
     * @param string $text Text content to embed
     * @param string|null $provider AI provider to use for embeddings
     * @return array Vector embedding as array of floats
     * @throws \RuntimeException If embedding generation fails
     */
    public function generateEmbedding(string $text, ?string $provider = null): array
    {
        if (!$this->isAiEnabled()) {
            throw new \RuntimeException('AI functionality is not enabled');
        }

        try {
            $embeddingsClient = $this->createEmbeddingsClient($provider);
            $embedding = $embeddingsClient->embedText($text);
            
            $this->logger->debug('Generated embedding for text', [
                'text_length' => strlen($text),
                'embedding_dimensions' => count($embedding),
                'provider' => $provider ?? 'default'
            ]);
            
            return $embedding;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate embedding', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
                'provider' => $provider ?? 'default'
            ]);
            throw new \RuntimeException('Failed to generate embedding: ' . $e->getMessage());
        }
    }//end generateEmbedding()

    /**
     * Enrich object with AI-generated content
     *
     * Automatically generates text representation and embeddings for an object
     * if AI auto-enrichment is enabled in settings.
     *
     * @param array $objectData Object data to enrich
     * @return array Enriched object data with AI-generated content
     */
    public function enrichObject(array $objectData): array
    {
        if (!$this->isAiEnabled()) {
            return $objectData;
        }

        $aiSettings = $this->settingsService->getAiSettingsOnly();
        if (!$aiSettings['autoEnrichObjects']) {
            return $objectData;
        }

        try {
            // Generate text representation
            $textRepresentation = $this->generateTextRepresentation($objectData);
            $objectData['text'] = $textRepresentation;
            
            // Generate embedding for the text
            $embedding = $this->generateEmbedding($textRepresentation);
            $objectData['embedding'] = $embedding;
            
            $this->logger->info('Object enriched with AI content', [
                'object_id' => $objectData['id'] ?? 'unknown',
                'text_length' => strlen($textRepresentation),
                'embedding_dimensions' => count($embedding)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to enrich object with AI content', [
                'error' => $e->getMessage(),
                'object_id' => $objectData['id'] ?? 'unknown'
            ]);
            // Don't fail the entire operation if AI enrichment fails
        }

        return $objectData;
    }//end enrichObject()

    /**
     * Handle chat conversation with AI
     *
     * Processes a chat message and returns an AI-generated response,
     * with optional context and conversation history.
     *
     * @param string $message User message
     * @param array $context Optional context information
     * @param array $history Optional conversation history
     * @param string|null $provider AI provider to use
     * @return array Chat response with message and metadata
     * @throws \RuntimeException If chat processing fails
     */
    public function chat(string $message, array $context = [], array $history = [], ?string $provider = null): array
    {
        if (!$this->isAiEnabled()) {
            throw new \RuntimeException('AI functionality is not enabled');
        }

        try {
            $chatClient = $this->createChatClient($provider);
            $config = $this->getAiConfig($provider);
            
            // Prepare messages for the conversation
            $messages = [];
            
            // Add system message with context
            $systemPrompt = $this->buildSystemPrompt($context);
            if (!empty($systemPrompt)) {
                $messages[] = new SystemMessage($systemPrompt);
            }
            
            // Add conversation history
            foreach ($history as $historyItem) {
                if ($historyItem['role'] === 'user') {
                    $messages[] = new UserMessage($historyItem['content']);
                } elseif ($historyItem['role'] === 'assistant') {
                    $messages[] = new Message($historyItem['content'], 'assistant');
                }
            }
            
            // Add current user message
            $messages[] = new UserMessage($message);
            
            // Generate response
            $response = $chatClient->generateText($messages);
            
            $this->logger->info('AI chat response generated', [
                'message_length' => strlen($message),
                'response_length' => strlen($response),
                'provider' => $provider ?? 'default',
                'context_provided' => !empty($context)
            ]);
            
            return [
                'message' => $response,
                'provider' => $config['provider'],
                'model' => $config['model'],
                'timestamp' => date('c'),
                'context_used' => !empty($context),
                'history_length' => count($history)
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process chat message', [
                'error' => $e->getMessage(),
                'message_length' => strlen($message),
                'provider' => $provider ?? 'default'
            ]);
            throw new \RuntimeException('Failed to process chat message: ' . $e->getMessage());
        }
    }//end chat()

    /**
     * Build system prompt with context information
     *
     * @param array $context Context information for the AI
     * @return string System prompt text
     */
    private function buildSystemPrompt(array $context): string
    {
        $prompt = "You are an AI assistant for OpenRegister, a Nextcloud application for managing object-oriented data stores. ";
        $prompt .= "You help users understand and work with their data objects, schemas, and registers.";
        
        if (!empty($context['register'])) {
            $prompt .= "\n\nCurrent register: " . $context['register'];
        }
        
        if (!empty($context['schema'])) {
            $prompt .= "\nCurrent schema: " . $context['schema'];
        }
        
        if (!empty($context['objects'])) {
            $prompt .= "\nAvailable objects: " . implode(', ', array_slice($context['objects'], 0, 10));
            if (count($context['objects']) > 10) {
                $prompt .= " and " . (count($context['objects']) - 10) . " more";
            }
        }
        
        $prompt .= "\n\nPlease provide helpful, accurate responses about OpenRegister functionality and data management.";
        
        return $prompt;
    }//end buildSystemPrompt()

    /**
     * Test AI connection and functionality
     *
     * @param string|null $provider AI provider to test
     * @return array Test results with success status and details
     */
    public function testConnection(?string $provider = null): array
    {
        try {
            if (!$this->isAiEnabled()) {
                return [
                    'success' => false,
                    'message' => 'AI functionality is not enabled',
                    'details' => []
                ];
            }

            $config = $this->getAiConfig($provider);
            
            // Test chat functionality
            $chatClient = $this->createChatClient($provider);
            $testMessage = new UserMessage("Hello, this is a connection test. Please respond with 'Connection successful'.");
            $chatResponse = $chatClient->generateText([$testMessage]);
            
            // Test embeddings functionality
            $embeddingsClient = $this->createEmbeddingsClient($provider);
            $testEmbedding = $embeddingsClient->embedText("This is a test text for embedding generation.");
            
            return [
                'success' => true,
                'message' => 'AI connection test successful',
                'details' => [
                    'provider' => $config['provider'],
                    'chat_model' => $config['model'],
                    'embedding_model' => $config['embeddingModel'],
                    'chat_response_length' => strlen($chatResponse),
                    'embedding_dimensions' => count($testEmbedding),
                    'test_timestamp' => date('c')
                ]
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'AI connection test failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'provider' => $provider ?? 'default',
                    'test_timestamp' => date('c')
                ]
            ];
        }
    }//end testConnection()

}//end class
