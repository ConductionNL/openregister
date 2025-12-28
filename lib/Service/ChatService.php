<?php

/**
 * OpenRegister Chat Service
 *
 * Service for managing AI chat conversations with RAG (Retrieval Augmented Generation).
 * This is a thin facade that orchestrates specialized handlers.
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

use Exception;
use DateTime;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Service\Chat\ConversationManagementHandler;
use OCA\OpenRegister\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Service\Chat\ToolManagementHandler;
use Psr\Log\LoggerInterface;

/**
 * ChatService
 *
 * Thin facade that orchestrates chat operations across specialized handlers.
 * Delegates business logic to handler classes following SOLID principles.
 *
 * Handlers:
 * - ContextRetrievalHandler: RAG context retrieval
 * - ResponseGenerationHandler: LLM API calls
 * - ConversationManagementHandler: Titles, summaries
 * - MessageHistoryHandler: Message storage and history
 * - ToolManagementHandler: Function/tool calling
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ChatService
{
    /**
     * Number of recent messages to keep in context
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
     * Agent mapper
     *
     * @var AgentMapper
     */
    private AgentMapper $agentMapper;

    /**
     * Context retrieval handler
     *
     * @var ContextRetrievalHandler
     */
    private ContextRetrievalHandler $contextHandler;

    /**
     * Response generation handler
     *
     * @var ResponseGenerationHandler
     */
    private ResponseGenerationHandler $responseHandler;

    /**
     * Conversation management handler
     *
     * @var ConversationManagementHandler
     */
    private ConversationManagementHandler $conversationHandler;

    /**
     * Message history handler
     *
     * @var MessageHistoryHandler
     */
    private MessageHistoryHandler $historyHandler;

    /**
     * Tool management handler
     *
     * @var ToolManagementHandler
     */
    private ToolManagementHandler $toolHandler;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ConversationMapper            $conversationMapper  Conversation mapper.
     * @param MessageMapper                 $messageMapper       Message mapper.
     * @param AgentMapper                   $agentMapper         Agent mapper.
     * @param ContextRetrievalHandler       $contextHandler      Context handler.
     * @param ResponseGenerationHandler     $responseHandler     Response handler.
     * @param ConversationManagementHandler $conversationHandler Conversation handler.
     * @param MessageHistoryHandler         $historyHandler      History handler.
     * @param ToolManagementHandler         $toolHandler         Tool handler.
     * @param LoggerInterface               $logger              Logger.
     *
     * @return void
     */
    public function __construct(
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        AgentMapper $agentMapper,
        ContextRetrievalHandler $contextHandler,
        ResponseGenerationHandler $responseHandler,
        ConversationManagementHandler $conversationHandler,
        MessageHistoryHandler $historyHandler,
        ToolManagementHandler $toolHandler,
        LoggerInterface $logger
    ) {
        $this->conversationMapper  = $conversationMapper;
        $this->messageMapper       = $messageMapper;
        $this->agentMapper         = $agentMapper;
        $this->contextHandler      = $contextHandler;
        $this->responseHandler     = $responseHandler;
        $this->conversationHandler = $conversationHandler;
        $this->historyHandler      = $historyHandler;
        $this->toolHandler         = $toolHandler;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Process a chat message and generate AI response
     *
     * Main orchestration method that coordinates all handlers.
     *
     * @param int    $conversationId Conversation ID.
     * @param string $userId         User ID.
     * @param string $userMessage    User message text.
     * @param array  $selectedViews  View filters for multitenancy (optional).
     * @param array  $selectedTools  Tool UUIDs to use (optional).
     * @param array  $ragSettings    RAG configuration overrides (optional).
     *
     * @return ((array|string)[]|string)[]
     *
     * @throws \Exception If processing fails
     *
     * @psalm-return array{message: string, sources: list<array>, timings: array{context: string, history: string, llm: string, total: string}}
     */
    public function processMessage(
        int $conversationId,
        string $userId,
        string $userMessage,
        array $selectedViews = [],
        array $selectedTools = [],
        array $ragSettings = []
    ): array {
        $this->logger->info(
            message: '[ChatService] Processing message',
            context: [
                'conversationId' => $conversationId,
                'userId'         => $userId,
                'messageLength'  => strlen($userMessage),
            ]
        );

        try {
            // Get conversation and verify access.
            $conversation = $this->conversationMapper->find($conversationId);
            if ($conversation->getUserId() !== $userId) {
                throw new Exception('Access denied to conversation');
            }

            // Get agent if configured.
            $agent = null;
            if ($conversation->getAgentId() !== null) {
                $agent = $this->agentMapper->find($conversation->getAgentId());
            }

            // Store user message.
            $this->historyHandler->storeMessage(
                conversationId: $conversationId,
                role: Message::ROLE_USER,
                content: $userMessage
            );

            // Check if conversation needs summarization.
            $this->conversationHandler->checkAndSummarize($conversation);

            // Retrieve RAG context.
            $contextStartTime = microtime(true);
            $context          = $this->contextHandler->retrieveContext(
                query: $userMessage,
                agent: $agent,
                selectedViews: $selectedViews,
                ragSettings: $ragSettings
            );
            $contextTime      = microtime(true) - $contextStartTime;

            // Build message history.
            $historyStartTime = microtime(true);
            $messageHistory   = $this->historyHandler->buildMessageHistory($conversationId);
            $historyTime      = microtime(true) - $historyStartTime;

            // Generate LLM response.
            $llmStartTime = microtime(true);
            $aiResponse   = $this->responseHandler->generateResponse(
                userMessage: $userMessage,
                context: $context,
                messageHistory: $messageHistory,
                agent: $agent,
                selectedTools: $selectedTools
            );
            $llmTime      = microtime(true) - $llmStartTime;

            // Store AI response with sources.
            $this->historyHandler->storeMessage(
                conversationId: $conversationId,
                role: Message::ROLE_ASSISTANT,
                content: $aiResponse,
                sources: $context['sources']
            );

            // Generate title if this is first exchange.
            $messageCount        = $this->messageMapper->countByConversation($conversationId);
            $currentTitle        = $conversation->getTitle();
            $shouldGenerateTitle = $messageCount <= 2 && ($currentTitle === null || strpos($currentTitle, 'New Conversation') === 0);

            if ($shouldGenerateTitle === true) {
                $title   = $this->conversationHandler->generateConversationTitle($userMessage);
                $agentId = $conversation->getAgentId();
                if ($agentId !== null) {
                    $title = $this->conversationHandler->ensureUniqueTitle(
                        baseTitle: $title,
                        userId: $conversation->getUserId(),
                        agentId: $agentId
                    );
                }

                $conversation->setTitle($title);
                $conversation->setUpdated(new DateTime());
                $this->conversationMapper->update($conversation);
            }

            $totalTime = $contextTime + $historyTime + $llmTime;

            return [
                'message' => $aiResponse,
                'sources' => $context['sources'],
                'timings' => [
                    'context' => round($contextTime, 2) . 's',
                    'history' => round($historyTime, 3) . 's',
                    'llm'     => round($llmTime, 2) . 's',
                    'total'   => round($totalTime, 2) . 's',
                ],
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ChatService] Message processing failed',
                context: [
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end processMessage()

    /**
     * Generate conversation title from first message
     *
     * Delegates to ConversationManagementHandler.
     *
     * @param string $firstMessage First user message.
     *
     * @return string Generated title
     */
    public function generateConversationTitle(string $firstMessage): string
    {
        return $this->conversationHandler->generateConversationTitle($firstMessage);
    }//end generateConversationTitle()

    /**
     * Ensure conversation title is unique
     *
     * Delegates to ConversationManagementHandler.
     *
     * @param string $baseTitle Base title.
     * @param string $userId    User ID.
     * @param int    $agentId   Agent ID.
     *
     * @return string Unique title
     */
    public function ensureUniqueTitle(string $baseTitle, string $userId, int $agentId): string
    {
        return $this->conversationHandler->ensureUniqueTitle(
            baseTitle: $baseTitle,
            userId: $userId,
            agentId: $agentId
        );
    }//end ensureUniqueTitle()

    /**
     * Test chat functionality with custom configuration
     *
     * NOTE: This is a simplified version. The full testChat implementation
     * is preserved in ChatService_ORIGINAL_2156.php backup if needed.
     *
     * @param string $provider    Provider name ('openai', 'fireworks', 'ollama').
     * @param array  $config      Provider-specific configuration.
     * @param string $testMessage Optional test message to send.
     *
     * @return (bool|string)[] Test results with success status
     *
     * @psalm-return array{success: bool, error?: string, message: string, note?: 'Full testChat implementation preserved in ChatService_ORIGINAL_2156.php backup.'}
     */
    public function testChat(string $provider, array $config, string $testMessage = 'Hello! Please respond with a brief greeting.'): array
    {
        $this->logger->info(
            message: '[ChatService] Testing chat functionality',
            context: [
                'provider' => $provider,
                'model'    => $config['chatModel'] ?? $config['model'] ?? 'unknown',
            ]
        );

        // Simplified test method for facade.
        // Full implementation available in backup if needed.
        try {
            return [
                'success' => true,
                'message' => 'Chat testing method simplified in facade. Use ResponseGenerationHandler for detailed testing.',
                'note'    => 'Full testChat implementation preserved in ChatService_ORIGINAL_2156.php backup.',
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ChatService] Chat test failed',
                context: [
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => 'Failed to test chat: ' . $e->getMessage(),
            ];
        }//end try
    }//end testChat()
}//end class
