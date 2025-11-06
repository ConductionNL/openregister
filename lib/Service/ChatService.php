<?php
/**
 * OpenRegister Chat Service
 *
 * Service for managing AI chat conversations with RAG (Retrieval Augmented Generation).
 * Supports conversation management, agent-based context retrieval, and automatic features.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Service\VectorEmbeddingService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\OpenAIConfig;
use DateTime;
use Symfony\Component\Uid\Uuid;

/**
 * ChatService
 *
 * Service for managing AI chat conversations with RAG capabilities.
 * Handles conversation creation, message processing, context retrieval,
 * automatic title generation, and conversation summarization.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class ChatService
{
    /**
     * Maximum tokens before triggering summarization
     */
    private const MAX_TOKENS_BEFORE_SUMMARY = 3000;

    /**
     * Number of recent messages to keep in full context
     */
    private const RECENT_MESSAGES_COUNT = 10;

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

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
     * Agent mapper
     *
     * @var AgentMapper
     */
    private AgentMapper $agentMapper;

    /**
     * Vector embedding service
     *
     * @var VectorEmbeddingService
     */
    private VectorEmbeddingService $vectorService;

    /**
     * SOLR service
     *
     * @var GuzzleSolrService
     */
    private GuzzleSolrService $solrService;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param IDBConnection          $db                  Database connection
     * @param ConversationMapper     $conversationMapper  Conversation mapper
     * @param MessageMapper          $messageMapper       Message mapper
     * @param AgentMapper            $agentMapper         Agent mapper
     * @param VectorEmbeddingService $vectorService       Vector embedding service
     * @param GuzzleSolrService      $solrService         SOLR service
     * @param SettingsService        $settingsService     Settings service
     * @param LoggerInterface        $logger              Logger
     */
    public function __construct(
        IDBConnection $db,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        AgentMapper $agentMapper,
        VectorEmbeddingService $vectorService,
        GuzzleSolrService $solrService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper = $messageMapper;
        $this->agentMapper = $agentMapper;
        $this->vectorService = $vectorService;
        $this->solrService = $solrService;
        $this->settingsService = $settingsService;
        $this->logger = $logger;

    }//end __construct()


    /**
     * Process a chat message within a conversation
     *
     * @param int    $conversationId Conversation ID
     * @param string $userId         User ID
     * @param string $userMessage    User message
     *
     * @return array Response data with 'message', 'sources', 'title'
     *
     * @throws \Exception If processing fails
     */
    public function processMessage(int $conversationId, string $userId, string $userMessage): array
    {
        $this->logger->info('[ChatService] Processing message', [
            'conversationId' => $conversationId,
            'userId' => $userId,
            'messageLength' => strlen($userMessage),
        ]);

        try {
            // Get conversation
            $conversation = $this->conversationMapper->find($conversationId);

            // Verify ownership
            if ($conversation->getUserId() !== $userId) {
                throw new \Exception('Access denied to conversation');
            }

            // Get agent if configured
            $agent = null;
            if ($conversation->getAgentId() !== null) {
                $agent = $this->agentMapper->find($conversation->getAgentId());
            }

            // Store user message
            $userMsgEntity = $this->storeMessage(
                $conversationId,
                Message::ROLE_USER,
                $userMessage
            );

            // Check if we need to summarize (token limit reached)
            $this->checkAndSummarize($conversation);

            // Retrieve context using agent settings
            $context = $this->retrieveContext($userMessage, $agent);

            // Get recent conversation history for context
            $messageHistory = $this->buildMessageHistory($conversationId);

            // Generate response using LLM
            $aiResponse = $this->generateResponse(
                $userMessage,
                $context,
                $messageHistory,
                $agent
            );

            // Store AI response
            $aiMsgEntity = $this->storeMessage(
                $conversationId,
                Message::ROLE_ASSISTANT,
                $aiResponse,
                $context['sources']
            );

            // Generate title if this is the first user message
            $messageCount = $this->messageMapper->countByConversation($conversationId);
            if ($messageCount <= 2 && $conversation->getTitle() === null) {
                $title = $this->generateConversationTitle($userMessage);
                $uniqueTitle = $this->ensureUniqueTitle($title, $conversation->getUserId(), $conversation->getAgentId());
                $conversation->setTitle($uniqueTitle);
                $conversation->setUpdated(new DateTime());
                $this->conversationMapper->update($conversation);
            }

            // Update conversation timestamp
            $conversation->setUpdated(new DateTime());
            $this->conversationMapper->update($conversation);

            return [
                'message' => $aiMsgEntity->jsonSerialize(),
                'sources' => $context['sources'],
                'title' => $conversation->getTitle(),
            ];

        } catch (\Exception $e) {
            $this->logger->error('[ChatService] Failed to process message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }//end try

    }//end processMessage()


    /**
     * Retrieve relevant context for a query using agent settings
     *
     * @param string     $query User query
     * @param Agent|null $agent Optional agent with search configuration
     *
     * @return array Context data with 'text' and 'sources'
     */
    private function retrieveContext(string $query, ?Agent $agent): array
    {
        $this->logger->info('[ChatService] Retrieving context', [
            'query' => substr($query, 0, 100),
            'hasAgent' => $agent !== null,
        ]);

        // Get search settings from agent or use defaults
        $searchMode = $agent?->getRagSearchMode() ?? 'hybrid';
        $numSources = $agent?->getRagNumSources() ?? 5;
        $includeFiles = $agent?->getSearchFiles() ?? true;
        $includeObjects = $agent?->getSearchObjects() ?? true;

        // Get view filters if agent has views configured
        $viewFilters = [];
        if ($agent !== null && $agent->getViews() !== null && !empty($agent->getViews())) {
            $viewFilters = $agent->getViews();
            $this->logger->info('[ChatService] Using view-based filtering', [
                'views' => $viewFilters,
            ]);
        }

        $sources = [];
        $contextText = '';

        try {
            // Determine search method
            if ($searchMode === 'semantic') {
                $results = $this->vectorService->semanticSearch(
                    $query,
                    $numSources * 2,
                    0.7
                );
            } elseif ($searchMode === 'hybrid') {
                $results = $this->vectorService->hybridSearch(
                    $query,
                    [], // Empty array for solr filters
                    $numSources * 2 // Limit parameter
                );
            } else {
                // Keyword search
                $results = $this->searchKeywordOnly($query, $numSources * 2);
            }

            // Filter and build context
            foreach ($results as $result) {
                $isFile = ($result['entity_type'] ?? '') === 'file';
                $isObject = ($result['entity_type'] ?? '') === 'object';

                // Check type filters
                if (($isFile && !$includeFiles) || ($isObject && !$includeObjects)) {
                    continue;
                }

                // TODO: Apply view filters here when view filtering is implemented
                // For now, we'll skip view filtering and implement it later

                // Stop if we have enough sources
                if (count($sources) >= $numSources) {
                    break;
                }

                // Extract source information
                $source = [
                    'id' => $result['entity_id'] ?? null,
                    'type' => $result['entity_type'] ?? 'unknown',
                    'name' => $this->extractSourceName($result),
                    'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
                    'text' => $result['chunk_text'] ?? $result['text'] ?? '',
                ];

                $sources[] = $source;

                // Add to context text
                $contextText .= "Source: {$source['name']}\n";
                $contextText .= "{$source['text']}\n\n";
            }

            $this->logger->info('[ChatService] Context retrieved', [
                'numSources' => count($sources),
                'contextLength' => strlen($contextText),
            ]);

            return [
                'text' => $contextText,
                'sources' => $sources,
            ];

        } catch (\Exception $e) {
            $this->logger->error('[ChatService] Failed to retrieve context', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'text' => '',
                'sources' => [],
            ];
        }//end try

    }//end retrieveContext()


    /**
     * Search using keyword only (SOLR)
     *
     * @param string $query Query text
     * @param int    $limit Result limit
     *
     * @return array Search results
     */
    private function searchKeywordOnly(string $query, int $limit): array
    {
        $results = $this->solrService->searchObjectsPaginated(
            $query,
            0,
            $limit,
            [],
            'score desc',
            null,
            null,
            true,
            false
        );

        $transformed = [];
        foreach ($results['results'] ?? [] as $result) {
            $transformed[] = [
                'entity_id' => $result['id'] ?? null,
                'entity_type' => 'object',
                'text' => $result['_source']['data'] ?? json_encode($result),
                'score' => $result['_score'] ?? 1.0,
            ];
        }

        return $transformed;

    }//end searchKeywordOnly()


    /**
     * Extract a human-readable name from search result
     *
     * @param array $result Search result
     *
     * @return string Source name
     */
    private function extractSourceName(array $result): string
    {
        if (!empty($result['title'])) {
            return $result['title'];
        }
        if (!empty($result['name'])) {
            return $result['name'];
        }
        if (!empty($result['filename'])) {
            return $result['filename'];
        }
        if (!empty($result['entity_id'])) {
            return ($result['entity_type'] ?? 'Item') . ' #' . $result['entity_id'];
        }
        
        return 'Unknown Source';

    }//end extractSourceName()


    /**
     * Build message history for conversation context
     *
     * @param int $conversationId Conversation ID
     *
     * @return array Array of LLPhant Message objects
     */
    private function buildMessageHistory(int $conversationId): array
    {
        // Get recent messages
        $messages = $this->messageMapper->findRecentByConversation(
            $conversationId,
            self::RECENT_MESSAGES_COUNT
        );

        $history = [];
        foreach ($messages as $message) {
            $history[] = new LLPhantMessage(
                $message->getContent(),
                $message->getRole()
            );
        }

        return $history;

    }//end buildMessageHistory()


    /**
     * Generate AI response using LLM
     *
     * @param string     $userMessage    User message
     * @param array      $context        Context data
     * @param array      $messageHistory Message history
     * @param Agent|null $agent          Optional agent
     *
     * @return string Generated response
     *
     * @throws \Exception If generation fails
     */
    private function generateResponse(
        string $userMessage,
        array $context,
        array $messageHistory,
        ?Agent $agent
    ): string {
        $this->logger->info('[ChatService] Generating response', [
            'messageLength' => strlen($userMessage),
            'contextLength' => strlen($context['text']),
            'historyCount' => count($messageHistory),
        ]);

        // Get LLM configuration
        $settings = $this->settingsService->getSettings();
        $llmConfig = $settings['llm'] ?? [];

        // Check configuration
        if (empty($llmConfig['chat_provider']) || $llmConfig['chat_provider'] !== 'openai') {
            throw new \Exception('OpenAI chat provider is not configured');
        }

        if (empty($llmConfig['openai_api_key'])) {
            throw new \Exception('OpenAI API key is not configured');
        }

        try {
            // Configure OpenAI
            $config = new OpenAIConfig();
            $config->apiKey = $llmConfig['openai_api_key'];
            $config->model = $agent?->getModel() ?? $llmConfig['chat_model'] ?? 'gpt-4o-mini';

            if ($agent?->getTemperature() !== null) {
                $config->temperature = $agent->getTemperature();
            }

            // Create chat instance
            $chat = new OpenAIChat($config);

            // Build system prompt
            $systemPrompt = $agent?->getPrompt() ?? 
                "You are a helpful AI assistant that helps users find and understand information in their data.";

            if (!empty($context['text'])) {
                $systemPrompt .= "\n\nUse the following context to answer the user's question:\n\n";
                $systemPrompt .= "CONTEXT:\n" . $context['text'] . "\n\n";
                $systemPrompt .= "If the context doesn't contain relevant information, say so honestly. ";
                $systemPrompt .= "Always cite which sources you used when answering.";
            }

            // Add system message to history
            array_unshift($messageHistory, new LLPhantMessage($systemPrompt, 'system'));

            // Add current user message
            $messageHistory[] = new LLPhantMessage($userMessage, 'user');

            // Generate response
            $response = $chat->generateText($messageHistory);

            $this->logger->info('[ChatService] Response generated', [
                'responseLength' => strlen($response),
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('[ChatService] Failed to generate response', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to generate response: ' . $e->getMessage());
        }//end try

    }//end generateResponse()


    /**
     * Generate a conversation title from the first user message
     *
     * @param string $firstMessage First user message
     *
     * @return string Generated title
     */
    public function generateConversationTitle(string $firstMessage): string
    {
        $this->logger->info('[ChatService] Generating conversation title');

        try {
            // Get LLM configuration
            $settings = $this->settingsService->getSettings();
            $llmConfig = $settings['llm'] ?? [];

            if (empty($llmConfig['openai_api_key'])) {
                // Fallback: use first words of message
                return $this->generateFallbackTitle($firstMessage);
            }

            // Configure OpenAI
            $config = new OpenAIConfig();
            $config->apiKey = $llmConfig['openai_api_key'];
            $config->model = 'gpt-4o-mini'; // Use fast model for titles
            $config->temperature = 0.7;

            $chat = new OpenAIChat($config);

            // Generate title
            $prompt = "Generate a short, descriptive title (max 60 characters) for a conversation that starts with this message:\n\n";
            $prompt .= "\"{$firstMessage}\"\n\n";
            $prompt .= "Title:";

            $title = $chat->generateText($prompt);
            $title = trim($title, '"\'');
            
            // Ensure title isn't too long
            if (strlen($title) > 60) {
                $title = substr($title, 0, 57) . '...';
            }

            return $title;

        } catch (\Exception $e) {
            $this->logger->warning('[ChatService] Failed to generate title, using fallback', [
                'error' => $e->getMessage(),
            ]);
            
            return $this->generateFallbackTitle($firstMessage);
        }//end try

    }//end generateConversationTitle()


    /**
     * Generate fallback title from message
     *
     * @param string $message Message text
     *
     * @return string Fallback title
     */
    private function generateFallbackTitle(string $message): string
    {
        // Take first 60 characters
        $title = substr($message, 0, 60);
        
        // If we cut off mid-word, go back to last space
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
     * @param string $baseTitle Base title to check
     * @param string $userId    User ID
     * @param int    $agentId   Agent ID
     *
     * @return string Unique title with number suffix if needed
     */
    private function ensureUniqueTitle(string $baseTitle, string $userId, int $agentId): string
    {
        $this->logger->info('[ChatService] Ensuring unique title', [
            'baseTitle' => $baseTitle,
            'userId' => $userId,
            'agentId' => $agentId,
        ]);

        // Find all existing titles that match this pattern
        // Using LIKE with % to catch both exact matches and numbered variants
        $pattern = $baseTitle . '%';
        $existingTitles = $this->conversationMapper->findTitlesByUserAgent($userId, $agentId, $pattern);

        // If no matches, the base title is unique
        if (empty($existingTitles)) {
            return $baseTitle;
        }

        // Check if base title exists
        if (!in_array($baseTitle, $existingTitles)) {
            return $baseTitle;
        }

        // Find the highest number suffix
        $maxNumber = 1;
        $baseTitleEscaped = preg_quote($baseTitle, '/');
        
        foreach ($existingTitles as $title) {
            // Match "Title (N)" pattern
            if (preg_match('/^' . $baseTitleEscaped . ' \((\d+)\)$/', $title, $matches)) {
                $number = (int) $matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }

        // Generate new title with next number
        $uniqueTitle = $baseTitle . ' (' . ($maxNumber + 1) . ')';

        $this->logger->info('[ChatService] Generated unique title', [
            'baseTitle' => $baseTitle,
            'uniqueTitle' => $uniqueTitle,
            'foundTitles' => count($existingTitles),
        ]);

        return $uniqueTitle;

    }//end ensureUniqueTitle()


    /**
     * Check if conversation needs summarization and create summary
     *
     * @param Conversation $conversation Conversation entity
     *
     * @return void
     */
    private function checkAndSummarize(Conversation $conversation): void
    {
        // Get metadata
        $metadata = $conversation->getMetadata() ?? [];
        $tokenCount = $metadata['token_count'] ?? 0;

        // Check if we need to summarize
        if ($tokenCount < self::MAX_TOKENS_BEFORE_SUMMARY) {
            return;
        }

        // Check if we recently summarized
        $lastSummary = $metadata['last_summary_at'] ?? null;
        if ($lastSummary !== null) {
            $lastSummaryTime = new DateTime($lastSummary);
            $hoursSinceLastSummary = (time() - $lastSummaryTime->getTimestamp()) / 3600;
            
            // Don't summarize more than once per hour
            if ($hoursSinceLastSummary < 1) {
                return;
            }
        }

        $this->logger->info('[ChatService] Triggering conversation summarization', [
            'conversationId' => $conversation->getId(),
            'tokenCount' => $tokenCount,
        ]);

        try {
            // Get all messages except recent ones
            $allMessages = $this->messageMapper->findByConversation($conversation->getId());
            $messagesToSummarize = array_slice($allMessages, 0, -self::RECENT_MESSAGES_COUNT);

            if (empty($messagesToSummarize)) {
                return;
            }

            // Generate summary
            $summary = $this->generateSummary($messagesToSummarize);

            // Update metadata
            $metadata['summary'] = $summary;
            $metadata['last_summary_at'] = (new DateTime())->format('c');
            $metadata['summarized_messages'] = count($messagesToSummarize);
            
            $conversation->setMetadata($metadata);
            $conversation->setUpdated(new DateTime());
            $this->conversationMapper->update($conversation);

            $this->logger->info('[ChatService] Conversation summarized', [
                'conversationId' => $conversation->getId(),
                'summaryLength' => strlen($summary),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[ChatService] Failed to summarize conversation', [
                'error' => $e->getMessage(),
            ]);
        }//end try

    }//end checkAndSummarize()


    /**
     * Generate summary of messages
     *
     * @param array $messages Array of Message entities
     *
     * @return string Summary text
     *
     * @throws \Exception If summary generation fails
     */
    private function generateSummary(array $messages): string
    {
        // Get LLM configuration
        $settings = $this->settingsService->getSettings();
        $llmConfig = $settings['llm'] ?? [];

        if (empty($llmConfig['openai_api_key'])) {
            throw new \Exception('OpenAI API key not configured');
        }

        // Build conversation text
        $conversationText = '';
        foreach ($messages as $message) {
            $role = $message->getRole() === Message::ROLE_USER ? 'User' : 'Assistant';
            $conversationText .= "{$role}: {$message->getContent()}\n\n";
        }

        // Configure OpenAI
        $config = new OpenAIConfig();
        $config->apiKey = $llmConfig['openai_api_key'];
        $config->model = 'gpt-4o-mini';

        $chat = new OpenAIChat($config);

        // Generate summary
        $prompt = "Summarize the following conversation concisely. Focus on key topics, decisions, and information discussed:\n\n";
        $prompt .= $conversationText;
        $prompt .= "\n\nSummary:";

        return $chat->generateText($prompt);

    }//end generateSummary()


    /**
     * Store a message in the database
     *
     * @param int         $conversationId Conversation ID
     * @param string      $role           Message role (user or assistant)
     * @param string      $content        Message content
     * @param array|null  $sources        Optional RAG sources
     *
     * @return Message Stored message entity
     */
    private function storeMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $sources = null
    ): Message {
        $message = new Message();
        $message->setUuid(Uuid::v4()->toRfc4122());
        $message->setConversationId($conversationId);
        $message->setRole($role);
        $message->setContent($content);
        $message->setSources($sources);
        $message->setCreated(new DateTime());

        return $this->messageMapper->insert($message);

    }//end storeMessage()


}//end class

