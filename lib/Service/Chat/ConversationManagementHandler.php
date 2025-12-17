<?php
/**
 * OpenRegister Chat Conversation Management Handler
 *
 * Handler for conversation lifecycle management.
 * Manages conversation titles, summaries, and history management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */


namespace OCA\OpenRegister\Service\Chat;

use DateTime;
use Exception;
use ReflectionClass;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Chat\OllamaChat;
use LLPhant\OpenAIConfig;
use LLPhant\OllamaConfig;

/**
 * ConversationManagementHandler
 *
 * Handles conversation lifecycle including title generation, summarization,
 * and conversation history management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

class ConversationManagementHandler
{

    /**
     * Maximum tokens before triggering summarization
     *
     * @var int
     */
    private const MAX_TOKENS_BEFORE_SUMMARY = 4000;

    /**
     * Number of recent messages to keep when summarizing
     *
     * @var int
     */
    private const RECENT_MESSAGES_COUNT = 10;

    /**
     * Conversation mapper
     *
     * @var ConversationMapper
     */
    private ConversationMapper $conversationMapper;

    /**
     * Message mapper
     *
     * @var MessageMapper
     */
    private MessageMapper $messageMapper;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Response generation handler
     *
     * @var ResponseGenerationHandler
     */
    private ResponseGenerationHandler $responseHandler;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ConversationMapper        $conversationMapper Conversation mapper.
     * @param MessageMapper             $messageMapper      Message mapper.
     * @param SettingsService           $settingsService    Settings service.
     * @param ResponseGenerationHandler $responseHandler    Response handler for API calls.
     * @param LoggerInterface           $logger             Logger.
     *
     * @return void
     */
    public function __construct(
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        SettingsService $settingsService,
        ResponseGenerationHandler $responseHandler,
        LoggerInterface $logger
    ) {
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper      = $messageMapper;
        $this->settingsService    = $settingsService;
        $this->responseHandler    = $responseHandler;
        $this->logger = $logger;

    }//end __construct()

    /**
     * Generate a conversation title from the first user message
     *
     * Uses configured LLM to generate a descriptive title.
     * Falls back to extracting first 60 characters if LLM fails.
     *
     * @param string $firstMessage First user message.
     *
     * @return string Generated title
     */
    public function generateConversationTitle(string $firstMessage): string
    {
        $this->logger->info(
            message:'[ChatService] Generating conversation title'
        );

        try {
            // Get LLM configuration.
            $llmConfig    = $this->settingsService->getLLMSettingsOnly();
            $chatProvider = $llmConfig['chatProvider'] ?? null;

            // Try to use configured LLM, fallback if not available.
            if (empty($chatProvider) === true) {
                return $this->generateFallbackTitle($firstMessage);
            }

            // Configure LLM based on provider.
            // Ollama uses its own native config.
            if ($chatProvider === 'ollama') {
                $ollamaConfig = $llmConfig['ollamaConfig'] ?? [];
                if (empty($ollamaConfig['url']) === true) {
                    return $this->generateFallbackTitle($firstMessage);
                }

                // Use native Ollama configuration.
                $config        = new OllamaConfig();
                $config->url   = rtrim($ollamaConfig['url'], '/').'/api/';
                $config->model = $ollamaConfig['chatModel'] ?? 'llama2';
                $config->modelOptions['temperature'] = 0.7;
            } else {
                // OpenAI and Fireworks use OpenAIConfig.
                $config = new OpenAIConfig();

                if ($chatProvider === 'openai') {
                    $openaiConfig = $llmConfig['openaiConfig'] ?? [];
                    if (empty($openaiConfig['apiKey']) === true) {
                        return $this->generateFallbackTitle($firstMessage);
                    }

                    $config->apiKey = $openaiConfig['apiKey'];
                    $config->model  = 'gpt-4o-mini';
                    // Use fast model for titles.
                } else if ($chatProvider === 'fireworks') {
                    $fireworksConfig = $llmConfig['fireworksConfig'] ?? [];
                    if (empty($fireworksConfig['apiKey']) === true) {
                        return $this->generateFallbackTitle($firstMessage);
                    }

                    $config->apiKey = $fireworksConfig['apiKey'];
                    $config->model  = 'accounts/fireworks/models/llama-v3p1-8b-instruct';
                    $baseUrl        = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
                    if (str_ends_with($baseUrl, '/v1') === false) {
                        $baseUrl .= '/v1';
                    }

                    $config->url = $baseUrl;
                } else {
                    return $this->generateFallbackTitle($firstMessage);
                }//end if

                /*
                 * @psalm-suppress UndefinedPropertyAssignment - LLPhant\OpenAIConfig has dynamic properties
                 */

                $config->temperature = 0.7;
            }//end if

            // Generate title.
            $prompt  = "Generate a short, descriptive title (max 60 characters) for a conversation that starts with this message:\n\n";
            $prompt .= "\"{$firstMessage}\"\n\n";
            $prompt .= "Title:";

            // Generate title based on provider.
            if ($chatProvider === 'fireworks') {
                // Use ResponseGenerationHandler's Fireworks method.
                $reflectionClass = new ReflectionClass($this->responseHandler);
                $method          = $reflectionClass->getMethod('callFireworksChatAPI');
                $method->setAccessible(true);

                /*
                 * @psalm-suppress UndefinedPropertyFetch - LLPhant\OllamaConfig has dynamic properties
                 */

                $title = $method->invoke(
                    $this->responseHandler,
                    $config->apiKey,
                    $config->model,
                    $config->url,
                    $prompt
                );
            } else if ($chatProvider === 'ollama') {
                // Use native Ollama chat.
                $chat  = new OllamaChat($config);
                $title = $chat->generateText($prompt);
            } else {
                // OpenAI chat.
                $chat  = new OpenAIChat($config);
                $title = $chat->generateText($prompt);
            }//end if

            $title = trim($title, '"\'');

            // Ensure title isn't too long.
            if (strlen($title) > 60) {
                $title = substr($title, 0, 57).'...';
            }

            return $title;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[ChatService] Failed to generate title, using fallback',
                context: [
                    'error' => $e->getMessage(),
                ]
            );

            return $this->generateFallbackTitle($firstMessage);
        }//end try

    }//end generateConversationTitle()

    /**
     * Generate fallback title from message
     *
     * Extracts first 60 characters from message as title.
     *
     * @param string $message Message text.
     *
     * @return string Fallback title
     */
    private function generateFallbackTitle(string $message): string
    {
        // Take first 60 characters.
        $title = substr($message, 0, 60);

        // If we cut off mid-word, go back to last space.
        if (strlen($message) > 60) {
            $lastSpace = strrpos($title, ' ');
            if ($lastSpace !== false && $lastSpace > 30) {
                $title = substr($title, 0, $lastSpace);
            }

            $title .= '...';
        }

        return $title;

    }//end generateFallbackTitle()

    /**
     * Ensure conversation title is unique for user-agent combination
     *
     * If a conversation with the same title already exists for this user and agent,
     * appends a number (e.g., "Title (2)", "Title (3)") to make it unique.
     *
     * @param string $baseTitle Base title to check.
     * @param string $userId    User ID.
     * @param int    $agentId   Agent ID.
     *
     * @return string Unique title with number suffix if needed
     */
    public function ensureUniqueTitle(string $baseTitle, string $userId, int $agentId): string
    {
        $this->logger->info(
            message: '[ChatService] Ensuring unique title',
            context: [
                'baseTitle' => $baseTitle,
                'userId'    => $userId,
                'agentId'   => $agentId,
            ]
        );

        // Find all existing titles that match this pattern.
        // Using LIKE with % to catch both exact matches and numbered variants.
        $pattern        = $baseTitle.'%';
        $existingTitles = $this->conversationMapper->findTitlesByUserAgent(userId: $userId, agentId: $agentId, titlePattern: $pattern);

        // If no matches, the base title is unique.
        if (empty($existingTitles) === true) {
            return $baseTitle;
        }

        // Check if base title exists.
        if (in_array($baseTitle, $existingTitles, true) === false) {
            return $baseTitle;
        }

        // Find the highest number suffix.
        $maxNumber        = 1;
        $baseTitleEscaped = preg_quote($baseTitle, '/');

        foreach ($existingTitles as $title) {
            // Match "Title (N)" pattern.
            if (preg_match('/^'.$baseTitleEscaped.' \((\d+)\)$/', $title, $matches) === 1) {
                $number = (int) $matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }

        // Generate new title with next number.
        $uniqueTitle = $baseTitle.' ('.($maxNumber + 1).')';

        $this->logger->info(
            message: '[ChatService] Generated unique title',
            context: [
                'baseTitle'   => $baseTitle,
                'uniqueTitle' => $uniqueTitle,
                'foundTitles' => count($existingTitles),
            ]
        );

        return $uniqueTitle;

    }//end ensureUniqueTitle()

    /**
     * Check if conversation needs summarization and create summary
     *
     * Triggers summarization when token count exceeds threshold.
     *
     * @param Conversation $conversation Conversation entity.
     *
     * @return void
     */
    public function checkAndSummarize(Conversation $conversation): void
    {
        // Get metadata.
        $metadata   = $conversation->getMetadata() ?? [];
        $tokenCount = $metadata['token_count'] ?? 0;

        // Check if we need to summarize.
        if ($tokenCount < self::MAX_TOKENS_BEFORE_SUMMARY) {
            return;
        }

        // Check if we recently summarized.
        $lastSummary = $metadata['last_summary_at'] ?? null;
        if ($lastSummary !== null) {
            $lastSummaryTime       = new DateTime($lastSummary);
            $hoursSinceLastSummary = (time() - $lastSummaryTime->getTimestamp()) / 3600;

            // Don't summarize more than once per hour.
            if ($hoursSinceLastSummary < 1) {
                return;
            }
        }

        $this->logger->info(
            message: '[ChatService] Triggering conversation summarization',
            context: [
                'conversationId' => $conversation->getId(),
                'tokenCount'     => $tokenCount,
            ]
        );

        try {
            // Get all messages except recent ones.
            $allMessages         = $this->messageMapper->findByConversation($conversation->getId());
            $messagesToSummarize = array_slice($allMessages, 0, -self::RECENT_MESSAGES_COUNT);

            if (empty($messagesToSummarize) === true) {
                return;
            }

            // Generate summary.
            $summary = $this->generateSummary($messagesToSummarize);

            // Update metadata.
            $metadata['summary']         = $summary;
            $metadata['last_summary_at'] = (new DateTime())->format('c');
            $metadata['summarized_messages'] = count($messagesToSummarize);

            $conversation->setMetadata($metadata);
            $conversation->setUpdated(new DateTime());
            $this->conversationMapper->update($conversation);

            $this->logger->info(
                message: '[ChatService] Conversation summarized',
                context: [
                    'conversationId' => $conversation->getId(),
                    'summaryLength'  => strlen($summary),
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ChatService] Failed to summarize conversation',
                context: [
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

    }//end checkAndSummarize()

    /**
     * Generate summary of messages
     *
     * Uses configured LLM to generate a concise summary of conversation messages.
     *
     * @param array $messages Array of Message entities.
     *
     * @return string Summary text
     *
     * @throws \Exception If summary generation fails
     */
    private function generateSummary(array $messages): string
    {
        // Get LLM configuration.
        $llmConfig    = $this->settingsService->getLLMSettingsOnly();
        $chatProvider = $llmConfig['chatProvider'] ?? null;

        if (empty($chatProvider) === true) {
            throw new Exception('Chat provider not configured');
        }

        // Build conversation text.
        $conversationText = '';
        foreach ($messages as $message) {
            if ($message->getRole() === Message::ROLE_USER) {
                $role = 'User';
            } else {
                $role = 'Assistant';
            }

            $conversationText .= "{$role}: {$message->getContent()}\n\n";
        }

        // Configure LLM based on provider.
        // Ollama uses its own native config.
        if ($chatProvider === 'ollama') {
            $ollamaConfig = $llmConfig['ollamaConfig'] ?? [];
            if (empty($ollamaConfig['url']) === true) {
                throw new Exception('Ollama URL not configured');
            }

            // Use native Ollama configuration.
            $config        = new OllamaConfig();
            $config->url   = rtrim($ollamaConfig['url'], '/').'/api/';
            $config->model = $ollamaConfig['chatModel'] ?? 'llama2';
        } else {
            // OpenAI and Fireworks use OpenAIConfig.
            $config = new OpenAIConfig();

            if ($chatProvider === 'openai') {
                $openaiConfig = $llmConfig['openaiConfig'] ?? [];
                if (empty($openaiConfig['apiKey']) === true) {
                    throw new Exception('OpenAI API key not configured');
                }

                $config->apiKey = $openaiConfig['apiKey'];
                $config->model  = 'gpt-4o-mini';
            } else if ($chatProvider === 'fireworks') {
                $fireworksConfig = $llmConfig['fireworksConfig'] ?? [];
                if (empty($fireworksConfig['apiKey']) === true) {
                    throw new Exception('Fireworks AI API key not configured');
                }

                $config->apiKey = $fireworksConfig['apiKey'];
                $config->model  = 'accounts/fireworks/models/llama-v3p1-8b-instruct';
                $baseUrl        = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
                if (str_ends_with($baseUrl, '/v1') === false) {
                    $baseUrl .= '/v1';
                }

                $config->url = $baseUrl;
            }//end if
        }//end if

        // Generate summary.
        $prompt  = "Summarize the following conversation concisely. Focus on key topics, decisions, and information discussed:\n\n";
        $prompt .= $conversationText;
        $prompt .= "\n\nSummary:";

        // Generate summary based on provider.
        if ($chatProvider === 'fireworks') {
            // Use ResponseGenerationHandler's Fireworks method via reflection.
            $reflectionClass = new ReflectionClass($this->responseHandler);
            $method          = $reflectionClass->getMethod('callFireworksChatAPI');
            $method->setAccessible(true);

            /*
             * @psalm-suppress UndefinedPropertyFetch - LLPhant\OllamaConfig has dynamic properties
             */

            return $method->invoke(
                $this->responseHandler,
                $config->apiKey,
                $config->model,
                $config->url,
                $prompt
            );
        } else if ($chatProvider === 'ollama') {
            // Use native Ollama chat.
            $chat = new OllamaChat($config);
            return $chat->generateText($prompt);
        } else {
            // OpenAI chat.
            $chat = new OpenAIChat($config);
            return $chat->generateText($prompt);
        }//end if

    }//end generateSummary()
}//end class
