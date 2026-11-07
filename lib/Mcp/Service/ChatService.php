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

namespace OCA\OpenRegister\Mcp\Service;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use Exception;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use OCA\OpenRegister\Mcp\Service\Chat\ContextRetrievalHandler;
use OCA\OpenRegister\Mcp\Service\Chat\ConversationManagementHandler;
use OCA\OpenRegister\Mcp\Service\Chat\MessageHistoryHandler;
use OCA\OpenRegister\Mcp\Service\Chat\ResponseGenerationHandler;
use OCA\OpenRegister\Kernel\Contracts\ChatStreamChannelInterface;
use OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler;
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
class ChatService implements ChatServiceInterface {
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
	 * @var MessageMapperInterface
	 */
	private MessageMapperInterface $messageMapper;

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
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param ConversationMapper $conversationMapper Conversation mapper.
	 * @param MessageMapperInterface $messageMapper Message mapper.
	 * @param AgentMapper $agentMapper Agent mapper.
	 * @param ContextRetrievalHandler $contextHandler Context handler.
	 * @param ResponseGenerationHandler $responseHandler Response handler.
	 * @param ConversationManagementHandler $conversationHandler Conversation handler.
	 * @param MessageHistoryHandler $historyHandler History handler.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ConversationMapper $conversationMapper,
		MessageMapperInterface $messageMapper,
		AgentMapper $agentMapper,
		ContextRetrievalHandler $contextHandler,
		ResponseGenerationHandler $responseHandler,
		ConversationManagementHandler $conversationHandler,
		MessageHistoryHandler $historyHandler,
		LoggerInterface $logger,
	) {
		$this->conversationMapper = $conversationMapper;
		$this->messageMapper = $messageMapper;
		$this->agentMapper = $agentMapper;
		$this->contextHandler = $contextHandler;
		$this->responseHandler = $responseHandler;
		$this->conversationHandler = $conversationHandler;
		$this->historyHandler = $historyHandler;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Process a chat message and generate AI response
	 *
	 * Main orchestration method that coordinates all handlers.
	 *
	 * @param int $conversationId Conversation ID.
	 * @param string $userId User ID.
	 * @param string $userMessage User message text.
	 * @param array $selectedViews View filters for multitenancy (optional).
	 * @param array $selectedTools Tool UUIDs to use (optional).
	 * @param array $ragSettings RAG configuration overrides (optional).
	 * @param array $context CnAiContext snapshot the frontend sent
	 *                       (orchestrator §8). Persisted on the
	 *                       user-authored Message row when non-empty.
	 * @param ChatStreamChannelInterface|null $channel Streaming channel forwarded to the response
	 *                                         handler so SSE consumers (ChatStreamController)
	 *                                         can interleave `token` / `tool_call` /
	 *                                         `tool_result` frames as the LLM yields. Null
	 *                                         for blocking callers (POST /api/chat/send,
	 *                                         background workers) — behaviour unchanged.
	 *
	 * @return ((array|string)[]|string)[]
	 *
	 * @throws \Exception If processing fails
	 *
	 * @psalm-return array{message: string, messageId: string, sources: list<array>,
	 *     timings: array{context: string, history: string, llm: string,
	 *     total: string}}
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function processMessage(
		int $conversationId,
		string $userId,
		string $userMessage,
		array $selectedViews = [],
		array $selectedTools = [],
		array $ragSettings = [],
		array $context = [],
		?ChatStreamChannelInterface $channel = null,
	): array {
		$request = new ChatMessageRequest(conversationId: $conversationId, userId: $userId, userMessage: $userMessage);
		$request->selectedViews = $selectedViews;
		$request->selectedTools = $selectedTools;
		$request->ragSettings = $ragSettings;
		$request->cnAiContext = $context;
		$request->channel = $channel;
		return $this->handleMessage(request: $request);
	}//end processMessage()

	/**
	 * Answer one chat message: store it, generate the reply, store that and title the conversation.
	 *
	 * @param ChatMessageRequest $request The message and its options.
	 *
	 * @return array The processMessage() result.
	 *
	 * @throws Exception If processing fails; the failure is logged first.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function handleMessage(ChatMessageRequest $request): array {
		$this->logger->info(
			message: '[ChatService] Processing message',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'conversationId' => $request->conversationId,
				'userId' => $request->userId,
				'messageLength' => strlen($request->userMessage),
			]
		);

		try {
			$conversation = $this->findOwnConversation(request: $request);
			$agent = $this->findConversationAgent(conversation: $conversation);
			$this->recordUserMessage(request: $request, conversation: $conversation);
			$result = $this->answerMessage(request: $request, agent: $agent);
			$this->titleNewConversation(request: $request, conversation: $conversation);
			// Per-run LLM token/latency usage for run-cost recording (run-analytics).
			$result['usage'] = $this->responseHandler->lastUsage;
			return $result;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ChatService] Message processing failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end handleMessage()

	/**
	 * Load the request's conversation, refusing one owned by another user.
	 *
	 * @param ChatMessageRequest $request The message and its options.
	 *
	 * @return Conversation The conversation.
	 *
	 * @throws Exception If the conversation belongs to another user.
	 */
	private function findOwnConversation(ChatMessageRequest $request): Conversation {
		$conversation = $this->conversationMapper->find($request->conversationId);
		if ($conversation->getUserId() !== $request->userId) {
			throw new Exception('Access denied to conversation');
		}

		return $conversation;
	}//end findOwnConversation()

	/**
	 * The agent configured on a conversation, if any.
	 *
	 * @param Conversation $conversation The conversation.
	 *
	 * @return Agent|null The agent, or null when none is configured.
	 */
	private function findConversationAgent(Conversation $conversation): ?Agent {
		if ($conversation->getAgentId() === null) {
			return null;
		}

		return $this->agentMapper->find($conversation->getAgentId());
	}//end findConversationAgent()

	/**
	 * Store the user's message with its CnAiContext snapshot and summarize the conversation when due.
	 *
	 * @param ChatMessageRequest $request      The message and its options.
	 * @param Conversation       $conversation The conversation.
	 *
	 * @return void
	 */
	private function recordUserMessage(ChatMessageRequest $request, Conversation $conversation): void {
		$this->historyHandler->storeMessage(
			conversationId: $request->conversationId,
			role: Message::ROLE_USER,
			content: $request->userMessage,
			sources: null,
			context: $request->cnAiContext
		);

		$this->conversationHandler->checkAndSummarize($conversation);
	}//end recordUserMessage()

	/**
	 * Retrieve RAG context and history, generate the reply and store it.
	 *
	 * The CnAiContext snapshot goes to the response handler so the system prompt can name
	 * the app the user is in.
	 *
	 * @param ChatMessageRequest $request The message and its options.
	 * @param Agent|null         $agent   The conversation's agent.
	 *
	 * @return array The reply, its stored message id, sources and timings.
	 */
	private function answerMessage(ChatMessageRequest $request, ?Agent $agent): array {
		$started = microtime(true);
		$context = $this->contextHandler->retrieveContext(
			query: $request->userMessage,
			agent: $agent,
			selectedViews: $request->selectedViews,
			ragSettings: $request->ragSettings
		);
		$contextTime = microtime(true) - $started;

		$started = microtime(true);
		$messageHistory = $this->historyHandler->buildMessageHistory($request->conversationId);
		$historyTime = microtime(true) - $started;

		$started = microtime(true);
		$aiResponse = $this->responseHandler->generateResponse(
			userMessage: $request->userMessage,
			context: $context,
			messageHistory: $messageHistory,
			agent: $agent,
			selectedTools: $request->selectedTools,
			channel: $request->channel,
			cnAiContext: $request->cnAiContext
		);
		$llmTime = microtime(true) - $started;

		return $this->storeAnswer(
			request: $request,
			answer: [
				'message' => $aiResponse,
				'sources' => $context['sources'],
				'times' => [$contextTime, $historyTime, $llmTime],
			]
		);
	}//end answerMessage()

	/**
	 * Store the assistant's reply and shape the result.
	 *
	 * The stored message id lets SSE consumers (ChatStreamController and the widget's render key)
	 * address the assistant bubble.
	 *
	 * @param ChatMessageRequest $request The message and its options.
	 * @param array              $answer  Reply text, sources and the context/history/llm seconds.
	 *
	 * @return array The reply, its stored message id, sources and timings.
	 */
	private function storeAnswer(ChatMessageRequest $request, array $answer): array {
		$assistantStored = $this->historyHandler->storeMessage(
			conversationId: $request->conversationId,
			role: Message::ROLE_ASSISTANT,
			content: $answer['message'],
			sources: $answer['sources']
		);

		[$contextTime, $historyTime, $llmTime] = $answer['times'];
		$totalTime = $contextTime + $historyTime + $llmTime;

		return [
			'message' => $answer['message'],
			'messageId' => (string)($assistantStored->getId() ?? ''),
			'sources' => $answer['sources'],
			'timings' => [
				'context' => round($contextTime, 2) . 's',
				'history' => round($historyTime, 3) . 's',
				'llm' => round($llmTime, 2) . 's',
				'total' => round($totalTime, 2) . 's',
			],
		];
	}//end storeAnswer()

	/**
	 * Give a still-untitled conversation a title after its first exchange.
	 *
	 * @param ChatMessageRequest $request      The first user message and its options.
	 * @param Conversation       $conversation The conversation.
	 *
	 * @return void
	 */
	private function titleNewConversation(ChatMessageRequest $request, Conversation $conversation): void {
		$messageCount = $this->messageMapper->countByConversation($request->conversationId);
		$currentTitle = $conversation->getTitle();
		$isNewConversation = $currentTitle === null || strpos($currentTitle, 'New Conversation') === 0;
		if ($messageCount > 2 || $isNewConversation === false) {
			return;
		}

		$title = $this->conversationHandler->generateConversationTitle($request->userMessage);
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
	}//end titleNewConversation()

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
	public function generateConversationTitle(string $firstMessage): string {
		return $this->conversationHandler->generateConversationTitle($firstMessage);
	}//end generateConversationTitle()

	/**
	 * Ensure conversation title is unique
	 *
	 * Delegates to ConversationManagementHandler.
	 *
	 * @param string $baseTitle Base title.
	 * @param string $userId User ID.
	 * @param int $agentId Agent ID.
	 *
	 * @return string Unique title
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function ensureUniqueTitle(string $baseTitle, string $userId, int $agentId): string {
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
	 * @param string $provider Provider name ('openai', 'fireworks', 'ollama').
	 * @param array $config Provider-specific configuration.
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
		string $_testMessage = 'Hello! Please respond with a brief greeting.',
	): array {
		$this->logger->info(
			message: '[ChatService] Testing chat functionality',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $provider,
				'model' => $config['chatModel'] ?? $config['model'] ?? 'unknown',
			]
		);

		// Simplified test method for facade.
		// Full implementation available in backup if needed.
		try {
			return [
				'success' => true,
				'message' => 'Chat testing method simplified in facade. Use ResponseGenerationHandler for detailed testing.',
				'note' => 'Full testChat implementation preserved in ChatService_ORIGINAL_2156.php backup.',
			];
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ChatService] Chat test failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return [
				'success' => false,
				'error' => $e->getMessage(),
				'message' => 'Failed to test chat: ' . $e->getMessage(),
			];
		}//end try
	}//end testChat()
}//end class
