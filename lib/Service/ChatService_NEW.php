<?php
/**
 * OpenRegister Chat Service (Refactored Facade)
 *
 * Service for managing AI chat conversations with RAG (Retrieval Augmented Generation).
 * This is a thin facade that orchestrates specialized handlers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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
 */
class ChatService
{
    private const RECENT_MESSAGES_COUNT = 10;

    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private AgentMapper $agentMapper;
    private ContextRetrievalHandler $contextHandler;
    private ResponseGenerationHandler $responseHandler;
    private ConversationManagementHandler $conversationHandler;
    private MessageHistoryHandler $historyHandler;
    private ToolManagementHandler $toolHandler;
    private LoggerInterface $logger;

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
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper      = $messageMapper;
        $this->agentMapper        = $agentMapper;
        $this->contextHandler     = $contextHandler;
        $this->responseHandler    = $responseHandler;
        $this->conversationHandler = $conversationHandler;
        $this->historyHandler     = $historyHandler;
        $this->toolHandler        = $toolHandler;
        $this->logger             = $logger;
    }

    public function processMessage(
        int $conversationId,
        string $userId,
        string $userMessage,
        array $selectedViews=[],
        array $selectedTools=[],
        array $ragSettings=[]
    ): array {
        $this->logger->info('[ChatService] Processing message', [
            'conversationId' => $conversationId,
            'userId'         => $userId,
            'messageLength'  => strlen($userMessage),
        ]);

        try {
            $conversation = $this->conversationMapper->find($conversationId);
            if ($conversation->getUserId() !== $userId) {
                throw new Exception('Access denied to conversation');
            }

            $agent = null;
            if ($conversation->getAgentId() !== null) {
                $agent = $this->agentMapper->find($conversation->getAgentId());
            }

            // Store user message.
            $this->historyHandler->storeMessage($conversationId, Message::ROLE_USER, $userMessage);

            // Check if conversation needs summarization.
            $this->conversationHandler->checkAndSummarize($conversation);

            // Retrieve RAG context.
            $contextStartTime = microtime(true);
            $context          = $this->contextHandler->retrieveContext($userMessage, $agent, $selectedViews, $ragSettings);
            $contextTime      = microtime(true) - $contextStartTime;

            // Build message history.
            $historyStartTime = microtime(true);
            $messageHistory   = $this->historyHandler->buildMessageHistory($conversationId);
            $historyTime      = microtime(true) - $historyStartTime;

            // Generate LLM response.
            $llmStartTime = microtime(true);
            $aiResponse   = $this->responseHandler->generateResponse($userMessage, $context, $messageHistory, $agent, $selectedTools);
            $llmTime      = microtime(true) - $llmStartTime;

            // Store AI response.
            $this->historyHandler->storeMessage($conversationId, Message::ROLE_ASSISTANT, $aiResponse, $context['sources']);

            // Generate title if needed.
            $messageCount = $this->messageMapper->countByConversation($conversationId);
            $currentTitle = $conversation->getTitle();
            $shouldGenerateTitle = $messageCount <= 2 && ($currentTitle === null || strpos($currentTitle, 'New Conversation') === 0);

            if ($shouldGenerateTitle === true) {
                $title = $this->conversationHandler->generateConversationTitle($userMessage);
                $agentId = $conversation->getAgentId();
                if ($agentId !== null) {
                    $title = $this->conversationHandler->ensureUniqueTitle($title, $conversation->getUserId(), $agentId);
                }
                $conversation->setTitle($title);
                $conversation->setUpdated(new DateTime());
                $this->conversationMapper->update($conversation);
            }

            $totalTime = microtime(true) - ($contextStartTime - $contextTime);

            return [
                'message' => $aiResponse,
                'sources' => $context['sources'],
                'timings' => [
                    'context' => round($contextTime, 2).'s',
                    'history' => round($historyTime, 3).'s',
                    'llm'     => round($llmTime, 2).'s',
                    'total'   => round($totalTime, 2).'s',
                ],
            ];
        } catch (Exception $e) {
            $this->logger->error('[ChatService] Message processing failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function generateConversationTitle(string $firstMessage): string
    {
        return $this->conversationHandler->generateConversationTitle($firstMessage);
    }

    public function ensureUniqueTitle(string $baseTitle, string $userId, int $agentId): string
    {
        return $this->conversationHandler->ensureUniqueTitle($baseTitle, $userId, $agentId);
    }
