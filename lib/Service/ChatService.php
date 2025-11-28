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
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\OpenAIChat;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\Message as LLPhantMessage;
use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;
use LLPhant\OpenAIConfig;
use LLPhant\OllamaConfig;
use OpenAI\Exceptions\ErrorException as OpenAIErrorException;
use DateTime;
use Symfony\Component\Uid\Uuid;

/**
 * ChatService
 *
 * Service for managing AI chat conversations with RAG (Retrieval Augmented Generation).
 * This service handles ALL LLM chat operations and business logic.
 *
 * RESPONSIBILITIES:
 * - Process chat messages with RAG context retrieval
 * - Generate AI responses using configured LLM providers
 * - Manage conversation history and summarization
 * - Generate conversation titles automatically
 * - Test LLM chat configurations without saving settings
 * - Handle agent-based context retrieval and filtering
 *
 * PROVIDER SUPPORT:
 * - OpenAI: GPT-4, GPT-4o-mini, and other chat models
 * - Fireworks AI: Llama, Mistral, and other open models
 * - Ollama: Local LLM deployments
 *
 * ARCHITECTURE:
 * - Uses LLPhant library for LLM interactions
 * - Integrates with VectorEmbeddingService for semantic search
 * - Can use GuzzleSolrService for keyword search (optional)
 * - Independent chat logic, not tied to SOLR infrastructure
 *
 * RAG CAPABILITIES:
 * - Semantic search using VectorEmbeddingService
 * - Keyword search using GuzzleSolrService (optional)
 * - Hybrid search combining both approaches
 * - Agent-based filtering and context retrieval
 * - View-based filtering for multi-tenancy
 *
 * CONVERSATION MANAGEMENT:
 * - Automatic title generation from first message
 * - Conversation summarization when token limit reached
 * - Message history management with context windows
 * - Source tracking for RAG responses
 *
 * INTEGRATION POINTS:
 * - VectorEmbeddingService: For semantic search in RAG
 * - GuzzleSolrService: For keyword search (optional)
 * - SettingsService: For reading LLM configuration
 * - SettingsController: Delegates testing to this service
 *
 * NOTE: This service handles all LLM chat business logic. Controllers should
 * delegate to this service rather than implementing chat logic themselves.
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
     * Tool registry
     *
     * @var ToolRegistry
     */
    private ToolRegistry $toolRegistry;


    /**
     * Constructor
     *
     * @param IDBConnection          $db                 Database connection
     * @param ConversationMapper     $conversationMapper Conversation mapper
     * @param MessageMapper          $messageMapper      Message mapper
     * @param AgentMapper            $agentMapper        Agent mapper
     * @param VectorEmbeddingService $vectorService      Vector embedding service
     * @param GuzzleSolrService      $solrService        SOLR service
     * @param SettingsService        $settingsService    Settings service
     * @param LoggerInterface        $logger             Logger
     * @param RegisterTool           $registerTool       Register tool for function calling (legacy)
     * @param SchemaTool             $schemaTool         Schema tool for function calling (legacy)
     * @param ObjectsTool            $objectsTool        Objects tool for function calling (legacy)
     * @param ToolRegistry           $toolRegistry       Tool registry for dynamic tool loading
     */
    public function __construct(
        IDBConnection $db,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        AgentMapper $agentMapper,
        VectorEmbeddingService $vectorService,
        GuzzleSolrService $solrService,
        SettingsService $settingsService,
        LoggerInterface $logger,
        RegisterTool $registerTool,
        SchemaTool $schemaTool,
        ObjectsTool $objectsTool,
        ToolRegistry $toolRegistry
    ) {
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper      = $messageMapper;
        $this->agentMapper        = $agentMapper;
        $this->vectorService      = $vectorService;
        $this->solrService        = $solrService;
        $this->settingsService    = $settingsService;
        $this->logger       = $logger;
        $this->toolRegistry = $toolRegistry;

    }//end __construct()


    /**
     * Process a chat message within a conversation
     *
     * @param int    $conversationId Conversation ID
     * @param string $userId         User ID
     * @param string $userMessage    User message
     * @param array  $selectedViews  Array of view UUIDs to use (empty = use all agent views)
     * @param array  $selectedTools  Array of tool UUIDs to use (empty = use all agent tools)
     * @param array  $ragSettings    RAG configuration settings (includeObjects, includeFiles, numSources)
     *
     * @return array Response data with 'message', 'sources', 'title'
     *
     * @throws \Exception If processing fails
     */
    public function processMessage(
        int $conversationId,
        string $userId,
        string $userMessage,
        array $selectedViews=[],
        array $selectedTools=[],
        array $ragSettings=[]
    ): array {
        $this->logger->info(
                message: '[ChatService] Processing message',
                context: [
                    'conversationId' => $conversationId,
                    'userId'         => $userId,
                    'messageLength'  => strlen($userMessage),
                    'selectedViews'  => count($selectedViews),
                    'selectedTools'  => count($selectedTools),
                    'ragSettings'    => $ragSettings,
                ]
                );

        try {
            // Get conversation.
            $conversation = $this->conversationMapper->find($conversationId);

            // Verify ownership.
            if ($conversation->getUserId() !== $userId) {
                throw new \Exception('Access denied to conversation');
            }

            // Get agent if configured.
            $agent = null;
            if ($conversation->getAgentId() !== null) {
                $agent = $this->agentMapper->find($conversation->getAgentId());
            }

            // Store user message (validation only - return value not used).
            $this->storeMessage(
                conversationId: $conversationId,
                role: Message::ROLE_USER,
                content: $userMessage
            );

            // Check if we need to summarize (token limit reached).
            $this->checkAndSummarize($conversation);

            // Retrieve context using agent settings, selected views, and RAG settings.
            $contextStartTime = microtime(true);
            $context          = $this->retrieveContext(query: $userMessage, agent: $agent, selectedViews: $selectedViews, ragSettings: $ragSettings);
            $contextTime      = microtime(true) - $contextStartTime;

            // Get recent conversation history for context.
            $historyStartTime = microtime(true);
            $messageHistory   = $this->buildMessageHistory($conversationId);
            $historyTime      = microtime(true) - $historyStartTime;

            // Generate response using LLM with selected tools.
            $llmStartTime = microtime(true);
            $aiResponse   = $this->generateResponse(
                userMessage: $userMessage,
                context: $context,
                messageHistory: $messageHistory,
                agent: $agent,
                selectedTools: $selectedTools
            );
            $llmTotalTime = microtime(true) - $llmStartTime;

            // Store AI response.
            $aiMsgEntity = $this->storeMessage(
                conversationId: $conversationId,
                role: Message::ROLE_ASSISTANT,
                content: $aiResponse,
                sources: $context['sources']
            );

            // Generate title if this is the first user message and title is still default.
            $messageCount = $this->messageMapper->countByConversation($conversationId);
            $currentTitle = $conversation->getTitle();

            // Check if we should generate a new title:.
            // - Message count is 2 or less (first exchange).
            // - Title is null OR starts with "New Conversation".
            $shouldGenerateTitle = $messageCount <= 2 && (
                $currentTitle === null ||
                strpos($currentTitle, 'New Conversation') === 0
            );

            if ($shouldGenerateTitle === true) {
                $this->logger->info(
                    message: '[ChatService] Generating title for conversation',
                    context: [
                        'conversationId' => $conversationId,
                        'currentTitle'   => $currentTitle,
                        'messageCount'   => $messageCount,
                    ]
                        );

                $title = $this->generateConversationTitle($userMessage);

                // Only make title unique if we have an agentId to filter by.
                $agentId = $conversation->getAgentId();
                if ($agentId !== null) {
                    $uniqueTitle = $this->ensureUniqueTitle(baseTitle: $title, userId: $conversation->getUserId(), agentId: $agentId);
                } else {
                    // Without agent, just use the generated title.
                    $uniqueTitle = $title;
                }

                $conversation->setTitle($uniqueTitle);
                $conversation->setUpdated(new DateTime());
                $this->conversationMapper->update($conversation);

                $this->logger->info(
                    message: '[ChatService] Title generated',
                    context: [
                        'conversationId' => $conversationId,
                        'newTitle'       => $uniqueTitle,
                    ]
                        );
            }//end if

            // Update conversation timestamp.
            $conversation->setUpdated(new DateTime());
            $this->conversationMapper->update($conversation);

            // Log overall performance.
            $this->logger->info(
                    message: '[ChatService] Message processed - OVERALL PERFORMANCE',
                    context: [
                        'conversationId'  => $conversationId,
                        'timings'         => [
                            'contextRetrieval' => round($contextTime, 2).'s',
                            'historyBuilding'  => round($historyTime, 3).'s',
                            'llmGeneration'    => round($llmTotalTime, 2).'s',
                        ],
                        'contextSize'     => strlen($context['text']),
                        'historyMessages' => count($messageHistory),
                        'responseLength'  => strlen($aiResponse),
                    ]
                    );

            return [
                'message' => $aiMsgEntity->jsonSerialize(),
                // Note: sources are already included in message->sources.
                'title'   => $conversation->getTitle(),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ChatService] Failed to process message',
                    context: [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );
            throw $e;
        }//end try

    }//end processMessage()


    /**
     * Retrieve relevant context for a query using agent settings
     *
     * @param string     $query         User query
     * @param Agent|null $agent         Optional agent with search configuration
     * @param array      $selectedViews Array of view UUIDs to search (empty = all agent views)
     * @param array      $ragSettings   RAG configuration overrides
     *
     * @return array Context data with 'text' and 'sources'
     */
    private function retrieveContext(string $query, ?Agent $agent, array $selectedViews=[], array $ragSettings=[]): array
    {
        $this->logger->info(
                message: '[ChatService] Retrieving context',
                context: [
                    'query'       => substr($query, 0, 100),
                    'hasAgent'    => $agent !== null,
                    'ragSettings' => $ragSettings,
                ]
                );

        // Get search settings from agent or use defaults, then apply RAG settings overrides.
        $searchMode        = $agent?->getRagSearchMode() ?? 'hybrid';
        $numSources        = $agent?->getRagNumSources() ?? 5;
        $includeFiles      = $ragSettings['includeFiles'] ?? ($agent?->getSearchFiles() ?? true);
        $includeObjects    = $ragSettings['includeObjects'] ?? ($agent?->getSearchObjects() ?? true);
        $numSourcesFiles   = $ragSettings['numSourcesFiles'] ?? $numSources;
        $numSourcesObjects = $ragSettings['numSourcesObjects'] ?? $numSources;

        // Calculate total sources needed (will be filtered by type later).
        $totalSources = max($numSourcesFiles, $numSourcesObjects);

        // Get view filters if agent has views configured.
        /*
         */
        $viewFilters = [];
        if ($agent !== null && $agent->getViews() !== null && !empty($agent->getViews())) {
            $agentViews = $agent->getViews();

            // If selectedViews provided, filter to only those views.
            if (!empty($selectedViews)) {
                $viewFilters = array_intersect($agentViews, $selectedViews);
                $this->logger->info(
                    message: '[ChatService] Using filtered views',
                    context: [
                        'agentViews'    => count($agentViews),
                        'selectedViews' => count($selectedViews),
                        'filteredViews' => count($viewFilters),
                    ]
                        );
            } else {
                // Use all agent views.
                $viewFilters = $agentViews;
                $this->logger->info(
                    message: '[ChatService] Using all agent views',
                    context: [
                        'views' => count($viewFilters),
                    ]
                        );
            }
        } else if (!empty($selectedViews)) {
            // User selected views but agent has no views configured - use selected ones.
            $viewFilters = $selectedViews;
            $this->logger->info(
                    message: '[ChatService] Using user-selected views (agent has none)',
                    context: [
                        'views' => count($viewFilters),
                    ]
                    );
        }//end if

        $sources     = [];
        $contextText = '';

        try {
            // Build filters for vector search.
            $vectorFilters = [];

            // Filter by entity types based on agent settings.
            $entityTypes = [];
            if ($includeObjects === true) {
                $entityTypes[] = 'object';
            }

            if ($includeFiles === true) {
                $entityTypes[] = 'file';
            }

            // Only add entity_type filter if we're filtering.
            if (!empty($entityTypes) === true && count($entityTypes) < 2) {
                $vectorFilters['entity_type'] = $entityTypes;
            }

            // Determine search method - fetch more results than needed for filtering.
            $fetchLimit = $totalSources * 2;

            if ($searchMode === 'semantic') {
                $results = $this->vectorService->semanticSearch(
                    query: $query,
                    limit: $fetchLimit,
                    filters: $vectorFilters
                // Pass filters array instead of 0.7.
                );
            } else if ($searchMode === 'hybrid') {
                $hybridResponse = $this->vectorService->hybridSearch(
                    query: $query,
                    solrFilters: ['vector_filters' => $vectorFilters],
                // Pass filters in SOLR filters array.
                    limit: $fetchLimit
                // Limit parameter.
                );
                // Extract results array from hybrid search response.
                $results = $hybridResponse['results'] ?? [];
            } else {
                // Keyword search.
                $results = $this->searchKeywordOnly($query, $fetchLimit);
            }//end if

            // Ensure results is an array.
            if (!is_array($results)) {
                $this->logger->warning(
                    message: '[ChatService] Search returned non-array result',
                    context: [
                        'searchMode'  => $searchMode,
                        'resultType'  => gettype($results),
                        'resultValue' => $results,
                    ]
                        );
                $results = [];
            }

            // Filter and build context - track file and object counts separately.
            $fileSourceCount   = 0;
            $objectSourceCount = 0;

            foreach ($results as $result) {
                // Skip if result is not an array.
                if (!is_array($result)) {
                    $this->logger->warning(
                        message: '[ChatService] Skipping non-array result',
                        context: [
                            'resultType'  => gettype($result),
                            'resultValue' => $result,
                        ]
                            );
                    continue;
                }

                $isFile   = ($result['entity_type'] ?? '') === 'file';
                $isObject = ($result['entity_type'] ?? '') === 'object';

                // Check type filters.
                if (($isFile === true && $includeFiles === false) === true || ($isObject === true && $includeObjects === false) === true) {
                    continue;
                }

                // Check if we've reached the limit for this source type.
                if (($isFile === true) === true && ($fileSourceCount >= $numSourcesFiles) === true) {
                    continue;
                }

                if (($isObject === true) === true && ($objectSourceCount >= $numSourcesObjects) === true) {
                    continue;
                }

                // TODO: Apply view filters here when view filtering is implemented.
                // For now, we'll skip view filtering and implement it later.
                // Extract source information.
                $source = [
                    'id'         => $result['entity_id'] ?? null,
                    'type'       => $result['entity_type'] ?? 'unknown',
                    'name'       => $this->extractSourceName($result),
                    'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
                    'text'       => $result['chunk_text'] ?? $result['text'] ?? '',
                ];

                // Add type-specific metadata.
                $metadata = $result['metadata'] ?? [];
                if (is_string($metadata) === true) {
                    $metadata = json_decode($metadata, true) ?? [];
                }

                // For objects: add UUID, register, schema.
                if ($source['type'] === 'object') {
                    $source['uuid']     = $metadata['uuid'] ?? null;
                    $source['register'] = $metadata['register_id'] ?? $metadata['register'] ?? null;
                    $source['schema']   = $metadata['schema_id'] ?? $metadata['schema'] ?? null;
                    $source['uri']      = $metadata['uri'] ?? null;
                }

                // For files: add file_id, path.
                if ($source['type'] === 'file') {
                    $source['file_id']   = $metadata['file_id'] ?? $source['id'];
                    $source['file_path'] = $metadata['file_path'] ?? null;
                    $source['mime_type'] = $metadata['mime_type'] ?? null;
                }

                $sources[] = $source;

                // Increment the appropriate counter.
                if ($isFile === true) {
                    $fileSourceCount++;
                } else if ($isObject === true) {
                    $objectSourceCount++;
                }

                // Add to context text.
                $contextText .= "Source: {$source['name']}\n";
                $contextText .= "{$source['text']}\n\n";

                // Stop if we've reached limits for both types.
                if ((!$includeFiles || $fileSourceCount >= $numSourcesFiles)
                    && (!$includeObjects || $objectSourceCount >= $numSourcesObjects)
                ) {
                    break;
                }
            }//end foreach

            $this->logger->info(
                    message: '[ChatService] Context retrieved',
                    context: [
                        'numSources'        => count($sources),
                        'fileSources'       => $fileSourceCount,
                        'objectSources'     => $objectSourceCount,
                        'contextLength'     => strlen($contextText),
                        'searchMode'        => $searchMode,
                        'includeObjects'    => $includeObjects,
                        'includeFiles'      => $includeFiles,
                        'numSourcesFiles'   => $numSourcesFiles,
                        'numSourcesObjects' => $numSourcesObjects,
                        'rawResultsCount'   => is_array($results) === true ? count($results) : gettype($results),
                    ]
                    );

            // DEBUG: Log first source.
            if (!empty($sources)) {
                $this->logger->info(
                    message: '[ChatService] First source details',
                    context: [
                        'source' => $sources[0],
                    ]
                        );
            }

            return [
                'text'    => $contextText,
                'sources' => $sources,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ChatService] Failed to retrieve context',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return [
                'text'    => '',
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
            query: ['_search' => $query],
            rbac: true,
            multi: true,
            published: false,
            deleted: false
        );

        $transformed = [];
        foreach ($results['results'] ?? [] as $result) {
            $transformed[] = [
                'entity_id'   => $result['id'] ?? null,
                'entity_type' => 'object',
                'text'        => $result['_source']['data'] ?? json_encode($result),
                'score'       => $result['_score'] ?? 1.0,
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
        // First check top-level fields.
        if (!empty($result['title'])) {
            return $result['title'];
        }

        if (!empty($result['name'])) {
            return $result['name'];
        }

        if (!empty($result['filename'])) {
            return $result['filename'];
        }

        // Check metadata for object_title, file_name, etc.
        if (!empty($result['metadata'])) {
            $metadata = is_array($result['metadata']) === true ? $result['metadata'] : json_decode($result['metadata'], true);

            if (!empty($metadata['object_title'])) {
                return $metadata['object_title'];
            }

            if (!empty($metadata['file_name'])) {
                return $metadata['file_name'];
            }

            if (!empty($metadata['name'])) {
                return $metadata['name'];
            }

            if (!empty($metadata['title'])) {
                return $metadata['title'];
            }
        }

        // Fallback to entity ID.
        if (!empty($result['entity_id'])) {
            $type = $result['entity_type'] ?? 'Item';
            // Capitalize first letter for display.
            $type = ucfirst($type);
            return $type.' #'.substr($result['entity_id'], 0, 8);
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
        // Get recent messages.
        $messages = $this->messageMapper->findRecentByConversation(
            $conversationId,
            self::RECENT_MESSAGES_COUNT
        );

        $this->logger->debug(
                message: '[ChatService] Building message history',
                context: [
                    'conversationId' => $conversationId,
                    'messageCount'   => count($messages),
                ]
                );

        $history = [];
        foreach ($messages as $message) {
            $content = $message->getContent();
            $role    = $message->getRole();

            $this->logger->debug(
                    message: '[ChatService] Adding message to history',
                    context: [
                        'role'          => $role,
                        'contentLength' => strlen($content ?? ''),
                        'hasContent'    => !empty($content),
                        'hasRole'       => !empty($role),
                    ]
                    );

            // Only add messages that have both role and content.
            if (!empty($role) === true && !empty($content)) {
                // Use static factory methods based on role.
                if ($role === 'user') {
                    $history[] = LLPhantMessage::user($content);
                } else if ($role === 'assistant') {
                    $history[] = LLPhantMessage::assistant($content);
                } else if ($role === 'system') {
                    $history[] = LLPhantMessage::system($content);
                } else {
                    $this->logger->warning(
                        message: '[ChatService] Unknown message role',
                        context: [
                            'role' => $role,
                        ]
                        );
                }
            } else {
                $this->logger->warning(
                    message: '[ChatService] Skipping message with missing role or content',
                    context: [
                        'hasRole'    => !empty($role),
                        'hasContent' => !empty($content),
                    ]
                        );
            }//end if
        }//end foreach

        $this->logger->info(
                message: '[ChatService] Message history built',
                context: [
                    'historyCount' => count($history),
                ]
                );

        return $history;

    }//end buildMessageHistory()


    /**
     * Generate AI response using LLM
     *
     * @param string     $userMessage    User message
     * @param array      $context        Context data
     * @param array      $messageHistory Message history
     * @param Agent|null $agent          Optional agent
     * @param array      $selectedTools  Array of tool UUIDs to use (empty = all agent tools)
     *
     * @return string Generated response
     *
     * @throws \Exception If generation fails
     */
    private function generateResponse(
        string $userMessage,
        array $context,
        array $messageHistory,
        ?Agent $agent,
        array $selectedTools=[]
    ): string {
        $startTime = microtime(true);

        $this->logger->info(
                message: '[ChatService] Generating response',
                context: [
                    'messageLength' => strlen($userMessage),
                    'contextLength' => strlen($context['text']),
                    'historyCount'  => count($messageHistory),
                    'selectedTools' => count($selectedTools),
                ]
                );

        // Get enabled tools for agent, filtered by selectedTools.
        $toolsStartTime = microtime(true);
        $tools          = $this->getAgentTools($agent, $selectedTools);
        $toolsTime      = microtime(true) - $toolsStartTime;
        if (!empty($tools)) {
            $this->logger->info(
                    message: '[ChatService] Agent has tools enabled',
                    context: [
                        'toolCount' => count($tools),
                        'tools'     => array_map(fn($tool) => $tool->getName(), $tools),
                    ]
                    );
        }

        // Get LLM configuration.
        $llmConfig = $this->settingsService->getLLMSettingsOnly();

        // Get chat provider.
        $chatProvider = $llmConfig['chatProvider'] ?? null;

        if (empty($chatProvider) === true) {
            throw new \Exception('Chat provider is not configured. Please configure OpenAI, Fireworks AI, or Ollama in settings.');
        }

        $this->logger->info(
                message: '[ChatService] Using chat provider',
                context: [
                    'provider'  => $chatProvider,
                    'llmConfig' => $llmConfig,
                    'hasTools'  => !empty($tools),
                ]
                );

        try {
            // Configure LLM client based on provider.
            // Ollama uses its own native config and chat class.
            if ($chatProvider === 'ollama') {
                $ollamaConfig = $llmConfig['ollamaConfig'] ?? [];
                if (empty($ollamaConfig['url']) === true) {
                    throw new \Exception('Ollama URL is not configured');
                }

                // Use native Ollama configuration.
                $config      = new OllamaConfig();
                $config->url = rtrim($ollamaConfig['url'], '/').'/api/';
                // Use agent model if set and not empty, otherwise fallback to global config.
                $agentModel    = $agent?->getModel();
                $config->model = (!empty($agentModel)) ? $agentModel : ($ollamaConfig['chatModel'] ?? 'llama2');

                // Set temperature from agent or default.
                if ($agent?->getTemperature() !== null) {
                    $config->modelOptions['temperature'] = $agent->getTemperature();
                }
            } else {
                // OpenAI and Fireworks use OpenAIConfig.
                $config = new OpenAIConfig();

                if ($chatProvider === 'openai') {
                    $openaiConfig = $llmConfig['openaiConfig'] ?? [];
                    if (empty($openaiConfig['apiKey']) === true) {
                        throw new \Exception('OpenAI API key is not configured');
                    }

                    $config->apiKey = $openaiConfig['apiKey'];
                    // Use agent model if set and not empty, otherwise fallback to global config.
                    $agentModel    = $agent?->getModel();
                    $config->model = (!empty($agentModel)) ? $agentModel : ($openaiConfig['chatModel'] ?? 'gpt-4o-mini');

                    if (!empty($openaiConfig['organizationId'])) {
                        $config->organizationId = $openaiConfig['organizationId'];
                    }
                } else if ($chatProvider === 'fireworks') {
                    $fireworksConfig = $llmConfig['fireworksConfig'] ?? [];
                    if (empty($fireworksConfig['apiKey']) === true) {
                        throw new \Exception('Fireworks AI API key is not configured');
                    }

                    $config->apiKey = $fireworksConfig['apiKey'];
                    // Use agent model if set and not empty, otherwise fallback to global config.
                    $agentModel    = $agent?->getModel();
                    $config->model = (!empty($agentModel)) ? $agentModel : ($fireworksConfig['chatModel'] ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct');

                    // Fireworks AI uses OpenAI-compatible API.
                    $baseUrl = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
                    if (!str_ends_with($baseUrl, '/v1')) {
                        $baseUrl .= '/v1';
                    }

                    $config->url = $baseUrl;
                } else {
                    throw new \Exception("Unsupported chat provider: {$chatProvider}");
                }//end if

                // Set temperature from agent or default (OpenAI/Fireworks).
                if ($agent?->getTemperature() !== null) {
                    $config->temperature = $agent->getTemperature();
                }
            }//end if

            // Build system prompt.
            $systemPrompt = $agent?->getPrompt() ?? "You are a helpful AI assistant that helps users find and understand information in their data.";

            if (!empty($context['text'])) {
                $systemPrompt .= "\n\nUse the following context to answer the user's question:\n\n";
                $systemPrompt .= "CONTEXT:\n".$context['text']."\n\n";
                $systemPrompt .= "If the context doesn't contain relevant information, say so honestly. ";
                $systemPrompt .= "Always cite which sources you used when answering.";
            }

            // Add system message to history.
            array_unshift($messageHistory, LLPhantMessage::system($systemPrompt));

            // Add current user message.
            $messageHistory[] = LLPhantMessage::user($userMessage);

            // Convert tools to functions if agent has tools enabled.
            $functions = [];
            if (!empty($tools)) {
                $functions = $this->convertToolsToFunctions($tools);
            }

            // Create chat instance based on provider.
            if ($chatProvider === 'fireworks') {
                // For Fireworks, use direct HTTP to avoid OpenAI library error handling bugs.
                $response = $this->callFireworksChatAPIWithHistory(
                    $config->apiKey,
                    $config->model,
                    $config->url,
                    $messageHistory,
                    $functions
                // Pass functions.
                );
            } else if ($chatProvider === 'ollama') {
                // Use native Ollama chat with LLPhant's built-in tool support.
                $chat = new OllamaChat($config);

                // Add functions if available - Ollama supports tools via LLPhant!
                if (!empty($functions)) {
                    // Convert array-based function definitions to FunctionInfo objects.
                    $functionInfoObjects = $this->convertFunctionsToFunctionInfo($functions, $tools);
                    $chat->setTools($functionInfoObjects);
                }

                // Use generateChat() for message arrays.
                $llmStartTime = microtime(true);
                $response     = $chat->generateChat($messageHistory);
                $llmTime      = microtime(true) - $llmStartTime;
            } else {
                // OpenAI chat.
                $chat = new OpenAIChat($config);

                // Add functions if available.
                if (!empty($functions)) {
                    // Convert array-based function definitions to FunctionInfo objects.
                    $functionInfoObjects = $this->convertFunctionsToFunctionInfo($functions, $tools);
                    $chat->setTools($functionInfoObjects);
                }

                // Use generateChat() for message arrays, which properly handles tools/functions.
                $llmStartTime = microtime(true);
                $response     = $chat->generateChat($messageHistory);
                $llmTime      = microtime(true) - $llmStartTime;
            }//end if

            $totalTime = microtime(true) - $startTime;

            $this->logger->info(
                    message: '[ChatService] Response generated - PERFORMANCE',
                    context: [
                        'provider'       => $chatProvider,
                        'model'          => $config->model,
                        'responseLength' => strlen($response),
                        'timings'        => [
                            'total'         => round($totalTime, 2).'s',
                            'toolsLoading'  => round($toolsTime, 3).'s',
                            'llmGeneration' => round($llmTime, 2).'s',
                            'overhead'      => round($totalTime - $llmTime - $toolsTime, 3).'s',
                        ],
                    ]
                    );

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ChatService] Failed to generate response',
                    context: [
                        'provider' => $chatProvider ?? 'unknown',
                        'error'    => $e->getMessage(),
                    ]
                    );
            throw new \Exception('Failed to generate response: '.$e->getMessage());
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
                    if (!str_ends_with($baseUrl, '/v1')) {
                        $baseUrl .= '/v1';
                    }

                    $config->url = $baseUrl;
                } else {
                    return $this->generateFallbackTitle($firstMessage);
                }//end if

                $config->temperature = 0.7;
            }//end if

            // Generate title.
            $prompt  = "Generate a short, descriptive title (max 60 characters) for a conversation that starts with this message:\n\n";
            $prompt .= "\"{$firstMessage}\"\n\n";
            $prompt .= "Title:";

            // Generate title based on provider.
            if ($chatProvider === 'fireworks') {
                // Use direct HTTP for Fireworks to avoid OpenAI library issues.
                $title = $this->callFireworksChatAPI(
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
            }

            $title = trim($title, '"\'');

            // Ensure title isn't too long.
            if (strlen($title) > 60) {
                $title = substr($title, 0, 57).'...';
            }

            return $title;
        } catch (\Exception $e) {
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
     * @param string $message Message text
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
     * @param string $baseTitle Base title to check
     * @param string $userId    User ID
     * @param int    $agentId   Agent ID
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
        $existingTitles = $this->conversationMapper->findTitlesByUserAgent($userId, $agentId, $pattern);

        // If no matches, the base title is unique.
        if (empty($existingTitles) === true) {
            return $baseTitle;
        }

        // Check if base title exists.
        if (!in_array($baseTitle, $existingTitles)) {
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
     * Test chat functionality with custom configuration
     *
     * Tests if the provided chat configuration works correctly by sending
     * a test message and receiving a response. Does not save any configuration
     * or create a conversation.
     *
     * @param string $provider    Provider name ('openai', 'fireworks', 'ollama')
     * @param array  $config      Provider-specific configuration
     * @param string $testMessage Optional test message to send
     *
     * @return array Test results with success status and chat response
     */
    public function testChat(string $provider, array $config, string $testMessage='Hello! Please respond with a brief greeting.'): array
    {
        $this->logger->info(
                message: '[ChatService] Testing chat functionality',
                context: [
                    'provider'          => $provider,
                    'model'             => $config['chatModel'] ?? $config['model'] ?? 'unknown',
                    'testMessageLength' => strlen($testMessage),
                ]
                );

        try {
            // Validate provider.
            if (!in_array($provider, ['openai', 'fireworks', 'ollama'])) {
                throw new \Exception("Unsupported provider: {$provider}");
            }

            // Configure LLM client based on provider.
            // Ollama uses its own native config.
            if ($provider === 'ollama') {
                if (empty($config['url']) === true) {
                    throw new \Exception('Ollama URL is required');
                }

                // Use native Ollama configuration.
                $llphantConfig        = new OllamaConfig();
                $llphantConfig->url   = rtrim($config['url'], '/').'/api/';
                $llphantConfig->model = $config['chatModel'] ?? $config['model'] ?? 'llama2';

                // Set temperature if provided.
                if (($config['temperature'] ?? null) !== null) {
                    $llphantConfig->modelOptions['temperature'] = (float) $config['temperature'];
                }
            } else {
                // OpenAI and Fireworks use OpenAIConfig.
                $llphantConfig = new OpenAIConfig();

                if ($provider === 'openai') {
                    if (empty($config['apiKey']) === true) {
                        throw new \Exception('OpenAI API key is required');
                    }

                    $llphantConfig->apiKey = $config['apiKey'];
                    $llphantConfig->model  = $config['chatModel'] ?? $config['model'] ?? 'gpt-4o-mini';

                    if (!empty($config['organizationId'])) {
                        $llphantConfig->organizationId = $config['organizationId'];
                    }
                } else if ($provider === 'fireworks') {
                    if (empty($config['apiKey']) === true) {
                        throw new \Exception('Fireworks AI API key is required');
                    }

                    $llphantConfig->apiKey = $config['apiKey'];
                    $llphantConfig->model  = $config['chatModel'] ?? $config['model'] ?? 'accounts/fireworks/models/llama-v3p1-8b-instruct';

                    // Fireworks AI uses OpenAI-compatible API but needs specific URL format.
                    $baseUrl = rtrim($config['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
                    // Ensure the URL ends with /v1 for compatibility.
                    if (!str_ends_with($baseUrl, '/v1')) {
                        $baseUrl .= '/v1';
                    }

                    $llphantConfig->url = $baseUrl;
                }//end if

                // Set temperature if provided.
                if (($config['temperature'] ?? null) !== null) {
                    $llphantConfig->temperature = (float) $config['temperature'];
                }
            }//end if

            // Generate test response based on provider.
            if ($provider === 'fireworks') {
                // For Fireworks, use direct HTTP to avoid OpenAI library error handling bugs.
                $response = $this->callFireworksChatAPI(
                    $llphantConfig->apiKey,
                    $llphantConfig->model,
                    $llphantConfig->url,
                    $testMessage
                );
            } else if ($provider === 'ollama') {
                // Use native Ollama chat.
                $chat = new OllamaChat($llphantConfig);

                // Generate response.
                $this->logger->debug(
                    message: '[ChatService] Sending test message to Ollama',
                    context: [
                        'provider' => $provider,
                        'model'    => $llphantConfig->model,
                        /*
                         */
                        'url'      => $llphantConfig->url ?? 'default',
                    ]
                        );

                $response = $chat->generateText($testMessage);
            } else {
                // Use OpenAI chat.
                $chat = new OpenAIChat($llphantConfig);

                // Generate response.
                $this->logger->debug(
                    message: '[ChatService] Sending test message to LLM',
                    context: [
                        'provider' => $provider,
                        'model'    => $llphantConfig->model,
                        'url'      => $llphantConfig->url ?? 'default',
                    ]
                        );

                $response = $chat->generateText($testMessage);
            }//end if

            $this->logger->info(
                    message: '[ChatService] Chat test successful',
                    context: [
                        'provider'       => $provider,
                        'model'          => $llphantConfig->model,
                        'responseLength' => strlen($response),
                    ]
                    );

            return [
                'success' => true,
                'message' => 'Chat test successful',
                'data'    => [
                    'provider'       => $provider,
                    'model'          => $llphantConfig->model,
                    'testMessage'    => $testMessage,
                    'response'       => $response,
                    'responseLength' => strlen($response),
                    /*
                     */
                    'url'            => $llphantConfig->url ?? null,
                ],
            ];
        } catch (OpenAIErrorException $e) {
            // Handle OpenAI client library errors (including Fireworks AI errors).
            $errorMessage = $e->getMessage();

            // Try to extract more meaningful error from the exception.
            if (str_contains($errorMessage, 'unauthorized') === true) {
                $errorMessage = 'Authentication failed. Please check your API key.';
            } else if (str_contains($errorMessage, 'invalid_api_key') === true) {
                $errorMessage = 'Invalid API key provided.';
            } else if (str_contains($errorMessage, 'model_not_found') === true) {
                $errorMessage = 'Model not found. Please check the model name.';
            } else if (str_contains($errorMessage, 'rate_limit') === true) {
                $errorMessage = 'Rate limit exceeded. Please try again later.';
            }

            $this->logger->error(
                    message: '[ChatService] Chat test failed (OpenAI client error)',
                    context: [
                        'provider'     => $provider,
                        'error'        => $e->getMessage(),
                        'parsed_error' => $errorMessage,
                    ]
                    );

            return [
                'success' => false,
                'error'   => $errorMessage,
                'message' => 'Failed to test chat: '.$errorMessage,
                'details' => [
                    'provider'  => $provider,
                    'model'     => $llphantConfig->model ?? 'unknown',
                    'raw_error' => $e->getMessage(),
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[ChatService] Chat test failed',
                    context: [
                        'provider' => $provider,
                        'error'    => $e->getMessage(),
                        'trace'    => $e->getTraceAsString(),
                    ]
                    );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => 'Failed to test chat: '.$e->getMessage(),
            ];
        }//end try

    }//end testChat()


    /**
     * Call Fireworks AI chat API directly to avoid OpenAI library error handling bugs
     *
     * The OpenAI PHP client has issues parsing Fireworks error responses, so we
     * make direct HTTP calls for better error handling.
     *
     * @param string $apiKey  Fireworks API key
     * @param string $model   Model name
     * @param string $baseUrl Base API URL
     * @param string $message Message to send
     *
     * @return string Generated response text
     *
     * @throws \Exception If API call fails
     */
    private function callFireworksChatAPI(string $apiKey, string $model, string $baseUrl, string $message): string
    {
        $url = rtrim($baseUrl, '/').'/chat/completions';

        $this->logger->debug(
                message: '[ChatService] Calling Fireworks chat API directly',
                context: [
                    'url'   => $url,
                    'model' => $model,
                ]
                );

        $payload = [
            'model'    => $model,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => $message,
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Authorization: Bearer '.$apiKey,
                    'Content-Type: application/json',
                ]
                );
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \Exception("Fireworks API request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            // Parse error response.
            $errorData    = is_string($response) === true ? json_decode($response, true) : [];
            $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? (is_string($response) === true ? $response : 'Unknown error');

            // Make error messages user-friendly.
            if ($httpCode === 401 || $httpCode === 403) {
                throw new \Exception('Authentication failed. Please check your Fireworks API key.');
            } else if ($httpCode === 404) {
                throw new \Exception("Model not found: {$model}. Please check the model name.");
            } else if ($httpCode === 429) {
                throw new \Exception('Rate limit exceeded. Please try again later.');
            } else {
                throw new \Exception("Fireworks API error (HTTP {$httpCode}): {$errorMessage}");
            }
        }

        $data = is_string($response) === true ? json_decode($response, true) : [];
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception("Unexpected Fireworks API response format: ".(is_string($response) === true ? $response : 'Invalid response'));
        }

        return $data['choices'][0]['message']['content'];

    }//end callFireworksChatAPI()


    /**
     * Call Fireworks AI chat API with full message history
     *
     * Similar to callFireworksChatAPI but supports message history for conversations.
     *
     * @param string $apiKey         Fireworks API key
     * @param string $model          Model name
     * @param string $baseUrl        Base API URL
     * @param array  $messageHistory Array of LLPhantMessage objects
     *
     * @return string Generated response text
     *
     * @throws \Exception If API call fails
     */
    private function callFireworksChatAPIWithHistory(string $apiKey, string $model, string $baseUrl, array $messageHistory, array $functions=[]): string
    {
        $url = rtrim($baseUrl, '/').'/chat/completions';

            // Note: Function calling with Fireworks AI is not yet implemented.
        // Functions will be ignored for Fireworks provider.
        if (!empty($functions)) {
            $this->logger->warning(
                    message: '[ChatService] Function calling not yet supported for Fireworks AI. Tools will be ignored.',
                    context: [
                        'functionCount' => count($functions),
                    ]
                    );
        }

        $this->logger->debug(
                message: '[ChatService] Calling Fireworks chat API with history',
                context: [
                    'url'          => $url,
                    'model'        => $model,
                    'historyCount' => count($messageHistory),
                ]
                );

        // Convert LLPhant messages to API format.
        // LLPhant Message properties are public, so we can access them directly.
        $messages = [];
        foreach ($messageHistory as $msg) {
            // Convert ChatRole enum to string value.
            $roleString = $msg->role->value;
            $content    = $msg->content;

            $messages[] = [
                'role'    => $roleString,
                'content' => $content,
            ];
        }

        // Log final message count.
        $this->logger->debug(
                message: '[ChatService] Prepared messages for API',
                context: [
                    'messageCount' => count($messages),
                ]
                );

        $payload = [
            'model'    => $model,
            'messages' => $messages,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Authorization: Bearer '.$apiKey,
                    'Content-Type: application/json',
                ]
                );
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        // Longer timeout for conversations.
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new \Exception("Fireworks API request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            // Parse error response.
            $errorData    = is_string($response) === true ? json_decode($response, true) : [];
            $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? (is_string($response) === true ? $response : 'Unknown error');

            // Make error messages user-friendly.
            if ($httpCode === 401 || $httpCode === 403) {
                throw new \Exception('Authentication failed. Please check your Fireworks API key.');
            } else if ($httpCode === 404) {
                throw new \Exception("Model not found: {$model}. Please check the model name.");
            } else if ($httpCode === 429) {
                throw new \Exception('Rate limit exceeded. Please try again later.');
            } else {
                throw new \Exception("Fireworks API error (HTTP {$httpCode}): {$errorMessage}");
            }
        }

        $data = is_string($response) === true ? json_decode($response, true) : [];
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception("Unexpected Fireworks API response format: ".(is_string($response) === true ? $response : 'Invalid response'));
        }

        return $data['choices'][0]['message']['content'];

    }//end callFireworksChatAPIWithHistory()


    /**
     * Check if conversation needs summarization and create summary
     *
     * @param Conversation $conversation Conversation entity
     *
     * @return void
     */
    private function checkAndSummarize(Conversation $conversation): void
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
            $lastSummaryTime       = new \DateTime($lastSummary);
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
        } catch (\Exception $e) {
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
     * @param array $messages Array of Message entities
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
            throw new \Exception('Chat provider not configured');
        }

        // Build conversation text.
        $conversationText = '';
        foreach ($messages as $message) {
            $role = $message->getRole() === Message::ROLE_USER ? 'User' : 'Assistant';
            $conversationText .= "{$role}: {$message->getContent()}\n\n";
        }

        // Configure LLM based on provider.
        // Ollama uses its own native config.
        if ($chatProvider === 'ollama') {
            $ollamaConfig = $llmConfig['ollamaConfig'] ?? [];
            if (empty($ollamaConfig['url']) === true) {
                throw new \Exception('Ollama URL not configured');
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
                    throw new \Exception('OpenAI API key not configured');
                }

                $config->apiKey = $openaiConfig['apiKey'];
                $config->model  = 'gpt-4o-mini';
            } else if ($chatProvider === 'fireworks') {
                $fireworksConfig = $llmConfig['fireworksConfig'] ?? [];
                if (empty($fireworksConfig['apiKey']) === true) {
                    throw new \Exception('Fireworks AI API key not configured');
                }

                $config->apiKey = $fireworksConfig['apiKey'];
                $config->model  = 'accounts/fireworks/models/llama-v3p1-8b-instruct';
                $baseUrl        = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
                if (!str_ends_with($baseUrl, '/v1')) {
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
            // Use direct HTTP for Fireworks to avoid OpenAI library issues.
            return $this->callFireworksChatAPI(
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
        }

    }//end generateSummary()


    /**
     * Store a message in the database
     *
     * @param int        $conversationId Conversation ID
     * @param string     $role           Message role (user or assistant)
     * @param string     $content        Message content
     * @param array|null $sources        Optional RAG sources
     *
     * @return Message Stored message entity
     */
    private function storeMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $sources=null
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


    /**
     * Get available tools for an agent
     *
     * Returns an array of tool instances that are enabled for the given agent.
     * Uses ToolRegistry to support tools from other apps.
     *
     * @param Agent|null $agent The agent to get tools for
     *
     * @return array Array of ToolInterface instances
     */


    /**
     * Get agent tools, optionally filtered by selected tool UUIDs
     *
     * @param Agent|null $agent         Agent with tools configuration
     * @param array      $selectedTools Array of tool UUIDs to filter by (empty = all agent tools)
     *
     * @return array Array of ToolInterface instances
     */
    private function getAgentTools(?Agent $agent, array $selectedTools=[]): array
    {
        if ($agent === null) {
            return [];
        }

        $enabledToolIds = $agent->getTools();
        if ($enabledToolIds === null || empty($enabledToolIds) === true) {
            return [];
        }

        // If selectedTools provided, filter enabled tools.
        if (!empty($selectedTools)) {
            $enabledToolIds = array_intersect($enabledToolIds, $selectedTools);
            $this->logger->info(
                    message: '[ChatService] Filtering tools',
                    context: [
                        'agentTools'    => count($agent->getTools()),
                        'selectedTools' => count($selectedTools),
                        'filteredTools' => count($enabledToolIds),
                    ]
                    );
        }

        $tools = [];

        foreach ($enabledToolIds as $toolId) {
            // Support both old format (register, schema, objects) and new format (app.tool).
            $fullToolId = strpos($toolId, '.') !== false ? $toolId : 'openregister.'.$toolId;

            $tool = $this->toolRegistry->getTool($fullToolId);
            if ($tool !== null) {
                $tool->setAgent($agent);
                $tools[] = $tool;
                $this->logger->debug(
                    message: '[ChatService] Loaded tool',
                    context: ['id' => $fullToolId]
                    );
            } else {
                $this->logger->warning(
                    message: '[ChatService] Tool not found',
                    context: ['id' => $fullToolId]
                    );
            }
        }//end foreach

        return $tools;

    }//end getAgentTools()


    /**
     * Convert tools to OpenAI function format
     *
     * Converts our tool definitions to the format expected by OpenAI's function calling API.
     *
     * @param array $tools Array of ToolInterface instances
     *
     * @return array Array of function definitions for OpenAI
     */
    private function convertToolsToFunctions(array $tools): array
    {
        $functions = [];

        foreach ($tools as $tool) {
            $toolFunctions = $tool->getFunctions();
            foreach ($toolFunctions as $function) {
                $functions[] = $function;
            }
        }

        return $functions;

    }//end convertToolsToFunctions()


    /**
     * Convert array-based function definitions to FunctionInfo objects
     *
     * Converts the array format returned by our Tool classes into
     * FunctionInfo objects that LLPhant expects for setTools().
     * Now includes the tool instance so LLPhant can call methods directly.
     *
     * @param array $functions Array of function definitions
     * @param array $tools     Tool instances that have the methods
     *
     * @return array Array of FunctionInfo objects
     */
    private function convertFunctionsToFunctionInfo(array $functions, array $tools): array
    {
        $functionInfoObjects = [];

        foreach ($functions as $func) {
            // Create parameters array.
            $parameters = [];
            $required   = [];

            if (($func['parameters']['properties'] ?? null) !== null) {
                foreach ($func['parameters']['properties'] as $paramName => $paramDef) {
                    // Determine parameter type from definition.
                    $type        = $paramDef['type'] ?? 'string';
                    $description = $paramDef['description'] ?? '';
                    $enum        = $paramDef['enum'] ?? [];
                    $format      = $paramDef['format'] ?? null;
                    $itemsOrProperties = null;

                    // Handle nested object/array types.
                    if ($type === 'object') {
                        // For object types, pass the properties definition (empty array if not specified).
                        $itemsOrProperties = $paramDef['properties'] ?? [];
                    } else if ($type === 'array') {
                        // For array types, pass the items definition (empty array if not specified).
                        $itemsOrProperties = $paramDef['items'] ?? [];
                    }

                    // Create parameter using constructor.
                    // Constructor: __construct(string $name, string $type, string $description, array $enum = [], ?string $format = null, array|string|null $itemsOrProperties = null).
                    $parameters[] = new Parameter($paramName, $type, $description, $enum, $format, $itemsOrProperties);
                }//end foreach
            }//end if

            if (($func['parameters']['required'] ?? null) !== null) {
                $required = $func['parameters']['required'];
            }

            // Find the tool instance that has this function.
            $toolInstance = null;
            foreach ($tools as $tool) {
                $toolFunctions = $tool->getFunctions();
                foreach ($toolFunctions as $toolFunc) {
                    if ($toolFunc['name'] === $func['name']) {
                        $toolInstance = $tool;
                        break 2;
                    }
                }
            }

            // Create FunctionInfo object with the tool instance.
            // LLPhant will call $toolInstance->{$func['name']}(...$args).
            $functionInfo = new FunctionInfo(
                $func['name'],
                $toolInstance,
            // Pass the tool instance.
                $func['description'] ?? '',
                $parameters,
                $required
            );

            $functionInfoObjects[] = $functionInfo;
        }//end foreach

        return $functionInfoObjects;

    }//end convertFunctionsToFunctionInfo()


    /**
     * Handle function call from LLM
     *
     * Executes a function call requested by the LLM and returns the result.
     *
     * @param string      $functionName Function name requested by LLM
     * @param array       $parameters   Function parameters from LLM
     * @param array       $tools        Available tools
     * @param string|null $userId       User ID for context
     *
     * @return string JSON-encoded function result
     */
    private function handleFunctionCall(string $functionName, array $parameters, array $tools, ?string $userId=null): string
    {
        $this->logger->info(
                message: '[ChatService] Handling function call',
                context: [
                    'function'   => $functionName,
                    'parameters' => $parameters,
                ]
                );

        // Find the tool that has this function.
        foreach ($tools as $tool) {
            $toolFunctions = $tool->getFunctions();
            foreach ($toolFunctions as $funcDef) {
                if ($funcDef['name'] === $functionName) {
                    try {
                        $result = $tool->executeFunction($functionName, $parameters, $userId);
                        return json_encode($result);
                    } catch (\Exception $e) {
                        $this->logger->error(
                                message: '[ChatService] Function execution failed',
                                context: [
                                    'function' => $functionName,
                                    'error'    => $e->getMessage(),
                                ]
                                );
                        return json_encode(
                                [
                                    'success' => false,
                                    'error'   => $e->getMessage(),
                                ]
                                );
                    }
                }//end if
            }//end foreach
        }//end foreach

        return json_encode(
                [
                    'success' => false,
                    'error'   => "Unknown function: {$functionName}",
                ]
                );

    }//end handleFunctionCall()


}//end class
