<?php

/**
 * OpenRegister Chat Service
 *
 * Service for managing AI chat conversations with RAG (Retrieval Augmented Generation).
 * This is a thin facade that orchestrates specialized handlers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/chat-ai/spec.md
 * @spec openspec/specs/chat-ai/spec.md
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
use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
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
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)  testChat() declares ($provider, $config, $_testMessage)
 * matching the public contract expected by callers and the backup implementation; the simplified stub
 * body doesn't use all three but changing the signature would break callers.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Facade orchestrates eight dependencies
 * (ConversationMapper, MessageMapper, AgentMapper, and five handler classes); each handler is a
 * separate concern extracted per SOLID, and removing any would lose a capability.
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
     * @param int                     $conversationId Conversation ID.
     * @param string                  $userId         User ID.
     * @param string                  $userMessage    User message text.
     * @param array                   $selectedViews  View filters for multitenancy (optional).
     * @param array                   $selectedTools  Tool UUIDs to use (optional).
     * @param array                   $ragSettings    RAG configuration overrides (optional).
     * @param array                   $context        CnAiContext snapshot the frontend sent
     *                                                (orchestrator §8). Persisted on the
     *                                                user-authored Message row when non-empty.
     * @param StreamYieldChannel|null $channel        Streaming channel forwarded to the response
     *                                                handler so SSE consumers (ChatStreamController)
     *                                                can interleave `token` / `tool_call` /
     *                                                `tool_result` frames as the LLM yields. Null
     *                                                for blocking callers (POST /api/chat/send,
     *                                                background workers) — behaviour unchanged.
     *
     * @return ((array|string)[]|string)[]
     *
     * @throws \Exception If processing fails
     *
     * @psalm-return array{message: string, messageId: string, sources: list<array>,
     *     timings: array{context: string, history: string, llm: string,
     *     total: string}}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Chat processing involves multiple handler coordination steps
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many optional paths for agent, title generation, and timing
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Full chat orchestration requires comprehensive step handling
     *
     * @spec openspec/specs/chat-ai/spec.md
     */
    public function processMessage(
        int $conversationId,
        string $userId,
        string $userMessage,
        array $selectedViews=[],
        array $selectedTools=[],
        array $ragSettings=[],
        array $context=[],
        ?StreamYieldChannel $channel=null
    ): array {
        $this->logger->info(
            message: '[ChatService] Processing message',
            context: [
                'file'           => __FILE__,
                'line'           => __LINE__,
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

            // Capture the CnAiContext snapshot under its own name before
            // the retrieveContext() call below reuses `$context` for the
            // RAG context object. Without this rename the snapshot would
            // be silently overwritten and the LLM would never see it.
            $cnAiContext = $context;

            // Store user message with the CnAiContext snapshot.
            $this->historyHandler->storeMessage(
                conversationId: $conversationId,
                role: Message::ROLE_USER,
                content: $userMessage,
                sources: null,
                context: $cnAiContext
            );

            // Check if conversation needs summarization.
            $this->conversationHandler->checkAndSummarize($conversation);

            // Retrieve RAG context. Note: `$context` is now the RAG
            // context shape `{text, sources}`, distinct from `$cnAiContext`.
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

            // Generate LLM response. Forward the CnAiContext snapshot so
            // the system prompt can include "the user is currently in
            // {app}" — without it the model would default to generic
            // platform-wide phrasing and pick the wrong tool family.
            $llmStartTime = microtime(true);
            $aiResponse   = $this->responseHandler->generateResponse(
                userMessage: $userMessage,
                context: $context,
                messageHistory: $messageHistory,
                agent: $agent,
                selectedTools: $selectedTools,
                channel: $channel,
                cnAiContext: $cnAiContext
            );
            $llmTime      = microtime(true) - $llmStartTime;

            // Store AI response with sources. Capture the return so we can surface
            // the persisted assistant message's id to the caller (ChatStreamController
            // needs it to populate the SSE `final` event's messageId field; the widget
            // uses it as the Vue render key for the assistant bubble).
            $assistantStored = $this->historyHandler->storeMessage(
                conversationId: $conversationId,
                role: Message::ROLE_ASSISTANT,
                content: $aiResponse,
                sources: $context['sources']
            );

            // Generate title if this is first exchange.
            $messageCount        = $this->messageMapper->countByConversation($conversationId);
            $currentTitle        = $conversation->getTitle();
            $isNewConversation   = $currentTitle === null || strpos($currentTitle, 'New Conversation') === 0;
            $shouldGenerateTitle = $messageCount <= 2 && $isNewConversation;

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
                'message'   => $aiResponse,
                // Surface the persisted assistant message id for SSE consumers
                // (ChatStreamController + the nc-vue widget render key).
                'messageId' => (string) ($assistantStored->getId() ?? ''),
                'sources'   => $context['sources'],
                'timings'   => [
                    'context' => round($contextTime, 2).'s',
                    'history' => round($historyTime, 3).'s',
                    'llm'     => round($llmTime, 2).'s',
                    'total'   => round($totalTime, 2).'s',
                ],
                // Per-run LLM token/latency usage for run-cost recording (run-analytics).
                'usage'     => $this->responseHandler->lastUsage,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ChatService] Message processing failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
     *
     * @spec openspec/specs/chat-ai/spec.md
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
     *
     * @spec openspec/specs/chat-ai/spec.md
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
     * @param string $provider     Provider name ('openai', 'fireworks', 'ollama').
     * @param array  $config       Provider-specific configuration.
     * @param string $_testMessage Optional test message to send.
     *
     * @return array Test result with success status, message, and optional error.
     *
     * @spec exclude Facade plumbing: simplified stub returning a static result; real testing goes through
     *       ResponseGenerationHandler (chat-ai). No standalone contract.
     */
    public function testChat(
        string $provider,
        array $config,
        string $_testMessage='Hello! Please respond with a brief greeting.'
    ): array {
        $this->logger->info(
            message: '[ChatService] Testing chat functionality',
            context: [
                'file'     => __FILE__,
                'line'     => __LINE__,
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
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'message' => 'Failed to test chat: '.$e->getMessage(),
            ];
        }//end try
    }//end testChat()
}//end class
